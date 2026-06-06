# Phase 5: Tìm kiếm & Thông báo - Research

**Researched:** 2026-06-07
**Domain:** Building the final two stub services (search-service + notification-service) over the established raw-PDO + database-per-service doctrine; gateway-composed user search (LIKE results enriched with viewer-relative relationship status + quick-connect cards, email-allowlisted) and gateway-coordinated polling notifications (best-effort fire after invite/reaction/comment, list + unread_count + mark-read, actor enrichment); idempotent non-destructive live migration wired into deploy.sh; minimal Alpine/Tailwind search box + notification bell.
**Confidence:** HIGH — every claim is verified against actual source read this session: both stub services (Db/Json/routes/index), the gateway clients (Search/Notification/Profile/Connection), the controllers to modify (ConnectionsController.sendRequest, FeedController.react/addComment/listComments), the data sources (profile-service UserController + ProfileController, feed-service PostController + CommentController, connection-service ConnectionController), the infra (db/00-init.sh, db/04-migrate-phase4.sql, scripts/deploy.sh, docker-compose env), the demo seed (migrate-phase1 users + phase2 headline/skills), the UI wrapper (web/assets/app.js), and gateway routes.php + public/index.php DI.

## Summary

Phase 5 is the cheapest-infrastructure phase yet: BOTH new services already exist as `/health` stubs whose scaffold (Slim 4 manual-wire `public/index.php`, raw-PDO `Db.php` pointed at `proconnect_search` / `proconnect_notification`, `App\Json`/`App\DomainError`/`App\JsonErrorHandler`) is byte-for-byte the connection/feed service shape. Their databases (`proconnect_search`, `proconnect_notification`) and `*_svc` users with `GRANT ALL` already exist from Phase 1 (`db/00-init.sh`), and the gateway already has `SearchClient`/`NotificationClient` stubs registered as DI singletons plus the `SEARCH_SERVICE_URL`/`NOTIFICATION_SERVICE_URL` env wired in `docker-compose.yml`. There is NO new container, NO new secret, NO new grant. [VERIFIED]

The two features map onto two patterns already proven in Phase 3/4. **Search** is the connection-suggestions pattern inverted: search-service owns a denormalized `search_index` table and does a parameterized case-insensitive LIKE; the gateway composes each hit with the viewer's relationship status via `ConnectionClient::statusFor` (the same per-pair status route used by the sendRequest invariant) and an email-dropping allowlist — this is a second API-composition showcase (search hit + relationship status). **Notifications** are pure gateway orchestration: notification-service is a recipient-scoped CRUD over a `notifications` table; the gateway centrally fires a best-effort `NotificationClient::create` AFTER each successful invite/reaction/comment, swallowing any failure so the main action never breaks — the single coordination point that reinforces the Gateway pattern (no service-to-service eventing).

Two concrete gotchas dominate the plan. (1) **Reindex data source:** profile-service `GET /users` (the index list) returns ONLY `id, username, email, display_name, avatar_url, created_at` — it has NO headline and NO skills. The denormalized index needs headline + `skills_text`, which only `GET /users/{id}/full` exposes (it returns `headline`, `location`, and a `skills` array). So reindex is: `ProfileClient::allUsers()` for the id universe, then one `getFull(id)` per user to gather headline + skills → flatten skills to `skills_text` → upsert into search-service. Bounded N+1 over ~5-100 demo users in a one-shot admin action is acceptable. (2) **Notification recipient for reaction/comment:** the existing `FeedController::react`/`addComment` are thin passthroughs, and feed-service's `react` returns only `{post_id,type}` while `addComment` returns the *commenter's* `author_id` — NEITHER gives the post's author (the recipient). The gateway must look up the post author via `FeedClient::getPost(id)` to know whom to notify. For invites the recipient is trivial: `sendRequest` already holds `$target` (the addressee).

**Primary recommendation:** Build search-service `SearchController` (one `upsert` + one `reindex`-replace + one LIKE `search`) and notification-service `NotificationController` (create + list-with-unread-count + mark-one + mark-all), both cloning the connection-service raw-PDO/X-User-Id doctrine. Extend `SearchClient` (search/upsert/reindexReplace) and `NotificationClient` (create/list/markRead/markAllRead). Add a gateway `SearchController` (compose status + allowlist) and a gateway `NotificationsController` (list + actor enrich + mark-read; reindex trigger). Inject best-effort `notify()` calls into `ConnectionsController::sendRequest` (after the write, recipient = target), `FeedController::react` and `FeedController::addComment` (after the upstream 2xx, recipient = `getPost(id).author_id`, skip self, try/catch GuzzleException swallow). Ship `db/05-migrate-phase5.sql` (clone `04`, idempotent, seeds the 5 demo index rows + a couple demo notifications for duyet) wired into deploy.sh after the phase-4 step. Smoke via `scripts/smoke-phase5.sh` cloned from `smoke-phase4.sh` (NON-DESTRUCTIVE pre-clean discipline). All Vietnamese, no new deps, no WebSocket.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**A. Search (SEARCH-01/02, gateway-composed)**
- **D-01:** search-service owns `proconnect_search.search_index(user_id BIGINT PK, username VARCHAR(64), display_name VARCHAR(128), headline VARCHAR(160) NULL, location VARCHAR(128) NULL, skills_text TEXT NULL)` — denormalized copy for LIKE search. (DB-per-service: search-service cannot read profile-service; it keeps its own index.)
- **D-02:** Index populated by `POST /api/search/reindex` (gateway pulls profile-service `/users` + each user's skills, upserts into the index) + the migration seeds the 5 demo users' index rows. **Honest tradeoff (no event bus):** the index is eventually-consistent — a profile edit isn't reflected until reindex. Documented; reindex refreshes; acceptable for the demo.
- **D-03:** `GET /api/search?q=` → search-service LIKE on display_name/username/headline/skills_text (case-insensitive, cap results) → **gateway composes** each hit with the viewer's relationship status (connection-service statusFor) → returns quick-connect-ready cards `{id, username, display_name, headline, avatar_url, connection_status}` (email ALLOWLIST — no email). Degrade-safe. This is the SEARCH composition showcase (search + relationship status).

**B. Notifications (NOTIF-01/02/03, gateway-coordinated)**
- **D-04:** notification-service owns `proconnect_notification.notifications(id, user_id BIGINT [recipient], type ENUM('invite','reaction','comment'), actor_id BIGINT, ref_id BIGINT NULL [post/request id], created_at, read_at TIMESTAMP NULL, INDEX(user_id,read_at), INDEX(user_id,created_at))`.
- **D-05:** **Creation = GATEWAY orchestrates centrally** (ROADMAP SC#4): after a successful connection invite (ConnectionsController.sendRequest) → notify addressee (type 'invite'); after reaction (FeedController.react) → notify post author (type 'reaction'); after comment (FeedController.addComment) → notify post author (type 'comment'). Skip self-notify (actor==recipient). **Best-effort**: a notification-create failure NEVER fails the main action (try/catch GuzzleException, swallow) — degrade silently. The gateway is the single coordination point (no service-to-service eventing).
- **D-06:** `GET /api/notifications` → recipient's notifications newest-first + `unread_count`; gateway enriches `actor` (profile basics, allowlist). `POST /api/notifications/read-all` (mark all read) + `POST /api/notifications/{id}/read`. Frontend POLLS every ~15s for the unread badge.
- **D-07:** notification-service trusts X-User-Id (NO JWT/host port), all reads/writes scoped to recipient user_id, raw-PDO, Vietnamese.

**C. Infra**
- **D-08:** Idempotent NON-destructive `db/05-migrate-phase5.sql` (CREATE TABLE IF NOT EXISTS search_index in proconnect_search + notifications in proconnect_notification + seed: index rows for the 5 demo users + a couple demo notifications for duyet) wired BLOCKING into deploy.sh after phase-4 migrate; fresh-volume schema files. proconnect_search/notification + their *_svc users already provisioned (Phase 1).
- **D-09:** Both services clone the connection/feed PDO doctrine (X-User-Id scoped, uniform 404, Vietnamese DomainError). No new container/secret/grant.

**D. UI**
- **D-10:** Search box (navbar input → results list or search.html) with quick-connect buttons (reuse connection actions). Notification bell with unread badge (polls /api/notifications every ~15s), dropdown/list with mark-read + mark-all-read. Alpine+Tailwind CDN, Vietnamese, minimal (NO branding/3-cột — Phase 6). x-text.

### Claude's Discretion
- Search result cap/ordering, LIKE vs FULLTEXT (LIKE keep-simple), reindex auth (any logged-in user or admin), poll interval, notification message phrasing, where the search box lives (navbar vs page).

### Deferred Ideas (OUT OF SCOPE)
- WebSocket/real push (polling only); branding/3-cột (Phase 6); full-text search engine; event-bus-driven index freshness (manual/triggered reindex instead); notification for repost.
</user_constraints>

## Project Constraints (from CLAUDE.md)

Same authority as locked decisions. The planner must not propose anything contradicting them.

- **Stack locked:** PHP 8.2 + Slim 4 + Guzzle + firebase/php-jwt v7 + MariaDB 10.11 + Docker Compose. Frontend HTML + Alpine.js + Tailwind CDN, no heavy build step. Do NOT add new heavy dependencies. [CITED: CLAUDE.md Constraints]
- **2GB RAM shared VPS, ≤ ~9-10 containers.** Phase 5 adds NO new container — search-service + notification-service already exist in `docker-compose.yml` (built, health-only) with their `*_SERVICE_URL` injected into the gateway. [VERIFIED: docker-compose.yml lines 69-88 services + 102-103 URLs + 112-113 gateway depends_on]
- **All UI + content + error messages in Vietnamese with diacritics.** [CITED: CLAUDE.md]
- **API Gateway pattern must not be blurred** — core grading criterion. Search composition (hit + relationship status) and gateway-coordinated notifications are BOTH Gateway-pattern showcases; keep them legible. [CITED: CLAUDE.md Core Value + CONTEXT "Specific Ideas"]
- **Keep SIMPLE** — the team must present the code. Favour existing patterns over cleverness. [CITED: CONTEXT + CLAUDE.md]
- **MANDATORY review workflow:** after plan approval, run `/codex-plan-review` and start coding only after Codex APPROVES the plan. Before any commit, run `/codex-impl-review` and commit only after Codex APPROVES. Fix valid issues and re-review. The planner MUST bake these two gates into the phase plan as explicit steps. [CITED: ~/.claude/CLAUDE.md + project CLAUDE.md + CONTEXT "Specific Ideas"]
- **Deploy flow:** local git → review → push public GitHub `main` → GitHub Actions → VPS `git pull --ff-only` → `scripts/deploy.sh` → smoke. Docker NOT available locally — runtime verification is on the VPS. [CITED: CLAUDE.md + MEMORY]

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| SEARCH-01 | Tìm người dùng theo tên/username/chức danh/kỹ năng | search-service `SearchController::search` — parameterized case-insensitive LIKE over `display_name`/`username`/`headline`/`skills_text` in `proconnect_search.search_index` (D-01/D-03). Gateway `GET /api/search?q=` → `SearchClient::search($q)`. See Pattern 1 + Pattern 3. |
| SEARCH-02 | Kết quả kèm trạng thái quan hệ + nút kết nối nhanh | Gateway composition: for each hit `ConnectionClient::statusFor($me, $hitId)` → `connection_status` field; email-allowlisted quick-connect card `{id,username,display_name,headline,avatar_url,connection_status}`. Reuse `web/connections.html` connect actions (`POST /api/connections/requests`). See Pattern 2 + Pattern 7. |
| NOTIF-01 | Tạo thông báo khi có invite/reaction/comment mới | Gateway-coordinated best-effort `notify()` injected AFTER the successful write in `ConnectionsController::sendRequest` (recipient=target, type 'invite'), `FeedController::react` (recipient=post author via `getPost`, type 'reaction'), `FeedController::addComment` (recipient=post author, type 'comment'). Skip self; try/catch GuzzleException swallow (D-05). See Pattern 4 + Pattern 5. |
| NOTIF-02 | Danh sách thông báo + đếm chưa đọc (badge) | notification-service `NotificationController::index` returns recipient-scoped rows newest-first + `unread_count` in meta; gateway `GET /api/notifications` enriches `actor` via `ProfileClient::batch` (allowlist). Frontend polls every ~15s. See Pattern 6 + Pattern 8. |
| NOTIF-03 | Đánh dấu đã đọc (một / tất cả) | notification-service `markRead` (scoped `UPDATE ... SET read_at=NOW() WHERE id=:id AND user_id=:caller AND read_at IS NULL`) + `markAllRead` (scoped UPDATE all). Gateway `POST /api/notifications/{id}/read` + `POST /api/notifications/read-all`. See Pattern 6. |
</phase_requirements>

## Standard Stack

No new dependencies. Both services' `composer.json` are dependency-minimal and identical to connection/feed-service (`slim/slim`, `slim/psr7`, `monolog/monolog`, `ext-mbstring`, `ext-pdo` — NO ORM, NO Guzzle, NO JWT). [VERIFIED: both services have committed composer.json + composer.lock; stubs mirror connection-service exactly]

### Core (verified from committed source + STACK.md — NOT training data)
| Library | Version (locked) | Purpose | Why Standard |
|---------|------------------|---------|--------------|
| `slim/slim` | 4.15.1 | HTTP framework, gateway + both services | [VERIFIED: STACK.md] Already the framework. |
| `slim/psr7` | 1.8.0 | PSR-7 messages | [VERIFIED: STACK.md] |
| `php-di/php-di` | 7.1.1 | DI container, **gateway only** | [VERIFIED: gateway/public/index.php uses `DI\Container`; both services wire MANUALLY in `public/index.php` — AppFactory + addBodyParsingMiddleware + JsonErrorHandler, no container] |
| `guzzlehttp/guzzle` | 7.10.0 | Gateway → service HTTP | [VERIFIED: gateway HttpClient.php] |
| `guzzlehttp/promises` | 2.3.0 | `Utils::settle` parallel composition (search status fan-out, optional) | [VERIFIED: AggregateController/FeedController use `GuzzleHttp\Promise\Utils`] |
| `firebase/php-jwt` | v7.0.5 | JWT HS256, **gateway only** | [VERIFIED: D-07 both services trust X-User-Id, no JWT dep] |
| `ext-pdo` / `ext-pdo_mysql` | bundled (php:8.2-fpm-alpine) | Raw PDO | [VERIFIED: search-service Db.php + notification-service Db.php are real PDO singletons, EMULATE_PREPARES=false, pointed at proconnect_search / proconnect_notification] |

### Frontend (CDN, no build)
| Asset | Source | Purpose |
|-------|--------|---------|
| Tailwind | `https://cdn.tailwindcss.com` (Play CDN) | Styling | [VERIFIED: web/* pages] |
| Alpine.js 3.x | `https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js` | Reactivity | [VERIFIED: web/* pages] |
| `web/assets/app.js` | local classic script | `window.api` (get/post/patch/delete, Bearer auto-attach, returns `{ok,status,data,meta}`; 204 → `{data:null}`; non-2xx throws `err` with `.status`/`.code`), `window.auth`, `window.navbar`, `window.formatDate` | [VERIFIED: read in full — `api.post(p,body)` JSON-encodes; `api.get/post/patch/delete` only] |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Per-user `getFull(id)` to gather headline+skills for reindex | A new profile-service `/users?fields=...` or `/users/{id}/skills` endpoint | RECOMMENDED: reuse the EXISTING `GET /users/{id}/full` (returns headline, location, skills[]) — NO profile-service change. The list `GET /users` lacks headline/skills (VERIFIED below), so a per-user full fetch is required regardless. A new endpoint is scope creep for a one-shot reindex over ~5 demo users. [VERIFIED: UserController::index SELECT omits headline/skills; UserController::full includes them] |
| Search-service does its own status | Gateway composes status per hit | RECOMMENDED: gateway composes (D-03). search-service is DB-per-service and cannot read connection-service; status MUST be a gateway join. This IS the SEARCH-02 showcase. [VERIFIED: DB-per-service doctrine + ConnectionClient::statusFor exists] |
| `LIKE` for search | MariaDB FULLTEXT index | RECOMMENDED: `LIKE '%q%'` (D-03 + Claude's discretion "LIKE keep-simple"). FULLTEXT needs min-token-length tuning and is overkill for a 5-user demo. Document the eventually-consistent/LIKE tradeoff. [CITED: D-03] |
| Gateway reaction/comment notify recipient from the upstream response | Look up post author via `FeedClient::getPost(id)` | RECOMMENDED: `getPost(id)` — feed-service `react` returns only `{post_id,type}` and `addComment` returns the *commenter's* author_id, NEITHER gives the post author (the recipient). The gateway already has `FeedClient::getPost`. One extra read inside the best-effort block (which itself never fails the action). See Pattern 5 + Pitfall 2. [VERIFIED: feed-service PostController::react returns `['post_id','type']`; CommentController::create returns the comment row] |
| `reindex` = upsert each row | `reindex` = TRUNCATE + re-insert (replace-all) inside a transaction | EITHER works. RECOMMENDED: gateway sends each user row to a `POST /search/index` upsert (`INSERT ... ON DUPLICATE KEY UPDATE`), keyed on `user_id` PK — idempotent, no destructive truncate, and degrade-safe per row. A bulk replace endpoint is also fine if the team prefers a clean rebuild; keep it transactional. Flag at plan review. [VERIFIED: user_id PK supports ON DUPLICATE KEY UPDATE] |

**Installation:** none. `composer install` already run; lockfiles committed; Dockerfiles copy `composer.json composer.lock`. [VERIFIED: both service trees include composer.lock + Dockerfile]

**Version verification:** Versions taken from `.planning/codebase/STACK.md` (read from committed lockfiles). Stack is pinned and reproducible; do NOT bump versions. No registry lookup needed.

## Architecture Patterns

### Project structure touched in Phase 5
```
db/
├── 01-schema-search.sql          # NEW (fresh-volume): CREATE TABLE search_index (mirrors DDL of the migration)
├── 01-schema-notification.sql    # NEW (fresh-volume): CREATE TABLE notifications
├── 05-migrate-phase5.sql         # NEW: idempotent live-volume migration (BOTH tables, in their respective DBs) + demo seed (clone db/04-migrate-phase4.sql)
└── 99-seed.sql                   # OPTIONAL: mirror demo seed for fresh volumes (guarded)

services/search-service/src/
├── routes.php                    # ADD /search, /index (upsert), /reindex-or-batch (currently /health only)
├── Controllers/SearchController.php   # NEW: LIKE search + upsert (clone connection-service PDO doctrine)
└── (Db.php / Json.php / DomainError.php / JsonErrorHandler.php / HealthController.php present — reuse as-is)

services/notification-service/src/
├── routes.php                    # ADD list/create/mark-read/mark-all (currently /health only)
├── Controllers/NotificationController.php   # NEW: recipient-scoped CRUD (clone connection-service doctrine)
└── (Db.php / Json.php / DomainError.php / JsonErrorHandler.php / HealthController.php present — reuse as-is)

gateway/src/
├── routes.php                    # ADD /api/search, /api/search/reindex, /api/notifications*, all JWT
├── public/index.php              # ADD DI: SearchController(SearchClient, ProfileClient, ConnectionClient) + NotificationsController(NotificationClient, ProfileClient)
├── Controllers/SearchController.php       # NEW: compose status + allowlist + reindex orchestration
├── Controllers/NotificationsController.php # NEW: list + actor enrich + mark-read passthrough
├── Services/SearchClient.php     # EXTEND: search($q,$limit) + upsert($row) + (optional) reindexReplace($rows)
└── Services/NotificationClient.php # EXTEND: create(...) + list($user) + markRead($user,$id) + markAllRead($user)

web/
├── search.html                   # NEW (D-10): search box + results list + quick-connect buttons (reuse connect action)
├── index.html / connections.html / feed.html  # EXTEND nav: search box + notification bell partial
└── assets/app.js                 # OPTIONAL: small notification-bell poll helper (api/auth already suffice)

scripts/
├── deploy.sh                     # EXTEND: apply db/05-migrate-phase5.sql right AFTER the phase-4 step (lines 146-154), against BOTH DBs
└── smoke-phase5.sh               # NEW: clone smoke-phase4.sh discipline (NON-DESTRUCTIVE pre-clean)
```

### Pattern 1: search-service LIKE search (SEARCH-01 / D-03)
**What:** A parameterized, case-insensitive LIKE over the denormalized `search_index`, capped. Returns matched index rows (id + denormalized fields); the gateway adds status + allowlist.
**Source:** connection-service `ConnectionController::listAccepted`/`suggestions` (raw-PDO list shape, `Json::list`), profile-service `UserController::index` (the `?ids=` parameterized-IN pattern), feed-service `PostController` (positional binding discipline with EMULATE_PREPARES=false).

```php
// services/search-service/src/Controllers/SearchController.php — NEW (clone connection-service doctrine).
public function search(Request $req, Response $res): Response
{
    $q = trim((string) ($req->getQueryParams()['q'] ?? ''));
    if ($q === '') {
        return Json::list($res, [], ['total' => 0, 'q' => '']);
    }
    if (mb_strlen($q) > 100) {
        throw new DomainError(400, 'VALIDATION_FAILED', 'Từ khoá tìm kiếm quá dài.');
    }
    $limit = min(50, max(1, (int) ($req->getQueryParams()['limit'] ?? 20)));

    // Case-insensitive LIKE. utf8mb4_unicode_ci collation already folds case, but
    // LOWER() on both sides makes the intent explicit and ASCII-safe. The same
    // :term value is needed 4x; native prepared statements (EMULATE_PREPARES=false)
    // cannot reuse a named placeholder, so bind 4 distinct names (mirrors
    // user-service verifyCredentials :uname/:email). LIKE wildcards are LITERAL
    // text in a bound value — no injection (Pitfall 4).
    $term = '%' . $q . '%';
    $sql =
        'SELECT user_id, username, display_name, headline, location, skills_text
           FROM search_index
          WHERE display_name LIKE :t1
             OR username     LIKE :t2
             OR headline     LIKE :t3
             OR skills_text  LIKE :t4
          ORDER BY display_name ASC
          LIMIT :lim';
    $stmt = Db::pdo()->prepare($sql);
    $stmt->bindValue(':t1', $term);
    $stmt->bindValue(':t2', $term);
    $stmt->bindValue(':t3', $term);
    $stmt->bindValue(':t4', $term);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);   // LIMIT must be PARAM_INT (Pitfall 3)
    $stmt->execute();

    $rows = array_map(static function (array $r): array {
        $r['user_id'] = (int) $r['user_id'];
        return $r;
    }, $stmt->fetchAll());

    return Json::list($res, $rows, ['total' => count($rows), 'q' => $q]);
}
```
**CRITICAL — `LIMIT :lim` binding:** with `EMULATE_PREPARES=false`, `LIMIT :lim` MUST be bound with `PDO::PARAM_INT` (default string binding quotes it → SQL syntax error). feed-service's `CommentController::index` already does exactly this (`bindValue(':lim', $perPage, PDO::PARAM_INT)`). [VERIFIED: CommentController::index lines 52-54]
**Case-insensitivity:** the tables are `utf8mb4_unicode_ci` (VERIFIED: db/00-init.sh + migrate-phase4 DDL), which is already case-folding for LIKE, so a bare `LIKE :t` is case-insensitive. `LOWER()` is optional belt-and-suspenders; either is fine (Claude's discretion).

### Pattern 2: The gateway search composition (SEARCH-02 — second showcase)
**What:** `GET /api/search?q=` → search-service LIKE → for each hit attach the viewer's `connection_status` (per-pair `ConnectionClient::statusFor`) + allowlist out email → quick-connect cards. Degrade-safe.
**Source:** `ConnectionsController::sendRequest` (uses `statusFor($me,$target)` and reads `decode(...)['data']['status']`), `ConnectionsController::enrich` + `FeedController::allowlist` (email-dropping `array_intersect_key`), `AggregateController` settle/decode.

```php
// gateway/src/Controllers/SearchController.php — NEW.
public function search(Request $req, Response $res): Response
{
    $me = $this->me($req);
    $q  = trim((string) ($req->getQueryParams()['q'] ?? ''));
    if ($q === '') {
        return Json::list($res, [], ['q' => '']);
    }

    $sr = $this->search->search($q, 20);
    if ($sr->getStatusCode() !== 200) {
        return Json::raw($res, $this->decode($sr), $sr->getStatusCode());
    }
    $hits = (array) ($this->decode($sr)['data'] ?? []);

    $degraded = [];
    $cards = [];
    foreach ($hits as $h) {
        $uid = (int) ($h['user_id'] ?? 0);
        if ($uid <= 0 || $uid === $me) {
            // Skip self in results OR keep with status 'self' — Claude's discretion.
            continue;
        }
        // Per-hit relationship status — the SEARCH-02 composition payoff. Each is the
        // SAME single status computation the sendRequest invariant uses. A status
        // failure degrades that card's status to 'unknown', NEVER 500 (Pitfall 5).
        $status = 'none';
        try {
            $st = $this->connections->statusFor($me, $uid);
            if ($st->getStatusCode() === 200) {
                $status = (string) ($this->decode($st)['data']['status'] ?? 'none');
            } else {
                $status = 'unknown';
                $degraded['status'] = true;
            }
        } catch (GuzzleException $e) {
            $status = 'unknown';
            $degraded['status'] = true;
        }

        // Allowlist — search_index has NO email column, but allowlist anyway as a
        // defense-in-depth contract (Pitfall 1). Quick-connect-ready card.
        $cards[] = [
            'id'                => $uid,
            'username'          => $h['username']     ?? null,
            'display_name'      => $h['display_name'] ?? null,
            'headline'          => $h['headline']     ?? null,
            'avatar_url'        => $h['avatar_url']    ?? null,   // see note below
            'connection_status' => $status,
        ];
    }

    $meta = ['q' => $q];
    if ($degraded !== []) {
        $meta['degraded'] = true;
        $meta['parts'] = array_keys($degraded);
    }
    return Json::list($res, $cards, $meta);
}
```
**N status calls vs batch (Open Q1):** `statusFor` is per-pair (`?viewer=&target=`); there is NO batch status endpoint. With a capped result set (≤20) this is N small internal calls — acceptable and consistent with the brownfield's per-pair model. If the team wants visible parallelism for the slide, wrap the N `statusForAsync` promises in ONE `Utils::settle([...])` (the async variant `ConnectionClient::statusForAsync` already exists) — RECOMMENDED for both latency and the "gateway fans out in parallel" demo. Flag the exact shape at plan review. [VERIFIED: ConnectionClient::statusFor + statusForAsync both exist; no batch status route in connection-service routes]
**`avatar_url` note (decision needed):** D-01's `search_index` does NOT include `avatar_url`, but D-03's card contract lists it. Two clean options: (a) ADD `avatar_url VARCHAR(512) NULL` to `search_index` and denormalize it during reindex (profile `GET /users` returns `avatar_url`); or (b) drop `avatar_url` from the card. RECOMMENDED: option (a) — it is one more denormalized column and `allUsers()` already carries `avatar_url`, giving quick-connect cards an avatar with no extra fetch. Flag at plan review (one-word DDL addition vs contract trim). [VERIFIED: D-01 column list omits avatar_url; ProfileClient::allUsers/UserController::index include avatar_url]

### Pattern 3: Reindex — the gateway pull (D-02)
**What:** `POST /api/search/reindex` → gateway pulls the user universe from profile-service, gathers each user's headline + skills, flattens skills to `skills_text`, upserts each into search-service. Eventually-consistent (documented).
**Source:** `ProfileClient::allUsers()` (returns decoded `data` list of `{id,username,display_name,avatar_url,...}` — NO headline/skills), `ProfileClient::getFull($id)` (`GET /users/{id}/full` returns headline, location, skills array), `ConnectionsController::suggestions` (the allUsers-as-universe pattern).

```php
// gateway/src/Controllers/SearchController.php — reindex orchestration.
public function reindex(Request $req, Response $res): Response
{
    $this->me($req);   // any logged-in user may trigger (Claude's discretion: or gate to admin)

    // 1) Universe of ids from profile-service GET /users (degrade-safe, returns [] on failure).
    $universe = $this->profiles->allUsers(100);   // [{id,username,display_name,avatar_url,...}]

    $indexed = 0;
    $failed  = 0;
    foreach ($universe as $u) {
        $uid = (int) ($u['id'] ?? 0);
        if ($uid <= 0) { continue; }

        // 2) headline + skills come ONLY from /users/{id}/full (GET /users lacks them — Pitfall 6).
        $headline = null; $location = null; $skillsText = null;
        try {
            $fr = $this->profiles->getFull($uid);
            if ($fr->getStatusCode() === 200) {
                $full = (array) ($this->decode($fr)['data'] ?? []);
                $headline = $full['headline'] ?? null;
                $location = $full['location'] ?? null;
                $skills   = array_map(static fn($s) => (string) ($s['name'] ?? ''), (array) ($full['skills'] ?? []));
                $skillsText = implode(', ', array_filter($skills)) ?: null;
            }
        } catch (GuzzleException $e) {
            // proceed with basic fields only — partial index beats no index.
        }

        // 3) Upsert into search-service (idempotent on user_id PK).
        try {
            $ir = $this->search->upsert([
                'user_id'      => $uid,
                'username'     => $u['username']     ?? '',
                'display_name' => $u['display_name'] ?? '',
                'avatar_url'   => $u['avatar_url']   ?? null,   // if Pattern-2 option (a)
                'headline'     => $headline,
                'location'     => $location,
                'skills_text'  => $skillsText,
            ]);
            $ir->getStatusCode() === 200 || $ir->getStatusCode() === 201 ? $indexed++ : $failed++;
        } catch (GuzzleException $e) {
            $failed++;
        }
    }

    return Json::raw($res, ['data' => ['indexed' => $indexed, 'failed' => $failed, 'total' => count($universe)]], 200);
}
```
**CRITICAL — `GET /users` has NO headline/skills:** profile-service `UserController::index` SELECTs `id, username, email, display_name, avatar_url, created_at` ONLY. `headline`/`location`/`skills` exist solely on `GET /users/{id}/full`. So reindex MUST do one `getFull(id)` per user to populate `headline` + `skills_text`. This is bounded N+1 over a one-shot admin reindex (~5 demo users) — acceptable. Do NOT assume `/users` carries headline. [VERIFIED: UserController::index lines 145-158 vs UserController::full lines 250-278]
**search-service upsert endpoint:** `POST /index` (or `PUT /index/{userId}`) → `INSERT INTO search_index (...) VALUES (...) ON DUPLICATE KEY UPDATE username=VALUES(username), display_name=VALUES(...), headline=VALUES(...), ...`. Keyed on `user_id` PK → idempotent, no race. The migration also seeds the 5 demo rows directly so search works pre-reindex. [VERIFIED: user_id PK in D-01 supports ON DUPLICATE KEY UPDATE]

### Pattern 4: Gateway-coordinated notify — invite hook (NOTIF-01 / D-05)
**What:** AFTER a successful connection invite write, fire a best-effort notification to the addressee. The recipient is `$target` (already known in `sendRequest`); the actor is `$me`. Best-effort: never fail the invite.
**Source:** `ConnectionsController::sendRequest` (the existing invariant ending with `$up = $this->connections->createRequest(...)`), the degrade try/catch idiom in `enrich`.

```php
// gateway/src/Controllers/ConnectionsController.php — sendRequest, AFTER the write.
// ... (existing invariant: profile-exists, status-none checks) ...
$up = $this->connections->createRequest($me, $target);

// D-05 best-effort notify: only on a successful write. NEVER fail the invite on a
// notification error (try/catch GuzzleException + swallow). actor=$me, recipient=$target.
$createdCode = $up->getStatusCode();
if ($createdCode === 200 || $createdCode === 201) {
    $this->notifyBestEffort($target, $me, 'invite', $target /* ref: addressee/request — see note */);
}

return Json::raw($res, $this->decode($up), $createdCode);
```
```php
// Shared best-effort helper (add to ConnectionsController AND FeedController, or a small trait/service).
private function notifyBestEffort(int $recipient, int $actor, string $type, ?int $refId): void
{
    if ($recipient <= 0 || $recipient === $actor) {
        return;   // skip self-notify and invalid recipients (D-05)
    }
    try {
        $this->notifications->create($recipient, $actor, $type, $refId);
    } catch (GuzzleException $e) {
        // swallow — a notification failure must NEVER break the main action (D-05).
    }
}
```
**`ref_id` for invite:** D-04's `ref_id` is "post/request id". For an invite the natural ref is the created connection-request id. `createRequest` returns `{id, status:'pending'}` (VERIFIED: connection-service `create` returns `['id'=>lastInsertId,'status'=>'pending']`). RECOMMENDED: decode `$up` and pass the request `id` as `ref_id`; if the team prefers, the addressee id is also defensible. Flag at plan review. [VERIFIED: ConnectionController::create return shape]
**DI change:** `ConnectionsController` currently takes `(ProfileClient, ConnectionClient)`. Add `NotificationClient` as a third constructor arg + update the `public/index.php` factory. [VERIFIED: index.php line 63-66 factory]

### Pattern 5: Gateway-coordinated notify — reaction/comment hooks (NOTIF-01 / D-05)
**What:** AFTER a successful react/addComment, notify the POST AUTHOR. The post author is NOT in the upstream response — look it up with `FeedClient::getPost($postId)`. Skip self (author reacting/commenting on own post). Best-effort.
**Source:** `FeedController::react` + `FeedController::addComment` (thin passthroughs ending in `Json::raw($res, decode($up), $up->getStatusCode())`), `FeedClient::getPost($id,$viewer=0)` (returns the post incl. `author_id`).

```php
// gateway/src/Controllers/FeedController.php — react(), AFTER the upstream call.
public function react(Request $req, Response $res, array $args): Response
{
    $me     = $this->me($req);
    $postId = (int) $args['id'];
    $type   = trim((string) ((array) ($req->getParsedBody() ?? []))['type'] ?? '') ?: 'like';
    $up     = $this->feed->react($me, $postId, $type);

    // D-05 best-effort: only on 2xx; recipient = post author (lookup), skip self.
    $code = $up->getStatusCode();
    if ($code === 200 || $code === 201) {
        $this->notifyPostAuthor($postId, $me, 'reaction');
    }
    return Json::raw($res, $this->decode($up), $code);
}

// addComment() — identical shape, type 'comment'.

private function notifyPostAuthor(int $postId, int $actor, string $type): void
{
    try {
        $pr = $this->feed->getPost($postId, 0);
        if ($pr->getStatusCode() !== 200) { return; }
        $authorId = (int) ($this->decode($pr)['data']['author_id'] ?? 0);
        if ($authorId <= 0 || $authorId === $actor) { return; }   // skip self (D-05)
        $this->notifications->create($authorId, $actor, $type, $postId);   // ref_id = postId
    } catch (GuzzleException $e) {
        // swallow — best-effort (D-05). The react/comment already succeeded.
    }
}
```
**CRITICAL — the post author is NOT in the react/comment response:** feed-service `PostController::react` returns `{post_id, type}` (no author); `CommentController::create` returns the comment row whose `author_id` is the COMMENTER (the actor), not the post author. The gateway MUST resolve the recipient via `FeedClient::getPost($postId)` → `data.author_id`. Doing the lookup INSIDE the try/catch keeps it best-effort: even a `getPost` failure can't break the reaction/comment (it already returned 2xx upstream). [VERIFIED: PostController::react return line 210; CommentController::create returns find($id) row; FeedClient::getPost exists]
**DI change:** `FeedController` currently takes `(FeedClient, ProfileClient, ConnectionClient)`. Add `NotificationClient` + update the `public/index.php` factory (line 67-71). [VERIFIED: index.php FeedController factory]
**Ordering subtlety:** the notify runs AFTER the upstream returns 2xx, so the reaction row already exists. The extra `getPost` read happens on the gateway after the mutation — adds latency only to the mutating call, never blocks/breaks it.

### Pattern 6: notification-service recipient-scoped CRUD (D-04/D-06/D-07 — NOTIF-02/03)
**What:** create (trusts gateway), list newest-first + unread_count (scoped to recipient), mark-one-read (scoped), mark-all-read (scoped). Caller identity = `X-User-Id` for reads/marks; create trusts the gateway body (gateway is the only caller, network-isolated — D-05/D-07).
**Source:** connection-service `ConnectionController` doctrine verbatim — X-User-Id scoping, scoped UPDATE + scoped SELECT existence proof (NOT rowCount), uniform 404, native prepared statements with distinct names for reused values.

```php
// services/notification-service/src/Controllers/NotificationController.php — NEW.

// POST /notifications — gateway-coordinated create. The gateway computes recipient
// (user_id), actor_id, type, ref_id and posts them. notification-service trusts the
// gateway (D-07). Validate type against the enum; skip-self is enforced at the gateway.
public function create(Request $req, Response $res): Response
{
    $b        = (array) ($req->getParsedBody() ?? []);
    $userId   = (int) ($b['user_id']  ?? 0);    // recipient
    $actorId  = (int) ($b['actor_id'] ?? 0);
    $type     = trim((string) ($b['type'] ?? ''));
    $refId    = isset($b['ref_id']) && $b['ref_id'] !== null ? (int) $b['ref_id'] : null;

    if ($userId <= 0 || $actorId <= 0) {
        throw new DomainError(400, 'VALIDATION_FAILED', 'Thiếu user_id hoặc actor_id.');
    }
    if (!in_array($type, ['invite', 'reaction', 'comment'], true)) {
        throw new DomainError(400, 'VALIDATION_FAILED', 'Loại thông báo không hợp lệ.');
    }

    $stmt = Db::pdo()->prepare(
        'INSERT INTO notifications (user_id, type, actor_id, ref_id) VALUES (:u, :t, :a, :r)'
    );
    $stmt->execute([':u' => $userId, ':t' => $type, ':a' => $actorId, ':r' => $refId]);
    return Json::ok($res, ['id' => (int) Db::pdo()->lastInsertId()], 201);
}

// GET /notifications — recipient = X-User-Id. Newest-first + unread_count in meta.
public function index(Request $req, Response $res): Response
{
    $me = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
    if ($me <= 0) {
        throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).');
    }
    $limit = min(50, max(1, (int) ($req->getQueryParams()['limit'] ?? 30)));

    $stmt = Db::pdo()->prepare(
        'SELECT id, user_id, type, actor_id, ref_id, created_at, read_at
           FROM notifications WHERE user_id = :u
          ORDER BY created_at DESC, id DESC
          LIMIT :lim'
    );
    $stmt->bindValue(':u', $me, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);   // PARAM_INT (Pitfall 3)
    $stmt->execute();
    $rows = array_map([self::class, 'shape'], $stmt->fetchAll());

    // unread_count is its OWN scoped query (the list is capped; the badge must be exact).
    $c = Db::pdo()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL');
    $c->execute([':u' => $me]);
    $unread = (int) $c->fetchColumn();

    return Json::list($res, $rows, ['unread_count' => $unread, 'total' => count($rows)]);
}

// POST /notifications/{id}/read — scoped mark-one. Existence proven by scoped SELECT.
public function markRead(Request $req, Response $res, array $args): Response
{
    $me = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
    $id = (int) $args['id'];

    Db::pdo()->prepare(
        'UPDATE notifications SET read_at = NOW()
          WHERE id = :id AND user_id = :u AND read_at IS NULL'
    )->execute([':id' => $id, ':u' => $me]);

    // Existence by scoped SELECT, NOT rowCount (a no-op UPDATE on an already-read row
    // yields rowCount 0 — would be a false 404). Mirror connection-service accept().
    $chk = Db::pdo()->prepare('SELECT id, read_at FROM notifications WHERE id = :id AND user_id = :u LIMIT 1');
    $chk->execute([':id' => $id, ':u' => $me]);
    $row = $chk->fetch();
    if ($row === false) {
        throw new DomainError(404, 'NOTIFICATION_NOT_FOUND', 'Không tìm thấy thông báo.');
    }
    return Json::ok($res, ['id' => $id, 'read_at' => $row['read_at']]);
}

// POST /notifications/read-all — scoped mark-all.
public function markAllRead(Request $req, Response $res): Response
{
    $me = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
    $stmt = Db::pdo()->prepare(
        'UPDATE notifications SET read_at = NOW() WHERE user_id = :u AND read_at IS NULL'
    );
    $stmt->execute([':u' => $me]);
    return Json::ok($res, ['marked' => $stmt->rowCount()]);
}
```
**CRITICAL — `read_at` existence vs rowCount:** mirror `ConnectionController::accept` — a scoped UPDATE then a scoped SELECT to prove existence; rowCount is unreliable for marking an already-read row (no-op UPDATE → 0). For `markAllRead`, rowCount IS meaningful (count of newly-read rows) and fine to return. [VERIFIED: ConnectionController::accept lines 126-147 documents this exact rowCount pitfall]
**Create-trust model:** notification-service `create` trusts the body's `user_id`/`actor_id` because the gateway is the ONLY caller on the isolated network (D-07, same trust model as X-User-Id). Reads/marks are scoped to `X-User-Id` so one user can never read/mark another's notifications. [CITED: D-07 + ARCHITECTURE trust-by-network model]

### Pattern 7: gateway notification list + actor enrichment (NOTIF-02 / D-06)
**What:** `GET /api/notifications` → notification-service list (scoped by `me()`→X-User-Id) → batch-enrich `actor` via `ProfileClient::batch` (email allowlist) → pass through `unread_count`. Mark-read endpoints are thin passthroughs.
**Source:** `ConnectionsController::enrich` (batch enrich + email allowlist + degrade), `FeedController::listComments` (the same enrich-author pattern), `NotificationClient` (extend from health-only).

```php
// gateway/src/Controllers/NotificationsController.php — NEW (clone ConnectionsController::enrich shape).
public function index(Request $req, Response $res): Response
{
    $me = $this->me($req);
    $up = $this->notifications->list($me);          // X-User-Id injected by the client
    if ($up->getStatusCode() !== 200) {
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }
    $decoded = $this->decode($up);
    $rows    = (array) ($decoded['data'] ?? []);
    $unread  = (int) ($decoded['meta']['unread_count'] ?? 0);

    // Batch-enrich the actor (the user who invited/reacted/commented). Email allowlist.
    $ids = array_values(array_unique(array_filter(
        array_map(static fn($r) => (int) ($r['actor_id'] ?? 0), $rows),
        static fn(int $i) => $i > 0,
    )));
    $cardsById = [];
    $degraded  = false;
    if ($ids !== []) {
        try {
            $pRes = $this->profiles->batch($ids);
            if ($pRes->getStatusCode() === 200) {
                foreach ((array) ($this->decode($pRes)['data'] ?? []) as $u) {
                    $cardsById[(int) ($u['id'] ?? 0)] =
                        array_intersect_key($u, array_flip(['id', 'username', 'display_name', 'avatar_url']));
                }
            } else { $degraded = true; }
        } catch (GuzzleException $e) { $degraded = true; }
    }

    $out = array_map(static function (array $r) use ($cardsById): array {
        $r['actor'] = $cardsById[(int) ($r['actor_id'] ?? 0)] ?? null;
        return $r;
    }, $rows);

    $meta = ['unread_count' => $unread];
    if ($degraded) { $meta['degraded'] = true; $meta['parts'] = ['profiles']; }
    return Json::list($res, $out, $meta);
}
// markRead / markAllRead — thin passthroughs (me()→X-User-Id via the client), Json::raw.
```
**Client X-User-Id injection:** `NotificationClient` reads inject `X-User-Id` (mirror `ConnectionClient::listAccepted` but WITH a header — note `ConnectionClient::listAccepted` uses a `?user=` query, while notification-service scopes by the `X-User-Id` HEADER per D-07; the client must set the header on list/markRead/markAllRead). [VERIFIED: D-07 header scoping; ConnectionClient mutation header injection pattern]

### Pattern 8: UI — search box + notification bell (D-10)
**What:** A navbar search input → results list (or `search.html`) with quick-connect buttons, and a notification bell with an unread badge that polls `GET /api/notifications` every ~15s, dropdown with mark-read + mark-all-read.
**Source:** `web/assets/app.js` (`window.api`/`auth`/`navbar`/`formatDate`), `web/connections.html` (Alpine x-data pattern, connect-action calls, x-text discipline).

```html
<!-- Alpine component (clone connections.html structure). x-text only (no x-html, XSS-safe). -->
<div x-data="notificationBell()" x-init="start()">
  <button @click="open = !open">
    🔔 <span x-show="unread > 0" x-text="unread"></span>
  </button>
  <div x-show="open">
    <button @click="markAll()">Đánh dấu tất cả đã đọc</button>
    <template x-for="n in items" :key="n.id">
      <div :class="n.read_at ? '' : 'font-bold'" @click="markOne(n.id)">
        <span x-text="message(n)"></span>
        <span x-text="formatDate(n.created_at)"></span>
      </div>
    </template>
  </div>
</div>
<script>
function notificationBell() {
  return {
    open: false, items: [], unread: 0, timer: null,
    async load() {
      try {
        const r = await api.get('/notifications');
        this.items = r.data || [];
        this.unread = (r.meta && r.meta.unread_count) || 0;
      } catch (e) { /* degrade silently — badge stays */ }
    },
    start() { this.load(); this.timer = setInterval(() => this.load(), 15000); },  // ~15s poll (D-06)
    async markOne(id) { await api.post('/notifications/' + id + '/read'); this.load(); },
    async markAll()   { await api.post('/notifications/read-all'); this.load(); },
    message(n) {
      const who = (n.actor && n.actor.display_name) || 'Ai đó';
      if (n.type === 'invite')   return who + ' đã gửi lời mời kết nối';
      if (n.type === 'reaction') return who + ' đã bày tỏ cảm xúc về bài viết của bạn';
      if (n.type === 'comment')  return who + ' đã bình luận về bài viết của bạn';
      return who + ' có hoạt động mới';
    },
  };
}
</script>
```
**Poll lifecycle:** `setInterval` 15s (D-06 + Claude's discretion on interval). Only poll when logged in (`auth.isLoggedIn()`). `api.post('/notifications/{id}/read')` with NO body is fine — `api.post(p)` sends `body===undefined` → no Content-Type, empty body (VERIFIED: app.js `request` only adds body when `!== undefined`). [VERIFIED: app.js request signature]
**Message phrasing is Claude's discretion** (D-10) — keep Vietnamese, x-text only.

### Anti-Patterns to Avoid
- **Reindexing from `GET /users` alone** — it has no headline/skills; the index would be missing the two most useful search fields. MUST also `getFull(id)` per user. [VERIFIED: UserController::index column list]
- **Notifying the commenter/reactor instead of the post author** — `addComment` returns the actor's `author_id`; the recipient is the POST author from `getPost`. Notifying the actor is both wrong and a self-notify. [VERIFIED: feed-service return shapes]
- **Letting a notification failure break the invite/reaction/comment** — D-05 is best-effort; the notify call (incl. the `getPost` recipient lookup) MUST be inside try/catch GuzzleException that swallows. Run it AFTER the main write returns 2xx. [CITED: D-05]
- **`LIMIT :n` bound as a string** — with EMULATE_PREPARES=false this is a SQL syntax error; bind `PDO::PARAM_INT`. [VERIFIED: feed-service CommentController does this correctly]
- **Mixing named + positional placeholders / reusing a named placeholder** — native prepared statements forbid both; bind the 4 LIKE terms under distinct names (:t1..:t4). [VERIFIED: user-service verifyCredentials + feed-service doctrine]
- **rowCount() as existence proof on mark-read** — a no-op UPDATE (already read) yields 0; prove with a scoped SELECT (mirror ConnectionController::accept). [VERIFIED: ConnectionController::accept]
- **Leaking email in search cards or actor enrichment** — allowlist to `{id,username,display_name,avatar_url}` (+headline for search). `ProfileClient::batch` SELECTs email. [VERIFIED: UserController::index includes email; existing allowlist pattern]
- **Adding the tables only to a fresh-volume schema file** — the live VPS volume already exists; the init glob no-ops there. Ship `db/05-migrate-phase5.sql` wired into deploy.sh (Pitfall 1, the Phase 2/3/4 lesson). [VERIFIED: db/00-init.sh initdb-glob behavior + migrate-phase4 precedent]
- **Destructive reindex (TRUNCATE without a transaction) during a live demo** — if a bulk-replace is chosen, wrap it transactionally so a mid-rebuild failure doesn't empty the index. Per-row upsert avoids this entirely. [CITED: keep-simple]
- **`x-html` in search.html / bell** — D-10 says x-text only (XSS-safe). [CITED: D-10]

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| New service scaffold | new Slim app from scratch | The EXISTING search/notification stubs (Db/Json/DomainError/JsonErrorHandler/index.php) | Byte-for-byte the connection/feed shape; just add controllers + routes. [VERIFIED] |
| Recipient-scoped CRUD / IDOR safety | role checks on body | `X-User-Id` header scope + scoped UPDATE + scoped SELECT existence | connection-service doctrine verbatim. [VERIFIED] |
| Mark-read existence check | rowCount() | scoped SELECT after scoped UPDATE | MariaDB rowCount = rows *changed*; no-op UPDATE → false 404. [VERIFIED: ConnectionController::accept] |
| Search-result relationship status | a status copy in search-service | gateway `ConnectionClient::statusFor` per hit (or `statusForAsync` + `Utils::settle`) | DB-per-service; status is a gateway join (SEARCH-02 showcase). [VERIFIED] |
| Reindex skills/headline source | a new profile endpoint | EXISTING `GET /users/{id}/full` (headline+location+skills[]) | No profile-service change; `/users` list lacks them. [VERIFIED] |
| Actor/result enrichment | N+1 per-row profile fetch | `ProfileClient::batch()` (`?ids=`, cap 100) + email allowlist | enrich precedent in ConnectionsController/FeedController. [VERIFIED] |
| Best-effort notify | bespoke error suppression | try/catch GuzzleException swallow AFTER 2xx | D-05; mirrors the degrade idiom. [VERIFIED] |
| Search upsert | SELECT-then-INSERT/UPDATE | `INSERT ... ON DUPLICATE KEY UPDATE` on `user_id` PK | atomic, idempotent reindex. [VERIFIED] |
| Error envelope | ad-hoc JSON | `App\DomainError` + `App\JsonErrorHandler` (VN) | already present in both stubs. [VERIFIED] |
| Idempotent live migration | manual SQL on VPS | `db/05-migrate-phase5.sql` + deploy.sh step (clone 04) | Phase 2/3/4 solved this exact pitfall. [VERIFIED] |
| Parallel status fan-out (if used) | curl_multi/threads | `GuzzleHttp\Promise\Utils::settle` + `statusForAsync` | already the pattern; async variant exists. [VERIFIED] |
| Polling badge | WebSocket / SSE | `setInterval(load, 15000)` in Alpine | D-06 + OUT-of-scope WebSocket. [CITED] |

**Key insight:** Phase 5 introduces ZERO new infrastructure. Both services are stubs to flesh out with the connection-service doctrine; the search composition is `ConnectionsController::enrich` + per-hit `statusFor`; the notification coordination is three best-effort `create()` calls injected after existing successful writes; the migration clones `04`; enrichment reuses `ProfileClient::batch`. The two real hazards are (1) reindex MUST pull headline/skills from `/users/{id}/full` not `/users`, and (2) the reaction/comment recipient is the POST author resolved via `getPost`, not the actor in the upstream response — both verified above.

## Runtime State Inventory

> Phase 5 is additive build-out (new tables, new code), NOT a rename/refactor/migration of existing runtime state. This section is included for completeness given the live brownfield deployment.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | `proconnect_search.search_index` and `proconnect_notification.notifications` do NOT exist yet on the live VPS volume (Phase 1 created the empty DBs only). | `db/05-migrate-phase5.sql` CREATE TABLE IF NOT EXISTS + demo seed against the running mariadb (Pitfall 1). |
| Live service config | None — no n8n/Datadog/external service config carries Phase 5 state. The `SEARCH_SERVICE_URL`/`NOTIFICATION_SERVICE_URL` are already in `docker-compose.yml`. | None — verified by reading docker-compose.yml lines 102-103. |
| OS-registered state | None — no Task Scheduler/cron/systemd units reference search or notification. Polling is browser-side `setInterval`. | None. |
| Secrets/env vars | `SEARCH_SVC_DB_PASS` + `NOTIFICATION_SVC_DB_PASS` already required by deploy.sh preflight + 00-init.sh; DB users + GRANT ALL already provisioned (Phase 1). | None — verified deploy.sh line 29 preflight + 00-init.sh lines 38-45. |
| Build artifacts / installed packages | composer.lock already committed for both services; Dockerfiles already build them (health-only today). New controllers are PSR-4 autoloaded — no composer change. | `docker compose build` (deploy.sh step 4) picks up the new PHP files. No reinstall needed. |

**The canonical question — after every repo file is updated, what runtime systems still need action?** Only the two new tables on the live mariadb volume (the migration handles it) and a `docker compose build` to bake the new controllers into the service images (deploy.sh already does this). Nothing else carries old/Phase-5 state.

## Common Pitfalls

### Pitfall 1: The live volume already exists — fresh-volume schema files silently no-op
**What goes wrong:** Adding `search_index`/`notifications` only to `db/01-schema-*.sql` does nothing on the live VPS — MariaDB's initdb glob runs ONLY on a fresh volume. Every search/notification endpoint then 500s on a missing table.
**Why:** `db/00-init.sh` + `db/*.sql` are initdb-only; the live volume was initialized in Phase 1.
**How to avoid:** Ship the idempotent `db/05-migrate-phase5.sql` (CREATE TABLE IF NOT EXISTS + guarded seed) wired BLOCKING into deploy.sh after the phase-4 step, applied to BOTH DBs. Keep fresh-volume `01-schema-*.sql` in sync for new volumes.
**Warning signs:** `Table 'proconnect_search.search_index' doesn't exist` in service logs; `/api/search` 500 on the VPS.
[VERIFIED: db/00-init.sh comment + migrate-phase4 header documents this exact lesson]

### Pitfall 2: The reaction/comment recipient is the POST author, not anyone in the response
**What goes wrong:** Notifying `addComment`'s returned `author_id` notifies the commenter (self-notify) and misses the actual recipient (the post author).
**Why:** feed-service `react` returns `{post_id,type}` (no author); `addComment` returns the commenter's row.
**How to avoid:** Resolve recipient via `FeedClient::getPost($postId)` → `data.author_id`; skip if `== actor`. Inside the best-effort try/catch.
**Warning signs:** Smoke: a reaction by demo on duyet's post produces NO notification for duyet, or produces a self-notification for demo.
[VERIFIED: feed-service return shapes]

### Pitfall 3: `LIMIT :n` and scoped int params must bind PARAM_INT
**What goes wrong:** `bindValue(':lim', $n)` defaults to string → `LIMIT '20'` → SQL syntax error with native prepared statements.
**Why:** EMULATE_PREPARES=false sends a real prepared statement; LIMIT needs an integer literal.
**How to avoid:** `bindValue(':lim', $n, PDO::PARAM_INT)` (feed-service CommentController already does this).
**Warning signs:** A 500 on `/api/search` or `/api/notifications` with a MariaDB syntax error near `LIMIT`.
[VERIFIED: CommentController::index lines 52-54]

### Pitfall 4: LIKE wildcard/term handling
**What goes wrong:** Concatenating the user query directly into SQL (injection) or forgetting that `%`/`_` in the user's query are LIKE metacharacters.
**Why:** `LIKE '%$q%'` interpolation is injectable; user-typed `%` becomes a wildcard.
**How to avoid:** Bind the term as a VALUE: `$term = '%'.$q.'%'; bindValue(':t', $term)` — the wildcards we add are literal in SQL, and the user's text is fully escaped by the driver. Optionally escape user `%`/`_` if you want them literal (NOT required for a demo; document the choice). NEVER interpolate `$q` into the SQL string.
**Warning signs:** A search for `%` returns everything (acceptable for a demo) or a quote breaks the query (injection — must never happen with binding).
[VERIFIED: parameterized binding doctrine throughout the codebase]

### Pitfall 5: Search/notification composition must degrade, never 500
**What goes wrong:** A per-hit `statusFor` failure or an actor-batch failure bubbles a 500, breaking the whole search/notification page.
**Why:** Composition spans services; any can be slow/down.
**How to avoid:** Per-hit status in try/catch → status `'unknown'` + `meta.degraded`; actor batch in try/catch → `actor:null` + `meta.degraded`. Mirror ConnectionsController/FeedController exactly.
**Warning signs:** `/api/search` or `/api/notifications` returns 500 when connection/profile service is slow.
[VERIFIED: existing degrade idioms]

### Pitfall 6: `GET /users` has no headline/skills
**What goes wrong:** Reindex reads `allUsers()` and indexes empty headline/skills_text → "search by skill/title" returns nothing.
**Why:** `UserController::index` SELECTs only id/username/email/display_name/avatar_url/created_at.
**How to avoid:** Per-user `getFull(id)` to read headline/location/skills[] → flatten skills to `skills_text`.
**Warning signs:** Smoke "search by skill (PHP) → finds duyet" fails even though duyet has the PHP skill seeded.
[VERIFIED: UserController::index vs full; phase2 seed: duyet has skills PHP/Docker/Kiến trúc hướng dịch vụ]

## Code Examples

All verified patterns are inlined in Patterns 1-8 above with file-level sources. Key reusable signatures to ADD:

```php
// gateway/src/Services/SearchClient.php — extend (clone ConnectionClient mutation injection).
public function search(string $q, int $limit = 20): ResponseInterface {
    return $this->http->request('GET', '/search', ['query' => ['q' => $q, 'limit' => $limit]]);
}
public function upsert(array $row): ResponseInterface {
    return $this->http->request('POST', '/index', [
        'json' => $row, 'headers' => ['Content-Type' => 'application/json'],
    ]);
}

// gateway/src/Services/NotificationClient.php — extend.
public function create(int $recipient, int $actor, string $type, ?int $refId): ResponseInterface {
    return $this->http->request('POST', '/notifications', [
        'json'    => ['user_id' => $recipient, 'actor_id' => $actor, 'type' => $type, 'ref_id' => $refId],
        'headers' => ['Content-Type' => 'application/json'],
    ]);
}
public function list(int $user): ResponseInterface {
    return $this->http->request('GET', '/notifications', ['headers' => ['X-User-Id' => (string) $user]]);
}
public function markRead(int $user, int $id): ResponseInterface {
    return $this->http->request('POST', '/notifications/' . $id . '/read', ['headers' => ['X-User-Id' => (string) $user]]);
}
public function markAllRead(int $user): ResponseInterface {
    return $this->http->request('POST', '/notifications/read-all', ['headers' => ['X-User-Id' => (string) $user]]);
}
```

```php
// gateway/src/routes.php — ADD inside the $app->group('/api', ...). All JWT.
$g->get ('/search',          [SearchController::class, 'search'])->add($jwtMw);
$g->post('/search/reindex',  [SearchController::class, 'reindex'])->add($jwtMw);
$g->get ('/notifications',                       [NotificationsController::class, 'index'])->add($jwtMw);
$g->post('/notifications/read-all',              [NotificationsController::class, 'markAllRead'])->add($jwtMw);   // literal BEFORE {id}
$g->post('/notifications/{id:[0-9]+}/read',      [NotificationsController::class, 'markRead'])->add($jwtMw);
```
**ROUTE-ORDERING:** register literal `/notifications/read-all` BEFORE `/notifications/{id:[0-9]+}/read`; the numeric `{id}` constraint already prevents `read-all` colliding, but keep the literal first to match the Phase-3/4 ordering discipline. In service routes, register literal `/notifications` collection routes before any parameterized ones. [VERIFIED: gateway routes.php ordering discipline]

```php
// gateway/public/index.php — DI additions.
$container->set(SearchController::class, fn(Container $c) => new SearchController(
    $c->get(SearchClient::class), $c->get(ProfileClient::class), $c->get(ConnectionClient::class),
));
$container->set(NotificationsController::class, fn(Container $c) => new NotificationsController(
    $c->get(NotificationClient::class), $c->get(ProfileClient::class),
));
// MODIFY existing factories to add NotificationClient:
//   ConnectionsController(ProfileClient, ConnectionClient, NotificationClient)
//   FeedController(FeedClient, ProfileClient, ConnectionClient, NotificationClient)
```

```bash
# scripts/deploy.sh — ADD after the phase-4 step (line 154). Two DBs.
echo "[deploy] applying db/05-migrate-phase5.sql (additive)"
docker compose exec -T mariadb mysql -uroot -p"$DB_ROOT_PASSWORD" < db/05-migrate-phase5.sql
echo "[deploy] phase-5 additive migration applied"
```
**Migration note:** unlike phase 2/3/4 (which each `mysql ... <db> < file`), phase 5 spans TWO databases (`proconnect_search` + `proconnect_notification`). Either (a) one file with explicit `USE proconnect_search;` / `USE proconnect_notification;` blocks applied WITHOUT a DB arg (RECOMMENDED — mirrors migrate-phase1.sql.tmpl which switches DBs internally), or (b) two separate files each piped to its DB. The `migrate-phase4.sql` uses a leading `USE proconnect_feed;` so a single multi-USE file is consistent. Flag at plan review. [VERIFIED: migrate-phase4.sql line 42 `USE proconnect_feed;`; migrate-phase1.sql.tmpl switches DBs without a CLI db arg]

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Search via cross-service reads | search-service owns a denormalized index fed by gateway pull | this phase (ARCHITECTURE prescribed it) | DB-per-service preserved; eventually-consistent index (documented tradeoff). |
| Service-to-service eventing for notifications | Gateway central coordination (best-effort fire after the action) | this phase | No event bus/broker (none exists); the gateway is the single coordination point — reinforces the Gateway pattern. |

**Deprecated/outdated:** none relevant. No event bus, broker, or cache exists or is being added (OUT of scope per CONTEXT + ARCHITECTURE).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `avatar_url` should be ADDED to `search_index` + reindexed so quick-connect cards show an avatar (D-01 omits it but D-03's card lists it). | Pattern 2 | Low — if not added, drop `avatar_url` from the card; one-word DDL choice flagged for plan review. |
| A2 | `ref_id` for an invite = the created connection-request id (from `createRequest` `{id}`). | Pattern 4 | Low — addressee id is also defensible; ref_id is informational only (not used for scoping). |
| A3 | Phase-5 migration is ONE multi-`USE` .sql applied without a CLI db arg (mirrors migrate-phase1.sql.tmpl). | Code Examples | Low — alternative is two single-DB files; both work; flagged for plan review. |
| A4 | Reindex auth = any logged-in user (Claude's discretion) rather than an admin gate. | Pattern 3 | Low — explicitly Claude's discretion in CONTEXT; can gate to a specific user if the team wants. |
| A5 | notification-service `create` trusts the gateway-supplied body `user_id`/`actor_id` (no X-User-Id on create) because the gateway is the only network-isolated caller. | Pattern 6 | Low — matches D-07 trust-by-network; alternative is to also pass X-User-Id=actor and derive, but recipient ≠ caller so the body is needed regardless. |

## Open Questions (RESOLVED — A1 settle, A2 avatar_url in index, A3 single multi-USE migration — adopted in plans 05-04/05-01)

1. **Batch vs N status calls in search composition.**
   - What we know: `ConnectionClient::statusFor` is per-pair; `statusForAsync` exists; there is NO batch status route in connection-service.
   - What's unclear: whether to add a connection-service batch-status endpoint or keep N (≤20) per-hit calls.
   - Recommendation: keep per-hit but wrap in ONE `Utils::settle([statusForAsync...])` for parallelism + the "gateway fans out" slide. Do NOT add a new connection-service endpoint (scope creep). Confirm at plan review.

2. **`avatar_url` in `search_index` (A1).** Recommendation: add the column + denormalize from `allUsers()`. One-word DDL; decide at plan review.

3. **Single multi-USE migration file vs two files (A3).** Recommendation: single file with `USE proconnect_search;` and `USE proconnect_notification;` blocks (consistent with migrate-phase1.sql.tmpl). Decide at plan review.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| proconnect_search DB + search_svc user | search-service | ✓ (Phase 1) | MariaDB 10.11 | — |
| proconnect_notification DB + notification_svc user | notification-service | ✓ (Phase 1) | MariaDB 10.11 | — |
| `SEARCH_SVC_DB_PASS` / `NOTIFICATION_SVC_DB_PASS` env | both services | ✓ (deploy preflight + 00-init) | — | — (deploy aborts if missing) |
| `SEARCH_SERVICE_URL` / `NOTIFICATION_SERVICE_URL` (gateway) | gateway clients | ✓ | http://search-service:80 / :80 | — |
| search-service + notification-service containers | runtime | ✓ (built, health-only) | — | — (no new container) |
| Docker / docker compose | build + run | VPS only (NOT local) | — | static `php -l` + smoke on VPS |
| profile-service `GET /users/{id}/full` | reindex headline+skills | ✓ | — | partial index (basic fields only) if /full fails |

**Missing dependencies with no fallback:** none — Phase 1 provisioned both DBs/users; both services + URLs already exist.
**Missing dependencies with fallback:** Docker is not available locally (verify on VPS via deploy + smoke, per CLAUDE.md). Reindex degrades to basic-fields-only index if `/users/{id}/full` is unavailable.

## Validation Architecture

> nyquist_validation is not explicitly false in config; section included. There is NO PHP test framework in this project (STACK.md: "None detected" — no PHPUnit/Pest, only `php -l` in CI). Validation is `php -l` syntax lint + bash/curl smoke against the deployed stack, consistent with smoke-phase1..4.

### Test Framework
| Property | Value |
|----------|-------|
| Framework | None (no PHPUnit/Pest). `php -l` lint in CI + bash+curl smoke scripts. |
| Config file | none — `.github/workflows/deploy.yml` lint job + `scripts/smoke-phase*.sh` |
| Quick run command | `php -l` over changed PHP files (local, no Docker) |
| Full suite command | `bash scripts/smoke-phase5.sh` (against VPS or `docker compose up -d` stack) + regression `smoke-phase1..4.sh` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| SEARCH-01 | `GET /api/search?q=duyet` returns duyet (display_name match) | smoke (curl) | `bash scripts/smoke-phase5.sh` | ❌ Wave 0 |
| SEARCH-01 | search by skill: `q=PHP` returns duyet (skills_text match, post-reindex) | smoke | `bash scripts/smoke-phase5.sh` | ❌ Wave 0 |
| SEARCH-02 | each hit has `connection_status` + NO email/@ in body | smoke | `bash scripts/smoke-phase5.sh` (assert_no_pii) | ❌ Wave 0 |
| NOTIF-01 | invite demo→someone creates an 'invite' notification for recipient | smoke | `bash scripts/smoke-phase5.sh` | ❌ Wave 0 |
| NOTIF-01 | reaction/comment on a post creates a notification for the POST author | smoke | `bash scripts/smoke-phase5.sh` | ❌ Wave 0 |
| NOTIF-01 | best-effort: react/comment still 2xx even if recipient lookup/notify path 404s (assert indirectly) | smoke | `bash scripts/smoke-phase5.sh` | ❌ Wave 0 |
| NOTIF-02 | `GET /api/notifications` newest-first + `unread_count` + actor enriched no email | smoke | `bash scripts/smoke-phase5.sh` | ❌ Wave 0 |
| NOTIF-03 | mark-one decrements unread_count; mark-all → 0 | smoke | `bash scripts/smoke-phase5.sh` | ❌ Wave 0 |
| (lint) | every new/edited PHP file syntactically valid | unit-ish | `php -l <file>` | ✓ CI |

### Sampling Rate
- **Per task commit:** `php -l` on changed PHP files (Docker not local).
- **Per wave merge / phase gate:** `bash scripts/smoke-phase5.sh` + regression `smoke-phase1..4.sh` against the deployed VPS stack (runtime verification is on the VPS per CLAUDE.md).
- **Phase gate:** all smokes green before `/gsd-verify-work`; `/codex-impl-review` APPROVE before commit.

### Wave 0 Gaps
- [ ] `scripts/smoke-phase5.sh` — clone `smoke-phase4.sh`. NON-DESTRUCTIVE: `trap restore EXIT` registered before the first write; cancel any connection-request it creates; mark-read only on notifications it triggered; NEVER delete the demo seed (search_index rows, demo notifications). Pre-clean any leftover test connection-request between demo↔a target before the invite step (the Phase 3/4 idempotency lesson). Assert: reindex 200; search 'duyet' → duyet with `connection_status`, no email; search 'PHP' → duyet (skills); invite demo→target creates recipient notification + unread_count increments; reaction/comment on a tracked post notifies the post author; best-effort (react still 2xx if notify path would 404 — assert the react 2xx, then assert notification absent gracefully); mark-one decrements; mark-all → 0; actor enriched no `@`/email.
- [ ] Framework install: none (no test runner — consistent with the project).

*(Existing smoke-phase1..4.sh cover regression. No new test infra beyond the new smoke script.)*

## Security Domain

> security_enforcement not explicitly false; section included. Phase 5 is internal-network services + gateway composition; the security surface is identity scoping + PII allowlisting (consistent with Phases 2-4).

### Applicable ASVS Categories
| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Gateway JWT (firebase/php-jwt HS256); both new services trust `X-User-Id` (D-07) on the isolated `blog-net`; no host port binding. [VERIFIED: docker-compose internal-only services] |
| V3 Session Management | no | Stateless JWT; no server sessions. |
| V4 Access Control | yes | notification reads/marks scoped by `X-User-Id` (recipient-only) → IDOR-safe uniform 404 (clone ConnectionController). Search returns public-safe cards only. [VERIFIED: connection-service scoping doctrine] |
| V5 Input Validation | yes | Parameterized PDO everywhere; LIKE term bound as a value (no injection); type-enum validation on notification create; length caps on `q`. [VERIFIED: binding doctrine] |
| V6 Cryptography | no | No new crypto; passwords/JWT handled in Phase 1 gateway/profile-service. |
| V7 Data Protection (PII) | yes | Email allowlist on search cards + actor enrichment ({id,username,display_name,avatar_url}); search_index stores NO email. [VERIFIED: existing allowlist pattern; D-01 column list] |

### Known Threat Patterns for PHP/Slim + MariaDB + gateway composition
| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| SQL injection via search `q` / LIKE | Tampering | Bind `q` as a parameter; build the `%...%` term in PHP and bind it (never interpolate). [VERIFIED] |
| IDOR — reading/marking another user's notifications | Elevation/Info disclosure | Scope every read/UPDATE by `X-User-Id`; uniform 404; never trust a body `user_id` on reads/marks. [VERIFIED: connection doctrine] |
| PII (email) leak in search/notification responses | Info disclosure | Allowlist enrichment to public fields; search_index has no email; smoke `assert_no_pii` (@/email) on every card body. [VERIFIED] |
| Notification spoofing (service-to-service) | Spoofing | notification-service create trusts ONLY the gateway (network-isolated, no host port); gateway computes recipient/actor — clients can't post directly. [VERIFIED: trust-by-network model] |
| Notify failure breaking the main action (availability) | DoS (self-inflicted) | Best-effort try/catch GuzzleException swallow after the 2xx write (D-05). [CITED: D-05] |
| XSS via search/notification content in the UI | Tampering | Alpine `x-text` only (no `x-html`), D-10. [CITED: D-10] |

## Sources

### Primary (HIGH confidence — source read this session)
- `services/search-service/{src/routes.php, src/Db.php, src/Json.php, public/index.php}` + `services/notification-service/{src/Db.php}` — stub scaffolds to flesh out.
- `gateway/src/Services/{SearchClient,NotificationClient,ProfileClient,ConnectionClient}.php` — clients to extend; `allUsers`/`batch`/`statusFor`/`getFull` signatures.
- `gateway/src/Controllers/{ConnectionsController,FeedController}.php` — notify hook sites + enrich/allowlist/degrade idioms; `me()`/`decode()` helpers.
- `services/profile-service/src/Controllers/{UserController,ProfileController}.php` — `GET /users` (no headline/skills) vs `GET /users/{id}/full` (headline+location+skills[]); skills table.
- `services/feed-service/src/Controllers/{PostController,CommentController}.php` — react/comment return shapes (no post author); `LIMIT :lim` PARAM_INT pattern; `getPost`/find.
- `services/connection-service/src/Controllers/ConnectionController.php` — raw-PDO X-User-Id scoping, scoped-UPDATE+scoped-SELECT existence, uniform 404, status route, distinct named placeholders.
- `db/{00-init.sh, 04-migrate-phase4.sql, migrate-phase1.sql.tmpl, 02-migrate-phase2.sql}` + `scripts/deploy.sh` — DBs/users/grants provisioned; idempotent migration + deploy wiring; demo seed (users + headline/skills).
- `gateway/{routes.php, public/index.php}` — route group, JWT/optional MW, DI factories (need NotificationClient added to two controllers).
- `web/assets/app.js` + `scripts/smoke-phase4.sh` — UI wrapper + NON-DESTRUCTIVE smoke discipline to clone.
- `docker-compose.yml` (grep) — search/notification services + `*_SERVICE_URL` + `depends_on` already wired.

### Secondary / Tertiary
- None needed — every claim is grounded in repo source; no external lookup required (pinned stack, internal patterns).

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — verified from committed source + STACK.md; zero new deps.
- Architecture / patterns: HIGH — every pattern maps to a verified existing file (connection/feed/profile controllers, gateway clients/controllers, deploy/migration).
- Pitfalls: HIGH — the two load-bearing gotchas (reindex needs `/full`; recipient via `getPost`) are verified against the actual return shapes, not assumed.

**Research date:** 2026-06-07
**Valid until:** ~30 days (stable pinned stack; brownfield patterns unlikely to drift).

## RESEARCH COMPLETE

**Phase:** 5 - Tìm kiếm & Thông báo
**Confidence:** HIGH

### Key Findings
- ZERO new infrastructure: both services are `/health` stubs (connection-service shape), their DBs/users/grants + gateway clients + `*_SERVICE_URL` env all exist from Phase 1. Build = add controllers + routes + extend two clients + two new gateway controllers + inject 3 best-effort notify calls.
- Reindex MUST pull headline + skills from `GET /users/{id}/full` (per user) — `GET /users` returns NO headline/skills. Bounded N+1 over ~5 demo users, one-shot.
- The reaction/comment notification recipient is the POST author, resolved via `FeedClient::getPost($id).author_id` — NOT present in the react/comment upstream response (react returns `{post_id,type}`; comment returns the commenter's id). Skip-self + best-effort try/catch after the 2xx write.
- Search composition (per-hit `statusFor` + email allowlist) and notification list (actor `ProfileClient::batch` + allowlist) clone `ConnectionsController::enrich`/`FeedController::listComments` verbatim, incl. degrade.
- Migration spans TWO DBs (`proconnect_search` + `proconnect_notification`); clone `04-migrate-phase4.sql` (idempotent CREATE IF NOT EXISTS + guarded demo seed) wired into deploy.sh after the phase-4 step. NON-DESTRUCTIVE smoke (clone smoke-phase4) with pre-clean discipline.

### File Created
`/Users/theduyet/Documents/Code/SOA/soa-blog/.planning/phases/05-t-m-ki-m-th-ng-b-o/05-RESEARCH.md`

### Confidence Assessment
| Area | Level | Reason |
|------|-------|--------|
| Standard Stack | HIGH | Verified from committed source/lockfiles; no new deps. |
| Architecture | HIGH | Every pattern maps to a verified existing file. |
| Pitfalls | HIGH | The two key gotchas verified against actual return shapes. |

### Open Questions (all low-risk, flag at plan review)
1. Batch vs N (Utils::settle) status calls in search — recommend settle of N statusForAsync.
2. Add `avatar_url` to `search_index` (recommend yes) vs drop from card.
3. Single multi-USE migration file (recommend) vs two single-DB files.

### Ready for Planning
Research complete. Planner can create PLAN.md files. Remember the mandatory `/codex-plan-review` gate before coding and `/codex-impl-review` before commit.
