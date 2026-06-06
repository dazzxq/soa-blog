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
}
