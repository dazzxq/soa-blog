<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

final class ProfileClient
{
    private Client $http;

    public function __construct()
    {
        $base = getenv('PROFILE_SERVICE_URL') ?: 'http://profile-service:80';
        $this->http = HttpClient::create($base);
    }

    public function health(): ResponseInterface
    {
        return $this->http->request('GET', '/health');
    }
    public function healthAsync(): PromiseInterface
    {
        return $this->http->requestAsync('GET', '/health');
    }

    public function get(int $id): ResponseInterface
    {
        return $this->http->request('GET', '/users/' . $id);
    }
    public function getAsync(int $id): PromiseInterface
    {
        return $this->http->requestAsync('GET', '/users/' . $id);
    }

    /**
     * Full public-safe profile (basic + experience + education + skills, no email).
     * Used by the flagship /api/profiles/{id}/full composition.
     */
    public function getFull(int $id): ResponseInterface
    {
        return $this->http->request('GET', '/users/' . $id . '/full');
    }
    public function getFullAsync(int $id): PromiseInterface
    {
        return $this->http->requestAsync('GET', '/users/' . $id . '/full');
    }

    // --- Owner-only CRUD (X-User-Id scoped; never trusts a body user_id) ---

    public function addExperience(int $userId, array $body): ResponseInterface
    {
        return $this->http->request('POST', '/users/' . $userId . '/experience', [
            'json'    => $body,
            'headers' => ['Content-Type' => 'application/json', 'X-User-Id' => (string) $userId],
        ]);
    }
    public function updateExperience(int $userId, int $eid, array $body): ResponseInterface
    {
        return $this->http->request('PATCH', '/users/' . $userId . '/experience/' . $eid, [
            'json'    => $body,
            'headers' => ['Content-Type' => 'application/json', 'X-User-Id' => (string) $userId],
        ]);
    }
    public function deleteExperience(int $userId, int $eid): ResponseInterface
    {
        return $this->http->request('DELETE', '/users/' . $userId . '/experience/' . $eid, [
            'headers' => ['X-User-Id' => (string) $userId],
        ]);
    }

    public function addEducation(int $userId, array $body): ResponseInterface
    {
        return $this->http->request('POST', '/users/' . $userId . '/education', [
            'json'    => $body,
            'headers' => ['Content-Type' => 'application/json', 'X-User-Id' => (string) $userId],
        ]);
    }
    public function updateEducation(int $userId, int $eid, array $body): ResponseInterface
    {
        return $this->http->request('PATCH', '/users/' . $userId . '/education/' . $eid, [
            'json'    => $body,
            'headers' => ['Content-Type' => 'application/json', 'X-User-Id' => (string) $userId],
        ]);
    }
    public function deleteEducation(int $userId, int $eid): ResponseInterface
    {
        return $this->http->request('DELETE', '/users/' . $userId . '/education/' . $eid, [
            'headers' => ['X-User-Id' => (string) $userId],
        ]);
    }

    public function addSkill(int $userId, array $body): ResponseInterface
    {
        return $this->http->request('POST', '/users/' . $userId . '/skills', [
            'json'    => $body,
            'headers' => ['Content-Type' => 'application/json', 'X-User-Id' => (string) $userId],
        ]);
    }
    public function deleteSkill(int $userId, int $sid): ResponseInterface
    {
        return $this->http->request('DELETE', '/users/' . $userId . '/skills/' . $sid, [
            'headers' => ['X-User-Id' => (string) $userId],
        ]);
    }

    /**
     * Batch fetch — gateway uses this to enrich aggregated responses.
     */
    public function batch(array $ids): ResponseInterface
    {
        $ids = array_values(array_unique(array_filter($ids, static fn($i) => $i > 0)));
        if ($ids === []) {
            // Return an empty 200 by talking to /users?ids= which yields []
            return $this->http->request('GET', '/users', ['query' => ['ids' => '']]);
        }
        return $this->http->request('GET', '/users', [
            'query' => ['ids' => implode(',', $ids)],
        ]);
    }
    public function batchAsync(array $ids): PromiseInterface
    {
        return $this->http->requestAsync('GET', '/users', [
            'query' => ['ids' => implode(',', $ids)],
        ]);
    }

    /**
     * Candidate universe for the gateway-composed suggestions (Pitfall 6 / Open Q1):
     * connection-service cannot enumerate users, so the gateway pulls the public-safe
     * user list from profile-service GET /users and supplies it as the candidate set.
     *
     * Returns the decoded `data` list (NOT a ResponseInterface). profile-service
     * GET /users returns only public fields (id, username, display_name, avatar_url —
     * NEVER email); this path adds no email. Degrades to [] on any non-200, never throws.
     */
    public function allUsers(int $limit = 100): array
    {
        // Never throws: a network failure / timeout to profile-service must degrade the
        // suggestions universe to an empty list, NOT bubble up a 500 (codex-impl-review fix).
        try {
            $res = $this->http->request('GET', '/users', ['query' => ['limit' => $limit]]);
            if ($res->getStatusCode() !== 200) {
                return [];
            }
            $body = json_decode((string) $res->getBody(), true);
            return is_array($body['data'] ?? null) ? $body['data'] : [];
        } catch (GuzzleException $e) {
            return [];
        }
    }

    public function create(array $body): ResponseInterface
    {
        return $this->http->request('POST', '/users', [
            'json' => $body,
            'headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    public function verifyCredentials(string $login, string $password): ResponseInterface
    {
        return $this->http->request('POST', '/users/verify-credentials', [
            'json' => ['login' => $login, 'password' => $password],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    public function update(int $id, array $body, int $callerId): ResponseInterface
    {
        return $this->http->request('PATCH', '/users/' . $id, [
            'json'    => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-User-Id'    => (string) $callerId,
            ],
        ]);
    }
}
