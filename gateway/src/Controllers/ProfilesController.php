<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Json;
use App\Services\ProfileClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

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

    private function decode(\Psr\Http\Message\ResponseInterface $r): array
    {
        $decoded = json_decode((string) $r->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
