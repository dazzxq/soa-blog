<?php
declare(strict_types=1);

namespace App\Controllers;

use App\DomainError;
use App\Json;
use App\Services\ConnectionClient;
use App\Services\ProfileClient;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /api/connections/* — the gateway connection layer.
 *
 * Centerpiece: sendRequest enforces the D-01 cross-service invariant — clone of
 * the git-history PostsController::delete() 503-on-incomplete rule. A connection
 * write on unverified cross-service information is the integrity hazard we refuse:
 *   self/invalid       -> 400 INVALID_TARGET
 *   profile clean-404  -> 404 PROFILE_NOT_FOUND
 *   profile >=500/net  -> 503 PROFILE_SERVICE_UNAVAILABLE     (NO write)
 *   existing edge      -> 409 ALREADY_CONNECTED / REQUEST_EXISTS
 *   status  >=500/net  -> 503 CONNECTION_SERVICE_UNAVAILABLE  (NO write)
 *   else (200 + none)  -> write
 *
 * All enriched cards (connections, pending, suggestions) pass profile basics
 * through an email-dropping allowlist (Pitfall 3). The suggestions candidate
 * universe is composed at the gateway from ProfileClient::allUsers; the JWT
 * user_id is mapped to X-User-Id by the clients — a body user_id is never trusted.
 */
final class ConnectionsController
{
    public function __construct(
        private ProfileClient $profiles,
        private ConnectionClient $connections,
    ) {}

    /**
     * POST /api/connections/requests {target_id} — CONN-01 + CONN-07.
     * THE cross-service invariant (mirror PostsController::delete 503-on-incomplete).
     */
    public function sendRequest(Request $req, Response $res): Response
    {
        $me     = $this->me($req);
        $body   = (array) ($req->getParsedBody() ?? []);
        $target = (int) ($body['target_id'] ?? 0);

        // (1) self / invalid target.
        if ($target === $me || $target <= 0) {
            throw new DomainError(400, 'INVALID_TARGET', 'Không thể tự gửi lời mời cho chính mình.');
        }

        // (2) PROFILE-EXISTENCE check — refuse to write on incomplete info.
        try {
            $check = $this->profiles->get($target);
        } catch (GuzzleException $e) {
            throw new DomainError(503, 'PROFILE_SERVICE_UNAVAILABLE',
                'Không kiểm tra được người dùng. Vui lòng thử lại.');
        }
        $checkStatus = $check->getStatusCode();
        if ($checkStatus === 404) {
            throw new DomainError(404, 'PROFILE_NOT_FOUND', 'Người dùng không tồn tại.');
        }
        if ($checkStatus >= 500) {
            // NOT a Json::raw passthrough — a write on an unverified target is the hazard.
            throw new DomainError(503, 'PROFILE_SERVICE_UNAVAILABLE',
                'Không kiểm tra được người dùng. Vui lòng thử lại.');
        }
        if ($checkStatus !== 200) {
            // Any other non-200 (e.g. 4xx) — still not a confirmed-existing target.
            throw new DomainError(503, 'PROFILE_SERVICE_UNAVAILABLE',
                'Không kiểm tra được người dùng. Vui lòng thử lại.');
        }

        // (3) EXISTING-EDGE / status check — single status computation; refuse on incomplete info.
        try {
            $statusRes = $this->connections->statusFor($me, $target);
        } catch (GuzzleException $e) {
            throw new DomainError(503, 'CONNECTION_SERVICE_UNAVAILABLE',
                'Không kiểm tra được trạng thái kết nối. Vui lòng thử lại.');
        }
        $statusCode = $statusRes->getStatusCode();
        if ($statusCode >= 500) {
            throw new DomainError(503, 'CONNECTION_SERVICE_UNAVAILABLE',
                'Không kiểm tra được trạng thái kết nối. Vui lòng thử lại.');
        }
        if ($statusCode !== 200) {
            throw new DomainError(503, 'CONNECTION_SERVICE_UNAVAILABLE',
                'Không kiểm tra được trạng thái kết nối. Vui lòng thử lại.');
        }
        $status = (string) ($this->decode($statusRes)['data']['status'] ?? 'none');
        if ($status === 'connected') {
            throw new DomainError(409, 'ALREADY_CONNECTED', 'Hai người đã là kết nối.');
        }
        if ($status === 'pending_outgoing' || $status === 'pending_incoming') {
            throw new DomainError(409, 'REQUEST_EXISTS', 'Đã tồn tại một lời mời giữa hai người.');
        }

        // (4) ONLY a clean 200 profile + 'none' status proceeds to the write.
        // (The 23000 -> 409 DB backstop surfaces through this passthrough too.)
        $up = $this->connections->createRequest($me, $target);
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    /** POST /api/connections/requests/{id}/accept — CONN-02. */
    public function accept(Request $req, Response $res, array $args): Response
    {
        $me = $this->me($req);
        $up = $this->connections->accept($me, (int) $args['id']);
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    /** POST /api/connections/requests/{id}/reject — CONN-02 (pending-scoped DELETE). */
    public function reject(Request $req, Response $res, array $args): Response
    {
        $me = $this->me($req);
        $up = $this->connections->deleteRequest($me, (int) $args['id']);
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    /** DELETE /api/connections/requests/{id} — cancel (same pending-scoped DELETE). */
    public function cancel(Request $req, Response $res, array $args): Response
    {
        $me = $this->me($req);
        $up = $this->connections->deleteRequest($me, (int) $args['id']);
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    /** DELETE /api/connections/{userId} — remove an accepted edge (D-03). */
    public function remove(Request $req, Response $res, array $args): Response
    {
        $me = $this->me($req);
        $up = $this->connections->removeEdge($me, (int) $args['userId']);
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    /** GET /api/connections — CONN-03 (enriched accepted list). */
    public function listConnections(Request $req, Response $res): Response
    {
        $me = $this->me($req);
        $up = $this->connections->listAccepted($me);
        if ($up->getStatusCode() !== 200) {
            return Json::raw($res, $this->decode($up), $up->getStatusCode());
        }
        $rows = (array) ($this->decode($up)['data'] ?? []);
        [$cards, $degraded] = $this->enrich($rows);
        return Json::list($res, $cards, $degraded ? ['degraded' => true, 'parts' => ['profiles']] : []);
    }

    /** GET /api/connections/requests?direction=incoming|outgoing — CONN-04 (enriched pending). */
    public function listPending(Request $req, Response $res): Response
    {
        $me        = $this->me($req);
        $direction = (string) ($req->getQueryParams()['direction'] ?? 'incoming');
        if ($direction !== 'incoming' && $direction !== 'outgoing') {
            $direction = 'incoming';
        }
        $up = $this->connections->listPending($me, $direction);
        if ($up->getStatusCode() !== 200) {
            return Json::raw($res, $this->decode($up), $up->getStatusCode());
        }
        $rows = (array) ($this->decode($up)['data'] ?? []);
        [$cards, $degraded] = $this->enrich($rows);
        $meta = ['direction' => $direction];
        if ($degraded) {
            $meta['degraded'] = true;
            $meta['parts']    = ['profiles'];
        }
        return Json::list($res, $cards, $meta);
    }

    /** GET /api/connections/suggestions — CONN-06 (gateway composes the universe). */
    public function suggestions(Request $req, Response $res): Response
    {
        $me = $this->me($req);

        // Candidate universe composed at the gateway (Pitfall 6 / Open Q1): the
        // connection-service cannot enumerate users, so profile-service supplies them.
        $universe = $this->profiles->allUsers(100);
        $candidateIds = array_values(array_filter(
            array_map(static fn($u) => (int) ($u['id'] ?? 0), $universe),
            static fn(int $id) => $id > 0 && $id !== $me,   // guard: exclude self
        ));
        if ($candidateIds === []) {
            return Json::list($res, [], ['degraded' => true, 'parts' => ['profiles']]);
        }

        $sg = $this->connections->suggestions($me, $candidateIds, 10);
        if ($sg->getStatusCode() !== 200) {
            return Json::raw($res, $this->decode($sg), $sg->getStatusCode());
        }
        $rows = (array) ($this->decode($sg)['data'] ?? []);
        [$cards, $degraded] = $this->enrich($rows);
        return Json::list($res, $cards, $degraded ? ['degraded' => true, 'parts' => ['profiles']] : []);
    }

    /** GET /api/connections/status/{userId} — CONN-05 (shares the single status computation). */
    public function statusVsMe(Request $req, Response $res, array $args): Response
    {
        $me = $this->me($req);
        $up = $this->connections->statusFor($me, (int) $args['userId']);
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    /**
     * Enrich connection/pending/suggestion rows with public-safe profile basics.
     *
     * Returns [$cards, $degraded]. The allowlist DROPS email (Pitfall 3 / T-03-13):
     * only id, username, display_name, avatar_url ever reach the client.
     *
     * @return array{0: list<array>, 1: bool}
     */
    private function enrich(array $rows): array
    {
        $degraded = false;
        $ids = array_values(array_unique(array_map(
            static fn(array $r) => (int) ($r['user_id'] ?? 0),
            $rows,
        )));
        $ids = array_values(array_filter($ids, static fn(int $i) => $i > 0));

        $profiles = [];
        if ($ids !== []) {
            // A profile-service outage/timeout must DEGRADE (cards with profile:null),
            // never bubble a 500 (codex-impl-review fix). batch() can throw GuzzleException
            // on a network failure even with http_errors=false, so catch it here too.
            try {
                $res = $this->profiles->batch($ids);
                if ($res->getStatusCode() === 200) {
                    foreach ((array) ($this->decode($res)['data'] ?? []) as $u) {
                        $profiles[(int) ($u['id'] ?? 0)] = array_intersect_key(
                            $u,
                            array_flip(['id', 'username', 'display_name', 'avatar_url']),
                        );
                    }
                } else {
                    $degraded = true;
                }
            } catch (GuzzleException $e) {
                $degraded = true;
            }
        }

        $cards = array_map(static function (array $r) use ($profiles): array {
            $r['profile'] = $profiles[(int) ($r['user_id'] ?? 0)] ?? null;
            return $r;
        }, $rows);

        return [array_values($cards), $degraded];
    }

    /** Resolve the authenticated caller from the JWT-set attribute (never the body). */
    private function me(Request $req): int
    {
        $me = (int) ($req->getAttribute('user_id') ?? 0);
        if ($me <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Vui lòng đăng nhập.');
        }
        return $me;
    }

    private function decode(ResponseInterface $r): array
    {
        $decoded = json_decode((string) $r->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
