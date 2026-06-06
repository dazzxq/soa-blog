<?php
declare(strict_types=1);

namespace App\Controllers;

use App\DomainError;
use App\Json;
use App\Services\ProfileClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Allowlist applied to /me success bodies. profile-service update() returns
 * find() whose SELECT includes `email`, so we re-trim here (defense-in-depth):
 * the caller's OWN email/password_hash must never be echoed back. Mirrors the
 * /full allowlist (minus created_at, which PATCH doesn't return).
 */

final class ProfilesController
{
    public function __construct(private ProfileClient $profiles) {}

    /**
     * GET /api/profiles/{id} — PUBLIC, unauthenticated (D-07, PLAT-02).
     *
     * D-07 (LOCKED): returns ONLY basic info {id, username, display_name}.
     * Because this route is public, passing the profile-service body through
     * verbatim would leak `email` (and avatar_url). We therefore TRIM the
     * success body to a POSITIVE allowlist so any extra field the service adds
     * later (email/avatar_url/created_at/...) is dropped automatically.
     * Error envelopes are passed through unchanged.
     */
    public function show(Request $req, Response $res, array $args): Response
    {
        $upstream = $this->profiles->get((int) $args['id']);
        $status   = $upstream->getStatusCode();
        $decoded  = $this->decode($upstream);

        if ($status >= 200 && $status < 300 && isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded['data'] = array_intersect_key(
                $decoded['data'],
                array_flip(['id', 'username', 'display_name']),
            );
        }

        return Json::raw($res, $decoded, $status);
    }

    // --- Owner-only CRUD via /me (D-10/D-11) ---
    // Each maps the JWT user_id (set by JwtAuthMiddleware on the route) to BOTH
    // the path id and the X-User-Id header (ProfileClient injects the header).
    // A body user_id is NEVER trusted. Upstream errors pass through verbatim.

    public function updateBasic(Request $req, Response $res): Response
    {
        $uid = $this->callerId($req);
        $up  = $this->profiles->update($uid, (array) $req->getParsedBody(), $uid);
        $status = $up->getStatusCode();
        if ($status >= 200 && $status < 300) {
            // PATCH /me must NOT echo the caller's own email/password_hash.
            $data = (array) ($this->decode($up)['data'] ?? []);
            $data = array_intersect_key($data, array_flip([
                'id', 'username', 'display_name', 'avatar_url', 'cover_url', 'headline', 'location', 'about',
            ]));
            return Json::ok($res, $data, $status);
        }
        return Json::raw($res, $this->decode($up), $status);
    }

    public function addExperience(Request $req, Response $res): Response
    {
        $uid = $this->callerId($req);
        return $this->passthrough($res, $this->profiles->addExperience($uid, (array) $req->getParsedBody()));
    }
    public function updateExperience(Request $req, Response $res, array $args): Response
    {
        $uid = $this->callerId($req);
        return $this->passthrough($res, $this->profiles->updateExperience($uid, (int) $args['eid'], (array) $req->getParsedBody()));
    }
    public function deleteExperience(Request $req, Response $res, array $args): Response
    {
        $uid = $this->callerId($req);
        return $this->passthrough($res, $this->profiles->deleteExperience($uid, (int) $args['eid']));
    }

    public function addEducation(Request $req, Response $res): Response
    {
        $uid = $this->callerId($req);
        return $this->passthrough($res, $this->profiles->addEducation($uid, (array) $req->getParsedBody()));
    }
    public function updateEducation(Request $req, Response $res, array $args): Response
    {
        $uid = $this->callerId($req);
        return $this->passthrough($res, $this->profiles->updateEducation($uid, (int) $args['eid'], (array) $req->getParsedBody()));
    }
    public function deleteEducation(Request $req, Response $res, array $args): Response
    {
        $uid = $this->callerId($req);
        return $this->passthrough($res, $this->profiles->deleteEducation($uid, (int) $args['eid']));
    }

    public function addSkill(Request $req, Response $res): Response
    {
        $uid = $this->callerId($req);
        return $this->passthrough($res, $this->profiles->addSkill($uid, (array) $req->getParsedBody()));
    }
    public function deleteSkill(Request $req, Response $res, array $args): Response
    {
        $uid = $this->callerId($req);
        return $this->passthrough($res, $this->profiles->deleteSkill($uid, (int) $args['sid']));
    }

    /**
     * Resolve the authenticated caller. JwtAuthMiddleware would already 401 on a
     * missing token, but defend in depth: 0 => not authenticated.
     */
    private function callerId(Request $req): int
    {
        $uid = (int) ($req->getAttribute('user_id') ?? 0);
        if ($uid <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Vui lòng đăng nhập.');
        }
        return $uid;
    }

    private function passthrough(Response $res, ResponseInterface $up): Response
    {
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    private function decode(ResponseInterface $r): array
    {
        $decoded = json_decode((string) $r->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
