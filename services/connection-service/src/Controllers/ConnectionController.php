<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\DomainError;
use App\Json;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Social-graph CRUD over the `connections` table (raw PDO), mirroring
 * profile-service's ProfileController doctrine:
 *
 *   - The caller identity is ALWAYS the gateway-trusted X-User-Id header
 *     (D-09, T-03-05). We NEVER read requester_id / user_id from the request
 *     body — that would be an IDOR oracle.
 *   - Every mutation is scoped by X-User-Id inside the WHERE clause. A caller
 *     who is not the right party affects 0 rows and gets a UNIFORM 404, never a
 *     403 — "not found" and "not yours" are intentionally indistinguishable.
 *   - Existence after an UPDATE is proven by a scoped SELECT, NOT by rowCount()
 *     (MariaDB reports rows *changed*; a no-op UPDATE yields rowCount 0 even for
 *     an existing row). On DELETE, rowCount()===0 → 404 is correct.
 *   - A duplicate INSERT raises PDOException '23000' → 409. With Plan 01's two
 *     unique keys this single catch backstops BOTH a same-direction duplicate
 *     (uq_conn_pair) AND an opposite-direction concurrent invite (uq_pair).
 *
 * Native prepared statements (EMULATE_PREPARES=false) cannot reuse a named
 * placeholder, so a value needed twice is bound under two distinct names
 * (:v1/:v2, :t1/:t2, :c1/:c2 …) — mirroring user-service verifyCredentials.
 */
final class ConnectionController
{
    /**
     * The ONE viewer-relative status computation, reused by status() and (in
     * Plan 03) by the gateway invariant check.
     *
     * Bidirectional lookup with LIMIT 1 is provably deterministic: Plan 01's
     * STORED unordered-pair UNIQUE `uq_pair (pair_lo, pair_hi)` guarantees AT
     * MOST ONE row per unordered pair regardless of direction, so the OR can
     * never match two rows. LIMIT 1 stays as defense-in-depth.
     */
    private function statusBetween(int $viewer, int $target): string
    {
        if ($viewer === $target) {
            return 'self';
        }

        $stmt = Db::pdo()->prepare(
            'SELECT requester_id, addressee_id, status
               FROM connections
              WHERE (requester_id = :v1 AND addressee_id = :t1)
                 OR (requester_id = :t2 AND addressee_id = :v2)
              LIMIT 1'
        );
        $stmt->execute([':v1' => $viewer, ':t1' => $target, ':t2' => $target, ':v2' => $viewer]);
        $row = $stmt->fetch();
        if ($row === false) {
            return 'none';
        }
        if ($row['status'] === 'accepted') {
            return 'connected';
        }
        // pending: direction is relative to the VIEWER.
        return ((int) $row['requester_id'] === $viewer) ? 'pending_outgoing' : 'pending_incoming';
    }

    /**
     * GET /connections/status?viewer=&target= — the D-05 payoff route.
     *
     * LOCKED contract: returns {"data":{"status":"self|none|connected|
     * pending_outgoing|pending_incoming"}}. AggregateController::profileFull
     * reads decode(...)['data']['status']; any other key silently leaves /full
     * at the default 'none' (Pitfall 2). DO NOT rename the `status` key.
     */
    public function status(Request $req, Response $res): Response
    {
        $q      = $req->getQueryParams();
        $viewer = (int) ($q['viewer'] ?? 0);
        $target = (int) ($q['target'] ?? 0);

        return Json::ok($res, ['status' => $this->statusBetween($viewer, $target)]);
    }

    /**
     * POST /connections (CONN-01) — create a pending edge.
     *
     * Requester = X-User-Id (NEVER the body). Addressee = body addressee_id.
     */
    public function create(Request $req, Response $res): Response
    {
        $requester = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        $b         = (array) ($req->getParsedBody() ?? []);
        $addressee = (int) ($b['addressee_id'] ?? 0);

        if ($requester <= 0 || $addressee <= 0 || $requester === $addressee) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Yêu cầu kết nối không hợp lệ.');
        }

        $pdo = Db::pdo();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO connections (requester_id, addressee_id, status) VALUES (:r, :a, 'pending')"
            );
            $stmt->execute([':r' => $requester, ':a' => $addressee]);
        } catch (PDOException $e) {
            // 23000 backstops BOTH races: same-direction duplicate (uq_conn_pair)
            // AND opposite-direction concurrent invite (uq_pair, Plan 01).
            if ($e->getCode() === '23000') {
                throw new DomainError(409, 'REQUEST_EXISTS', 'Đã tồn tại lời mời giữa hai người.');
            }
            throw $e;
        }

        return Json::ok($res, ['id' => (int) $pdo->lastInsertId(), 'status' => 'pending'], 201);
    }

    /**
     * POST /connections/{id}/accept (CONN-02) — only the addressee may accept.
     *
     * Scoped UPDATE; existence proven by a scoped SELECT (not rowCount). A
     * non-addressee caller hits the same 404 — no IDOR oracle.
     */
    public function accept(Request $req, Response $res, array $args): Response
    {
        $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        $id     = (int) $args['id'];

        $stmt = Db::pdo()->prepare(
            "UPDATE connections SET status='accepted'
              WHERE id=:id AND addressee_id=:caller AND status='pending'"
        );
        $stmt->execute([':id' => $id, ':caller' => $caller]);

        $chk = Db::pdo()->prepare(
            "SELECT id FROM connections
              WHERE id=:id AND addressee_id=:caller AND status='accepted' LIMIT 1"
        );
        $chk->execute([':id' => $id, ':caller' => $caller]);
        if ($chk->fetch() === false) {
            throw new DomainError(404, 'REQUEST_NOT_FOUND', 'Không tìm thấy lời mời để chấp nhận.');
        }

        return Json::ok($res, ['id' => $id, 'status' => 'accepted']);
    }

    /**
     * DELETE /connections/{id} (CONN-02/03) — reject (addressee) OR cancel
     * (requester), both collapse to one scoped DELETE of a pending row.
     *
     * The caller may be EITHER party of the pending edge. This "either pending
     * party may delete" design is INTENTIONAL and reviewer-approved. The scope
     * still guarantees only the requester or addressee can delete the row.
     */
    public function deleteRequest(Request $req, Response $res, array $args): Response
    {
        $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        $id     = (int) $args['id'];

        $stmt = Db::pdo()->prepare(
            "DELETE FROM connections
              WHERE id=:id AND status='pending' AND (requester_id=:c1 OR addressee_id=:c2)"
        );
        $stmt->execute([':id' => $id, ':c1' => $caller, ':c2' => $caller]);
        if ($stmt->rowCount() === 0) {
            throw new DomainError(404, 'REQUEST_NOT_FOUND', 'Không tìm thấy lời mời.');
        }

        return Json::ok($res, ['deleted' => true]);
    }

    /**
     * DELETE /connections/by-user/{userId} (CONN-03) — remove an accepted edge.
     *
     * Either side may remove; scoped so the caller must be one of the two
     * parties of an accepted edge.
     */
    public function removeEdge(Request $req, Response $res, array $args): Response
    {
        $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        $other  = (int) $args['userId'];

        $stmt = Db::pdo()->prepare(
            "DELETE FROM connections
              WHERE status='accepted'
                AND ((requester_id=:c1 AND addressee_id=:o1)
                  OR (requester_id=:o2 AND addressee_id=:c2))"
        );
        $stmt->execute([':c1' => $caller, ':o1' => $other, ':o2' => $other, ':c2' => $caller]);
        if ($stmt->rowCount() === 0) {
            throw new DomainError(404, 'CONNECTION_NOT_FOUND', 'Không tìm thấy kết nối.');
        }

        return Json::ok($res, ['deleted' => true]);
    }
}
