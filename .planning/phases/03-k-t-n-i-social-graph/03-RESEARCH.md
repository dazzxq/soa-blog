# Phase 3: Kết nối / Social Graph - Research

**Researched:** 2026-06-07
**Domain:** API Gateway cross-service invariant (the grading centerpiece) + build-out of the real connection-service (graph CRUD + viewer-relative status) + gateway composition/enrichment + idempotent live-DB migration + minimal Alpine/Tailwind UI
**Confidence:** HIGH — every claim below is verified against actual source files read this session (connection-service stub, gateway clients/controllers/routes/DI, profile-service controllers, db schema/migration/init, deploy.sh, smoke-phase2.sh, web pages) plus the canonical invariant recovered verbatim from git `2f6ecf8:gateway/src/Controllers/PostsController.php`.

## Summary

Phase 3 is the most architecturally on-point phase of the project: it builds the social graph AND lights up the API Gateway's headline responsibility — **enforcing cross-service invariants** — with the exact pattern the brownfield already proved (`PostsController::createComment` = "comment requires existing post" → 404; `PostsController::delete` = "blocked while comments exist" → 409). The Phase 3 invariant (`POST /api/connections/requests`) is the same shape: gateway rejects self-invite (400), checks profile-service existence (404), checks connection-service for an existing edge in either direction (409), then writes. This is not new technology — it is the `createComment` controller re-targeted at two backends.

Everything you need has a working precedent in the repo. The connection-service is a `/health`-only stub whose scaffold (Slim 4, manual wiring in `public/index.php`, raw-PDO `Db.php` pointed at `proconnect_connection`, `App\Json`/`App\DomainError`/`App\JsonErrorHandler`) is byte-for-byte the same shape as profile-service — so building out CRUD means cloning profile-service's `ProfileController` PDO patterns (prepared statements, owner-scoped `WHERE ... AND user_id=:caller`, 23000→409, scoped-SELECT existence checks). The gateway side reuses the `ConnectionClient` (which ALREADY calls `GET /connections/status?viewer=&target=` — D-05's exact contract), `ProfileClient::batch()` for enrichment (the `?ids=` batch already exists), and the `Utils::settle` + `meta.degraded` composition pattern from `AggregateController`/`ProfilesController`. The DB migration clones the proven `db/02-migrate-phase2.sql` + `deploy.sh` step `7b`.

The single highest-value item — and the demo's "wow" moment — is **D-05/the payoff**: `gateway/src/Services/ConnectionClient::statusForAsync()` already issues `GET /connections/status?viewer=&target=`, and `AggregateController::profileFull()` already consumes `data.status` from it and degrades when it 404s. **The moment connection-service implements that one internal route returning `{data:{status}}`, the Phase 2 `/api/profiles/{id}/full` `connection_status` goes live with ZERO gateway rework.** This is verified in source, not assumed.

**Primary recommendation:** Build the invariant as a new `ConnectionsController::sendRequest` cloned from git-history `PostsController::createComment` (existence check via `ProfileClient::get` → 404; edge check via a NEW `ConnectionClient::edgeBetween` → 409; then `ConnectionClient::createRequest`). Build connection-service as a `ConnectionController` mirroring profile-service's `ProfileController` raw-PDO patterns over a single directed `connections` table, with viewer-relative status computed from a two-row-direction OR lookup. Implement the internal `GET /connections/status?viewer=&target=` returning `{data:{status}}` to satisfy D-05 and light up `/full`. Enrich lists/suggestions with `ProfileClient::batch()` + `settle`/degrade. Migrate the live DB with an idempotent `db/03-migrate-phase3.sql` wired into `deploy.sh` right after the existing phase-2 step `7b`. Smoke via `scripts/smoke-phase3.sh` cloned from `smoke-phase2.sh`.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01 (the invariant centerpiece, CONN-04/CONN-07):** On `POST /api/connections/requests {target_id}` the gateway enforces, BEFORE any write: (1) self-invite → `400`; (2) `profile-service GET /users/{target_id}` → absent → `404 PROFILE_NOT_FOUND`; (3) connection-service edge check in EITHER direction → accepted → `409 ALREADY_CONNECTED`, pending → `409 REQUEST_EXISTS`; (4) only then write the pending edge. Mirrors the brownfield "comment requires existing post". Call it out in the presentation.
- **D-02:** Single table `connections(id, requester_id BIGINT, addressee_id BIGINT, status ENUM('pending','accepted') NOT NULL DEFAULT 'pending', created_at, updated_at, UNIQUE(requester_id, addressee_id), INDEX(addressee_id), INDEX(status))`. Direction preserved (requester vs addressee) → can show "đã gửi" vs "chờ phản hồi".
- **D-03:** Reject an invite = DELETE the pending row (re-invitable). Remove a connection = DELETE the accepted row. No soft-delete / no rejected status.
- **D-04 (gateway routes):**
  - `POST /api/connections/requests` `{target_id}` — send invite (D-01 invariant).
  - `POST /api/connections/requests/{id}/accept` — addressee accepts → `accepted`.
  - `POST /api/connections/requests/{id}/reject` — addressee rejects → delete.
  - `DELETE /api/connections/requests/{id}` — requester cancels own outgoing pending.
  - `DELETE /api/connections/{userId}` — remove an accepted connection (either side).
  - `GET /api/connections` — my accepted connections (gateway-enriched).
  - `GET /api/connections/requests?direction=incoming|outgoing` — my pending invites (enriched).
  - `GET /api/connections/suggestions` — People you may know (composition).
  - `GET /api/connections/status/{userId}` — relationship status vs me (also reused by the internal status call).
- **D-05:** connection-service MUST implement internal `GET /connections/status?viewer=&target=` — the EXACT path `ConnectionClient::statusForAsync()` already calls. Implementing it lights up Phase 2 `/api/profiles/{id}/full` `connection_status` with ZERO gateway rework. Status values: `"none" | "pending_outgoing" | "pending_incoming" | "connected" | "self"`.
- **D-06:** Connection/invite lists = gateway composition: connection-service returns user_ids + status; gateway batch-fetches profile basics (display_name, headline, avatar_url) to build cards; degrade-safe (`meta.degraded` if profile batch partly fails).
- **D-07:** "People you may know" (CONN-06): SIMPLE — candidates = users with NO edge (not connected, no pending), excluding self, capped (~10); connection-service computes the exclusion set, gateway enriches. No mutual-friend ranking.
- **D-08:** Idempotent `db/03-migrate-phase3.sql` (`CREATE TABLE IF NOT EXISTS connections` in `proconnect_connection`) wired BLOCKING into `scripts/deploy.sh` after the phase-2 migrate step; sync the fresh-volume schema file; small idempotent demo seed (duyet↔long accepted; demo→duyet pending). NON-destructive. No new container/secret/grant.
- **D-09:** connection-service trusts `X-User-Id` (no JWT lib), raw-PDO + Json/DomainError Vietnamese envelope. Gateway verifies JWT → X-User-Id; ownership enforced (accept/reject only addressee; cancel only requester) — at gateway and/or scoped in connection-service queries.
- **D-10:** `web/connections.html` (my connections + incoming/outgoing pending + suggestions) + `web/profile.html` relationship-status badge + context action. Alpine+Tailwind CDN, Vietnamese, minimal (NO branding/3-column — Phase 6).

### Claude's Discretion
- Exact suggestion cap/ordering, pagination, internal route shapes, status-enum storage details, which side "removes" a connection, demo-seed specifics.

### Deferred Ideas (OUT OF SCOPE)
- Feed (Phase 4), search/notifications (Phase 5), branding/3-column (Phase 6), mutual-friend ranking for suggestions.
- Notification on new invite — Phase 5. Phase 3 just creates the edges.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| CONN-01 | Gửi lời mời kết nối | `ConnectionsController::sendRequest` runs the D-01 invariant then `ConnectionClient::createRequest` → connection-service `POST /connections` inserts a `pending` row scoped to `X-User-Id` as requester. |
| CONN-02 | Chấp nhận / từ chối lời mời đến | `accept` → connection-service flips status to `accepted` (scoped: only the addressee). `reject` → DELETE the pending row (D-03). Ownership enforced at gateway + scoped query (Pattern 6). |
| CONN-03 | Xem danh sách kết nối | `GET /api/connections` → connection-service returns accepted edges as the OTHER user's id+direction; gateway enriches via `ProfileClient::batch()` (D-06). |
| CONN-04 | Xem lời mời đang chờ (incoming/outgoing) | `GET /api/connections/requests?direction=` → connection-service filters pending rows by `addressee_id=me` (incoming) or `requester_id=me` (outgoing); gateway enriches. |
| CONN-05 | Trạng thái quan hệ đúng trên hồ sơ | Two paths, ONE computation: `GET /connections/status?viewer=&target=` (internal, D-05 — lights up `/full`) and `GET /api/connections/status/{userId}` (gateway). Status from a single directed row via the OR lookup (Pattern 4). |
| CONN-06 | Gợi ý "Người bạn có thể biết" | `GET /api/connections/suggestions` → connection-service returns user_ids with NO edge (exclusion set), capped; gateway enriches + degrades (D-07). NOTE: needs the candidate universe — see Open Question 1. |
| CONN-07 | Gateway chặn invariant cross-service | THE centerpiece: self→400, profile-not-found→404, existing-edge→409, then write (D-01). Verbatim shape of git-history `PostsController::createComment` + `delete`. |
</phase_requirements>

## Standard Stack

No new dependencies. connection-service's `composer.json` is already dependency-minimal and identical to the other services (`slim/slim ^4.12`, `slim/psr7 ^1.6`, `monolog/monolog ^3.0`, `ext-mbstring`, `ext-pdo` — NO ORM, NO Guzzle, NO JWT lib). [VERIFIED: services/connection-service/composer.json read in full]

### Core (verified from committed lockfiles + STACK.md — NOT training data)
| Library | Version (locked) | Purpose | Why Standard |
|---------|------------------|---------|--------------|
| `slim/slim` | 4.15.1 | HTTP framework, gateway + connection-service | [VERIFIED: STACK.md] Already the framework. |
| `slim/psr7` | 1.8.0 | PSR-7 messages | [VERIFIED: STACK.md] |
| `php-di/php-di` | 7.1.1 | DI container, **gateway only** | [VERIFIED: gateway/public/index.php uses `DI\Container`; connection-service `public/index.php` wires MANUALLY — no container] |
| `guzzlehttp/guzzle` | 7.10.0 | Gateway → service HTTP | [VERIFIED: gateway/src/Services/HttpClient.php] |
| `guzzlehttp/promises` | 2.3.0 | `Utils::settle` parallel composition | [VERIFIED: AggregateController.php uses `use GuzzleHttp\Promise\Utils;`] |
| `firebase/php-jwt` | v7.0.5 | JWT HS256, **gateway only** | [VERIFIED: STACK.md; connection-service has NO JWT dep — D-09 trusts X-User-Id] |
| `ext-pdo` / `ext-pdo_mysql` | bundled (php:8.2-fpm-alpine) | Raw PDO in connection-service | [VERIFIED: services/connection-service/src/Db.php is a real PDO singleton] |

### Frontend (CDN, no build)
| Asset | Source | Purpose |
|-------|--------|---------|
| Tailwind | `https://cdn.tailwindcss.com` (Play CDN) | Styling | [VERIFIED: web/profile.html line 7] |
| Alpine.js 3.x | `https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js` | Reactivity | [VERIFIED: web/profile.html line 10] |
| `web/assets/app.js` | local classic script | `window.api`, `window.auth`, `window.navbar`, `window.loadFull` helpers | [VERIFIED: read in full] |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| New `ConnectionsController` in gateway | Add methods to `ProfilesController` | RECOMMENDED: a dedicated `ConnectionsController` (mirrors how the brownfield kept invariants in `PostsController` and composition in `AggregateController`). Keeps the showcase invariant legible. [VERIFIED: existing controllers are domain-scoped] |
| Edge check via a dedicated internal endpoint (`edgeBetween`) | Reuse `GET /connections/status` and treat `connected`/`pending_*` as 409 | EITHER works and BOTH are needed anyway (status powers D-05). RECOMMENDED: have the gateway invariant reuse the SAME status computation — call `ConnectionClient::statusForAsync($me,$target)` (or a sync variant), then map `connected`→409 ALREADY_CONNECTED, `pending_outgoing`/`pending_incoming`→409 REQUEST_EXISTS, `self`→400. One computation, two consumers (the invariant + `/full`). Reduces surface and guarantees consistency. [VERIFIED: status path already exists] |
| `Utils::settle` for list enrichment | `Utils::unwrap` / synchronous batch | `batch()` enrichment in the brownfield is a single synchronous `?ids=` call wrapped in try/catch (`PostsController::fetchAuthors`), not `settle`. For a single batch call, synchronous-with-try/catch is simpler and is the proven pattern. Use `settle` only if you fan out to ≥2 sources. [VERIFIED: PostsController::fetchAuthors git-history] |

**Installation:** none. `composer install` already run; lockfiles committed; Dockerfiles copy `composer.json composer.lock`. connection-service already has a committed `composer.lock`. [VERIFIED: services/connection-service/composer.lock present]

**Version verification:** Versions taken from `.planning/codebase/STACK.md` (which read the committed lockfiles). The stack is pinned and reproducible by design — do NOT bump versions in Phase 3. No registry lookup needed.

## Architecture Patterns

### Project structure touched in Phase 3
```
db/
├── 01-schema-connection.sql        # NEW (fresh-volume): CREATE TABLE connections (D-08 convergence)
├── 03-migrate-phase3.sql           # NEW: idempotent live-volume migration + demo seed (clone 02-migrate-phase2.sql)
                                     #   NOTE filename convention: see Pitfall 1 — the fresh-volume connection schema
                                     #   file name must NOT collide with profile's 01-schema-profile.sql alphabetical slot.

services/connection-service/src/
├── routes.php                      # ADD the internal route set (currently /health only)
├── Controllers/ConnectionController.php  # NEW: graph CRUD + status computation (clone profile-service ProfileController PDO patterns)
└── (Db.php / Json.php / DomainError.php / JsonErrorHandler.php already present — reuse as-is)

gateway/src/
├── routes.php                      # ADD /api/connections/* (mutations + lists), all under JWT except none-public
├── public/index.php                # ADD DI: ConnectionsController(ProfileClient, ConnectionClient)
├── Controllers/ConnectionsController.php # NEW: the D-01 invariant + list/suggestion composition + ownership passthrough
└── Services/ConnectionClient.php   # EXTEND: createRequest/accept/reject/cancel/remove/listConnections/listPending/suggestions + status (sync) + *Async

web/
├── connections.html                # NEW (D-10): connections + incoming/outgoing pending + suggestions
├── profile.html                    # EXTEND: real status badge + context action (Kết nối / Huỷ / Chấp nhận / Đã kết nối)
└── assets/app.js                   # OPTIONAL: small connection helpers

scripts/
├── deploy.sh                       # EXTEND: apply db/03-migrate-phase3.sql right AFTER step 7b (phase-2 migrate)
└── smoke-phase3.sh                 # NEW: invariant + accept/reject/cancel + lists + suggestions + the /full payoff
```

### Pattern 1: The cross-service invariant (THE centerpiece — clone git-history `createComment`)
**What:** Gateway orchestrates checks across profile-service + connection-service BEFORE writing. Verbatim shape of the recovered brownfield pattern.
**Source:** `git show 2f6ecf8:gateway/src/Controllers/PostsController.php` — `createComment` (existence→404) and `delete` (conflict→409). Read in full this session.

```php
// gateway/src/Controllers/ConnectionsController.php — NEW
// Mirrors git-history PostsController::createComment (404 existence) + ::delete (409 conflict).
public function sendRequest(Request $req, Response $res): Response
{
    $me = (int) ($req->getAttribute('user_id') ?? 0);
    if ($me === 0) {
        throw new DomainError(401, 'UNAUTHORIZED', 'Vui lòng đăng nhập.');
    }
    $target = (int) ((array)($req->getParsedBody() ?? []))['target_id'] ?? 0;

    // (1) self-invite → 400
    if ($target === $me || $target <= 0) {
        throw new DomainError(400, 'INVALID_TARGET', 'Không thể tự gửi lời mời cho chính mình.');
    }

    // (2) profile-service existence → 404  (mirrors createComment's GET /posts/{id})
    $check = $this->profiles->get($target);
    if ($check->getStatusCode() === 404) {
        throw new DomainError(404, 'PROFILE_NOT_FOUND', 'Người dùng không tồn tại.');
    }
    if ($check->getStatusCode() >= 500) {
        return Json::raw($res, $this->decode($check), $check->getStatusCode());
    }

    // (3) existing-edge → 409  (REUSE the status computation — one source of truth)
    $statusRes = $this->connections->statusFor($me, $target); // sync variant of statusForAsync
    if ($statusRes->getStatusCode() === 200) {
        $st = (string) ($this->decode($statusRes)['data']['status'] ?? 'none');
        if ($st === 'connected') {
            throw new DomainError(409, 'ALREADY_CONNECTED', 'Hai người đã là kết nối.');
        }
        if ($st === 'pending_outgoing' || $st === 'pending_incoming') {
            throw new DomainError(409, 'REQUEST_EXISTS', 'Đã tồn tại một lời mời giữa hai người.');
        }
    } elseif ($statusRes->getStatusCode() >= 500) {
        // refuse to write on incomplete info (mirror delete()'s 503 COMMENT_SERVICE_UNAVAILABLE)
        throw new DomainError(503, 'CONNECTION_SERVICE_UNAVAILABLE', 'Không kiểm tra được trạng thái kết nối. Vui lòng thử lại.');
    }

    // (4) only NOW write the pending edge
    $up = $this->connections->createRequest($me, $target);
    return Json::raw($res, $this->decode($up), $up->getStatusCode());
}
```
**CRITICAL — the 503-on-incomplete-info rule:** the git-history `delete()` returns a hard `503` when the comment-count check fails, refusing to act on incomplete info (avoids orphans). Mirror this: if the edge/status check fails (≥500 or rejected), DO NOT proceed to write — return 503. This is part of the invariant's correctness story, worth a sentence in the presentation. [VERIFIED: PostsController::delete lines for COMMENT_SERVICE_UNAVAILABLE]
**Race note (be honest in the slides):** check-then-write is not atomic; a concurrent duplicate could slip past the 409 check. The `UNIQUE(requester_id, addressee_id)` constraint in connection-service is the backstop — a duplicate INSERT hits 23000 → connection-service returns 409. So 409 is enforced in TWO places: the gateway check (fast/clean UX) AND the DB unique (correctness). Document this; it is the correct design, not a gap.

### Pattern 2: connection-service internal route set (build out the stub)
**What:** connection-service mirrors profile-service's "dumb service" shape — trusts `X-User-Id`, raw-PDO, Vietnamese envelope. Currently `routes.php` is `/health` only.
**Source:** `services/connection-service/src/routes.php` (stub) + `services/profile-service/src/routes.php` (the shape to mirror) + `services/profile-service/src/Controllers/ProfileController.php` (the PDO patterns).

Recommended internal routes (Claude's discretion on exact shapes — D-04 note):
```php
// services/connection-service/src/routes.php — extend
$app->get   ('/health', [HealthController::class, 'health']);                 // keep

// D-05 — the EXACT path ConnectionClient::statusForAsync already calls. PRIORITY.
$app->get   ('/connections/status',          [ConnectionController::class, 'status']);   // ?viewer=&target=

$app->post  ('/connections',                 [ConnectionController::class, 'create']);   // {requester_id?,addressee_id} — scope by X-User-Id
$app->post  ('/connections/{id:[0-9]+}/accept', [ConnectionController::class, 'accept']); // X-User-Id must be addressee
$app->delete('/connections/{id:[0-9]+}',     [ConnectionController::class, 'deleteRequest']); // reject (addressee) OR cancel (requester)
$app->delete('/connections/by-user/{userId:[0-9]+}', [ConnectionController::class, 'removeEdge']); // remove accepted (either side)
$app->get   ('/connections',                 [ConnectionController::class, 'listAccepted']);  // ?user= → accepted edges
$app->get   ('/connections/pending',         [ConnectionController::class, 'listPending']);   // ?user=&direction=
$app->get   ('/connections/suggestions',     [ConnectionController::class, 'suggestions']);   // ?user=&limit=
```
**Bootstrap:** `services/connection-service/public/index.php` is identical to profile-service's (manual wiring, no DI, `App\JsonErrorHandler` installed). No bootstrap change needed beyond adding the new controller class (autoloaded via PSR-4 `App\`). [VERIFIED: both public/index.php read — byte-for-byte same structure]

### Pattern 3: Viewer-relative status from a single directed row (status correctness)
**What:** With ONE directed row `(requester_id, addressee_id, status)` and `UNIQUE(requester_id, addressee_id)`, compute viewer-relative status. The UNIQUE is on the ordered pair, so an edge can exist in EITHER direction — check both with one OR query.
**Source:** PDO patterns from `services/profile-service/src/Controllers/ProfileController.php` (prepared statements, named params bound, `FETCH_ASSOC`).

```php
// connection-service ConnectionController — the ONE status computation reused everywhere.
private function statusBetween(int $viewer, int $target): string
{
    if ($viewer === $target) return 'self';

    // One lookup, either direction. Native prepared statements (EMULATE_PREPARES=false)
    // cannot reuse a named placeholder, so bind viewer/target twice (mirror UserController::verifyCredentials).
    $stmt = Db::pdo()->prepare(
        'SELECT requester_id, addressee_id, status
           FROM connections
          WHERE (requester_id = :v1 AND addressee_id = :t1)
             OR (requester_id = :t2 AND addressee_id = :v2)
          LIMIT 1'
    );
    $stmt->execute([':v1' => $viewer, ':t1' => $target, ':t2' => $target, ':v2' => $viewer]);
    $row = $stmt->fetch();
    if ($row === false) return 'none';

    if ($row['status'] === 'accepted') return 'connected';
    // pending: direction is relative to the VIEWER
    return ((int) $row['requester_id'] === $viewer) ? 'pending_outgoing' : 'pending_incoming';
}

public function status(Request $req, Response $res): Response
{
    $q = $req->getQueryParams();
    $viewer = (int) ($q['viewer'] ?? 0);
    $target = (int) ($q['target'] ?? 0);
    // Return {data:{status}} — EXACT shape AggregateController reads: data.status (D-05).
    return Json::ok($res, ['status' => $this->statusBetween($viewer, $target)]);
}
```
**CRITICAL response shape:** `AggregateController::profileFull` reads `$this->decode($c['value'])['data']['status']`. The status endpoint MUST return `{"data":{"status":"..."}}` (use `Json::ok($res, ['status'=>...])`). If you return `{"data":{"connection_status":...}}` or a bare string, `/full` silently stays at the default `'none'` and never lights up. [VERIFIED: AggregateController.php line reading `['data']['status']`]
**Anonymous viewer:** if `viewer=0` (no token at `/full`), `AggregateController` already short-circuits to `connection_status: null` before trusting the service (it sets `$connectionStatus = $viewerId > 0 ? 'none' : null`). connection-service can return `'none'` for viewer=0 safely; the gateway overrides to null for anon. [VERIFIED: AggregateController logic]

### Pattern 4: Ownership enforcement (D-09) — scope by X-User-Id, mirror ProfileController
**What:** accept/reject only by addressee; cancel only by requester; remove by either side. Enforce with scoped queries (the IDOR-safe pattern profile-service already uses) AND/OR a gateway pre-check.
**Source:** `ProfileController::ownerOnly` + the scoped `WHERE id=:eid AND user_id=:caller` + the "uniform 404 on 0-rowcount, no oracle" doctrine (read in full).

```php
// connection-service: accept — only the addressee may accept.
public function accept(Request $req, Response $res, array $args): Response
{
    $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
    $id     = (int) $args['id'];
    // Scope the UPDATE to addressee — a non-addressee affects 0 rows → uniform 404
    // (IDOR-safe: "not found" and "not yours" indistinguishable, like ProfileController).
    $stmt = Db::pdo()->prepare(
        "UPDATE connections SET status='accepted'
          WHERE id=:id AND addressee_id=:caller AND status='pending'"
    );
    $stmt->execute([':id'=>$id, ':caller'=>$caller]);
    // Existence via scoped SELECT, NOT rowCount (a no-op accept of an already-accepted row
    // would yield rowCount 0 — but here status='pending' guards that; still prefer SELECT to be safe).
    $chk = Db::pdo()->prepare('SELECT id FROM connections WHERE id=:id AND addressee_id=:caller AND status=:s LIMIT 1');
    $chk->execute([':id'=>$id, ':caller'=>$caller, ':s'=>'accepted']);
    if ($chk->fetch() === false) {
        throw new DomainError(404, 'REQUEST_NOT_FOUND', 'Không tìm thấy lời mời để chấp nhận.');
    }
    return Json::ok($res, ['id'=>$id, 'status'=>'accepted']);
}
```
**reject vs cancel both = DELETE (D-03), but scoped differently:** reject → `WHERE id=:id AND addressee_id=:caller AND status='pending'`; cancel → `WHERE id=:id AND requester_id=:caller AND status='pending'`. The gateway has two routes (`/reject`, `DELETE /requests/{id}`) that map to the same connection-service DELETE with a different scope hint, OR connection-service exposes one delete that scopes to "caller is requester OR addressee" for pending. RECOMMENDED: keep the gateway routes distinct for legible intent, and let connection-service scope appropriately. [VERIFIED: ProfileController delete-scope pattern]
**rowCount caveat (carried from Phase 2):** for the no-op-PATCH problem MariaDB reports rows *changed* not *matched*, so use a scoped SELECT to prove existence rather than `rowCount()` on UPDATEs. For DELETEs, `rowCount()===0 → 404` is correct (the row is gone or never matched). [VERIFIED: ProfileController comments on rowCount]

### Pattern 5: List + suggestion enrichment via batch (D-06/D-07) — clone fetchAuthors
**What:** connection-service returns user_ids (+ direction/status); gateway batch-fetches profile basics and degrades on partial failure.
**Source:** git-history `PostsController::fetchAuthors` (synchronous `UserClient::batch` + try/catch → degrade to `author:null`) and the present `ProfileClient::batch()` (the `?ids=` batch EXISTS — verified).

```php
// gateway ConnectionsController — enrich a list of {user_id, status, direction, request_id}
private function enrich(array $rows): array
{
    $ids = array_values(array_unique(array_map(fn($r) => (int)$r['user_id'], $rows)));
    $profiles = [];
    if ($ids !== []) {
        $res = $this->profiles->batch($ids);              // GET profile-service /users?ids=
        if ($res->getStatusCode() === 200) {
            foreach ((array)($this->decode($res)['data'] ?? []) as $u) {
                // profile-service /users?ids= SELECT includes email — TRIM to basics (D-06 + Pitfall 2).
                $profiles[(int)$u['id']] = array_intersect_key($u, array_flip(['id','username','display_name','avatar_url']));
            }
        }
        // else: degrade — leave $profiles empty; mark meta.degraded
    }
    // ... attach $profiles[$r['user_id']] ?? null to each row; return [$cards, $degraded]
}
```
**CRITICAL — email leak (Pitfall 2 carried from Phase 2):** `ProfileClient::batch()` → `GET /users?ids=` and `UserController::index` SELECTs `email` (verified line: `SELECT id, username, email, display_name, avatar_url ...`). Connection cards are shown to other users, so the gateway MUST allowlist basics (drop `email`) before emitting, exactly as `ProfilesController::show` and `AggregateController` already do. Smoke must assert no `@`/`email` in `/api/connections` output. [VERIFIED: UserController::index SELECT includes email]
**For headline in cards (D-06 mentions headline):** `/users?ids=` does NOT currently return `headline`. Either (a) accept that cards show display_name+avatar only (simplest, still satisfies D-06's intent), or (b) add `headline` to the `index()` SELECT's allowlist in profile-service (one-line change, public-safe). RECOMMENDED (b) if cards want headline — it is public info already exposed on `/full`. Flag at plan review. [VERIFIED: index() SELECT columns]

### Pattern 6: Registering the new gateway controller + routes + DI
**Source:** `gateway/public/index.php` (explicit closures) + `gateway/src/routes.php` (group `/api`, per-route `->add($jwtMw)`). Both read in full.
```php
// gateway/public/index.php — after existing $container->set(...) calls:
$container->set(ConnectionsController::class, fn(Container $c) => new ConnectionsController(
    $c->get(ProfileClient::class),
    $c->get(ConnectionClient::class),
));
```
```php
// gateway/src/routes.php — inside the $app->group('/api', ...) closure.
// ALL connection mutations + my-lists require JWT (they are "me"-relative).
$g->post  ('/connections/requests',                       [ConnectionsController::class, 'sendRequest'])->add($jwtMw);
$g->post  ('/connections/requests/{id:[0-9]+}/accept',    [ConnectionsController::class, 'accept'])->add($jwtMw);
$g->post  ('/connections/requests/{id:[0-9]+}/reject',    [ConnectionsController::class, 'reject'])->add($jwtMw);
$g->delete('/connections/requests/{id:[0-9]+}',           [ConnectionsController::class, 'cancel'])->add($jwtMw);
$g->delete('/connections/{userId:[0-9]+}',                [ConnectionsController::class, 'remove'])->add($jwtMw);
$g->get   ('/connections',                                [ConnectionsController::class, 'listConnections'])->add($jwtMw);
$g->get   ('/connections/requests',                       [ConnectionsController::class, 'listPending'])->add($jwtMw);
$g->get   ('/connections/suggestions',                    [ConnectionsController::class, 'suggestions'])->add($jwtMw);
$g->get   ('/connections/status/{userId:[0-9]+}',         [ConnectionsController::class, 'statusVsMe'])->add($jwtMw);
```
**ROUTE-ORDERING GOTCHA (verified real risk):** Slim/FastRoute matches the FIRST registered route that fits. `GET /connections/requests` (literal) and `GET /connections/status/{userId}` and `GET /connections` (bare) coexist fine because the segments differ. BUT `DELETE /connections/{userId:[0-9]+}` and `DELETE /connections/requests/{id}` could be ambiguous if `requests` were unconstrained — it is NOT (the `{userId:[0-9]+}` numeric constraint means the literal `requests` segment can never match the numeric param), so register order is safe. Keep the `[0-9]+` constraint on every numeric param, exactly as Phase 2 did for `/profiles/me` vs `/profiles/{id:[0-9]+}`. [VERIFIED: routes.php uses `{id:[0-9]+}` constraints; same disambiguation Phase 2 relied on]

### Pattern 7: Extending ConnectionClient (add the methods, keep statusForAsync)
**Source:** `gateway/src/Services/ConnectionClient.php` (currently `health`/`healthAsync`/`statusForAsync`) + `ProfileClient.php` (sync+async pairs, `X-User-Id` injection on mutations).
```php
// ConnectionClient additions — mirror ProfileClient's X-User-Id injection on mutations.
public function statusFor(int $viewer, int $target): ResponseInterface {       // sync (for the invariant)
    return $this->http->request('GET', '/connections/status', ['query'=>['viewer'=>$viewer,'target'=>$target]]);
}
// statusForAsync already EXISTS — do not touch it (D-05 contract).
public function createRequest(int $requester, int $target): ResponseInterface {
    return $this->http->request('POST', '/connections', [
        'json'    => ['addressee_id' => $target],
        'headers' => ['Content-Type'=>'application/json', 'X-User-Id'=>(string)$requester],
    ]);
}
public function accept(int $caller, int $id): ResponseInterface { /* POST /connections/{id}/accept + X-User-Id */ }
public function deleteRequest(int $caller, int $id): ResponseInterface { /* DELETE /connections/{id} + X-User-Id */ }
public function removeEdge(int $caller, int $otherUserId): ResponseInterface { /* DELETE /connections/by-user/{otherUserId} + X-User-Id */ }
public function listAccepted(int $user): ResponseInterface { /* GET /connections?user= */ }
public function listPending(int $user, string $direction): ResponseInterface { /* GET /connections/pending?user=&direction= */ }
public function suggestions(int $user, int $limit): ResponseInterface { /* GET /connections/suggestions?user=&limit= */ }
```
`X-Request-Id` is forwarded automatically by `HttpClient::create` — no extra plumbing. [VERIFIED: HttpClient + the health rid echo in connection-service HealthController proves forwarding already works]

### Anti-Patterns to Avoid
- **Returning the status under any key other than `data.status`** — breaks the D-05 `/full` payoff silently. [VERIFIED: AggregateController reads `data.status`]
- **Passing `/users?ids=` batch bodies through verbatim into connection cards** — leaks `email`. Allowlist to basics. [VERIFIED: UserController::index SELECTs email]
- **Trusting `requester_id`/`user_id` from the request body in connection-service** — IDOR. Scope by `X-User-Id` only (D-09), never the body. [CITED: D-09 + ProfileController doctrine]
- **Using `rowCount()` as existence proof on UPDATE (accept)** — MariaDB reports rows changed; a no-op yields 0. Use a scoped SELECT. (DELETE rowCount is fine.) [VERIFIED: ProfileController comments]
- **Adding the `connections` table only to a fresh-volume schema file** — the live VPS volume already exists; the schema file silently no-ops there. You MUST ship the idempotent `db/03-migrate-phase3.sql` wired into deploy.sh. [VERIFIED: db/00-init.sh init-glob comment + Phase 2 precedent]
- **Skipping the gateway 409 check because the DB UNIQUE catches duplicates** — the gateway check gives clean UX + is the legible invariant for grading; the UNIQUE is the correctness backstop. Keep BOTH. [VERIFIED: invariant is the grading centerpiece per CLAUDE.md]

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Cross-service invariant orchestration | bespoke check sequence | Clone git-history `PostsController::createComment` + `delete` shape | Proven, legible, matches the grading rubric. [VERIFIED] |
| Duplicate-edge prevention | app-level "search then maybe insert" only | `UNIQUE(requester_id, addressee_id)` + catch 23000→409 | The gateway check races; the DB unique is the backstop (mirror `SKILL_EXISTS` 409). [VERIFIED: ProfileController addSkill 23000→409] |
| Parallel fan-out (if used for /full or multi-source) | `curl_multi`/threads | `GuzzleHttp\Promise\Utils::settle` | Already the pattern in AggregateController. [VERIFIED] |
| List enrichment | N+1 per-row fetch | `ProfileClient::batch()` (`?ids=`) + try/catch degrade | `fetchAuthors` precedent; batch already exists. [VERIFIED] |
| Owner-scoped writes / IDOR safety | role checks in body | scoped `WHERE ... AND addressee_id/requester_id=:caller` + uniform 404 | ProfileController doctrine — no existence oracle. [VERIFIED] |
| Error envelope | ad-hoc JSON | `App\DomainError` + `App\JsonErrorHandler` (VN) | Already present in connection-service. [VERIFIED] |
| Idempotent live migration | manual ALTER on VPS | `db/03-migrate-phase3.sql` + deploy.sh step (clone 02) | Phase 2 solved this exact pitfall. [VERIFIED] |
| Request correlation | manual headers | `HttpClient::create` auto-forwards X-Request-Id | connection-service /health already echoes `rid` proving it works. [VERIFIED] |

**Key insight:** Phase 3 introduces ZERO genuinely-new infrastructure. The invariant is `createComment` re-pointed; the service is profile-service's `ProfileController` re-shaped; the migration is `02-migrate-phase2.sql` re-cloned; enrichment is `fetchAuthors` re-used. The risk is reusing the patterns correctly (especially the `data.status` shape and the email allowlist), not choosing technology.

## Runtime State Inventory

Brownfield with a LIVE deployed stack (8 containers on soa.duyet.vn). connection-service is currently a `/health`-only stub.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | **LIVE DB `proconnect_connection` on VPS is EMPTY** — the schema has NO `connections` table yet (Phase 1 created the empty schema + `connection_svc` user only; D-09 Phase 1 = no business tables). The fresh-volume + live-volume both lack the table. | **Data migration**: idempotent `CREATE TABLE IF NOT EXISTS connections` against the live volume via deploy.sh (NOT just a code edit) + a small idempotent demo seed (D-08). Mirrors Phase 2's `02-migrate-phase2.sql`. |
| Live service config | connection-service container is deployed and serving `/health` only. `CONNECTION_SERVICE_URL: http://connection-service:80` already injected into gateway; gateway `depends_on: connection-service service_healthy` already set. No docker-compose change needed. | **None** — the container exists and is wired (compose lines 43-54, 100, 110 verified). New routes ship via image rebuild on deploy. No new container/port/env. |
| OS-registered state | None — no Task Scheduler / cron / launchd referencing connections. Deploy is GitHub Actions → `scripts/deploy.sh`. | None. |
| Secrets / env vars | `CONNECTION_SVC_DB_PASS` already in `.env`/compose; `connection_svc`@'%' already exists with `GRANT ALL ON proconnect_connection.*` (verified in 00-init.sh AND migrate-phase1.sql.tmpl). The schema-wide grant covers the new `connections` table automatically. | **None** — no new secret, user, or grant (D-08 confirmed). |
| Build artifacts | connection-service `vendor/` committed/built; no new composer deps → no dependency rebuild. Image rebuilds on deploy (`docker compose build --pull`) so new PHP files ship. `web/` is bind-mounted; deploy.sh restarts web only when `web/` changed. | New `.php` ships via image rebuild (automatic). New/changed `web/*.html` ships via bind mount + conditional restart (automatic). No manual step. |

**The canonical question — after every repo file is updated, what runtime state still has the old shape?** Answer: the **live `proconnect_connection` schema** has no `connections` table. A repo-only schema edit deploys green but every connection endpoint 500s on `SELECT ... FROM connections`. The idempotent live migration wired into deploy.sh is the ONLY fix — exactly the Phase 2 pattern. NOTHING ELSE carries old state (the grant is schema-wide, the container/URL/depends_on are already correct).

## Common Pitfalls

### Pitfall 1: `connections` table invisible on the live volume + fresh-volume filename collision
**What goes wrong:** (a) You add `connections` to a schema file, deploy, and endpoints 500 with "Table 'connections' doesn't exist" because the live volume's init scripts already ran. (b) The fresh-volume connection schema file is named such that it sorts BEFORE `00-init.sh` (which creates the `proconnect_connection` DB) or conflicts with `01-schema-profile.sql`'s slot.
**Why:** MariaDB's initdb glob runs `db/*.sql` + `*.sh` alphabetically ONLY on a fresh volume; the live VPS volume already exists → silently skipped. Also, `00-init.sh` must run first (creates the DB), then any `0X-schema-*.sql`.
**How to avoid:**
1. Ship `db/03-migrate-phase3.sql` — idempotent `CREATE TABLE IF NOT EXISTS connections (...)` in `proconnect_connection` + the idempotent demo seed.
2. ALSO add a fresh-volume schema file so a from-scratch `docker compose up` converges. NAMING: `01-schema-profile.sql` already occupies the `01-` slot for the profile DB. Use a name that (i) sorts AFTER `00-init.sh`, (ii) does not clash with profile's file, e.g. `db/01-schema-connection.sql` is fine because both are `CREATE ... IF NOT EXISTS` and both run after `00-init.sh` (alphabetical among `01-*` is deterministic and harmless — order between the two `01-` files doesn't matter since each `USE`s its own DB). Confirm the chosen name with `ls db/` ordering at plan time. The migration file MUST `USE proconnect_connection;` at the top (the deploy step also passes the DB name explicitly).
3. Wire into `deploy.sh` IMMEDIATELY after step `7b` (the phase-2 additive migration), applied to the running container:
   ```bash
   echo "[deploy] applying db/03-migrate-phase3.sql (additive)"
   docker compose exec -T mariadb mysql -uroot -p"$DB_ROOT_PASSWORD" proconnect_connection < db/03-migrate-phase3.sql
   echo "[deploy] phase-3 additive migration applied"
   ```
   Plain `.sql`, NO envsubst (no secret placeholders — same reasoning as `02-migrate-phase2.sql`). [VERIFIED: deploy.sh step 7b pattern + 02-migrate-phase2.sql header]
**Warning signs:** local lint green, VPS deploy green, but `/api/connections` 500s.
**Confidence:** HIGH — directly mirrors the documented Phase-2 resolution.

### Pitfall 2: status returned under the wrong JSON key → `/full` never lights up
**What goes wrong:** connection-service returns `{"data":{"connection_status":"connected"}}` or `{"status":"connected"}` (no `data` wrapper), and `/api/profiles/{id}/full` keeps showing `connection_status:"none"` forever — the headline payoff silently fails.
**Why:** `AggregateController::profileFull` reads exactly `decode(response)['data']['status']` and falls back to `'none'` otherwise.
**How to avoid:** the status endpoint returns `Json::ok($res, ['status' => $value])` → `{"data":{"status":"..."}}`. Smoke MUST assert the live `/full` between two connected demo users shows `"connection_status":"connected"` (not "none", not null). [VERIFIED: AggregateController reads data.status; Json::ok wraps in data]

### Pitfall 3: email leak in connection cards
**What goes wrong:** `/api/connections` (or suggestions) cards include `email` because `ProfileClient::batch()` → `/users?ids=` SELECTs email and the gateway passes it through.
**Why:** `UserController::index` SELECT includes `email`. The brownfield always allowlisted (ProfilesController::show, AggregateController).
**How to avoid:** allowlist enrichment to `{id, username, display_name, avatar_url[, headline]}` before emitting. Smoke asserts no `@`/`email` in connection/suggestion output. [VERIFIED: UserController::index SELECTs email; allowlist precedent]

### Pitfall 4: direction confusion in pending lists
**What goes wrong:** "incoming" shows rows you sent (or vice-versa), or the status badge shows "đã gửi" when it should be "chờ phản hồi".
**Why:** the single directed row's meaning is viewer-relative; incoming = `addressee_id = me`, outgoing = `requester_id = me`. `pending_outgoing` (I sent it, waiting) vs `pending_incoming` (they sent it, I can accept).
**How to avoid:** centralize in `statusBetween()` (Pattern 3) and reuse it; for lists, filter explicitly by `addressee_id=me` (incoming) / `requester_id=me` (outgoing). Smoke asserts: after demo `demo→duyet` pending, duyet's incoming list contains demo AND demo's outgoing list contains duyet. [VERIFIED: D-02 direction-preserved table]

### Pitfall 5: ownership bypass on accept/reject/cancel
**What goes wrong:** user C accepts an invite addressed to user B, or cancels someone else's request.
**Why:** missing scope on the mutating query, or trusting a body field.
**How to avoid:** scope EVERY mutation by `X-User-Id` in the WHERE clause (accept→`addressee_id=:caller`; cancel→`requester_id=:caller`; reject→`addressee_id=:caller`) → mismatched caller affects 0 rows → uniform 404 (IDOR-safe, no oracle). Optionally pre-check at gateway too. Smoke asserts user C cannot accept duyet's incoming invite (404/403). [VERIFIED: ProfileController scoped-mutation doctrine]

### Pitfall 6: suggestions candidate universe (CONN-06 is under-specified)
**What goes wrong:** suggestions returns nothing, or 500s, because connection-service has no list of "all users" — users live in `proconnect_profile`, NOT `proconnect_connection`. connection-service cannot `SELECT` the user universe from its own DB.
**Why:** database-per-service: connection-service only knows about edges, not the full user list. The "exclusion set" (users I have an edge with) is computable in connection-service, but the CANDIDATE set (all other users) is not.
**How to avoid:** see Open Question 1. RECOMMENDED: gateway fetches candidate user ids from profile-service (`GET /users` returns up to 100 — verified), asks connection-service for the viewer's edge set (or passes candidate ids and lets connection-service filter), excludes self + edged users, caps, enriches. Keep it simple for the 5-account demo. [VERIFIED: UserController::index returns up to 100 users; database-per-service boundary in ARCHITECTURE.md]

## Code Examples

### Idempotent live migration (db/03-migrate-phase3.sql) — clone of 02-migrate-phase2.sql
```sql
-- Phase 3 live-volume ADDITIVE migration (D-02, D-08) — social graph.
-- WHY: db/*.sql run ONLY on a fresh volume; the live proconnect_connection schema
-- exists but is empty. This adds the connections table + demo seed to the running DB.
-- Plain .sql (no envsubst/secrets — connection_svc already has GRANT ALL on this schema).
-- NON-DESTRUCTIVE + idempotent (CREATE IF NOT EXISTS + guarded seed). Re-runs are no-ops.
USE proconnect_connection;

CREATE TABLE IF NOT EXISTS connections (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  requester_id BIGINT UNSIGNED NOT NULL,
  addressee_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conn_pair (requester_id, addressee_id),
  INDEX idx_conn_addressee (addressee_id),
  INDEX idx_conn_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent demo seed (D-08): duyet(2)<->long(3) accepted; demo(1)->duyet(2) pending.
-- Explicit ids + WHERE NOT EXISTS so re-runs are no-ops.
INSERT INTO connections (id, requester_id, addressee_id, status)
SELECT 1, 2, 3, 'accepted'
WHERE NOT EXISTS (SELECT 1 FROM connections WHERE id = 1);

INSERT INTO connections (id, requester_id, addressee_id, status)
SELECT 2, 1, 2, 'pending'
WHERE NOT EXISTS (SELECT 1 FROM connections WHERE id = 2);
```
[NOTE: `CREATE TABLE IF NOT EXISTS` + guarded INSERT is standard; mirrors the verified `02-migrate-phase2.sql` exactly. ENUM stores the status (D-02). Demo ids match CONTEXT "Specific Ideas".]

### Duplicate-edge backstop (connection-service create) — mirror addSkill 23000→409
```php
public function create(Request $req, Response $res): Response
{
    $requester = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
    $addressee = (int) ((array)($req->getParsedBody() ?? []))['addressee_id'] ?? 0;
    if ($requester === 0 || $addressee <= 0 || $requester === $addressee) {
        throw new DomainError(400, 'VALIDATION_FAILED', 'Yêu cầu kết nối không hợp lệ.');
    }
    try {
        $stmt = Db::pdo()->prepare(
            "INSERT INTO connections (requester_id, addressee_id, status) VALUES (:r, :a, 'pending')"
        );
        $stmt->execute([':r' => $requester, ':a' => $addressee]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') { // UNIQUE(requester,addressee) backstop
            throw new DomainError(409, 'REQUEST_EXISTS', 'Đã tồn tại lời mời theo hướng này.');
        }
        throw $e;
    }
    return Json::ok($res, ['id' => (int) Db::pdo()->lastInsertId(), 'status' => 'pending'], 201);
}
```
[VERIFIED: ProfileController::addSkill uses the exact 23000→409 pattern. NOTE: the UNIQUE is on the ordered pair, so it only catches same-direction dupes — the gateway's bidirectional status check (Pattern 1 step 3) catches the reverse-direction case (B→A when A→B exists). Both layers together = full coverage.]

### Frontend: connections.html data loaders (reuse app.js api/auth)
```js
// web/connections.html — Alpine component, reuses window.api/window.auth.
async function loadConnections() {
  const [conns, incoming, outgoing, sugg] = await Promise.all([
    api.get('/connections'),
    api.get('/connections/requests?direction=incoming'),
    api.get('/connections/requests?direction=outgoing'),
    api.get('/connections/suggestions'),
  ]);
  return { conns: conns.data, incoming: incoming.data, outgoing: outgoing.data, suggestions: sugg.data };
}
async function sendInvite(targetId) { return api.post('/connections/requests', { target_id: targetId }); }
async function accept(reqId)        { return api.post('/connections/requests/' + reqId + '/accept'); }
```
[VERIFIED: app.js exposes api.get/post/delete with Bearer auto-attach; profile.html already uses this exact pattern.]

### profile.html status badge + action (extend the existing badge block)
The badge block already exists (profile.html lines 88-99) and renders `connection_status`. Extend it to map the live values to VN labels + a context action button: `none`→"Kết nối" (POST request), `pending_outgoing`→"Huỷ lời mời" (DELETE request), `pending_incoming`→"Chấp nhận"/"Từ chối", `connected`→"Đã kết nối" (+ remove), `self`→hide. The viewer's own id resolves via `/api/me` (already done in `profilePage().load()`). [VERIFIED: profile.html badge block + load() resolve-myId pattern read in full]

## State of the Art

| Old Approach (Phase 2) | Current Approach (Phase 3) | When Changed | Impact |
|------------------------|----------------------------|--------------|--------|
| connection-service = `/health` stub; `/connections/status` 404s → `/full` degrades to `connection_status:"none"` + `meta.degraded` | connection-service implements `GET /connections/status` returning real status → `/full` lights up, degrade clears | Phase 3 | The headline payoff. ZERO gateway rework (D-05) — verified `ConnectionClient.statusForAsync` + `AggregateController` already consume it. |
| `proconnect_connection` schema empty (no business tables) | `connections` table added via idempotent live migration | Phase 3 | Live-DB migration required (Pitfall 1). |
| Gateway invariants only on posts/comments (retired) | Gateway invariant on the graph (self/404/409 + 503-on-incomplete) | Phase 3 | Re-introduces the brownfield `createComment`/`delete` pattern for the grading centerpiece. |

**Deprecated/outdated:** none. The stack is pinned; Phase 3 only extends.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Phase 3 status values use D-05's exact strings (`none/pending_outgoing/pending_incoming/connected/self`) and `{data:{status}}` shape, and `/full` will light up unchanged | Pattern 3, Pitfall 2 | LOW — verified `AggregateController` reads `data.status` and treats any string as the value; D-05 locks the value set. The only failure mode is a wrong JSON key, which the smoke test catches. |
| A2 | The live `proconnect_connection` schema currently has NO `connections` table | Runtime State Inventory | LOW — verified Phase 1 created only empty stub schemas (00-init.sh + D-09 Phase 1 "no business tables"); connection-service routes are `/health` only. Mitigation: `CREATE TABLE IF NOT EXISTS` is safe even if it somehow exists. Confirm with `SHOW TABLES` on VPS during deploy. |
| A3 | connection_svc's `GRANT ALL ON proconnect_connection.*` covers the new `connections` table automatically | Runtime State Inventory | LOW — schema-wide grant verified in 00-init.sh + migrate-phase1.sql.tmpl; `.*` covers later-created tables. |
| A4 | New connection-service `.php` files ship to VPS via `docker compose build` (image rebuild) with no manual step | Runtime State Inventory | LOW — deploy.sh runs `docker compose build --pull`; service builds from `./services/connection-service`. |
| A5 | `/users` (no ids) returning ≤100 users is an acceptable candidate universe for suggestions in a 5-account demo | Pitfall 6, Open Q1 | LOW/PRODUCT — fine for the demo; would not scale, but suggestions are explicitly "keep simple, no ranking" (D-07). Flag the approach at plan review. |
| A6 | A single `connections` DELETE endpoint scoped to "caller is requester OR addressee for pending" can serve both reject and cancel | Pattern 4 | LOW — design choice (Claude's discretion per CONTEXT). Distinct gateway routes keep intent legible regardless. |

## Open Questions

1. **Suggestions candidate universe (CONN-06).** connection-service (DB-per-service) cannot list all users — they live in `proconnect_profile`. 
   - What we know: connection-service can compute the viewer's edge set (excluded ids); profile-service `GET /users` returns up to 100 users (verified).
   - What's unclear: where the candidate list comes from. Options: (a) gateway calls `profile-service GET /users`, gets the viewer's edge set from connection-service, subtracts + caps + enriches (gateway-side composition — fits the showcase); (b) gateway passes profile-service's user ids to a connection-service `suggestions?candidates=...&user=` endpoint that returns the un-edged subset, then gateway enriches.
   - Recommendation: **(a)** — pure gateway composition, no new cross-DB knowledge in connection-service, best showcases the gateway. connection-service exposes a small `GET /connections?user=` (edge ids) the gateway already needs for lists. Confirm at `/codex-plan-review`.

2. **One delete endpoint vs two (reject/cancel/remove).** D-03 makes reject and cancel both DELETEs (pending), and remove a DELETE (accepted). 
   - Recommendation: keep THREE distinct gateway routes (legible intent) mapping to connection-service deletes scoped by caller role + status. Internal connection-service can collapse to one or two handlers. Claude's discretion (CONTEXT). 

3. **headline in connection cards (D-06).** `/users?ids=` does not currently return `headline`.
   - Recommendation: add `headline` to `UserController::index` public SELECT (one line, public-safe) IF cards want it; otherwise display_name+avatar suffices. Flag at plan review.

## Environment Availability

| Dependency | Required By | Available (local) | Version | Fallback |
|------------|------------|-------------------|---------|----------|
| Docker / docker compose | Runtime + smoke tests | ✗ (Docker NOT available locally) | — | Static verify locally (`php -l`, review); runtime verification on VPS after deploy (Phase 1/2 method). |
| PHP CLI (`php -l` lint) | CI lint gate | likely ✓ on CI | 8.2 | CI runs `php -l` over all `*.php`. |
| MariaDB 10.11 | Migration + queries | ✗ locally (container on VPS) | 10.11 | Apply/verify on VPS via `docker compose exec mariadb mysql`. |
| envsubst (gettext-base) | Phase-1 migration only | n/a | — | Phase 3 migration needs NO envsubst (plain `.sql`). |
| curl + bash | smoke-phase3.sh | ✓ | — | — |

**Missing dependencies with no fallback:** none — Docker-local absence handled by VPS-runtime verification (Phase 1/2 precedent).
**Missing dependencies with fallback:** Docker (→ verify on VPS); MariaDB (→ on VPS).

## Validation Architecture

`workflow.nyquist_validation = true` (config.json verified) → section included. Phase 1/2 established bash/curl smoke tests, NO PHPUnit; Phase 3 stays consistent (CLAUDE.md: keep simple, no new heavy deps).

### Test Framework
| Property | Value |
|----------|-------|
| Framework | bash + curl smoke test (no PHP test runner — consistent with Phase 1/2) [VERIFIED: STACK.md "None detected"; smoke-phase1/2.sh] |
| Config file | none — `scripts/smoke-phase2.sh` is the template (clone its `pass`/`fail`/`FAILURES`/`GW`/`trap restore EXIT` scaffolding) |
| Quick run command | `bash scripts/smoke-phase3.sh` (against running stack: VPS, or local if Docker) |
| Full suite command | `bash scripts/smoke-phase1.sh && bash scripts/smoke-phase2.sh && bash scripts/smoke-phase3.sh` (no regressions) |
| Static gate (local, always) | `php -l` over changed `*.php` (CI lint job mirrors this) |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command / Assertion | File Exists? |
|--------|----------|-----------|-------------------------------|--------------|
| CONN-07 (invariant) | invite missing user → 404 | smoke | `POST /api/connections/requests {target_id:999999}` → 404 PROFILE_NOT_FOUND | ❌ Wave 0 (smoke-phase3.sh) |
| CONN-07 | self-invite → 400 | smoke | `POST .../requests {target_id: <self>}` → 400 | ❌ Wave 0 |
| CONN-07 | duplicate/existing edge → 409 | smoke | invite twice (or invite an already-connected user) → second is 409 | ❌ Wave 0 |
| CONN-01 | send invite | smoke | login demo → `POST .../requests {target_id:4}` → 201/200; appears in demo's outgoing | ❌ Wave 0 |
| CONN-02 | accept → both connected | smoke | addressee accepts → `/connections/status/{requester}` = connected for both sides | ❌ Wave 0 |
| CONN-02 | reject/cancel → gone | smoke | reject (addressee) / cancel (requester) → request no longer in pending lists | ❌ Wave 0 |
| CONN-04 | incoming/outgoing lists | smoke | demo→X pending: X incoming contains demo; demo outgoing contains X | ❌ Wave 0 |
| CONN-03 | connections list enriched | smoke | `GET /api/connections` contains the connected user's display_name; NO `@`/email | ❌ Wave 0 |
| CONN-06 | suggestions exclude connected | smoke | `GET /api/connections/suggestions` does NOT include an already-connected user or self | ❌ Wave 0 |
| CONN-05 | correct status values | smoke | `/connections/status/{id}` returns each of none/pending_outgoing/pending_incoming/connected appropriately | ❌ Wave 0 |
| **PAYOFF (D-05)** | `/full` shows real connection_status | smoke | as duyet (token), `GET /api/profiles/3/full` → `"connection_status":"connected"` (NOT "none", NOT null) AND NO `meta.degraded` for the connection part | ❌ Wave 0 |
| Security | no email leak in connection/suggestion output | smoke | `/api/connections` + `/suggestions` bodies MUST NOT contain `@`/`email` | ❌ Wave 0 |
| Ownership | non-addressee cannot accept | smoke | user C accepts duyet's incoming invite → 404/403 | ❌ Wave 0 |
| Regression | Phase 1 + 2 still green | smoke | `bash scripts/smoke-phase1.sh && bash scripts/smoke-phase2.sh` pass | ✅ exist |

### Sampling Rate
- **Per task commit:** `php -l` on changed files + `/codex-impl-review` (mandatory CLAUDE.md gate before commit).
- **Per wave merge / phase gate:** `bash scripts/smoke-phase1.sh && smoke-phase2.sh && smoke-phase3.sh` green on the VPS-deployed stack.
- **Phase gate:** full smoke green before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `scripts/smoke-phase3.sh` — clone smoke-phase2.sh structure; cover CONN-01..07 + the `/full` payoff + email-leak + ownership; NON-DESTRUCTIVE (clean up created edges via DELETE; restore demo seed). The demo seed (duyet↔long accepted, demo→duyet pending) gives stable fixtures; smoke should not delete THOSE (or must recreate them).
- [ ] Confirm `db/03-migrate-phase3.sql` applied (deploy step after 7b) before any connection smoke runs.
- [ ] No framework install needed (bash/curl present).

## Security Domain

`security_enforcement` not explicitly false → included. Demo project, internal-network trust model — keep proportionate.

### Applicable ASVS Categories
| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | JWT HS256 at gateway (`firebase/php-jwt`), unchanged; all connection mutations + my-lists require `JwtAuthMiddleware`. [VERIFIED] |
| V3 Session Management | partial | Stateless JWT, 24h TTL. No change. |
| V4 Access Control | **yes (key)** | Owner-scoped graph mutations by `X-User-Id` (D-09): accept→addressee, cancel→requester, remove→either side; uniform 404 on mismatch (no oracle). Gateway is the only JWT-aware component; connection-service trusts the header + network isolation. [VERIFIED: ProfileController scoped-mutation doctrine + ARCHITECTURE.md trust model] |
| V5 Input Validation | yes | connection-service validates target/addressee ids (int > 0, not self); gateway validates `target_id`. VN `VALIDATION_FAILED`. [VERIFIED pattern] |
| V6 Cryptography | no new | No new crypto/secrets. |
| V7 Data protection | yes | Connection/suggestion cards MUST NOT leak `email` (`/users?ids=` SELECTs it). Allowlist to basics. [VERIFIED: UserController::index] |

### Known Threat Patterns for PHP/Slim/MariaDB + Gateway graph
| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| IDOR (accept/cancel another user's invite) | Elevation/Tampering | Scope every mutation by `X-User-Id` in the WHERE (addressee/requester); uniform 404. Never trust body ids (D-09). [VERIFIED doctrine] |
| SQL injection | Tampering | PDO prepared statements, `EMULATE_PREPARES=false`. Never interpolate. [VERIFIED: connection-service Db.php] |
| PII leak in cards | Information disclosure | Allowlist enrichment (drop email). [VERIFIED] |
| Duplicate/self edges | Tampering / integrity | Gateway 400 (self) + 409 (existing) check AND `UNIQUE(requester,addressee)` backstop (23000→409). [VERIFIED] |
| Spoofing X-User-Id | Spoofing | Network isolation (connection-service has no host port) + only gateway sets `X-User-Id`. Unchanged. [VERIFIED: docker-compose — connection-service has no `ports:`] |
| Acting on incomplete cross-service info | DoS / integrity | 503-on-incomplete (mirror `delete()`'s COMMENT_SERVICE_UNAVAILABLE): refuse to write the edge if the existence/status check failed. [VERIFIED: PostsController::delete] |

## Sources

### Primary (HIGH confidence — read directly this session)
- `git show 2f6ecf8:gateway/src/Controllers/PostsController.php` — the canonical cross-service invariant (createComment 404 + delete 409 + 503-on-incomplete + fetchAuthors enrich). THE spec for the Phase 3 gateway invariant.
- `gateway/src/Services/ConnectionClient.php` (has `statusForAsync` calling `GET /connections/status?viewer=&target=` — D-05 contract), `ProfileClient.php` (`batch()` `?ids=` + sync/async pairs + X-User-Id injection), `HttpClient.php`.
- `gateway/src/Controllers/AggregateController.php` (reads `data.status`, degrade), `ProfilesController.php` (allowlist + passthrough), `gateway/src/routes.php`, `gateway/public/index.php` (DI closures), `Middleware/JwtAuthMiddleware.php` + `OptionalJwtMiddleware.php`.
- `services/connection-service/*` — `routes.php` (/health only), `public/index.php` (manual wiring), `Db.php` (proconnect_connection PDO singleton), `Json.php`, `DomainError.php`, `JsonErrorHandler.php`, `HealthController.php`, `composer.json`/`.lock`.
- `services/profile-service/src/Controllers/ProfileController.php` (raw-PDO owner-scoped CRUD, 23000→409, scoped-SELECT existence, uniform 404 doctrine) + `UserController.php` (`index` SELECTs email; `full` public projection; `verifyCredentials` double-bind named-param note) + `routes.php` + `Db.php`.
- `db/00-init.sh` (5 DBs + scoped grants; connection_svc GRANT ALL on proconnect_connection), `db/01-schema-profile.sql`, `db/02-migrate-phase2.sql` (idempotent live-migration + guarded seed template), `db/migrate-phase1.sql.tmpl` (envsubst rationale).
- `scripts/deploy.sh` (step 7b phase-2 migration wiring — where step 7c phase-3 slots in), `scripts/smoke-phase2.sh` (smoke template).
- `web/profile.html` (status badge block + `profilePage().load()` resolve-myId), `web/assets/app.js` (api/auth/navbar/loadFull).
- `docker-compose.yml` (connection-service wired: env, depends_on service_healthy, no host port; CONNECTION_SERVICE_URL injected into gateway).
- `.planning/REQUIREMENTS.md` (CONN-01..07), `.planning/codebase/ARCHITECTURE.md` (invariant + trust model + database-per-service), both Phase 2 docs, `.planning/config.json` (nyquist_validation true), CLAUDE.md (review gates, keep-simple, VN, no heavy deps).

### Secondary (MEDIUM)
- `.planning/codebase/STACK.md` — version pins (read the lockfiles).

### Tertiary (LOW / to confirm at impl)
- VPS `proconnect_connection` has no `connections` table yet (A2) — confirm `SHOW TABLES` during deploy; `CREATE IF NOT EXISTS` is safe regardless.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new deps; versions from committed lockfiles; connection-service composer.json read.
- Invariant pattern: HIGH — recovered verbatim from git `2f6ecf8` PostsController; every gateway integration point read in source.
- connection-service build: HIGH — scaffold confirmed identical to profile-service; PDO patterns lifted from ProfileController read in full.
- The D-05 `/full` payoff: HIGH — verified `ConnectionClient.statusForAsync` issues the path and `AggregateController` consumes `data.status`; only the response-key shape must match (Pitfall 2).
- Live migration: HIGH — clones the shipped Phase-2 `02-migrate-phase2.sql` + deploy.sh step 7b.
- Suggestions candidate universe: MEDIUM — under-specified by CONN-06; resolved with a recommendation (Open Q1), needs plan-review confirmation.

**Research date:** 2026-06-07
**Valid until:** ~2026-07-07 (stable; the only volatile item is the live `proconnect_connection` schema state, which Phase 3 itself changes — re-verify VPS `SHOW TABLES` if implementation is delayed).

## RESEARCH COMPLETE

**Phase:** 03 - Kết nối / Social Graph
**Confidence:** HIGH

### Key Findings
- The gateway invariant (the grading centerpiece) is the git-history `PostsController::createComment`/`delete` re-targeted: self→400, `ProfileClient::get` existence→404, edge-check→409 (reuse the SAME status computation), 503-on-incomplete-info, then write. The `UNIQUE(requester_id, addressee_id)` is the duplicate backstop (23000→409, mirroring `addSkill`). Both layers are correct design, not redundancy. [VERIFIED in git source]
- **The headline payoff is real and verified:** `ConnectionClient::statusForAsync` already calls `GET /connections/status?viewer=&target=` and `AggregateController` already reads `data.status` + degrades. Implementing that ONE internal route returning `{"data":{"status":"..."}}` lights up Phase 2 `/full` `connection_status` with ZERO gateway rework (D-05). The only failure mode is a wrong JSON key (Pitfall 2) — the smoke test guards it.
- connection-service is a `/health`-only stub whose scaffold (Slim 4, manual wiring, raw-PDO `Db.php` on `proconnect_connection`, App\Json/DomainError/JsonErrorHandler) is byte-for-byte profile-service's shape. Build `ConnectionController` by cloning `ProfileController`'s PDO patterns: scoped `WHERE ... AND addressee_id/requester_id=:caller`, uniform 404, scoped-SELECT existence (not rowCount on UPDATE), 23000→409.
- Status is computed from a SINGLE directed row via one OR query (either direction); the result is viewer-relative (`self/none/connected/pending_outgoing/pending_incoming`). Centralize it once and reuse for `/full`, the invariant's 409 check, and the gateway status route.
- Live migration is a clone of `db/02-migrate-phase2.sql` + deploy.sh step "7b": idempotent `CREATE TABLE IF NOT EXISTS connections` in `proconnect_connection` + guarded demo seed (duyet↔long accepted, demo→duyet pending), plain `.sql` (no envsubst), wired BLOCKING after the phase-2 step. The `connection_svc` grant is already schema-wide. No new container/secret/grant.
- Two real gotchas to plan around: (1) email leak in connection/suggestion cards — `/users?ids=` SELECTs email; allowlist to basics. (2) Suggestions candidate universe — connection-service can't list all users (DB-per-service); gateway composes the candidate list from profile-service `/users` (Open Q1).

### File Created
`/Users/theduyet/Documents/Code/SOA/soa-blog/.planning/phases/03-k-t-n-i-social-graph/03-RESEARCH.md`

### Confidence Assessment
| Area | Level | Reason |
|------|-------|--------|
| Standard Stack | HIGH | No new deps; lockfiles + composer.json read |
| Invariant / composition | HIGH | Recovered verbatim from git; all gateway integration points read |
| connection-service build | HIGH | Scaffold confirmed; PDO patterns from ProfileController read in full |
| D-05 /full payoff | HIGH | statusForAsync + AggregateController data.status consumption verified |
| Live migration | HIGH | Clones shipped Phase-2 migration + deploy.sh wiring |
| Suggestions universe | MEDIUM | CONN-06 under-specified; recommendation pending plan-review |

### Open Questions
1. Suggestions candidate universe (connection-service can't list all users) — RECOMMENDED gateway-composes from profile-service `/users` (Open Q1). Confirm at `/codex-plan-review`.
2. One delete endpoint vs three (reject/cancel/remove) — Claude's discretion; recommend distinct gateway routes for legible intent.
3. `headline` in connection cards — `/users?ids=` doesn't return it; add to public SELECT if wanted (one line).

### Ready for Planning
Research complete. Planner can create PLAN.md files. Remember the mandatory CLAUDE.md gates: `/codex-plan-review` before coding, `/codex-impl-review` before commit.
