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
}
