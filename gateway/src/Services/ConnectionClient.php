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
}
