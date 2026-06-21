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
 * Recipient-scoped notifications CRUD over the `notifications` table (raw PDO),
 * cloning the connection-service ConnectionController doctrine (D-07/D-09):
 *
 *   - Reads/marks are ALWAYS scoped to the gateway-trusted X-User-Id header
 *     (the recipient). We NEVER read user_id from the body on a read/mark — that
 *     would be an IDOR oracle (T-05-08). create() is the ONE exception: it
 *     trusts the gateway-supplied recipient/actor in the body (T-05-09,
 *     trust-by-network — notification-service has no host port and is only
 *     reachable via the gateway on blog-net).
 *   - index/markRead/markAllRead all short-circuit to 401 UNAUTHORIZED when the
 *     X-User-Id header is missing or <= 0, BEFORE any DB query, so a missing
 *     header is never silently coerced to user 0 (which would mask an auth error
 *     behind a 404 against a phantom user).
 *   - markRead existence is proven by a SCOPED SELECT, NOT rowCount(): a no-op
 *     UPDATE on an already-read row reports rowCount 0 even though the row exists
 *     and belongs to the caller (connection-service accept() doctrine, T-05-11).
 *     A cross-user / missing row yields a UNIFORM 404 — "not found" and "not
 *     yours" are intentionally indistinguishable.
 *   - markAllRead's rowCount() IS meaningful (count of rows newly flipped read).
 *
 * Native prepared statements (EMULATE_PREPARES=false); LIMIT binds PARAM_INT
 * (mirroring feed-service CommentController). Vietnamese DomainError messages.
 * No JWT lib, no host port (D-07).
 */
final class NotificationController
{
    /** Allowed notification types — the ENUM allowlist from db/05-migrate-phase5.sql (T-05-10). */
    private const TYPES = ['invite', 'reaction', 'comment'];

    /**
     * POST /notifications — create a notification (gateway-trusted body).
     *
     * Recipient (user_id) + actor (actor_id) + type + optional ref_id come from
     * the gateway body. The gateway is the only blog-net caller (T-05-09).
     */
    public function create(Request $req, Response $res): Response
    {
        $b       = (array) ($req->getParsedBody() ?? []);
        $userId  = (int) ($b['user_id'] ?? 0);
        $actorId = (int) ($b['actor_id'] ?? 0);
        $type    = (string) ($b['type'] ?? '');
        // ref_id giữ STRING (post snowflake có thể > 2^53 → tránh mất chính xác ở JS).
        $refId   = isset($b['ref_id']) && $b['ref_id'] !== null && $b['ref_id'] !== '' ? (string) $b['ref_id'] : null;
        if ($refId !== null && !ctype_digit($refId)) {
            $refId = null;   // chỉ chấp nhận id dạng số
        }

        if ($userId <= 0 || $actorId <= 0) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Thiếu user_id hoặc actor_id.');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Loại thông báo không hợp lệ.');
        }

        $pdo  = Db::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, actor_id, ref_id) VALUES (:u, :t, :a, :r)'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':t', $type);
        $stmt->bindValue(':a', $actorId, PDO::PARAM_INT);
        $stmt->bindValue(':r', $refId, $refId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();

        return Json::ok($res, ['id' => (int) $pdo->lastInsertId()], 201);
    }

    /**
     * GET /notifications — the recipient's own notifications newest-first plus an
     * exact unread_count (NOTIF-02). Scoped to X-User-Id; IDOR-safe (T-05-08).
     */
    public function index(Request $req, Response $res): Response
    {
        $me = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($me <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
        }

        $limit = min(50, max(1, (int) ($req->getQueryParams()['limit'] ?? 30)));

        $pdo  = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, user_id, type, actor_id, ref_id, created_at, read_at
               FROM notifications
              WHERE user_id = :u
              ORDER BY created_at DESC, id DESC
              LIMIT :lim'
        );
        $stmt->bindValue(':u',   $me,    PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map([self::class, 'shape'], $stmt->fetchAll());

        // SEPARATE scoped count — the exact badge number (unread for this user).
        $cnt = $pdo->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL'
        );
        $cnt->bindValue(':u', $me, PDO::PARAM_INT);
        $cnt->execute();
        $unread = (int) $cnt->fetchColumn();

        return Json::list($res, $rows, ['unread_count' => $unread, 'total' => count($rows)]);
    }

    /**
     * POST /notifications/{id}/read — mark ONE of the caller's notifications read
     * (NOTIF-03). 401 guard runs BEFORE any query (a missing header is never a
     * masked 404 against user 0). Existence proven by a scoped SELECT, not
     * rowCount() — an already-read row returns 200, not a false 404 (T-05-11).
     */
    public function markRead(Request $req, Response $res, array $args): Response
    {
        $me = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($me <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
        }

        $id  = (int) $args['id'];
        $pdo = Db::pdo();

        // Scoped UPDATE — no-op if already read; rowCount is NOT trusted for existence.
        $upd = $pdo->prepare(
            'UPDATE notifications SET read_at = NOW()
              WHERE id = :id AND user_id = :u AND read_at IS NULL'
        );
        $upd->bindValue(':id', $id, PDO::PARAM_INT);
        $upd->bindValue(':u',  $me, PDO::PARAM_INT);
        $upd->execute();

        // Scoped SELECT proves the row exists AND belongs to the caller.
        $chk = $pdo->prepare(
            'SELECT id, read_at FROM notifications
              WHERE id = :id AND user_id = :u LIMIT 1'
        );
        $chk->bindValue(':id', $id, PDO::PARAM_INT);
        $chk->bindValue(':u',  $me, PDO::PARAM_INT);
        $chk->execute();
        $row = $chk->fetch();
        if ($row === false) {
            throw new DomainError(404, 'NOTIFICATION_NOT_FOUND', 'Không tìm thấy thông báo.');
        }

        return Json::ok($res, ['id' => $id, 'read_at' => $row['read_at']]);
    }

    /**
     * POST /notifications/read-all — mark every unread notification of the caller
     * read (NOTIF-03). Same 401 guard. rowCount() IS meaningful here — the number
     * of rows newly flipped to read.
     */
    public function markAllRead(Request $req, Response $res): Response
    {
        $me = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($me <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
        }

        $stmt = Db::pdo()->prepare(
            'UPDATE notifications SET read_at = NOW()
              WHERE user_id = :u AND read_at IS NULL'
        );
        $stmt->bindValue(':u', $me, PDO::PARAM_INT);
        $stmt->execute();

        return Json::ok($res, ['marked' => $stmt->rowCount()]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** Cast the integer columns; ref_id stays null when the DB row is NULL. */
    private static function shape(array $row): array
    {
        $row['id']       = (int) $row['id'];
        $row['user_id']  = (int) $row['user_id'];
        $row['actor_id'] = (int) $row['actor_id'];
        $row['ref_id']   = $row['ref_id'] === null ? null : (string) $row['ref_id'];   // STRING (post snowflake an toàn cho JS)
        return $row;
    }
}
