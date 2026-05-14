<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

/**
 * Singleton Guzzle factory for short connections to internal services.
 * Internal calls only — never used for external HTTP.
 */
final class HttpClient
{
    private static ?Client $client = null;

    public static function create(string $baseUri): Client
    {
        return new Client([
            'base_uri'        => $baseUri,
            'http_errors'     => false,   // we inspect status codes manually
            'connect_timeout' => 2.0,
            'timeout'         => 5.0,
            'headers'         => [
                'Accept'         => 'application/json',
                'X-Forwarded-By' => 'soa-blog-gateway',
            ],
        ]);
    }
}
