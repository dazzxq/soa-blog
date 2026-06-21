<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Typed feed-service client (Plan 04-03). Mirrors ConnectionClient/ProfileClient:
 *   - GETs use 'query'; mutations inject the gateway-trusted X-User-Id header
 *     (the JWT user_id is mapped to X-User-Id by the controller — a body user_id
 *     is NEVER trusted). feed-service reads identity ONLY from X-User-Id.
 *   - http_errors=false (HttpClient) → callers inspect status manually.
 *
 * The feed-service GET /posts route is dual-mode: ?authors=&viewer=&limit= is
 * the timeline; ?ids= is the batch repost-original resolution (no N+1).
 */
final class FeedClient
{
    private Client $http;

    public function __construct()
    {
        $base = getenv('FEED_SERVICE_URL') ?: 'http://feed-service:80';
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

    // --- Reads ---------------------------------------------------------------

    /** Timeline: posts (+ counts + viewer-relative my_reaction) for the author universe. */
    public function timeline(array $authorIds, int $viewer, int $limit): ResponseInterface
    {
        return $this->http->request('GET', '/posts', ['query' => [
            'authors' => implode(',', $authorIds),
            'viewer'  => $viewer,
            'limit'   => $limit,
        ]]);
    }

    /** Batch resolution of repost originals (?ids=) — no N+1. */
    public function getPosts(array $ids): ResponseInterface
    {
        return $this->http->request('GET', '/posts', ['query' => ['ids' => implode(',', $ids)]]);
    }

    /** Async variant of getPosts for the STEP 3 Utils::settle([...]) originals idiom. */
    public function getPostsAsync(array $ids): PromiseInterface
    {
        return $this->http->requestAsync('GET', '/posts', ['query' => ['ids' => implode(',', $ids)]]);
    }

    /** Single post + counts (viewer-relative my_reaction; 0 = anonymous). */
    public function getPost(int $id, int $viewer = 0): ResponseInterface
    {
        return $this->http->request('GET', '/posts/' . $id, ['query' => ['viewer' => $viewer]]);
    }

    public function listComments(int $id): ResponseInterface
    {
        return $this->http->request('GET', '/posts/' . $id . '/comments');
    }

    // --- Mutations (caller -> X-User-Id; body user_id never trusted) ---------

    public function createPost(int $author, array $body): ResponseInterface
    {
        return $this->http->request('POST', '/posts', [
            'json'    => $body,
            'headers' => ['Content-Type' => 'application/json', 'X-User-Id' => (string) $author],
        ]);
    }

    public function updatePost(int $caller, int $id, array $body): ResponseInterface
    {
        return $this->http->request('PATCH', '/posts/' . $id, [
            'json'    => $body,
            'headers' => ['Content-Type' => 'application/json', 'X-User-Id' => (string) $caller],
        ]);
    }

    public function deletePost(int $caller, int $id): ResponseInterface
    {
        return $this->http->request('DELETE', '/posts/' . $id, [
            'headers' => ['X-User-Id' => (string) $caller],
        ]);
    }

    public function repost(int $caller, int $id): ResponseInterface
    {
        return $this->http->request('POST', '/posts/' . $id . '/repost', [
            'headers' => ['X-User-Id' => (string) $caller],
        ]);
    }

    public function react(int $caller, int $id, string $type): ResponseInterface
    {
        return $this->http->request('POST', '/posts/' . $id . '/reactions', [
            'json'    => ['type' => $type],
            'headers' => ['Content-Type' => 'application/json', 'X-User-Id' => (string) $caller],
        ]);
    }

    public function unreact(int $caller, int $id): ResponseInterface
    {
        return $this->http->request('DELETE', '/posts/' . $id . '/reactions', [
            'headers' => ['X-User-Id' => (string) $caller],
        ]);
    }

    public function addComment(int $caller, int $id, string $body): ResponseInterface
    {
        return $this->http->request('POST', '/posts/' . $id . '/comments', [
            'json'    => ['body' => $body],
            'headers' => ['Content-Type' => 'application/json', 'X-User-Id' => (string) $caller],
        ]);
    }

    public function updateComment(int $caller, int $id, string $body): ResponseInterface
    {
        return $this->http->request('PATCH', '/comments/' . $id, [
            'json'    => ['body' => $body],
            'headers' => ['Content-Type' => 'application/json', 'X-User-Id' => (string) $caller],
        ]);
    }

    public function deleteComment(int $caller, int $id): ResponseInterface
    {
        return $this->http->request('DELETE', '/comments/' . $id, [
            'headers' => ['X-User-Id' => (string) $caller],
        ]);
    }
}
