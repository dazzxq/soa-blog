<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

final class ConnectionClient
{
    private Client $http;

    public function __construct()
    {
        $base = getenv('CONNECTION_SERVICE_URL') ?: 'http://connection-service:80';
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

    /**
     * Viewer-relative connection status between two users (D-03).
     *
     * Phase 2: connection-service is still a stub exposing only /health, so this
     * path 404s → the gateway's settle fan-out degrades to meta.degraded. Phase 3
     * implements GET /connections/status with ZERO gateway rework (D-03).
     */
    public function statusForAsync(int $viewerId, int $targetId): PromiseInterface
    {
        return $this->http->requestAsync('GET', '/connections/status', [
            'query' => ['viewer' => $viewerId, 'target' => $targetId],
        ]);
    }

    // --- Phase 3: synchronous status + graph mutations/lists (X-User-Id scoped) ---
    // statusForAsync (above) is the D-05 contract used by AggregateController and
    // MUST NOT change. The sync variant below feeds the sendRequest invariant.

    /**
     * Synchronous viewer-relative status — used by the sendRequest invariant and
     * GET /api/connections/status/{userId}. Shares the same computation route as
     * statusForAsync (the single status computation, D-05).
     */
    public function statusFor(int $viewer, int $target): ResponseInterface
    {
        return $this->http->request('GET', '/connections/status', [
            'query' => ['viewer' => $viewer, 'target' => $target],
        ]);
    }

    public function createRequest(int $requester, int $target): ResponseInterface
    {
        return $this->http->request('POST', '/connections', [
            'json'    => ['addressee_id' => $target],
            'headers' => ['Content-Type' => 'application/json', 'X-User-Id' => (string) $requester],
        ]);
    }

    public function accept(int $caller, int $id): ResponseInterface
    {
        return $this->http->request('POST', '/connections/' . $id . '/accept', [
            'headers' => ['X-User-Id' => (string) $caller],
        ]);
    }

    /**
     * DELETE a pending request — reject (addressee) and cancel (requester) both
     * map here; connection-service scopes the delete to either pending party.
     */
    public function deleteRequest(int $caller, int $id): ResponseInterface
    {
        return $this->http->request('DELETE', '/connections/' . $id, [
            'headers' => ['X-User-Id' => (string) $caller],
        ]);
    }

    public function removeEdge(int $caller, int $otherUserId): ResponseInterface
    {
        return $this->http->request('DELETE', '/connections/by-user/' . $otherUserId, [
            'headers' => ['X-User-Id' => (string) $caller],
        ]);
    }

    public function listAccepted(int $user): ResponseInterface
    {
        return $this->http->request('GET', '/connections', [
            'query' => ['user' => $user],
        ]);
    }

    public function listPending(int $user, string $direction): ResponseInterface
    {
        return $this->http->request('GET', '/connections/pending', [
            'query' => ['user' => $user, 'direction' => $direction],
        ]);
    }

    /**
     * Suggestions: the gateway supplies the candidate universe (composed from
     * profile-service via ProfileClient::allUsers); connection-service returns
     * the un-edged subset.
     */
    public function suggestions(int $user, array $candidateIds, int $limit): ResponseInterface
    {
        return $this->http->request('GET', '/connections/suggestions', [
            'query' => [
                'user'       => $user,
                'candidates' => implode(',', $candidateIds),
                'limit'      => $limit,
            ],
        ]);
    }
}
