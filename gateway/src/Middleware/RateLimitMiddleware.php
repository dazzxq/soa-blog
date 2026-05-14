<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Per-IP rate limit using a one-minute fixed window.
 * State is persisted to /tmp via flock-guarded JSON file — survives
 * worker restarts and is shared across php-fpm workers in the container.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private const STORE = '/tmp/rate-limit.json';

    public function __construct(
        private int $perMinute = 120,
        private ResponseFactory $responseFactory = new ResponseFactory(),
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if ($this->perMinute <= 0) {
            return $handler->handle($request);
        }

        $ip = $this->clientIp($request);
        $bucket = (int) (time() / 60); // 1-minute fixed window

        [$count, $resetIn] = $this->touch($ip, $bucket);

        if ($count > $this->perMinute) {
            $r = $this->responseFactory->createResponse(429);
            $r->getBody()->write(json_encode([
                'error' => [
                    'code'    => 'RATE_LIMITED',
                    'message' => 'Quá nhiều request. Vui lòng thử lại sau ít phút.',
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $r
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Retry-After', (string) $resetIn)
                ->withHeader('X-RateLimit-Limit',     (string) $this->perMinute)
                ->withHeader('X-RateLimit-Remaining', '0');
        }

        $response = $handler->handle($request);
        return $response
            ->withHeader('X-RateLimit-Limit',     (string) $this->perMinute)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $this->perMinute - $count));
    }

    /**
     * @return array{0:int,1:int} [current count for IP this bucket, seconds until reset]
     */
    private function touch(string $ip, int $bucket): array
    {
        $fp = @fopen(self::STORE, 'c+');
        if ($fp === false) {
            return [1, 60];
        }
        try {
            flock($fp, LOCK_EX);
            rewind($fp);
            $raw = stream_get_contents($fp) ?: '';
            $state = json_decode($raw, true);
            if (!is_array($state) || ($state['bucket'] ?? null) !== $bucket) {
                $state = ['bucket' => $bucket, 'counts' => []];
            }
            $count = (int) ($state['counts'][$ip] ?? 0) + 1;
            $state['counts'][$ip] = $count;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($state));
            fflush($fp);

            $resetIn = max(1, 60 - (time() % 60));
            return [$count, $resetIn];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Resolve client IP from trusted headers ONLY. We never trust
     * `X-Forwarded-For` directly because `$proxy_add_x_forwarded_for` in nginx
     * preserves any client-supplied value before appending the real address.
     *
     * Trust order:
     *   1. CF-Connecting-IP — set by Cloudflare edge; nginx host whitelists CF IPs.
     *   2. X-Real-IP        — set by the trusted nginx host proxy.
     *   3. REMOTE_ADDR      — direct TCP peer (development / local docker).
     */
    private function clientIp(Request $request): string
    {
        $cf = $request->getHeaderLine('CF-Connecting-IP');
        if ($cf !== '') return trim($cf);

        $xri = $request->getHeaderLine('X-Real-IP');
        if ($xri !== '') return trim($xri);

        return $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
    }
}
