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
 * LOCAL `SELECT id FROM posts` check (T-04-11) — no cross-service hop.
 *
 * IDENTIFIER MODEL: route `/posts/{id}/comments` mang post_id SNOWFLAKE. Bảng
 * comments lưu post_id NỘI BỘ (= posts.id); ta join posts để (a) lọc theo
 * snowflake và (b) trả `post_id` ra ngoài dưới dạng snowflake STRING (không lộ id
 * nội bộ, không mất chính xác ở JS). Comment id giữ auto-increment nhỏ (JS-safe).
 *
 * Doctrine: author = X-User-Id header (never the body, D-07/T-04-07); owner-only
 * delete → 403; uniform 404 for a missing post/comment.
 */
final class CommentController
{
    /** GET /posts/{id}/comments — list a post's comments (oldest first). {id}=snowflake. */
    public function index(Request $req, Response $res, array $args): Response
    {
        $sid = (string) $args['id'];
        // 404 nhất quán cho bài không tồn tại (thay vì 200 + danh sách rỗng).
        if ($this->resolvePostId($sid) === null) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
        }

        $q       = $req->getQueryParams();
        $page    = max(1, (int) ($q['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($q['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        $pdo = Db::pdo();

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM comments c JOIN posts p ON p.id = c.post_id WHERE p.post_id = :sid'
        );
        $countStmt->bindValue(':sid', $sid, PDO::PARAM_STR);
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT c.id, p.post_id AS post_sid, c.author_id, c.body, c.created_at, c.updated_at
               FROM comments c JOIN posts p ON p.id = c.post_id
              WHERE p.post_id = :sid
              ORDER BY c.created_at ASC, c.id ASC
              LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':sid', $sid,     PDO::PARAM_STR);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map([self::class, 'shape'], $stmt->fetchAll());
        return Json::list($res, $rows, [
            'post_id'  => $sid,
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
        ]);
    }

    /** POST /posts/{id}/comments — add a comment (local post-existence invariant). {id}=snowflake. */
    public function create(Request $req, Response $res, array $args): Response
    {
        $author = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($author <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
        }

        $sid = (string) $args['id'];
        // LOCAL invariant — feed owns both tables, no cross-service call (T-04-11).
        $postId = $this->resolvePostId($sid);
        if ($postId === null) {
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

    /** DELETE /comments/{id} — owner-only (T-04-06). {id}=comment id nội bộ (nhỏ). */
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
            'SELECT c.id, p.post_id AS post_sid, c.author_id, c.body, c.created_at, c.updated_at
               FROM comments c JOIN posts p ON p.id = c.post_id
              WHERE c.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::shape($row);
    }

    /** Resolve post_id snowflake → id nội bộ; null nếu không tồn tại. */
    private function resolvePostId(string $sid): ?int
    {
        $stmt = Db::pdo()->prepare('SELECT id FROM posts WHERE post_id = :sid LIMIT 1');
        $stmt->bindValue(':sid', $sid, PDO::PARAM_STR);
        $stmt->execute();
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private static function shape(array $row): array
    {
        $row['id']        = (int) $row['id'];
        $row['post_id']   = (string) $row['post_sid']; // snowflake công khai (STRING)
        unset($row['post_sid']);
        $row['author_id'] = (int) $row['author_id'];
        return $row;
    }
}
