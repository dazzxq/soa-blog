<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

final class CommentClient
{
    private Client $http;

    public function __construct()
    {
        $base = getenv('COMMENT_SERVICE_URL') ?: 'http://comment-service:80';
        $this->http = HttpClient::create($base);
    }

    public function health(): ResponseInterface      { return $this->http->request('GET', '/health'); }
    public function healthAsync(): PromiseInterface  { return $this->http->requestAsync('GET', '/health'); }

    public function listByPost(int $postId, array $extra = []): ResponseInterface
    {
        return $this->http->request('GET', '/comments', [
            'query' => array_merge(['post_id' => $postId], $extra),
        ]);
    }
    public function listByPostAsync(int $postId, array $extra = []): PromiseInterface
    {
        return $this->http->requestAsync('GET', '/comments', [
            'query' => array_merge(['post_id' => $postId], $extra),
        ]);
    }

    public function countByPost(int $postId): ResponseInterface
    {
        return $this->http->request('GET', '/comments/count', [
            'query' => ['post_id' => $postId],
        ]);
    }

    public function create(int $authorId, int $postId, string $body): ResponseInterface
    {
        return $this->http->request('POST', '/comments', [
            'json'    => ['post_id' => $postId, 'body' => $body],
            'headers' => [
                'Content-Type' => 'application/json',
                'X-User-Id'    => (string) $authorId,
            ],
        ]);
    }

    public function delete(int $id, int $callerId): ResponseInterface
    {
        return $this->http->request('DELETE', '/comments/' . $id, [
            'headers' => ['X-User-Id' => (string) $callerId],
        ]);
    }
}
