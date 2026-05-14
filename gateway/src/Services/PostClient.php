<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

final class PostClient
{
    private Client $http;

    public function __construct()
    {
        $base = getenv('POST_SERVICE_URL') ?: 'http://post-service:80';
        $this->http = HttpClient::create($base);
    }

    public function health(): ResponseInterface          { return $this->http->request('GET', '/health'); }
    public function healthAsync(): PromiseInterface      { return $this->http->requestAsync('GET', '/health'); }

    public function get(int $id): ResponseInterface      { return $this->http->request('GET', '/posts/' . $id); }
    public function getAsync(int $id): PromiseInterface  { return $this->http->requestAsync('GET', '/posts/' . $id); }

    public function list(array $query): ResponseInterface
    {
        return $this->http->request('GET', '/posts', ['query' => $query]);
    }

    public function create(int $authorId, array $body): ResponseInterface
    {
        return $this->http->request('POST', '/posts', [
            'json'    => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-User-Id'    => (string) $authorId,
            ],
        ]);
    }

    public function update(int $id, int $authorId, array $body): ResponseInterface
    {
        return $this->http->request('PATCH', '/posts/' . $id, [
            'json'    => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-User-Id'    => (string) $authorId,
            ],
        ]);
    }

    public function delete(int $id, int $callerId): ResponseInterface
    {
        return $this->http->request('DELETE', '/posts/' . $id, [
            'headers' => ['X-User-Id' => (string) $callerId],
        ]);
    }
}
