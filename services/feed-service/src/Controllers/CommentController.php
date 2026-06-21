<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\DomainError;
use App\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * feed-service Comments domain — cloned from the brownfield comment-service and
 * re-homed into proconnect_feed. Because feed-service now owns BOTH the posts
 * and comments tables, the "comment requires an existing post" invariant is a
 * LOCAL `SELECT 1 FROM posts` check (T-04-11) — no cross-service hop.
 *
 * Doctrine: author = X-User-Id header (never the body, D-07/T-04-07); owner-only
 * delete → 403; uniform 404 for a missing post/comment.
 *
 * The post id arrives via the route path (`/posts/{id}/comments`), NOT a body
 * or query param, so it can never be spoofed independently of the URL.
 */
final class CommentController
{
    /** GET /posts/{id}/comments — list a post's comments (oldest first). */
    public function index(Request $req, Response $res, array $args): Response
    {
        $postId = (int) $args['id'];
        if ($postId <= 0) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'post_id không hợp lệ.');
        }

        $q       = $req->getQueryParams();
        $page    = max(1, (int) ($q['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($q['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        $pdo = Db::pdo();

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE post_id = :p');
        $countStmt->execute([':p' => $postId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT id, post_id, author_id, body, created_at, updated_at
               FROM comments WHERE post_id = :p
              ORDER BY created_at ASC, id ASC
              LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':p',   $postId,  PDO::PARAM_INT);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map([self::class, 'shape'], $stmt->fetchAll());
        return Json::list($res, $rows, [
            'post_id'  => $postId,
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
        ]);
    }

    /** POST /posts/{id}/comments — add a comment (local post-existence invariant). */
    public function create(Request $req, Response $res, array $args): Response
    {
        $author = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($author <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
        }

        $postId = (int) $args['id'];
        // LOCAL invariant — feed owns both tables, no cross-service call (T-04-11).
        if (!$this->postExists($postId)) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
        }

        $b    = (array) ($req->getParsedBody() ?? []);
        $body = trim((string) ($b['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 5000) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Nội dung bình luận phải từ 1-5000 ký tự.');
        }

        $stmt = Db::pdo()->prepare(
            'INSERT INTO comments (post_id, author_id, body) VALUES (:p, :a, :b)'
        );
        $stmt->execute([':p' => $postId, ':a' => $author, ':b' => $body]);

        $id = (int) Db::pdo()->lastInsertId();
        return Json::ok($res, $this->find($id), 201);
    }

    /** DELETE /comments/{id} — owner-only (T-04-06). */
    public function delete(Request $req, Response $res, array $args): Response
    {
        $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        $id     = (int) $args['id'];

        $comment = $this->find($id);
        if ($comment === null) {
            throw new DomainError(404, 'COMMENT_NOT_FOUND', 'Không tìm thấy bình luận.');
        }
        if ($caller === 0 || $caller !== (int) $comment['author_id']) {
            throw new DomainError(403, 'FORBIDDEN', 'Bạn không có quyền xoá bình luận này.');
        }

        Db::pdo()->prepare('DELETE FROM comments WHERE id = :id')->execute([':id' => $id]);
        return $res->withStatus(204);
    }

    /** PATCH /comments/{id} — owner-only edit (plain text; hiển thị bằng x-text). */
    public function update(Request $req, Response $res, array $args): Response
    {
        $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        $id     = (int) $args['id'];

        $comment = $this->find($id);
        if ($comment === null) {
            throw new DomainError(404, 'COMMENT_NOT_FOUND', 'Không tìm thấy bình luận.');
        }
        if ($caller === 0 || $caller !== (int) $comment['author_id']) {
            throw new DomainError(403, 'FORBIDDEN', 'Bạn không có quyền sửa bình luận này.');
        }

        $b    = (array) ($req->getParsedBody() ?? []);
        $body = trim((string) ($b['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 5000) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Nội dung bình luận phải từ 1-5000 ký tự.');
        }

        Db::pdo()->prepare('UPDATE comments SET body = :b, updated_at = NOW() WHERE id = :id')
            ->execute([':b' => $body, ':id' => $id]);

        return Json::ok($res, $this->find($id));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, post_id, author_id, body, created_at, updated_at
               FROM comments WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::shape($row);
    }

    private function postExists(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $stmt = Db::pdo()->prepare('SELECT 1 FROM posts WHERE id = :p LIMIT 1');
        $stmt->execute([':p' => $id]);
        return $stmt->fetchColumn() !== false;
    }

    private static function shape(array $row): array
    {
        $row['id']        = (int) $row['id'];
        $row['post_id']   = (int) $row['post_id'];
        $row['author_id'] = (int) $row['author_id'];
        return $row;
    }
}
