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

    /**
     * Request-scoped correlation id (D-12). Set by RequestIdMiddleware at the
     * very start of EVERY request via setRequestId(); read here when a service
     * client is constructed so the id is forwarded downstream as X-Request-Id.
     *
     * INVARIANT (the real safety guard — NOT "one request per process"):
     * php-fpm workers are reused across requests, so this static is NOT reset
     * by process isolation. It is safe because every service client is
     * constructed LAZILY per-request inside route handlers (PHP-DI factories
     * resolve on first use), which is strictly AFTER the middleware stack has
     * run — and therefore after RequestIdMiddleware has called setRequestId()
     * for the CURRENT request, overwriting any value from a prior request.
     * Clients MUST NOT be constructed before that middleware runs (they are
     * not — DI resolves them lazily inside route handlers). If any future code
     * constructs a client before RequestIdMiddleware runs, this guarantee
     * breaks; this comment is the guard.
     */
    private static ?string $requestId = null;

    public static function setRequestId(?string $rid): void
    {
        self::$requestId = $rid;
    }

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
                'X-Request-Id'   => self::$requestId ?? '',
            ],
        ]);
    }
}
