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
 * feed-service Posts domain: posts CRUD, reactions (upsert/remove), repost, and
 * THE single-query timeline (D-06 / FEED-06) that returns each post with
 * reaction_count / comment_count / my_reaction computed server-side.
 *
 * Doctrine (cloned from the brownfield post-service + connection-service):
 *   - Caller identity is ALWAYS the gateway-trusted `X-User-Id` header (D-07,
 *     T-04-07). author_id / user_id are NEVER read from the request body.
 *   - Uniform 404 for a missing post (POST_NOT_FOUND); owner-only delete → 403.
 *   - Native prepared statements (EMULATE_PREPARES=false). The timeline/batch
 *     query uses POSITIONAL `?` placeholders ONLY (the dynamic IN-list + its
 *     surrounding scalars); fixed-arity statements use NAMED placeholders. The
 *     two are NEVER mixed in the same statement.
 *
 * THE COUNTS ARE CORRELATED SCALAR SUBQUERIES, never a double JOIN (Pitfall 3 /
 * T-04-09): joining posts to BOTH child tables and GROUP-BY would multiply the
 * two counts against each other (the fan-trap cross-product). The asymmetric
 * demo seed (post 1 = 2 reactions, 1 comment) is the smoke canary for that bug.
 */
final class PostController
{
    private const VALID_REACTIONS = ['like', 'love', 'haha', 'wow', 'sad', 'angry'];

    /**
     * The SELECT column list shared by the timeline, the batch (?ids=), and the
     * single-row find(). `?` for my_reaction's viewer is the FIRST positional
     * placeholder (it appears first in the list). For the named-placeholder
     * single-row variant we substitute `:viewer` instead (see find()).
     */
    private static function selectColumns(string $viewerPlaceholder): string
    {
        return "p.id, p.author_id, p.content, p.image_url, p.images, p.repost_of, p.created_at,
                (SELECT COUNT(*) FROM reactions r WHERE r.post_id = p.id)                                  AS reaction_count,
                (SELECT COUNT(*) FROM comments  c WHERE c.post_id = p.id)                                  AS comment_count,
                (SELECT r2.type  FROM reactions r2 WHERE r2.post_id = p.id AND r2.user_id = $viewerPlaceholder) AS my_reaction";
    }

    /** Coerce DB string columns to the contract's types. */
    private static function shape(array $row): array
    {
        $row['id']             = (int) $row['id'];
        $row['author_id']      = (int) $row['author_id'];
        $row['reaction_count'] = (int) $row['reaction_count'];
        $row['comment_count']  = (int) $row['comment_count'];
        $row['repost_of']      = $row['repost_of'] !== null ? (int) $row['repost_of'] : null;
        // images: decode the JSON array (null when absent). Always an array|null.
        $imgs = $row['images'] ?? null;
        if (is_string($imgs) && $imgs !== '') {
            $decoded = json_decode($imgs, true);
            $row['images'] = is_array($decoded) ? array_values($decoded) : null;
        } else {
            $row['images'] = null;
        }
        // my_reaction stays string|null (NOT bool — Pitfall 4 / T-04-12).
        return $row;
    }

    /**
     * GET /posts — dual-mode:
     *   ?ids=  → batch resolution of originals (gateway repost-original lookup).
     *   else   → the timeline (?authors=&viewer=&limit=).
     */
    public function index(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();

        // ---- Batch mode: ?ids= -------------------------------------------------
        $idsRaw = isset($q['ids']) ? (string) $q['ids'] : '';
        if ($idsRaw !== '') {
            $ids = self::parseIdList($idsRaw);
            if ($ids === []) {
                return Json::list($res, [], ['total' => 0]);
            }
            if (count($ids) > 100) {
                throw new DomainError(400, 'TOO_MANY_IDS', 'Tối đa 100 id mỗi lượt.');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            // Positional bind order: viewer (=0 → my_reaction null for originals)
            // FIRST, then the id IN-list.
            $sql = 'SELECT ' . self::selectColumns('?') . "
                      FROM posts p
                     WHERE p.id IN ($placeholders)
                     ORDER BY p.created_at DESC, p.id DESC";
            $stmt = Db::pdo()->prepare($sql);
            $stmt->bindValue(1, 0, PDO::PARAM_INT); // viewer
            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 2, $id, PDO::PARAM_INT);
            }
            $stmt->execute();

            $rows = array_map([self::class, 'shape'], $stmt->fetchAll());
            return Json::list($res, $rows, ['total' => count($rows)]);
        }

        // ---- Timeline mode: ?authors=&viewer=&limit= --------------------------
        $authors = self::parseIdList((string) ($q['authors'] ?? ''));
        if ($authors === []) {
            return Json::list($res, [], ['total' => 0]);
        }
        if (count($authors) > 100) {
            throw new DomainError(400, 'TOO_MANY_IDS', 'Tối đa 100 tác giả mỗi lượt.');
        }

        $viewer = (int) ($q['viewer'] ?? 0);
        $limit  = min(50, max(1, (int) ($q['limit'] ?? 20)));

        $placeholders = implode(',', array_fill(0, count($authors), '?'));
        // THE TIMELINE QUERY (D-06 / Pitfall 3). Positional bind order:
        //   1) viewer  (my_reaction subquery, first in SELECT list)
        //   2..) each author id (the IN-list)
        //   last) limit
        $sql = 'SELECT ' . self::selectColumns('?') . "
                  FROM posts p
                 WHERE p.author_id IN ($placeholders)
                 ORDER BY p.created_at DESC, p.id DESC
                 LIMIT ?";
        $stmt = Db::pdo()->prepare($sql);
        $pos  = 1;
        $stmt->bindValue($pos++, $viewer, PDO::PARAM_INT);
        foreach ($authors as $authorId) {
            $stmt->bindValue($pos++, $authorId, PDO::PARAM_INT);
        }
        $stmt->bindValue($pos, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map([self::class, 'shape'], $stmt->fetchAll());
        return Json::list($res, $rows, ['total' => count($rows)]);
    }

    /** GET /posts/{id} — single post + counts (?viewer=). */
    public function show(Request $req, Response $res, array $args): Response
    {
        $viewer = (int) ($req->getQueryParams()['viewer'] ?? 0);
        $post   = $this->find((int) $args['id'], $viewer);
        if ($post === null) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
        }
        return Json::ok($res, $post);
    }

    /** POST /posts — create. author = X-User-Id (never the body). */
    public function create(Request $req, Response $res): Response
    {
        $author = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($author <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
        }

        $b       = (array) ($req->getParsedBody() ?? []);
        $content = trim((string) ($b['content'] ?? ''));
        if ($content === '' || mb_strlen($content) > 5000) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Nội dung bài viết phải từ 1-5000 ký tự.');
        }

        $imageUrl = trim((string) ($b['image_url'] ?? ''));
        if ($imageUrl !== '' && mb_strlen($imageUrl) > 512) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Đường dẫn ảnh tối đa 512 ký tự.');
        }

        // Multiple images (max 9). Accept an `images` array of http(s) URLs; fall back
        // to the single image_url for older clients. Persist as JSON and mirror the
        // first URL into image_url for single-image consumers (search/repost preview).
        $images = [];
        if (is_array($b['images'] ?? null)) {
            foreach ($b['images'] as $u) {
                $u = trim((string) $u);
                if ($u === '') {
                    continue;
                }
                if (mb_strlen($u) > 512 || !preg_match('#^https?://#i', $u)) {
                    throw new DomainError(400, 'VALIDATION_FAILED', 'Đường dẫn ảnh không hợp lệ.');
                }
                $images[] = $u;
                if (count($images) >= 9) {
                    break;
                }
            }
        }
        if ($images === [] && $imageUrl !== '') {
            $images = [$imageUrl];
        }
        $firstImage = $images[0] ?? null;
        $imagesJson = $images === [] ? null
            : json_encode(array_values($images), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO posts (author_id, content, image_url, images, repost_of)
             VALUES (:a, :c, :img, :imgs, NULL)'
        );
        $stmt->execute([
            ':a'    => $author,
            ':c'    => $content,
            ':img'  => $firstImage,
            ':imgs' => $imagesJson,
        ]);

        $id = (int) Db::pdo()->lastInsertId();
        return Json::ok($res, $this->find($id, $author), 201);
    }

    /** POST /posts/{id}/reactions — upsert the caller's reaction (D-02). */
    public function react(Request $req, Response $res, array $args): Response
    {
        $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($caller <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
        }

        $postId = (int) $args['id'];
        $b      = (array) ($req->getParsedBody() ?? []);
        $type   = trim((string) ($b['type'] ?? ''));
        if ($type === '') {
            $type = 'like';
        }
        if (!in_array($type, self::VALID_REACTIONS, true)) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Loại cảm xúc không hợp lệ.');
        }

        // Existence invariant (T-04-11): react to a missing post → 404.
        if ($this->findPost($postId) === null) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
        }

        // Upsert: at most one row per (post,user) via uq_reaction_post_user.
        $stmt = Db::pdo()->prepare(
            'INSERT INTO reactions (post_id, user_id, type)
             VALUES (:p, :u, :t)
             ON DUPLICATE KEY UPDATE type = VALUES(type)'
        );
        $stmt->execute([':p' => $postId, ':u' => $caller, ':t' => $type]);

        return Json::ok($res, ['post_id' => $postId, 'type' => $type]);
    }

    /** DELETE /posts/{id}/reactions — remove the caller's reaction (idempotent). */
    public function unreact(Request $req, Response $res, array $args): Response
    {
        $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($caller <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
        }

        $postId = (int) $args['id'];
        $stmt   = Db::pdo()->prepare(
            'DELETE FROM reactions WHERE post_id = :p AND user_id = :u'
        );
        $stmt->execute([':p' => $postId, ':u' => $caller]);

        return Json::ok($res, ['post_id' => $postId, 'removed' => $stmt->rowCount() > 0]);
    }

    /** POST /posts/{id}/repost — repost an existing post (D-04 collapse). */
    public function repost(Request $req, Response $res, array $args): Response
    {
        $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($caller <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
        }

        $targetId = (int) $args['id'];
        $target   = $this->findPost($targetId);
        if ($target === null) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
        }

        // D-04: reposting a repost collapses to the root original.
        $rootId = $target['repost_of'] !== null ? (int) $target['repost_of'] : $targetId;

        $stmt = Db::pdo()->prepare(
            'INSERT INTO posts (author_id, content, image_url, repost_of)
             VALUES (:a, :c, NULL, :r)'
        );
        $stmt->execute([':a' => $caller, ':c' => '', ':r' => $rootId]);

        $id = (int) Db::pdo()->lastInsertId();
        return Json::ok($res, $this->find($id, $caller), 201);
    }

    /** DELETE /posts/{id} — owner-only + cascade (A5 / T-04-06). */
    public function delete(Request $req, Response $res, array $args): Response
    {
        $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        $id     = (int) $args['id'];

        $post = $this->find($id);
        if ($post === null) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
        }
        if ($caller === 0 || $caller !== (int) $post['author_id']) {
            throw new DomainError(403, 'FORBIDDEN', 'Bạn không có quyền xoá bài viết này.');
        }

        // Cascade: reactions → comments → the post itself. No physical FK
        // exists, so the application owns referential integrity here.
        $pdo = Db::pdo();
        $pdo->prepare('DELETE FROM reactions WHERE post_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM comments WHERE post_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM posts WHERE id = :id')->execute([':id' => $id]);

        return $res->withStatus(204);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Single-row variant of the timeline query (counts + viewer-relative
     * my_reaction). Pure NAMED placeholders (:viewer, :id) — never mixed with
     * positional. Returns null if the post does not exist.
     */
    private function find(int $id, int $viewer = 0): ?array
    {
        $sql = 'SELECT ' . self::selectColumns(':viewer') . '
                  FROM posts p
                 WHERE p.id = :id
                 LIMIT 1';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([':viewer' => $viewer, ':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::shape($row);
    }

    /** Lightweight existence/shape probe for invariants (react/repost). */
    private function findPost(int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, author_id, repost_of FROM posts WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Parse a comma list into unique positive ints (preserves order). */
    private static function parseIdList(string $raw): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($s) => (int) trim($s), explode(',', $raw)),
            static fn ($i) => $i > 0
        )));
    }
}
