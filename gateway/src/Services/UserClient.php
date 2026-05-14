<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

final class UserClient
{
    private Client $http;

    public function __construct()
    {
        $base = getenv('USER_SERVICE_URL') ?: 'http://user-service:80';
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
