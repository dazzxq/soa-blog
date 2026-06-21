<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\DomainError;
use App\Json;
use App\Snowflake;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * feed-service Posts domain: posts CRUD, reactions (upsert/remove), repost, and
 * THE single-query timeline (D-06 / FEED-06) that returns each post with
 * reaction_count / comment_count / my_reaction computed server-side.
 *
 * IDENTIFIER MODEL (alias):
 *   - `posts.id` BIGINT AUTO_INCREMENT là PK NỘI BỘ, không bao giờ lộ ra ngoài;
 *     mọi join con (reactions.post_id, comments.post_id, posts.repost_of) dùng id.
 *   - `posts.post_id` là Snowflake (xem App\Snowflake), LÀ ĐỊNH DANH CÔNG KHAI:
 *     route {id} từ gateway là snowflake, JSON trả `id` = snowflake (STRING để JS
 *     không mất chính xác), `repost_of` = snowflake của bài gốc.
 *   - Mọi endpoint post-scoped resolve snowflake → id nội bộ một lần (findPost),
 *     rồi thao tác bằng id nội bộ.
 *
 * Doctrine (cloned from the brownfield services):
 *   - Caller identity is ALWAYS the gateway-trusted `X-User-Id` header (D-07).
 *   - Uniform 404 for a missing post (POST_NOT_FOUND); owner-only delete → 403.
 *   - Native prepared statements. Timeline/batch dùng POSITIONAL `?`; fixed-arity
 *     dùng NAMED; KHÔNG trộn hai loại trong một statement.
 *
 * THE COUNTS ARE CORRELATED SCALAR SUBQUERIES, never a double JOIN (Pitfall 3).
 */
final class PostController
{
    private const VALID_REACTIONS = ['like', 'love', 'haha', 'wow', 'sad', 'angry'];

    /**
     * Danh sách cột dùng chung cho timeline, batch (?ids=), và find().
     * Trả `p.post_id` (định danh công khai) + `op.post_id AS repost_of_sid`
     * (snowflake của bài gốc qua join op). Counts/my_reaction bám `p.id` (nội bộ).
     * `$viewerPlaceholder` là `?` (positional) hoặc `:viewer` (named).
     */
    private static function selectColumns(string $viewerPlaceholder): string
    {
        return "p.post_id, p.author_id, p.content, p.content_format, p.image_url, p.images, p.repost_of AS repost_of_internal, op.post_id AS repost_of_sid, p.created_at, p.updated_at,
                (SELECT COUNT(*) FROM reactions r WHERE r.post_id = p.id)                                  AS reaction_count,
                (SELECT COUNT(*) FROM comments  c WHERE c.post_id = p.id)                                  AS comment_count,
                (SELECT r2.type  FROM reactions r2 WHERE r2.post_id = p.id AND r2.user_id = $viewerPlaceholder) AS my_reaction";
    }

    /**
     * Mệnh đề FROM dùng chung — LUÔN kèm self-join lấy snowflake bài gốc, để alias
     * `op.post_id` (repost_of_sid) tồn tại trong MỌI truy vấn đọc post (ISSUE-7).
     */
    private static function postFrom(): string
    {
        return 'FROM posts p LEFT JOIN posts op ON op.id = p.repost_of';
    }

    /** Coerce DB string columns to the contract's types. id/repost_of là STRING (snowflake). */
    private static function shape(array $row): array
    {
        $row['id'] = (string) $row['post_id'];   // định danh công khai = snowflake
        unset($row['post_id']);
        $row['author_id']      = (int) $row['author_id'];
        $row['reaction_count'] = (int) $row['reaction_count'];
        $row['comment_count']  = (int) $row['comment_count'];
        // is_repost: marker tin cậy từ id NỘI BỘ (không null kể cả khi bài gốc đã xoá).
        // repost_of: snowflake bài gốc — NULL khi gốc đã bị xoá (LEFT JOIN không khớp).
        $row['is_repost']      = ($row['repost_of_internal'] ?? null) !== null;
        $row['repost_of']      = ($row['repost_of_sid'] ?? null) !== null ? (string) $row['repost_of_sid'] : null;
        unset($row['repost_of_internal'], $row['repost_of_sid']);
        // content_format: 'html' (đã sanitize server) hoặc 'md' (legacy/escape-first ở FE).
        $row['content_format'] = (string) ($row['content_format'] ?? 'md');
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
     *   ?ids=  → batch resolution of originals (gateway repost-original lookup) — snowflake.
     *   else   → the timeline (?authors=&viewer=&limit=).
     */
    public function index(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();

        // ---- Batch mode: ?ids= (snowflake) ------------------------------------
        $idsRaw = isset($q['ids']) ? (string) $q['ids'] : '';
        if ($idsRaw !== '') {
            $ids = self::parseSnowflakeList($idsRaw);
            if ($ids === []) {
                return Json::list($res, [], ['total' => 0]);
            }
            if (count($ids) > 100) {
                throw new DomainError(400, 'TOO_MANY_IDS', 'Tối đa 100 id mỗi lượt.');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            // Positional bind order: viewer (=0 → my_reaction null for originals)
            // FIRST, then the post_id IN-list (snowflake, PARAM_STR).
            $sql = 'SELECT ' . self::selectColumns('?') . ' ' . self::postFrom() . "
                     WHERE p.post_id IN ($placeholders)
                     ORDER BY p.created_at DESC, p.id DESC";
            $stmt = Db::pdo()->prepare($sql);
            $stmt->bindValue(1, 0, PDO::PARAM_INT); // viewer
            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 2, $id, PDO::PARAM_STR);
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
        $sql = 'SELECT ' . self::selectColumns('?') . ' ' . self::postFrom() . "
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

    /** GET /posts/{id} — single post + counts (?viewer=). {id} = snowflake. */
    public function show(Request $req, Response $res, array $args): Response
    {
        $viewer = (int) ($req->getQueryParams()['viewer'] ?? 0);
        $post   = $this->find((string) $args['id'], $viewer);
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
        $content = $this->validateContent((string) ($b['content'] ?? ''));

        $imageUrl = trim((string) ($b['image_url'] ?? ''));
        if ($imageUrl !== '' && (mb_strlen($imageUrl) > 512 || !preg_match('#^https?://#i', $imageUrl))) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Đường dẫn ảnh không hợp lệ (chỉ http/https, tối đa 512 ký tự).');
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
            "INSERT INTO posts (post_id, author_id, content, content_format, image_url, images, repost_of)
             VALUES (:sid, :a, :c, 'html', :img, :imgs, NULL)"
        );
        $sid = $this->insertWithSnowflake($stmt, [
            ':a'    => $author,
            ':c'    => $content,
            ':img'  => $firstImage,
            ':imgs' => $imagesJson,
        ]);

        return Json::ok($res, $this->find($sid, $author), 201);
    }

    /** PATCH /posts/{id} — owner-only edit of the post content (HTML, sanitized). {id}=snowflake. */
    public function update(Request $req, Response $res, array $args): Response
    {
        $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        $sid    = (string) $args['id'];

        $post = $this->find($sid);
        if ($post === null) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
        }
        if ($caller === 0 || $caller !== (int) $post['author_id']) {
            throw new DomainError(403, 'FORBIDDEN', 'Bạn không có quyền sửa bài viết này.');
        }
        if (!empty($post['is_repost'])) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Không thể sửa bài chia sẻ lại.');
        }

        $b       = (array) ($req->getParsedBody() ?? []);
        $content = $this->validateContent((string) ($b['content'] ?? ''));

        $stmt = Db::pdo()->prepare(
            "UPDATE posts SET content = :c, content_format = 'html', updated_at = NOW() WHERE post_id = :sid"
        );
        $stmt->bindValue(':c', $content, PDO::PARAM_STR);
        $stmt->bindValue(':sid', $sid, PDO::PARAM_STR);
        $stmt->execute();

        return Json::ok($res, $this->find($sid, $caller));
    }

    /** POST /posts/{id}/reactions — upsert the caller's reaction (D-02). {id}=snowflake. */
    public function react(Request $req, Response $res, array $args): Response
    {
        $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($caller <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
        }

        $sid  = (string) $args['id'];
        $b    = (array) ($req->getParsedBody() ?? []);
        $type = trim((string) ($b['type'] ?? ''));
        if ($type === '') {
            $type = 'like';
        }
        if (!in_array($type, self::VALID_REACTIONS, true)) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Loại cảm xúc không hợp lệ.');
        }

        // Existence invariant (T-04-11): react to a missing post → 404.
        $found = $this->findPost($sid);
        if ($found === null) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
        }
        $postId = (int) $found['id'];

        // Upsert: at most one row per (post,user) via uq_reaction_post_user.
        $stmt = Db::pdo()->prepare(
            'INSERT INTO reactions (post_id, user_id, type)
             VALUES (:p, :u, :t)
             ON DUPLICATE KEY UPDATE type = VALUES(type)'
        );
        $stmt->execute([':p' => $postId, ':u' => $caller, ':t' => $type]);

        return Json::ok($res, ['post_id' => $sid, 'type' => $type]);
    }

    /** DELETE /posts/{id}/reactions — remove the caller's reaction (idempotent). {id}=snowflake. */
    public function unreact(Request $req, Response $res, array $args): Response
    {
        $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($caller <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
        }

        $sid   = (string) $args['id'];
        $found = $this->findPost($sid);
        if ($found === null) {
            return Json::ok($res, ['post_id' => $sid, 'removed' => false]);
        }
        $postId = (int) $found['id'];

        $stmt = Db::pdo()->prepare('DELETE FROM reactions WHERE post_id = :p AND user_id = :u');
        $stmt->execute([':p' => $postId, ':u' => $caller]);

        return Json::ok($res, ['post_id' => $sid, 'removed' => $stmt->rowCount() > 0]);
    }

    /** GET /posts/{id}/reactions — danh sách user đã react (mới nhất trước, có phân trang). {id}=snowflake. */
    public function reactions(Request $req, Response $res, array $args): Response
    {
        $sid   = (string) $args['id'];
        $found = $this->findPost($sid);
        if ($found === null) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
        }
        $postId = (int) $found['id'];

        $q       = $req->getQueryParams();
        // Cap page (chặn OFFSET scan đắt khi page lớn — CWE-770). FE chỉ lấy trang đầu.
        $page    = min(100, max(1, (int) ($q['page'] ?? 1)));
        $perPage = min(100, max(1, (int) ($q['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        $pdo = Db::pdo();
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM reactions WHERE post_id = :p');
        $countStmt->execute([':p' => $postId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT user_id, type, created_at
               FROM reactions WHERE post_id = :p
              ORDER BY created_at DESC, id DESC
              LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':p',   $postId,  PDO::PARAM_INT);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(static function (array $r): array {
            $r['user_id'] = (int) $r['user_id'];
            return $r;
        }, $stmt->fetchAll());

        return Json::list($res, $rows, [
            'post_id'  => $sid,
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
        ]);
    }

    /** POST /posts/{id}/repost — repost an existing post (D-04 collapse). {id}=snowflake. */
    public function repost(Request $req, Response $res, array $args): Response
    {
        $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($caller <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
        }

        $targetSid = (string) $args['id'];
        $target    = $this->findPost($targetSid);
        if ($target === null) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
        }

        // D-04: reposting a repost collapses to the root original (id NỘI BỘ).
        $rootId = $target['repost_of'] !== null ? (int) $target['repost_of'] : (int) $target['id'];

        $stmt = Db::pdo()->prepare(
            'INSERT INTO posts (post_id, author_id, content, image_url, repost_of)
             VALUES (:sid, :a, :c, NULL, :r)'
        );
        $sid = $this->insertWithSnowflake($stmt, [
            ':a' => $caller,
            ':c' => '',
            ':r' => $rootId,
        ]);

        return Json::ok($res, $this->find($sid, $caller), 201);
    }

    /** DELETE /posts/{id} — owner-only + cascade (A5 / T-04-06). {id}=snowflake. */
    public function delete(Request $req, Response $res, array $args): Response
    {
        $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        $sid    = (string) $args['id'];

        $found = $this->findPost($sid);
        if ($found === null) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
        }
        if ($caller === 0 || $caller !== (int) $found['author_id']) {
            throw new DomainError(403, 'FORBIDDEN', 'Bạn không có quyền xoá bài viết này.');
        }
        $id = (int) $found['id'];

        // Cascade: reactions → comments → the post itself. No physical FK
        // exists, so the application owns referential integrity here (id NỘI BỘ).
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
     * Thực thi INSERT với post_id Snowflake, retry khi đụng UNIQUE(post_id)
     * (backstop cho va chạm hiếm giữa các process). Trả snowflake đã dùng.
     */
    private function insertWithSnowflake(\PDOStatement $stmt, array $params): string
    {
        for ($attempt = 0; ; $attempt++) {
            $sid = Snowflake::next();
            try {
                $stmt->execute([':sid' => $sid] + $params);
                return $sid;
            } catch (\PDOException $e) {
                // 23000 = integrity constraint (chỉ post_id mới UNIQUE ngoài PK auto).
                if ($e->getCode() === '23000' && $attempt < 3) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Single-row variant of the timeline query (counts + viewer-relative
     * my_reaction). Tìm theo post_id (snowflake). NAMED placeholders only.
     */
    private function find(string $sid, int $viewer = 0): ?array
    {
        $sql = 'SELECT ' . self::selectColumns(':viewer') . ' ' . self::postFrom() . '
                 WHERE p.post_id = :sid
                 LIMIT 1';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->bindValue(':viewer', $viewer, PDO::PARAM_INT);
        $stmt->bindValue(':sid', $sid, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : self::shape($row);
    }

    /**
     * Resolve snowflake → hàng nội bộ (id, post_id, author_id, repost_of) cho các
     * invariant/thao tác (react/unreact/repost/delete/reactions). Trả null nếu không có.
     */
    private function findPost(string $sid): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, post_id, author_id, repost_of FROM posts WHERE post_id = :sid LIMIT 1'
        );
        $stmt->bindValue(':sid', $sid, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Validate + sanitize post content (WYSIWYG HTML). Raw char guard BEFORE purify,
     * byte guard AFTER (TEXT = 65535 byte; utf8mb4 ≤ 4 byte/ký tự → cap theo BYTE),
     * rồi chặn HTML rỗng. Trả HTML đã sạch (an toàn cho x-html ở client).
     */
    private function validateContent(string $raw): string
    {
        $content = trim($raw);
        if ($content === '' || mb_strlen($content) > 10000) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Nội dung bài viết phải từ 1-10000 ký tự.');
        }
        $content = $this->purify($content);
        if (strlen($content) > 60000) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Nội dung bài viết quá dài.');
        }
        // Chặn HTML rỗng kể cả khi chỉ chứa entity/nbsp (vd "<p>&nbsp;</p>").
        $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\x{00A0}/u', ' ', $text);
        if (trim((string) $text) === '') {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Nội dung bài viết không được để trống.');
        }
        return $content;
    }

    /** HTMLPurifier sanitize: allowlist hẹp khớp Trix, chỉ scheme http/https/mailto. */
    private function purify(string $html): string
    {
        static $purifier = null;
        if ($purifier === null) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', 'p,br,strong,em,b,i,u,h1,h2,h3,ul,ol,li,blockquote,pre,a[href]');
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('HTML.TargetBlank', true);
            $config->set('Cache.SerializerPath', sys_get_temp_dir());
            $purifier = new \HTMLPurifier($config);
        }
        return $purifier->purify($html);
    }

    /** Parse a comma list into unique positive ints (preserves order) — dùng cho author ids nhỏ. */
    private static function parseIdList(string $raw): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($s) => (int) trim($s), explode(',', $raw)),
            static fn ($i) => $i > 0
        )));
    }

    /** Parse a comma list into unique numeric STRINGS (snowflake post ids — tránh tràn int). */
    private static function parseSnowflakeList(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $s) {
            $s = trim($s);
            // length ≤ 20 (BIGINT UNSIGNED max) — chặn id rác cực dài (CWE-770).
            if ($s !== '' && $s !== '0' && ctype_digit($s) && strlen($s) <= 20) {
                $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }
}
