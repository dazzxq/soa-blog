<?php
declare(strict_types=1);

namespace App\Middleware;

use App\DomainError;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

final class JwtAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private string $secret) {}

    public function process(Request $request, Handler $handler): Response
    {
        $auth = $request->getHeaderLine('Authorization');
        if (!preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu token. Vui lòng đăng nhập.');
        }

        try {
            $payload = JWT::decode($m[1], new Key($this->secret, 'HS256'));
        } catch (\Throwable) {
            throw new DomainError(401, 'INVALID_TOKEN', 'Token không hợp lệ hoặc đã hết hạn.');
        }

        $userId = isset($payload->sub) ? (int) $payload->sub : 0;
        if ($userId <= 0) {
            throw new DomainError(401, 'INVALID_TOKEN', 'Token không hợp lệ.');
        }

        $request = $request->withAttribute('user_id', $userId);
        if (isset($payload->username)) {
            $request = $request->withAttribute('username', (string) $payload->username);
        }

        return $handler->handle($request);
    }
}
