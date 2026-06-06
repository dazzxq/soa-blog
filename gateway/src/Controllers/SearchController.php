<?php
declare(strict_types=1);

namespace App\Controllers;

use App\DomainError;
use App\Json;
use App\Services\ConnectionClient;
use App\Services\ProfileClient;
use App\Services\SearchClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /api/search + /api/search/reindex — the gateway search composition (SEARCH-01/02).
 *
 * search() is the SEARCH-02 showcase: ONE client request → search-service runs the
 * LIKE query, then the gateway composes EACH hit with the viewer's connection_status
 * via a PARALLEL fan-out (Utils::settle of statusForAsync, capped at ≤20 hits). A
 * failed/slow status promise degrades that card to 'unknown' + meta.degraded — never
 * a 500 (Pitfall 5 / T-05-13). Cards are email-allowlisted to
 * {id,username,display_name,headline,avatar_url,connection_status}; search_index has
 * no email column, so the allowlist is defense-in-depth (Pitfall 1 / T-05-12).
 *
 * reindex() rebuilds the index: it pulls the user universe from profile-service
 * (allUsers) and, per user, pulls headline/location/skills via getFull (the /users
 * list lacks those — Pitfall 6), flattens skills[] → skills_text, and upserts each
 * into search-service. A per-user getFull/upsert failure is isolated (partial index
 * beats none); it never aborts the whole reindex.
 *
 * Doctrine reused verbatim from ConnectionsController/FeedController:
 *   - identity from the JWT-set attribute via me() (never a body/query user id, T-05-15);
 *   - viewer-relative status is the single connection-service computation (D-05).
 */
final class SearchController
{
    public function __construct(
        private SearchClient $search,
        private ProfileClient $profiles,
        private ConnectionClient $connections,
    ) {}

    /**
     * GET /api/search?q= (SEARCH-01/02) — quick-connect cards with viewer-relative
     * connection_status via a parallel Utils::settle(statusForAsync) fan-out.
     */
    public function search(Request $req, Response $res): Response
    {
        $me = $this->me($req);
        $q  = trim((string) ($req->getQueryParams()['q'] ?? ''));
        if ($q === '') {
            return Json::list($res, [], ['q' => '']);
        }

        // search-service is the hard dep — a non-200 is passed through verbatim.
        $sr = $this->search->search($q, 20);
        if ($sr->getStatusCode() !== 200) {
            return Json::raw($res, $this->decode($sr), $sr->getStatusCode());
        }
        $hits = (array) ($this->decode($sr)['data'] ?? []);

        // Keep hits with a valid target uid that is not the viewer (no self-status).
        $kept = [];
        foreach ($hits as $h) {
            $uid = (int) ($h['user_id'] ?? 0);
            if ($uid <= 0 || $uid === $me) {
                continue;
            }
            $kept[] = $h;
        }

        // PARALLEL status fan-out (the SEARCH-02 showcase): one statusForAsync per
        // kept hit, settled together. settle never throws — a rejected/slow promise
        // degrades that card to 'unknown' + meta.degraded, never a 500 (T-05-13).
        $promises = [];
        foreach ($kept as $h) {
            $uid = (int) $h['user_id'];
            $promises[$uid] = $this->connections->statusForAsync($me, $uid);
        }
        $settled  = $promises === [] ? [] : Utils::settle($promises)->wait();
        $degraded = [];

        $cards = [];
        foreach ($kept as $h) {
            $uid = (int) $h['user_id'];
            $status = 'none';
            $r = $settled[$uid] ?? null;
            if ($r !== null && ($r['state'] ?? '') === 'fulfilled' && $r['value']->getStatusCode() === 200) {
                $status = (string) ($this->decode($r['value'])['data']['status'] ?? 'none');
            } elseif ($r !== null) {
                $status = 'unknown';
                $degraded['status'] = true;
            }

            // Allowlist: only these keys are emitted. search_index has no email
            // column, so this is defense-in-depth (Pitfall 1 / T-05-12).
            $cards[] = [
                'id'                => $uid,
                'username'          => $h['username'] ?? null,
                'display_name'      => $h['display_name'] ?? null,
                'headline'          => $h['headline'] ?? null,
                'avatar_url'        => $h['avatar_url'] ?? null,
                'connection_status' => $status,
            ];
        }

        $meta = ['q' => $q];
        if ($degraded !== []) {
            $meta['degraded'] = true;
            $meta['parts']    = array_keys($degraded);
        }
        return Json::list($res, $cards, $meta);
    }

    /**
     * POST /api/search/reindex — rebuild the index from profile-service.
     *
     * Any logged-in user may trigger it (T-05-14, A4): it is idempotent (search
     * upsert is ON DUPLICATE KEY UPDATE), reads only public profile fields, and
     * writes only the index — low blast radius for a demo.
     */
    public function reindex(Request $req, Response $res): Response
    {
        $this->me($req); // authn gate only (any logged-in user).

        $universe = $this->profiles->allUsers(100); // degrades to [] safely.
        $indexed  = 0;
        $failed   = 0;

        foreach ($universe as $u) {
            $uid = (int) ($u['id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }

            // /users (allUsers) lacks headline/location/skills — pull them via getFull
            // (Pitfall 6). A getFull failure proceeds with the basic fields (partial
            // index beats none); it does not skip the user.
            $headline   = null;
            $location   = null;
            $skillsText = null;
            try {
                $fr = $this->profiles->getFull($uid);
                if ($fr->getStatusCode() === 200) {
                    $full     = (array) ($this->decode($fr)['data'] ?? []);
                    $headline = $full['headline'] ?? null;
                    $location = $full['location'] ?? null;
                    $skills   = array_map(
                        static fn($s) => (string) ($s['name'] ?? ''),
                        (array) ($full['skills'] ?? []),
                    );
                    $skillsText = implode(', ', array_filter($skills)) ?: null;
                }
            } catch (GuzzleException $e) {
                // proceed with basic fields only.
            }

            try {
                $ir = $this->search->upsert([
                    'user_id'      => $uid,
                    'username'     => $u['username'] ?? '',
                    'display_name' => $u['display_name'] ?? '',
                    'avatar_url'   => $u['avatar_url'] ?? null,
                    'headline'     => $headline,
                    'location'     => $location,
                    'skills_text'  => $skillsText,
                ]);
                if (in_array($ir->getStatusCode(), [200, 201], true)) {
                    $indexed++;
                } else {
                    $failed++;
                }
            } catch (GuzzleException $e) {
                $failed++;
            }
        }

        return Json::raw($res, [
            'data' => ['indexed' => $indexed, 'failed' => $failed, 'total' => count($universe)],
        ], 200);
    }

    // --- Helpers (clone of ConnectionsController) ----------------------------

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
