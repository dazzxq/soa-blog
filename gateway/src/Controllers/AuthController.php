<?php
declare(strict_types=1);

namespace App\Controllers;

use App\DomainError;
use App\Json;
use App\Services\UserClient;
use Firebase\JWT\JWT;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function __construct(
        private UserClient $users,
        private string $secret,
        private int $ttlSeconds = 86400,
    ) {}

    public function register(Request $req, Response $res): Response
    {
        $body = (array) ($req->getParsedBody() ?? []);
        $upstream = $this->users->create($body);
        $payload = $this->decode($upstream);

        if ($upstream->getStatusCode() !== 201) {
            return Json::raw($res, $payload, $upstream->getStatusCode());
        }

        $user  = (array) $payload['data'];
        $token = $this->signToken($user);

        return Json::ok($res, ['user' => $user, 'token' => $token], 201);
    }

    public function login(Request $req, Response $res): Response
    {
        $body  = (array) ($req->getParsedBody() ?? []);
        $login = trim((string) ($body['login']    ?? ''));
        $pass  = (string)       ($body['password'] ?? '');

        if ($login === '' || $pass === '') {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Vui lòng nhập đầy đủ login và password.');
        }

        $upstream = $this->users->verifyCredentials($login, $pass);
        $payload  = $this->decode($upstream);

        if ($upstream->getStatusCode() !== 200) {
            return Json::raw($res, $payload, $upstream->getStatusCode());
        }

        $user  = (array) $payload['data'];
        $token = $this->signToken($user);

        return Json::ok($res, ['user' => $user, 'token' => $token]);
    }

    public function me(Request $req, Response $res): Response
    {
        $userId = (int) ($req->getAttribute('user_id') ?? 0);
        if ($userId === 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng.');
        }
        $upstream = $this->users->get($userId);
        return Json::raw($res, $this->decode($upstream), $upstream->getStatusCode());
    }

    private function signToken(array $user): string
    {
        $now = time();
        return JWT::encode([
            'iss'      => 'soa-blog-gateway',
            'sub'      => (int) $user['id'],
            'username' => (string) ($user['username'] ?? ''),
            'iat'      => $now,
            'exp'      => $now + $this->ttlSeconds,
        ], $this->secret, 'HS256');
    }

    private function decode(\Psr\Http\Message\ResponseInterface $r): array
    {
        $body = (string) $r->getBody();
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : ['error' => ['code' => 'UPSTREAM_ERROR', 'message' => 'Phản hồi dịch vụ không hợp lệ.']];
    }
}
