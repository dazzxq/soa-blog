<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\DomainError;
use App\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PostController
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
     * GET /posts — list (or batch via ?ids=).
     */
    public function index(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        $idsRaw = isset($q['ids']) ? (string) $q['ids'] : '';

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
                "SELECT id, author_id, title, slug, content, created_at, updated_at
                 FROM posts WHERE id IN ($placeholders)"
            );
            $stmt->execute($ids);
            return Json::list($res, $stmt->fetchAll(), ['total' => $stmt->rowCount()]);
        }

        $page    = max(1,   (int) ($q['page']     ?? 1));
        $perPage = min(50,  max(1, (int) ($q['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        $authorId = isset($q['author_id']) ? (int) $q['author_id'] : 0;

        $pdo = Db::pdo();

        if ($authorId > 0) {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE author_id = :a');
            $countStmt->execute([':a' => $authorId]);
            $total = (int) $countStmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT id, author_id, title, slug, content, created_at, updated_at
                 FROM posts WHERE author_id = :a
                 ORDER BY created_at DESC, id DESC
                 LIMIT :lim OFFSET :off'
            );
            $stmt->bindValue(':a',   $authorId,  PDO::PARAM_INT);
            $stmt->bindValue(':lim', $perPage,   PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset,    PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $total = (int) $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
            $stmt = $pdo->prepare(
                'SELECT id, author_id, title, slug, content, created_at, updated_at
                 FROM posts
                 ORDER BY created_at DESC, id DESC
                 LIMIT :lim OFFSET :off'
            );
            $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
            $stmt->execute();
        }

        return Json::list($res, $stmt->fetchAll(), [
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
        ]);
    }

    public function show(Request $req, Response $res, array $args): Response
    {
        $post = $this->find((int) $args['id']);
        if ($post === null) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Không tìm thấy bài viết.');
        }
        return Json::ok($res, $post);
    }

    public function create(Request $req, Response $res): Response
    {
        $authorId = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($authorId === 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
        }

        $b = (array) ($req->getParsedBody() ?? []);
        $title   = trim((string) ($b['title']   ?? ''));
        $content = trim((string) ($b['content'] ?? ''));

        if ($title === '' || mb_strlen($title) > 255) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Tiêu đề phải từ 1-255 ký tự.');
        }
        if ($content === '') {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Nội dung bài viết không được để trống.');
        }

        $slug = $this->uniqueSlug($title);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO posts (author_id, title, slug, content)
             VALUES (:a, :t, :s, :c)'
        );
        $stmt->execute([':a' => $authorId, ':t' => $title, ':s' => $slug, ':c' => $content]);
        $id = (int) Db::pdo()->lastInsertId();

        return Json::ok($res, $this->find($id), 201);
    }

    public function update(Request $req, Response $res, array $args): Response
    {
        $id       = (int) $args['id'];
        $callerId = (int) ($req->getHeaderLine('X-User-Id') ?: 0);

        $post = $this->find($id);
        if ($post === null) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Không tìm thấy bài viết.');
        }
        if ($callerId === 0 || $callerId !== (int) $post['author_id']) {
            throw new DomainError(403, 'FORBIDDEN', 'Bạn không có quyền chỉnh sửa bài viết này.');
        }

        $b = (array) ($req->getParsedBody() ?? []);
        $sets = [];
        $params = [':id' => $id];

        if (array_key_exists('title', $b)) {
            $t = trim((string) $b['title']);
            if ($t === '' || mb_strlen($t) > 255) {
                throw new DomainError(400, 'VALIDATION_FAILED', 'Tiêu đề phải từ 1-255 ký tự.');
            }
            $sets[] = 'title = :t';
            $params[':t'] = $t;
        }
        if (array_key_exists('content', $b)) {
            $c = trim((string) $b['content']);
            if ($c === '') {
                throw new DomainError(400, 'VALIDATION_FAILED', 'Nội dung không được để trống.');
            }
            $sets[] = 'content = :c';
            $params[':c'] = $c;
        }

        if ($sets === []) {
            return Json::ok($res, $post);
        }

        Db::pdo()->prepare('UPDATE posts SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);

        return Json::ok($res, $this->find($id));
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        $id       = (int) $args['id'];
        $callerId = (int) ($req->getHeaderLine('X-User-Id') ?: 0);

        $post = $this->find($id);
        if ($post === null) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Không tìm thấy bài viết.');
        }
        if ($callerId === 0 || $callerId !== (int) $post['author_id']) {
            throw new DomainError(403, 'FORBIDDEN', 'Bạn không có quyền xoá bài viết này.');
        }

        Db::pdo()->prepare('DELETE FROM posts WHERE id = :id')->execute([':id' => $id]);

        return $res->withStatus(204);
    }

    private function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, author_id, title, slug, content, created_at, updated_at
             FROM posts WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function uniqueSlug(string $title): string
    {
        $base = $this->slugify($title);
        if ($base === '') {
            $base = 'bai-viet';
        }
        $slug = $base;

        $stmt = Db::pdo()->prepare('SELECT 1 FROM posts WHERE slug = :s LIMIT 1');
        for ($i = 2; $i < 1000; $i++) {
            $stmt->execute([':s' => $slug]);
            if (!$stmt->fetchColumn()) {
                return substr($slug, 0, 255);
            }
            $slug = $base . '-' . $i;
        }
        return $base . '-' . bin2hex(random_bytes(3));
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        $from = ['à','á','ả','ã','ạ','ă','ằ','ắ','ẳ','ẵ','ặ','â','ầ','ấ','ẩ','ẫ','ậ',
                 'è','é','ẻ','ẽ','ẹ','ê','ề','ế','ể','ễ','ệ',
                 'ì','í','ỉ','ĩ','ị',
                 'ò','ó','ỏ','õ','ọ','ô','ồ','ố','ổ','ỗ','ộ','ơ','ờ','ớ','ở','ỡ','ợ',
                 'ù','ú','ủ','ũ','ụ','ư','ừ','ứ','ử','ữ','ự',
                 'ỳ','ý','ỷ','ỹ','ỵ','đ'];
        $to   = ['a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
                 'e','e','e','e','e','e','e','e','e','e','e',
                 'i','i','i','i','i',
                 'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
                 'u','u','u','u','u','u','u','u','u','u','u',
                 'y','y','y','y','y','d'];
        $text = str_replace($from, $to, $text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim($text, '-');
    }
}
