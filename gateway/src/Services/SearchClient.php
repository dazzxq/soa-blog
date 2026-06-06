<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

final class SearchClient
{
    private Client $http;

    public function __construct()
    {
        $base = getenv('SEARCH_SERVICE_URL') ?: 'http://search-service:80';
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
     * GET /search?q=&limit= (SEARCH-01/02). search-service runs the LIKE query
     * over the denormalized search_index. HttpClient is http_errors=false, so a
     * non-2xx returns a ResponseInterface; a network failure throws GuzzleException
     * (the gateway SearchController inspects status / handles degradation).
     */
    public function search(string $q, int $limit = 20): ResponseInterface
    {
        return $this->http->request('GET', '/search', [
            'query' => ['q' => $q, 'limit' => $limit],
        ]);
    }

    /**
     * POST /index (reindex sink) — idempotent upsert of one denormalized row,
     * keyed on user_id (ON DUPLICATE KEY UPDATE in search-service). The gateway's
     * reindex() calls this per user pulled from profile-service.
     */
    public function upsert(array $row): ResponseInterface
    {
        return $this->http->request('POST', '/index', [
            'json'    => $row,
            'headers' => ['Content-Type' => 'application/json'],
        ]);
    }
}
