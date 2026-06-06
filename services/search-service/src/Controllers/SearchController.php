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
 * search-service query/index engine over the denormalized `search_index`
 * (raw PDO), cloning the connection/feed-service doctrine (D-09):
 *
 *   - This service has NO host port and NO JWT lib. It trusts the gateway on
 *     the isolated blog-net (D-07, T-05-07); search() and upsert() do NOT read
 *     or require X-User-Id. Identity is irrelevant to a denormalized read index.
 *   - search_index stores NO email column (DDL); there is no PII to leak here
 *     (T-05-06), and the gateway adds a column allowlist as defense-in-depth.
 *
 * SQLi + wildcard hardening (T-05-05):
 *   - The query string `q` is ALWAYS bound as a VALUE, NEVER interpolated into
 *     the SQL text. Native prepared statements (EMULATE_PREPARES=false) cannot
 *     reuse a named placeholder, so the same LIKE term is bound under four
 *     distinct names (:t1..:t4) — one per searched column.
 *   - LIMIT is bound with PDO::PARAM_INT (a string-bound LIMIT would be quoted
 *     and rejected), mirroring feed-service CommentController::index.
 *   - Defense-in-depth on top of binding: the user's own `%`/`_` are escaped so
 *     they cannot act as SQL LIKE wildcards (a user searching "100%" must match
 *     the literal text "100%", not "100<anything>"). The escape char `\` is
 *     doubled FIRST, then `%`/`_`, so the backslashes we add are not themselves
 *     re-escaped; every predicate carries `ESCAPE '\\'`.
 */
final class SearchController
{
    /**
     * GET /search?q=&limit= (SEARCH-01) — case-insensitive LIKE over four
     * denormalized columns (display_name, username, headline, skills_text).
     *
     * Empty `q` short-circuits to an empty list with NO DB hit. The collation
     * (utf8mb4_unicode_ci) makes LIKE case-insensitive without LOWER().
     */
    public function search(Request $req, Response $res): Response
    {
        $params = $req->getQueryParams();
        $q      = trim((string) ($params['q'] ?? ''));

        // Empty query → empty list, no DB hit.
        if ($q === '') {
            return Json::list($res, [], ['total' => 0, 'q' => '']);
        }

        if (mb_strlen($q) > 100) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Từ khoá tìm kiếm quá dài.');
        }

        $limit = min(50, max(1, (int) ($params['limit'] ?? 20)));

        // Neutralize user-supplied LIKE wildcards (defense-in-depth on top of the
        // bound parameter). Order matters: escape the escape char `\` FIRST, then
        // `%`/`_`, so the backslashes we add are not themselves doubled.
        $esc  = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $term = '%' . $esc . '%'; // bound as a VALUE; the surrounding %…% are our literal wildcards

        $stmt = Db::pdo()->prepare(
            'SELECT user_id, username, display_name, headline, location, skills_text, avatar_url
               FROM search_index
              WHERE display_name LIKE :t1 ESCAPE \'\\\'
                 OR username     LIKE :t2 ESCAPE \'\\\'
                 OR headline     LIKE :t3 ESCAPE \'\\\'
                 OR skills_text  LIKE :t4 ESCAPE \'\\\'
              ORDER BY display_name ASC
              LIMIT :lim'
        );
        // Four distinct names — native prepared statements forbid placeholder reuse.
        $stmt->bindValue(':t1', $term, PDO::PARAM_STR);
        $stmt->bindValue(':t2', $term, PDO::PARAM_STR);
        $stmt->bindValue(':t3', $term, PDO::PARAM_STR);
        $stmt->bindValue(':t4', $term, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $r['user_id'] = (int) $r['user_id'];
            $rows[] = $r;
        }

        return Json::list($res, $rows, ['total' => count($rows), 'q' => $q]);
    }

    /**
     * POST /index (reindex sink) — idempotent upsert of one denormalized row,
     * keyed on the user_id PRIMARY KEY (ON DUPLICATE KEY UPDATE). The gateway's
     * Plan-04 reindex calls this per user; re-upserting the same user_id updates
     * in place and never errors. Body-only payload (no X-User-Id) — the gateway
     * is the only caller on blog-net (T-05-07).
     */
    public function upsert(Request $req, Response $res): Response
    {
        $b   = (array) ($req->getParsedBody() ?? []);
        $uid = (int) ($b['user_id'] ?? 0);
        if ($uid <= 0) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Thiếu user_id.');
        }

        $stmt = Db::pdo()->prepare(
            'INSERT INTO search_index
                 (user_id, username, display_name, headline, location, skills_text, avatar_url)
             VALUES (:uid, :un, :dn, :hl, :loc, :sk, :av)
             ON DUPLICATE KEY UPDATE
                 username     = VALUES(username),
                 display_name = VALUES(display_name),
                 headline     = VALUES(headline),
                 location     = VALUES(location),
                 skills_text  = VALUES(skills_text),
                 avatar_url   = VALUES(avatar_url)'
        );
        $stmt->execute([
            ':uid' => $uid,
            ':un'  => (string) ($b['username'] ?? ''),
            ':dn'  => (string) ($b['display_name'] ?? ''),
            ':hl'  => $b['headline']    ?? null,
            ':loc' => $b['location']    ?? null,
            ':sk'  => $b['skills_text'] ?? null,
            ':av'  => $b['avatar_url']  ?? null,
        ]);

        return Json::ok($res, ['user_id' => $uid], 200);
    }
}
