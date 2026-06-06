<?php
declare(strict_types=1);

namespace App\Controllers;

use App\DomainError;
use App\Json;
use App\Services\ConnectionClient;
use App\Services\ProfileClient;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/profiles/{id}/full — FLAGSHIP composition endpoint.
 *
 * The gateway fans out IN PARALLEL (Guzzle async + Utils::settle, Option B =
 * both calls async) to:
 *   - profile-service  GET /users/{id}/full      (HARD dependency / spine)
 *   - connection-service GET /connections/status (degradable enrichment, D-03)
 *
 * It merges into ONE response and DEGRADES safely (meta.degraded) when the
 * connection part fails. In Phase 2 the connection-service is a stub → it
 * ALWAYS 404s → ALWAYS degrades; Phase 3 lights it up with ZERO gateway rework.
 *
 * Public + auth-aware (OptionalJwtMiddleware): anonymous → connection_status
 * null; logged-in viewer → viewer-relative status (default 'none').
 */
final class AggregateController
{
    public function __construct(
        private ProfileClient $profiles,
        private ConnectionClient $connections,
    ) {}

    public function profileFull(Request $req, Response $res, array $args): Response
    {
        $targetId = (int) $args['id'];
        $viewerId = (int) ($req->getAttribute('user_id') ?? 0);   // 0 = anonymous (D-04)

        // PARALLEL fan-out: BOTH calls async in the settle array (Option B).
        $settled = Utils::settle([
            'profile'    => $this->profiles->getFullAsync($targetId),
            'connection' => $this->connections->statusForAsync($viewerId, $targetId),
        ])->wait();

        // profile-full is the HARD dependency (spine) — resolved AFTER settle.
        $p = $settled['profile'];
        if ($p['state'] !== 'fulfilled') {
            throw new DomainError(502, 'UPSTREAM_ERROR', 'Không tải được hồ sơ.');
        }
        $pStatus = $p['value']->getStatusCode();
        if ($pStatus === 404) {
            throw new DomainError(404, 'PROFILE_NOT_FOUND', 'Không tìm thấy hồ sơ.');
        }
        if ($pStatus !== 200) {
            return Json::raw($res, $this->decode($p['value']), $pStatus);
        }
        $profile = (array) ($this->decode($p['value'])['data'] ?? []);

        // DEFENSE-IN-DEPTH allowlist (Pitfall 2): even though /full omits email,
        // trim to a positive allowlist so the public route can never leak it.
        $profile = array_intersect_key($profile, array_flip([
            'id', 'username', 'display_name', 'avatar_url', 'cover_url', 'headline', 'location', 'about', 'created_at',
            'experience', 'education', 'skills',
        ]));

        $degraded = [];
        // connection_status: viewer-relative ONLY when logged in (D-04). Anonymous → null.
        // We deliberately do NOT consume the connection result for an anonymous viewer
        // even though connection-service now answers (Phase 3) — the Phase 2 contract is
        // anon connection_status === null, NOT "none". (codex-impl-review fix.)
        $connectionStatus = null;
        if ($viewerId > 0) {
            $connectionStatus = 'none';
            $c = $settled['connection'];
            if ($c['state'] === 'fulfilled' && $c['value']->getStatusCode() === 200) {
                $connectionStatus = (string) ($this->decode($c['value'])['data']['status'] ?? 'none');
            } else {
                $degraded[] = 'connection';   // logged-in but connection-service unavailable → degrade
            }
        }
        $profile['connection_status'] = $connectionStatus;

        $body = ['data' => $profile];
        if ($degraded !== []) {
            $body['meta'] = ['degraded' => true, 'parts' => $degraded];
        }
        $res->getBody()->write(json_encode($body, JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function decode(ResponseInterface $r): array
    {
        $decoded = json_decode((string) $r->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
