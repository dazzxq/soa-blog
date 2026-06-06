<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

final class NotificationClient
{
    private Client $http;

    public function __construct()
    {
        $base = getenv('NOTIFICATION_SERVICE_URL') ?: 'http://notification-service:80';
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

    // --- Phase 5: gateway-coordinated notifications (D-05) --------------------
    // create() is the best-effort sink: the gateway computes recipient/actor and
    // is the only blog-net caller (notification-service trusts the body, D-07).
    // Reads/marks scope to the recipient via the gateway-trusted X-User-Id header.

    public function create(int $recipient, int $actor, string $type, ?int $refId): ResponseInterface
    {
        return $this->http->request('POST', '/notifications', [
            'json'    => ['user_id' => $recipient, 'actor_id' => $actor, 'type' => $type, 'ref_id' => $refId],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    public function list(int $user): ResponseInterface
    {
        return $this->http->request('GET', '/notifications', ['headers' => ['X-User-Id' => (string) $user]]);
    }

    public function markRead(int $user, int $id): ResponseInterface
    {
        return $this->http->request('POST', '/notifications/' . $id . '/read', ['headers' => ['X-User-Id' => (string) $user]]);
    }

    public function markAllRead(int $user): ResponseInterface
    {
        return $this->http->request('POST', '/notifications/read-all', ['headers' => ['X-User-Id' => (string) $user]]);
    }
}
