<?php
declare(strict_types=1);

namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Optional-auth variant of JwtAuthMiddleware (D-04).
 *
 * Unlike JwtAuthMiddleware (which throws 401 on a missing/invalid token), this
 * middleware NEVER throws: it powers public + auth-aware routes such as
 * GET /api/profiles/{id}/full. A valid Bearer token sets the `user_id`
 * attribute (viewer-aware); an absent OR invalid/expired token is treated as
 * anonymous so a stale token can still view a public profile (RESEARCH A5).
 *
 * The controller reads `(int)($req->getAttribute('user_id') ?? 0)` → 0 means
 * anonymous (connection_status null), >0 means viewer-relative status.
 */
final class OptionalJwtMiddleware implements MiddlewareInterface
{
    public function __construct(private string $secret) {}

    public function process(Request $request, Handler $handler): Response
    {
        $auth = $request->getHeaderLine('Authorization');
        if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
            try {
                $payload = JWT::decode($m[1], new Key($this->secret, 'HS256'));
                $uid = isset($payload->sub) ? (int) $payload->sub : 0;
                if ($uid > 0) {
                    $request = $request->withAttribute('user_id', $uid);
                    if (isset($payload->username)) {
                        $request = $request->withAttribute('username', (string) $payload->username);
                    }
                }
            } catch (\Throwable) {
                // Invalid/expired token => treat as anonymous (never 401 here).
            }
        }

        return $handler->handle($request);
    }
}
