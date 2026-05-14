<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\DomainError;
use App\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UserController
{
    /**
     * GET /health — liveness + DB ping.
     */
    public function health(Request $req, Response $res): Response
    {
        $dbOk = Db::ping();
        return Json::raw($res, [
            'status' => $dbOk ? 'ok' : 'degraded',
            'db'     => $dbOk ? 'ok' : 'down',
            'ts'     => gmdate('c'),
        ], $dbOk ? 200 : 503);
    }

    /**
     * POST /users — register a new user.
     */
    public function create(Request $req, Response $res): Response
    {
        $b = (array) ($req->getParsedBody() ?? []);

        $username    = trim((string) ($b['username']     ?? ''));
        $email       = trim((string) ($b['email']        ?? ''));
        $password    = (string)       ($b['password']    ?? '');
        $displayName = trim((string) ($b['display_name'] ?? ''));

        if ($username === '' || $email === '' || $password === '' || $displayName === '') {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Vui lòng nhập đầy đủ username, email, password, display_name.');
        }
        if (strlen($username) < 3 || strlen($username) > 64 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Tên đăng nhập từ 3-64 ký tự, chỉ chấp nhận chữ, số, _, ., -');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Email không hợp lệ.');
        }
        if (strlen($password) < 6) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Mật khẩu phải có ít nhất 6 ký tự.');
        }
        if (mb_strlen($displayName) > 128) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Tên hiển thị quá dài (tối đa 128 ký tự).');
        }

        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = :u OR email = :e LIMIT 1');
        $stmt->execute([':u' => $username, ':e' => $email]);
        if ($stmt->fetchColumn()) {
            throw new DomainError(409, 'USER_EXISTS', 'Username hoặc email đã được sử dụng.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, display_name)
             VALUES (:u, :e, :p, :d)'
        );
        $stmt->execute([
            ':u' => $username,
            ':e' => $email,
            ':p' => password_hash($password, PASSWORD_BCRYPT),
            ':d' => $displayName,
        ]);
        $id = (int) $pdo->lastInsertId();

        return Json::ok($res, $this->find($id), 201);
    }

    /**
     * POST /users/verify-credentials — internal endpoint used by gateway on login.
     * Body: {login: username|email, password}.
     */
    public function verifyCredentials(Request $req, Response $res): Response
    {
        $b = (array) ($req->getParsedBody() ?? []);
        $login    = trim((string) ($b['login']    ?? ''));
        $password = (string)       ($b['password'] ?? '');

        if ($login === '' || $password === '') {
            throw new DomainError(400, 'VALIDATION_FAILED', 'login và password là bắt buộc.');
        }

        $stmt = Db::pdo()->prepare(
            'SELECT id, username, email, password_hash, display_name, avatar_url, created_at
             FROM users WHERE username = :l OR email = :l LIMIT 1'
        );
        $stmt->execute([':l' => $login]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            throw new DomainError(401, 'INVALID_CREDENTIALS', 'Sai thông tin đăng nhập.');
        }

        unset($row['password_hash']);
        return Json::ok($res, $row);
    }

    /**
     * GET /users/{id} — single user lookup.
     */
    public function show(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $user = $this->find($id);
        if ($user === null) {
            throw new DomainError(404, 'USER_NOT_FOUND', 'Không tìm thấy người dùng.');
        }
        return Json::ok($res, $user);
    }

    /**
     * GET /users?ids=1,2,3 — batch fetch (gateway aggregation).
     * GET /users — full list (small projects only).
     */
    public function index(Request $req, Response $res): Response
    {
        $params = $req->getQueryParams();
        $idsRaw = isset($params['ids']) ? (string) $params['ids'] : '';

        if ($idsRaw !== '') {
            $ids = array_values(array_unique(array_filter(array_map(
                static fn($s) => (int) trim($s),
                explode(',', $idsRaw)
            ), static fn($i) => $i > 0)));

            if ($ids === []) {
                return Json::list($res, [], ['total' => 0]);
            }
            if (count($ids) > 100) {
                throw new DomainError(400, 'TOO_MANY_IDS', 'Tối đa 100 id mỗi lượt.');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = Db::pdo()->prepare(
                "SELECT id, username, email, display_name, avatar_url, created_at
                 FROM users WHERE id IN ($placeholders)"
            );
            $stmt->execute($ids);
            $rows = $stmt->fetchAll();
            return Json::list($res, $rows, ['total' => count($rows)]);
        }

        $stmt = Db::pdo()->query(
            'SELECT id, username, email, display_name, avatar_url, created_at
             FROM users ORDER BY id DESC LIMIT 100'
        );
        $rows = $stmt->fetchAll();
        return Json::list($res, $rows, ['total' => count($rows)]);
    }

    /**
     * PATCH /users/{id} — update profile. Caller authenticated via X-User-Id.
     */
    public function update(Request $req, Response $res, array $args): Response
    {
        $id        = (int) $args['id'];
        $callerId  = (int) ($req->getHeaderLine('X-User-Id') ?: 0);

        if ($callerId === 0 || $callerId !== $id) {
            throw new DomainError(403, 'FORBIDDEN', 'Bạn chỉ có thể chỉnh sửa hồ sơ của chính mình.');
        }
        $existing = $this->find($id);
        if ($existing === null) {
            throw new DomainError(404, 'USER_NOT_FOUND', 'Không tìm thấy người dùng.');
        }

        $b = (array) ($req->getParsedBody() ?? []);
        $sets = [];
        $params = [':id' => $id];

        if (array_key_exists('display_name', $b)) {
            $name = trim((string) $b['display_name']);
            if ($name === '' || mb_strlen($name) > 128) {
                throw new DomainError(400, 'VALIDATION_FAILED', 'Tên hiển thị phải từ 1-128 ký tự.');
            }
            $sets[] = 'display_name = :dn';
            $params[':dn'] = $name;
        }
        if (array_key_exists('avatar_url', $b)) {
            $url = $b['avatar_url'] === null ? null : trim((string) $b['avatar_url']);
            if ($url !== null && (strlen($url) > 512 || !filter_var($url, FILTER_VALIDATE_URL))) {
                throw new DomainError(400, 'VALIDATION_FAILED', 'Avatar URL không hợp lệ.');
            }
            $sets[] = 'avatar_url = :av';
            $params[':av'] = $url;
        }

        if ($sets === []) {
            return Json::ok($res, $existing);
        }

        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
        Db::pdo()->prepare($sql)->execute($params);

        return Json::ok($res, $this->find($id));
    }

    private function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, username, email, display_name, avatar_url, created_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
