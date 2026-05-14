<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Single-line per-request log to stderr (captured by docker).
 */
final class LoggingMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $start = microtime(true);
        $response = $handler->handle($request);
        $ms = (int) round((microtime(true) - $start) * 1000);

        $line = sprintf(
            "[gateway] %s %s %s -> %d %dms rid=%s",
            $request->getServerParams()['REMOTE_ADDR'] ?? '-',
            $request->getMethod(),
            (string) $request->getUri()->getPath(),
            $response->getStatusCode(),
            $ms,
            (string) ($request->getAttribute('request_id') ?? '-')
        );

        // stderr — supervisord forwards to docker logs.
        @file_put_contents('php://stderr', $line . PHP_EOL);

        return $response;
    }
}
