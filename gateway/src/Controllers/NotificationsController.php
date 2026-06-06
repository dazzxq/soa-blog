<?php
declare(strict_types=1);

namespace App\Controllers;

use App\DomainError;
use App\Json;
use App\Services\NotificationClient;
use App\Services\ProfileClient;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /api/notifications/* — the gateway notification list/badge/mark layer
 * (NOTIF-02 / NOTIF-03). The recipient is ALWAYS the JWT identity via me(); the
 * gateway maps it to X-User-Id (the notification-service scopes every read/mark
 * to that header — IDOR-safe, T-05-18). A query/body user_id is never trusted.
 *
 * index() passes the notification-service unread_count straight through (the
 * exact badge number) and enriches each actor with a public-safe card. The actor
 * enrichment goes through the SAME email-dropping allowlist used everywhere else
 * (T-05-19): /users?ids= SELECTs email, so the gateway allowlists to
 * {id,username,display_name,avatar_url} before emitting — email never reaches the
 * client. A profile-service failure DEGRADES (actor:null + meta.degraded), it
 * never bubbles a 500. mark-read/read-all are thin scoped passthroughs.
 */
final class NotificationsController
{
    public function __construct(
        private NotificationClient $notifications,
        private ProfileClient $profiles,
    ) {}

    /** GET /api/notifications — recipient-scoped list + unread_count + actor enrich. */
    public function index(Request $req, Response $res): Response
    {
        $me = $this->me($req);
        $up = $this->notifications->list($me);
        if ($up->getStatusCode() !== 200) {
            return Json::raw($res, $this->decode($up), $up->getStatusCode());
        }

        $decoded = $this->decode($up);
        $rows    = (array) ($decoded['data'] ?? []);
        $unread  = (int) ($decoded['meta']['unread_count'] ?? 0);

        // Unique positive actor ids → ONE profile batch (no N+1).
        $ids = [];
        foreach ($rows as $r) {
            $aid = (int) ($r['actor_id'] ?? 0);
            if ($aid > 0) {
                $ids[] = $aid;
            }
        }
        $ids = array_values(array_unique($ids));

        $cardsById = [];
        $degraded  = false;
        if ($ids !== []) {
            try {
                $pRes = $this->profiles->batch($ids);
                if ($pRes->getStatusCode() === 200) {
                    foreach ((array) ($this->decode($pRes)['data'] ?? []) as $u) {
                        // Email-dropping allowlist (T-05-19): never emit email.
                        $cardsById[(int) ($u['id'] ?? 0)] = array_intersect_key(
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

        $out = array_map(static function (array $r) use ($cardsById): array {
            $r['actor'] = $cardsById[(int) ($r['actor_id'] ?? 0)] ?? null;
            return $r;
        }, $rows);

        $meta = ['unread_count' => $unread];
        if ($degraded) {
            $meta['degraded'] = true;
            $meta['parts']    = ['profiles'];
        }
        return Json::list($res, array_values($out), $meta);
    }

    /** POST /api/notifications/{id}/read — scoped passthrough (NOTIF-03). */
    public function markRead(Request $req, Response $res, array $args): Response
    {
        $me = $this->me($req);
        $up = $this->notifications->markRead($me, (int) $args['id']);
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    /** POST /api/notifications/read-all — scoped passthrough (NOTIF-03). */
    public function markAllRead(Request $req, Response $res): Response
    {
        $me = $this->me($req);
        $up = $this->notifications->markAllRead($me);
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
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
