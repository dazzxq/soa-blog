<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\DomainError;
use App\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CommentController
{
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
     * GET /comments?post_id=&page=&per_page=
     */
    public function index(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        $postId  = isset($q['post_id']) ? (int) $q['post_id'] : 0;
        $page    = max(1,   (int) ($q['page']     ?? 1));
        $perPage = min(100, max(1, (int) ($q['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        if ($postId <= 0) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Tham số post_id là bắt buộc.');
        }

        $pdo = Db::pdo();

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE post_id = :p');
        $countStmt->execute([':p' => $postId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT id, post_id, author_id, body, created_at
             FROM comments WHERE post_id = :p
             ORDER BY created_at ASC, id ASC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':p',   $postId,  PDO::PARAM_INT);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
        $stmt->execute();

        return Json::list($res, $stmt->fetchAll(), [
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
        ]);
    }

    /**
     * GET /comments/count?post_id=
     */
    public function count(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        $postId = isset($q['post_id']) ? (int) $q['post_id'] : 0;
        if ($postId <= 0) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Tham số post_id là bắt buộc.');
        }
        $stmt = Db::pdo()->prepare('SELECT COUNT(*) FROM comments WHERE post_id = :p');
        $stmt->execute([':p' => $postId]);
        return Json::ok($res, ['count' => (int) $stmt->fetchColumn()]);
    }

    /**
     * POST /comments — gateway already verified post_id exists.
     */
    public function create(Request $req, Response $res): Response
    {
        $authorId = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($authorId === 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
        }

        $b      = (array) ($req->getParsedBody() ?? []);
        $postId = (int) ($b['post_id'] ?? 0);
        $body   = trim((string) ($b['body'] ?? ''));

        if ($postId <= 0) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'post_id không hợp lệ.');
        }
        if ($body === '' || mb_strlen($body) > 5000) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Nội dung bình luận phải từ 1-5000 ký tự.');
        }

        $stmt = Db::pdo()->prepare(
            'INSERT INTO comments (post_id, author_id, body) VALUES (:p, :a, :b)'
        );
        $stmt->execute([':p' => $postId, ':a' => $authorId, ':b' => $body]);

        return Json::ok($res, $this->find((int) Db::pdo()->lastInsertId()), 201);
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        $id       = (int) $args['id'];
        $callerId = (int) ($req->getHeaderLine('X-User-Id') ?: 0);

        $comment = $this->find($id);
        if ($comment === null) {
            throw new DomainError(404, 'COMMENT_NOT_FOUND', 'Không tìm thấy bình luận.');
        }
        if ($callerId === 0 || $callerId !== (int) $comment['author_id']) {
            throw new DomainError(403, 'FORBIDDEN', 'Bạn không có quyền xoá bình luận này.');
        }

        Db::pdo()->prepare('DELETE FROM comments WHERE id = :id')->execute([':id' => $id]);
        return $res->withStatus(204);
    }

    private function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, post_id, author_id, body, created_at
             FROM comments WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
