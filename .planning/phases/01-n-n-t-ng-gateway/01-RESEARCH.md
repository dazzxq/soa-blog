# Phase 1: Nền tảng & Gateway - Research

**Researched:** 2026-06-06
**Domain:** Brownfield refactor of a PHP 8.2 / Slim 4 / MariaDB API-Gateway microservices stack (Docker Compose, VPS + Cloudflare CI/CD)
**Confidence:** HIGH (all findings verified against committed source in this repo; no training-data or web claims required)

> This is a brownfield, "clone the existing skeleton" phase. There is **no new library to choose** — every dependency is already locked in committed `composer.lock` files. The plan's correctness depends almost entirely on faithfully replicating existing file-level patterns, not on external best practices. Findings below cite exact files and line numbers.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions (D-01 .. D-13 — execute these, do NOT re-litigate)
- **D-01:** Retire `post-service` + `comment-service` now. Keep their code in-repo as reference for the future feed phase; do NOT keep containers running.
- **D-02:** Final Phase-1 topology = **8 containers**: infra (`mariadb`, `gateway`, `web`) + services (`profile`, `connection`, `feed`, `search`, `notification`). 1 slot spare under the ≤9 ceiling.
- **D-03:** Scaffold all 4 new services (`connection`, `feed`, `search`, `notification`) as **stubs** now.
- **D-04:** "Deploy additive / site không gãy" (PLAT-06) means: CI/CD deploy succeeds + all containers healthy + `/api/health` HTTPS green. It is ACCEPTED that old blog endpoints (`/api/posts/*`, `/api/comments/*`) disappear.
- **D-05:** `git mv` rename in place: `services/user-service` → `services/profile-service`; rename container, compose service, gateway client `UserClient`→`ProfileClient`, env `USER_SERVICE_URL`→`PROFILE_SERVICE_URL`. Keep existing auth/user logic, extend later.
- **D-06:** DB = **wipe & reseed** (no data-preserving migration). Drop old `blog_users`, create `proconnect_profile`, reseed 5 demo accounts (`demo`/`duyet`/`long`/`diep`/`tai`, pass `demo@123**`) from `db/99-seed.sql`. Planner must handle the "volume only inits on fresh volume" reality — choose the safest idempotent CI/CD-compatible approach. **HIGHEST-RISK item.**
- **D-07:** Phase-1 gateway route surface: KEEP `/api/auth/register`, `/api/auth/login`, `/api/me`; CHANGE `/api/users/{id}` → `/api/profiles/{id}` returning basic info (id, username, display_name). PROF-01 (register/login) must stay alive.
- **D-08:** Each stub = minimal Slim app, only `/health` returning `{status, db, ts}` WITH a DB check. No business routes.
- **D-09:** Provision stub DBs = empty schema + scoped DB user per service in `db/00-init.sh`; NO business tables yet.
- **D-10:** Gateway `/api/health` fans out to all 5 services; every one must be green.
- **D-11:** Each service gets a typed client + controller (`ProfileClient`, `ConnectionClient`, `FeedClient`, `SearchClient`, `NotificationClient`) following the existing `UserClient`/`PostClient` pattern. Stub clients only serve health now.
- **D-12:** Forward `X-Request-Id` from gateway downstream to every service via Guzzle header (currently gateway-only).
- **D-13:** DB naming: schema `proconnect_<svc>`, DB user `<svc>_svc`, grants scoped to own schema only.

### Claude's Discretion
- **Route path prefixes** per service (suggested `/api/profiles`, `/api/connections`, `/api/feed`, `/api/search`, `/api/notifications`) — "reserved" only; stubs expose no business routes. Finalize when each phase builds.
- **Stub folder structure** — reuse the `services/user-service` skeleton (`public/index.php`, `src/routes.php`, `src/Db.php`, `Json`, `DomainError`, `JsonErrorHandler`, `Dockerfile`, `nginx.conf`, `supervisord.conf`).
- **Healthcheck details** (interval/timeout), concrete container/compose service names, supervisord/nginx config — reuse current templates, pick reasonable values.
- **VPS volume-wipe mechanism** (drop `mariadb_data` once vs. migration drop+create) — researcher/planner picks the safest, idempotent, CI/CD-friendly approach. (Researcher recommendation below — see Pitfall 1 / Runtime State Inventory.)

> **User carte-blanche note (2026-06-06):** "thoải mái restructure như nào cũng được cho hợp lý, SOA hiện tại đổi tên hay làm gì cũng được, không cần bảo vệ nó." → Prioritise the cleanest showcase architecture; aggressive rename/delete of brownfield is allowed as long as register/login stays alive and the deploy is green.

### Deferred Ideas (OUT OF SCOPE for Phase 1)
- Business logic of connection / feed / search / notification (Phases 3/4/5).
- Full profile (cover photo, title, experience/education/skills + composition endpoint) — Phase 2.
- Notification real-time / WebSocket — out of scope; polling in Phase 5.
- Object storage / binary upload — out of scope v1; `avatar_url` stays a URL string.
- Shared Redis / cache — not needed; rate-limit stays file-backed per-gateway.
- Splitting MariaDB into multiple instances — keep one instance, many schemas.
- PHPUnit/Pest test framework — not present today; not mandatory for Phase 1 (researcher view below in Validation Architecture).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| **PLAT-01** | New microservices (profile, connection, feed, search [+notification]) run behind one gateway, each with its own DB | "New service recipe" (Architecture Patterns §Pattern 1); DB provisioning in `db/00-init.sh` (§Pattern 4); compose service template (§Pattern 2). 8-container topology confirmed fits 2GB (§Container/RAM Budget). |
| **PLAT-02** | Gateway routes `/api/*` to the right service and rewrites internal path | Gateway uses explicit controller-per-route + typed client (NOT transparent proxy): `gateway/src/routes.php:17-40`, `gateway/src/Controllers/UsersController.php`. Path rewrite = client maps `/api/profiles/{id}` → service `/users/{id}` (§Pattern 3). |
| **PLAT-03** | Centralized JWT verify at gateway; services trust only `X-User-Id` | `gateway/src/Middleware/JwtAuthMiddleware.php` (gateway-only secret), `gateway/public/index.php:23-32` fail-fast. Services never see JWT — `services/*/src/Db.php` + controllers read `X-User-Id` header (`UserController::update:168`). Unchanged in Phase 1. (§PROF-01 Continuity) |
| **PLAT-04** | Gateway keeps rate-limit, request-id, logging centrally for new traffic | Middleware stack `gateway/public/index.php:70-73` (Logging→CORS→RateLimit→RequestId) applies to ALL `/api/*` automatically. D-12 adds `X-Request-Id` downstream forwarding (§Pattern 5). |
| **PLAT-05** | `docker compose up` clean, all healthy, ≤9 containers (2GB) | Healthcheck anchor `docker-compose.yml:1-5`; per-service `*svc-healthcheck`. 8-container plan (§Container/RAM Budget). Health contract `{status,db,ts}` (`UserController::health:18-26`). |
| **PLAT-06** | Deploy to `soa.duyet.vn` via existing CI/CD, valid HTTPS | `.github/workflows/deploy.yml` + `scripts/deploy.sh`. Changes needed (§CI/CD Impact). Lint globs `services/*/src` already auto-cover new services (`deploy.yml:25-27`). Smoke = `/api/health` (§Validation Architecture). |
| **PROF-01** | User register + login (reuse/extend existing auth) | Auth lives at GATEWAY `gateway/src/Controllers/AuthController.php`, NOT in user/profile-service. Profile-service only owns password hash/verify (`UserController::create`/`verifyCredentials`). What must not break: §PROF-01 Continuity. |
</phase_requirements>

## Summary

The phase is a mechanical, low-novelty refactor of an already-clean, well-factored codebase. The gateway and the three services share one identical "new service recipe" (documented verbatim in `ARCHITECTURE.md` lines 236-242 and proven by three near-identical service folders). Adding the four stub services and renaming user→profile is **copy + find/replace + DB-provisioning + DI-wiring**, with one genuinely risky operation: wiping and reseeding the live MariaDB.

The single highest-risk item (D-06) is fully de-risked by one decisive finding: **ProConnect runs its OWN dedicated `mariadb:10.11` container with a private named volume `mariadb_data`, on the internal `blog-net` bridge, with no host port** (`docker-compose.yml:9-26, 108-109`). The "shared host MariaDB" mentioned in project memory is a *separate, host-level* instance used by other VPS sites; the ProConnect Compose stack never touches it. Therefore wiping `mariadb_data` destroys only ProConnect's data and **cannot affect other sites**. This converts the scariest decision into a safe, bounded operation.

**Primary recommendation:** Execute D-06 with an explicit, idempotent **drop+recreate migration script** that runs *inside the running mariadb container* during deploy (not by deleting the volume), because `db/00-init.sh` only ever runs on a fresh volume and will silently no-op on the live VPS where the volume already exists. Treat the rename, the four stubs, and the gateway re-wiring as three independent waves; the DB wipe as its own carefully-sequenced wave with a one-line rollback (restore from a pre-deploy `mysqldump`).

## Standard Stack

> No package selection is required — every dependency is committed and version-locked. This table documents what already exists so the planner reuses it rather than introducing anything new (CLAUDE.md constraint: no new heavy deps).

### Core (gateway — `gateway/composer.json`, locked in `gateway/composer.lock`)
| Library | Locked Version | Purpose | Why Standard Here |
|---------|---------------|---------|-------------------|
| slim/slim | 4.15.1 | HTTP framework | [VERIFIED: gateway/composer.json:7, STACK.md:33] Already used by gateway + all services |
| slim/psr7 | 1.8.0 | PSR-7 messages | [VERIFIED: STACK.md:33] |
| php-di/php-di | 7.1.1 | DI container (gateway only) | [VERIFIED: gateway/composer.json:9, public/index.php:35] Services wire manually |
| guzzlehttp/guzzle | 7.10.0 | gateway→service HTTP | [VERIFIED: gateway/composer.json:11, HttpClient.php] |
| firebase/php-jwt | 7.0.5 | HS256 JWT encode/decode | [VERIFIED: gateway/composer.json:12, JwtAuthMiddleware.php] gateway-only |
| ramsey/uuid | 4.x | request-id generation | [VERIFIED: gateway/composer.json:13, RequestIdMiddleware.php:10] gateway-only |
| monolog/monolog | 3.10.0 | logging | [VERIFIED: all composer.json] all modules |

### Core (each service — `services/*/composer.json`, identical across user/post/comment)
| Library | Version | Purpose | Note |
|---------|---------|---------|------|
| slim/slim | ^4.12 | HTTP framework | [VERIFIED: services/user-service/composer.json:7] |
| slim/psr7 | ^1.6 | PSR-7 | [VERIFIED: composer.json:8] |
| monolog/monolog | ^3.0 | logging | [VERIFIED: composer.json:9] |
| ext-mbstring, ext-pdo | * | string + DB | [VERIFIED: composer.json:10-11] **No Guzzle, no JWT, no DI, no ORM in services** — raw PDO only |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Cloning the service skeleton | A shared base image / composer path package for the four stubs | [ASSUMED] Cleaner DRY, but adds build complexity and breaks the "each service is self-contained, identical, easy to explain in class" property. CONTEXT D-03 + CLAUDE.md "keep it SIMPLE" → **clone, do not abstract.** Reject. |
| `git mv` rename in place (D-05) | New `profile-service` folder + delete `user-service` | git history continuity favors `git mv`; decision is locked. |
| PHPUnit for validation | bash smoke tests (curl) | No framework exists today; see Validation Architecture — bash/curl smoke is sufficient and matches the existing CI smoke test. |

**Installation:** No `npm`/`composer require` needed. Each new service inherits its deps by copying the committed `composer.json` + `composer.lock` from `services/user-service/`. The Dockerfile runs `composer install --no-dev --optimize-autoloader` at build (`services/user-service/Dockerfile:14-16`).

**Version verification:** Skipped intentionally — versions are pinned in committed lockfiles and must NOT drift during this refactor (reproducible-build invariant, STACK.md:36). Do not run `composer update`.

## Architecture Patterns

### Recommended Project Structure (after Phase 1)
```
services/
├── profile-service/        # git mv from user-service (D-05) — full auth/user logic retained
├── connection-service/     # NEW stub — /health only
├── feed-service/           # NEW stub — /health only
├── search-service/         # NEW stub — /health only
├── notification-service/   # NEW stub — /health only
├── post-service/           # RETIRED — keep on disk as reference (D-01), removed from compose
└── comment-service/        # RETIRED — keep on disk as reference (D-01), removed from compose
db/
├── 00-init.sh              # rewrite: 5 proconnect_* DBs + 5 *_svc users (D-09, D-13)
├── 01-schema-profile.sql   # rename of 01-schema-users.sql, USE proconnect_profile
├── 99-seed.sql             # USE proconnect_profile; same 5 demo rows
└── (drop 02-schema-posts.sql / 03-schema-comments.sql from init, optionally keep as reference)
gateway/src/
├── Services/  ProfileClient PhpClient... ConnectionClient FeedClient SearchClient NotificationClient
├── Controllers/ HealthController (5-way fan-out), AuthController (unchanged), ProfilesController
└── routes.php  /api/health, /api/auth/*, /api/me, /api/profiles/{id}
```

### Pattern 1: The "new service" recipe (the spine of this phase)
**What:** A repeatable 6-step recipe, documented in the repo itself and proven by 3 identical service folders.
**When to use:** For each of the 4 stubs, and (in modified form) for the profile rename.
**Source:** `.planning/codebase/ARCHITECTURE.md:236-242` (verbatim recipe) + observed in `services/*/`.

A stub service folder needs exactly these files (copy from `services/user-service/`, then trim):
| File | Stub change vs. user-service |
|------|------------------------------|
| `Dockerfile` | **identical, zero change** (`services/user-service/Dockerfile`) |
| `nginx.conf` | **identical, zero change** (already special-cases `/health` access_log off, `nginx.conf:8-11`) |
| `supervisord.conf` | **identical, zero change** |
| `composer.json` | change only `"name"` field; deps identical (`composer.json:2`) |
| `composer.lock` | **copy as-is** (must match composer.json — same deps → same lock) |
| `public/index.php` | **identical, zero change** (`public/index.php` has no app-specific code) |
| `src/Db.php` | **identical** — change nothing; `DB_NAME`/`DB_USER` come from env, defaults are only fallbacks (`Db.php:21-24`) |
| `src/Json.php` | **identical, zero change** |
| `src/DomainError.php` | **identical, zero change** |
| `src/JsonErrorHandler.php` | **identical, zero change** |
| `src/routes.php` | **trim to one line:** only `$app->get('/health', [HealthController::class, 'health']);` |
| `src/Controllers/*Controller.php` | replace `UserController` with a tiny `HealthController` (just the `health()` method, copied verbatim from `UserController::health`, lines 18-26) |

**Minimal stub `/health` implementation (copy verbatim from `services/user-service/src/Controllers/UserController.php:18-26`):**
```php
// Source: services/user-service/src/Controllers/UserController.php:18-26 (VERIFIED in repo)
public function health(Request $req, Response $res): Response
{
    $dbOk = \App\Db::ping();           // SELECT 1 against the service's own DB
    return \App\Json::raw($res, [
        'status' => $dbOk ? 'ok' : 'degraded',
        'db'     => $dbOk ? 'ok' : 'down',
        'ts'     => gmdate('c'),
    ], $dbOk ? 200 : 503);
}
```
`Db::ping()` (`services/user-service/src/Db.php:37-45`) runs `SELECT 1` and catches all throwables — it works against an **empty schema** (no tables required), which is exactly what stubs have (D-09). This is the crucial finding that makes "DB-checking health on a table-less schema" trivially correct.

### Pattern 2: docker-compose service block (clone template)
**What:** Each service is one compose block; a YAML anchor `*svc-healthcheck` is shared.
**Source:** `docker-compose.yml:1-5` (anchor), `:28-39` (user-service block).
```yaml
# Source: docker-compose.yml — clone per stub, change name/DB_*
connection-service:
  build: ./services/connection-service
  environment:
    DB_HOST: mariadb
    DB_NAME: proconnect_connection      # D-13
    DB_USER: connection_svc             # D-13
    DB_PASS: ${CONNECTION_SVC_DB_PASS}  # new env var → .env + .env.example + mariadb env block
  networks: [blog-net]
  depends_on:
    mariadb: { condition: service_healthy }
  healthcheck: *svc-healthcheck          # reuse anchor — wget /health
  restart: unless-stopped
```
**Gateway must also gain `depends_on` + `*_SERVICE_URL` env for each new service** (`docker-compose.yml:67-81`). Note: gateway currently `depends_on` user/post/comment with `service_healthy` — replace with profile + 4 stubs. Consider whether all 5 should be `service_healthy` gates (cleaner "all healthy" guarantee for PLAT-05, but slower boot) vs. `service_started` (faster). **Recommendation:** keep `service_healthy` for all 5 — it directly enforces success-criterion #1 and the 2GB box boots fine within `start_period`.

### Pattern 3: Path rewrite = explicit controller + typed client (NOT a transparent proxy)
**What:** Each gateway route is a real controller method that calls a typed client method; the client owns the internal URL/verb mapping. There is **no reverse-proxy pass-through** (ARCHITECTURE.md:122).
**Why it matters for PLAT-02:** "rewrite path nội bộ" is satisfied by the client mapping public `/api/profiles/{id}` → internal `GET profile-service /users/{id}`. For Phase 1 you do NOT need to rename the *internal* `/users` routes inside profile-service (D-05 keeps logic) — only the gateway-facing surface changes to `/api/profiles/{id}` (D-07).
**Source:** `gateway/src/Controllers/UsersController.php:15-19` (thin pass-through controller — the template for `ProfilesController`), `gateway/src/routes.php:26`.
```php
// ProfilesController (clone of UsersController.php) — gateway side
public function show(Request $req, Response $res, array $args): Response {
    $upstream = $this->profiles->get((int) $args['id']);   // ProfileClient::get → GET /users/{id} internally
    return Json::raw($res, $this->decode($upstream), $upstream->getStatusCode());
}
```
> **Decision needed for the planner (LOW risk):** D-07 says `/api/profiles/{id}` returns basic info (id, username, display_name). The current profile-service `/users/{id}` already returns more fields (email, avatar_url, created_at — `UserController::find:209-218`). Either (a) trim at the gateway controller, or (b) leave full passthrough and trim in Phase 2. **Recommendation:** passthrough now (simplest, D-04 spirit), trim/shape in Phase 2 when the profile model expands. Mark as [ASSUMED] — confirm with user if "basic info only" is a hard contract.

### Pattern 4: DB provisioning — schema + scoped user per service
**Source:** `db/00-init.sh:15-29`. Rewrite for Phase 1 to:
```sql
-- Source pattern: db/00-init.sh (VERIFIED) — adapted for D-09/D-13
CREATE DATABASE IF NOT EXISTS proconnect_profile      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS proconnect_connection   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS proconnect_feed         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS proconnect_search       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS proconnect_notification CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'profile_svc'@'%'      IDENTIFIED BY '${PROFILE_SVC_DB_PASS}';
-- ... connection_svc, feed_svc, search_svc, notification_svc ...
GRANT ALL PRIVILEGES ON proconnect_profile.*      TO 'profile_svc'@'%';
-- ... scoped grants per D-13 ...
FLUSH PRIVILEGES;
```
The script reads passwords from container env; the `mariadb` service env block (`docker-compose.yml:11-15`) must list the 5 new `*_SVC_DB_PASS` vars, and `.env`/`.env.example` must define them. **Only `proconnect_profile` gets a schema file + seed (D-09: stubs have empty schemas, no tables).**

### Pattern 5: Forward X-Request-Id downstream (D-12)
**What:** Today `RequestIdMiddleware` stores `request_id` on the request attribute and echoes it on the *response*, but Guzzle clients do NOT forward it (`RequestIdMiddleware.php:20-23`; gap noted in ARCHITECTURE.md:222).
**Two viable approaches:**
- **(A) Per-call header injection** in each client method (mirrors how `X-User-Id` is injected, `PostClient.php:34-38`). Requires passing the rid into every client call — verbose, touches every method.
- **(B) Guzzle default-header at client construction** — but `HttpClient::create` is called once at DI time (`UserClient.php:18`), before any request exists, so the rid isn't known yet. A Guzzle **middleware on the HandlerStack** that reads the rid from a request-scoped source is cleaner but adds Guzzle-handler complexity.
- **(C) Recommended — simplest for a class showcase:** Since clients are constructed per-request inside PHP-DI (each request is a fresh fpm worker invocation), inject the rid into the client constructor / a setter and add it as a default header. Concretely: in `gateway/public/index.php` the clients are `fn() => new XClient()`; change `RequestIdMiddleware` to stash the rid where clients can read it, OR pass `request->getAttribute('request_id')` from each controller into the client call.

> **[ASSUMED] / Open decision:** The cleanest minimal change is to add `'X-Request-Id' => $rid` to the `headers` array in `HttpClient::create`, sourcing `$rid` from a single per-request value. Because php-fpm handles one request per process at a time and the DI container is rebuilt per request (`public/index.php` runs top-to-bottom each request), a module-level captured rid is safe. **Planner should pick approach and confirm; recommend (A) explicit param if clarity-for-class outweighs verbosity, since it's the same visible pattern as `X-User-Id`.** Mark for discuss-phase confirmation.

### Anti-Patterns to Avoid
- **Deleting the `mariadb_data` volume on the live VPS to force re-init.** It works but is destructive, non-idempotent, and CI/CD can't safely `docker volume rm` mid-deploy without downtime ordering. See Pitfall 1 for the safe alternative.
- **Adding a host `ports:` mapping to any service.** Breaks the trust-by-network model (ARCHITECTURE.md:239). Services stay internal-only on `blog-net`.
- **Making stubs verify JWT or know `JWT_SECRET`.** Violates PLAT-03; stubs have no JWT lib anyway.
- **Renaming internal profile-service routes from `/users` to `/profiles` in Phase 1.** Unnecessary churn; D-05 keeps logic. Only the gateway-facing surface changes.
- **Running `composer update` during the refactor.** Breaks reproducible builds; copy committed lockfiles verbatim.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Service health check | Custom curl loop in PHP | Existing `Db::ping()` + `UserController::health` pattern + compose `*svc-healthcheck` anchor | Already handles empty schema, 503 on DB down, no-log on /health |
| Parallel multi-service fan-out | Sequential blocking calls | `GuzzleHttp\Promise\Utils::settle()` (already in `HealthController::check:32`) | Non-blocking, degrades per-service independently |
| JWT verify in services | Re-verify token downstream | Trust `X-User-Id` header (PLAT-03) | Centralized at gateway; services have no JWT lib |
| Error envelope | Ad-hoc JSON errors | `DomainError` + `JsonErrorHandler` (already mirrored in every service) | Stable codes + Vietnamese messages, consistent across stack |
| Request correlation | New tracing lib | `ramsey/uuid` + `RequestIdMiddleware` (extend for downstream, D-12) | Already present; just add forwarding |

**Key insight:** This stack is deliberately dependency-minimal and the patterns are already proven by 3 identical services. The entire phase should introduce **zero new libraries** and **zero new patterns** — it is replication + provisioning + wiring.

## Runtime State Inventory

> This IS a rename/refactor/migration phase. Every category answered explicitly.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| **Stored data** | MariaDB databases `blog_users`, `blog_posts`, `blog_comments` inside the **ProConnect-owned `mariadb` container's `mariadb_data` named volume** (`docker-compose.yml:16-17, 108-109`). Demo accounts seeded by `db/99-seed.sql` (5 users in `blog_users`). | **Data migration (D-06 wipe & reseed):** drop `blog_users`/`blog_posts`/`blog_comments`, create `proconnect_*` schemas + reseed 5 demo rows into `proconnect_profile`. See Pitfall 1 for the safe live-VPS mechanism. |
| **Live service config** | **None outside git.** No n8n / Datadog / external dashboards. The only non-git host state is nginx vhost files under `/etc/nginx/` — but `scripts/deploy.sh:62-85` already syncs them idempotently from `deploy/*.conf`, and they reference `127.0.0.1:8000` (gateway) which does **not** change. Cloudflare DNS A-record for `soa.duyet.vn` is unchanged. | **None** — host nginx points at the gateway host-port (8000) which is stable. Verified: gateway container name/port unchanged in plan. |
| **OS-registered state** | **None.** No systemd units, no cron, no Task Scheduler for ProConnect — deploy is `git pull` + `docker compose` driven by GitHub Actions SSH (`deploy.yml:42-50`). Containers use `restart: unless-stopped` (Docker-managed, not OS-registered). | **None — verified by reading `scripts/deploy.sh` and `.github/workflows/deploy.yml` (no OS registration anywhere).** |
| **Secrets / env vars** | `.env` on VPS (git-ignored, `.gitignore:1`). Current keys: `DB_ROOT_PASSWORD`, `USER_SVC_DB_PASS`, `POST_SVC_DB_PASS`, `COMMENT_SVC_DB_PASS`, `JWT_SECRET`, `RATE_LIMIT_PER_MIN` (`.env.example`). Renaming services means: `USER_SVC_DB_PASS`→`PROFILE_SVC_DB_PASS` and **5 new** `*_SVC_DB_PASS` vars; gateway `USER_SERVICE_URL`→`PROFILE_SERVICE_URL` + 4 new `*_SERVICE_URL`. `JWT_SECRET` **unchanged** (keeps existing tokens/flow valid). | **Code edit + manual VPS `.env` edit.** Update `.env.example` (in git) AND the live `.env` on the VPS (NOT in git — must be edited by hand on the VPS before/with deploy, or the new services fail to get DB passwords). **This is a deploy-ordering hazard — flag in plan.** |
| **Build artifacts / installed packages** | Each service builds its own image from `composer install` at `docker compose build` time (`Dockerfile:14-16`). `vendor/` is git-ignored (`.gitignore:4`) and rebuilt per image. Retired post/comment images become orphans. | **`docker compose up -d --remove-orphans`** (already in `deploy.sh:34`) removes retired post/comment containers automatically. No stale egg-info/binaries. New stub images build fresh. |

**The canonical question — after every repo file is updated, what runtime state still has the old string?**
Answer: only (1) the **live `.env` on the VPS** (must be hand-edited to add the 5 new `*_SVC_DB_PASS` and rename the user one — git can't do this), and (2) the **MariaDB data inside `mariadb_data`** (handled by the D-06 migration). Everything else is git-tracked or Docker-managed and self-updates on deploy. **Crucially: the wipe is contained to ProConnect's own volume and cannot affect other VPS sites.**

## Common Pitfalls

### Pitfall 1: `db/00-init.sh` only runs on a FRESH volume — the live VPS volume already exists (HIGHEST RISK, D-06)
**What goes wrong:** The MariaDB official image executes `/docker-entrypoint-initdb.d/*` **only when `/var/lib/mysql` is empty** (first init). On the live VPS, `mariadb_data` is already populated, so editing `db/00-init.sh` / `db/*.sql` and redeploying does **nothing** — no new `proconnect_*` schemas appear, the stubs' DB users don't exist, their health checks 503, the gateway `/api/health` goes red, and the deploy smoke test fails. This is the trap that silently breaks the whole phase on production.
**Why it happens:** init scripts are a one-time bootstrap, not a migration system (`db/00-init.sh:1-2` comment confirms; INTEGRATIONS.md:35 "applied once via /docker-entrypoint-initdb.d on first boot").
**How to avoid — two options, recommend B:**
- **(A) Drop the volume once:** on the VPS, `docker compose down`, `docker volume rm soa-blog_mariadb_data`, `docker compose up -d` → fresh init runs all scripts. Simple, but causes downtime and is NOT idempotent inside the CI/CD `deploy.sh` flow (the workflow never stops/removes volumes).
- **(B) RECOMMENDED — explicit idempotent migration applied to the running container:** add a one-shot migration step that, against the *running* mariadb, runs `DROP DATABASE IF EXISTS blog_users; ...; CREATE DATABASE IF NOT EXISTS proconnect_profile; CREATE USER IF NOT EXISTS ...; GRANT ...; ` then loads `01-schema-profile.sql` + `99-seed.sql`. This is idempotent, no downtime, CI-friendly. Mechanism choices: a `db/migrate-phase1.sql` executed via `docker compose exec -T mariadb mysql -uroot -p"$DB_ROOT_PASSWORD" < db/migrate-phase1.sql` added as a guarded step in `deploy.sh`, OR a numbered migration run manually once via SSH for the Phase-1 cutover.
**Safety net (do this regardless):** take a `mysqldump` of the current databases before the wipe → one-line rollback. Because data is throwaway demo data (D-06 rationale), risk is low, but the dump makes rollback trivial.
**Warning signs:** after deploy, `GET /api/health` returns `degraded` with stub services `down`; `docker compose logs <stub>` shows PDO "Access denied for user 'X_svc'" or "Unknown database 'proconnect_X'".
**Also update `db/00-init.sh` + schema files** so that a *future* fresh `docker compose up` (e.g. a teammate's clean checkout, or a full volume reset) provisions correctly — the migration handles the live VPS, the init script handles fresh environments. Both must agree.

> **Decisive de-risking finding:** ProConnect has its OWN `mariadb:10.11` container + private `mariadb_data` volume on the internal `blog-net` (no host port — `docker-compose.yml:9-26`). The "shared host MariaDB same root pass" in project memory is a separate host-level instance for other sites; the Compose stack never connects to it. **Wiping `mariadb_data` is fully contained to ProConnect.** [VERIFIED: docker-compose.yml]

### Pitfall 2: Live `.env` on the VPS is git-ignored — new DB passwords won't arrive via `git pull`
**What goes wrong:** New stub services need `CONNECTION_SVC_DB_PASS` etc. The `.env.example` is in git but the real `.env` is not (`.gitignore:1`). If you only commit `.env.example`, the deploy pulls code that references undefined env vars → compose substitutes empty strings → DB users created with empty passwords / services can't auth.
**How to avoid:** Plan an explicit manual step: SSH to the VPS and add the 5 new `*_SVC_DB_PASS` (and rename the user one) to `.env` **before** the GitHub Actions deploy runs, or as a documented pre-deploy checklist item. Compose env substitution + `db/00-init.sh`'s `: "${VAR:?...}"` guards (`00-init.sh:10-13`) will hard-fail loudly if missing — good, but only on fresh init; the migration script must read them too.
**Warning signs:** `mariadb` container exits at init with "USER_SVC_DB_PASS must be set", or services log empty-password auth failures.

### Pitfall 3: Web bind-mount inode caveat (already handled, don't re-break it)
**What goes wrong:** `deploy.sh:39-42` restarts the `web` container only when `web/` changed, because bind mounts track inodes across `git pull`. Phase 1 touches no `web/` files, so this won't trigger — but if the plan adds any `web/` change, ensure the restart logic still fires.
**How to avoid:** Don't touch `web/` in Phase 1 (UI is Phase 6). Verified non-issue here, documented to prevent accidental regression.

### Pitfall 4: Middleware order is LIFO — don't reorder when wiring new clients
**What goes wrong:** `gateway/public/index.php:68-73` relies on Slim's LIFO add order to produce Logging→CORS→RateLimit→RequestId. Adding new client/controller singletons (lines 37-60) is safe, but accidentally moving the `$app->add(...)` block would silently break rate-limit/request-id ordering for all new traffic (PLAT-04).
**How to avoid:** Only add to the container `set()` calls and to `routes.php`; leave the middleware `add()` block untouched.

### Pitfall 5: Retired services still referenced in gateway DI/routes → boot crash
**What goes wrong:** Removing post/comment containers (D-01) without removing `PostClient`/`CommentClient`/`PostsController`/`AggregateController` from `gateway/public/index.php:38-60` and `routes.php:29-39` leaves dangling DI wiring. The clients themselves won't crash at boot (they only construct Guzzle), but the `/api/posts*` routes will return 502/timeout, and `HealthController` (which fans out to post+comment, lines 27-31) will report them `down` → `/api/health` goes 503 → deploy smoke fails.
**How to avoid:** This is the core gateway-rewiring task. Replace `HealthController`'s 3-way fan-out (user/post/comment) with the 5-way fan-out (profile/connection/feed/search/notification, D-10). Remove post/comment clients, controllers, and routes. Keep `AuthController` + add `ProfilesController`.

## Code Examples

### Gateway DI wiring for a new typed client + controller (clone pattern)
```php
// Source: gateway/public/index.php:37-60 (VERIFIED) — add per service
$container->set(ConnectionClient::class, fn() => new ConnectionClient());
// ...feed, search, notification, profile...
$container->set(HealthController::class, fn(Container $c) => new HealthController(
    $c->get(ProfileClient::class),
    $c->get(ConnectionClient::class),
    $c->get(FeedClient::class),
    $c->get(SearchClient::class),
    $c->get(NotificationClient::class),
));
```

### Typed client clone (stub-level — only health needed now, D-11)
```php
// Source: gateway/src/Services/UserClient.php:11-28 + PostClient.php (VERIFIED)
final class ConnectionClient
{
    private \GuzzleHttp\Client $http;
    public function __construct() {
        $base = getenv('CONNECTION_SERVICE_URL') ?: 'http://connection-service:80';
        $this->http = HttpClient::create($base);
    }
    public function health(): \Psr\Http\Message\ResponseInterface { return $this->http->request('GET', '/health'); }
    public function healthAsync(): \GuzzleHttp\Promise\PromiseInterface { return $this->http->requestAsync('GET', '/health'); }
}
```

### 5-way health fan-out (extend existing settle pattern, D-10)
```php
// Source: gateway/src/Controllers/HealthController.php:25-54 (VERIFIED) — extend keys to 5
$promises = [
    'profile'      => $this->profile->healthAsync(),
    'connection'   => $this->connection->healthAsync(),
    'feed'         => $this->feed->healthAsync(),
    'search'       => $this->search->healthAsync(),
    'notification' => $this->notification->healthAsync(),
];
$settled = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
// ... existing loop builds {status, services, ts}, 200 if all ok else 503 ...
```

## State of the Art

| Old (current repo) | New (after Phase 1) | Impact |
|--------------------|---------------------|--------|
| 6 containers (mariadb, gateway, web, user, post, comment) | 8 containers (mariadb, gateway, web, profile, connection, feed, search, notification) | +2 net; still ≤9, fits 2GB |
| DBs `blog_users/posts/comments` | `proconnect_profile` + 4 empty `proconnect_*` | wipe & reseed (D-06) |
| `/api/users/{id}`, `/api/posts/*`, `/api/comments/*` | `/api/profiles/{id}` (+ reserved prefixes); blog routes removed | accepted breakage (D-04) |
| RequestId gateway-only | RequestId forwarded downstream (D-12) | end-to-end tracing |

**Deprecated/retired in this phase:**
- post-service, comment-service: removed from compose + DI + routes; code kept on disk as reference (D-01).
- `02-schema-posts.sql`, `03-schema-comments.sql`: dropped from active init (keep as reference if desired).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `/api/profiles/{id}` can pass through full profile-service `/users/{id}` fields in Phase 1 (trim in Phase 2) rather than strictly limiting to id/username/display_name now | Pattern 3 | LOW — if user wants strict "basic info only" contract, add a trim in `ProfilesController`. One-line change. Confirm in discuss. |
| A2 | Best X-Request-Id forwarding = inject `'X-Request-Id'` into client requests sourced from a per-request rid (vs. a Guzzle HandlerStack middleware) | Pattern 5 | LOW — both work; affects code clarity not correctness. Planner picks; recommend explicit-param approach for class readability. |
| A3 | All 5 services should remain `service_healthy` gates in the gateway's `depends_on` (vs `service_started`) | Pattern 2 | LOW — `service_healthy` is slower to boot but directly enforces PLAT-05 "all healthy". Reversible. |
| A4 | Recommended D-06 mechanism = idempotent in-container migration script (option B), not volume deletion (option A) | Pitfall 1 | MEDIUM — if the team prefers a clean one-time volume wipe with brief downtime, option A is simpler. Both safe because volume is ProConnect-private. Confirm preference. |

## Open Questions (RESOLVED)

1. **X-Request-Id forwarding approach (A2)** — RESOLVED: request-scoped rid stashed on `HttpClient::setRequestId()` by `RequestIdMiddleware`, read into the default `X-Request-Id` header at lazy per-request client construction (Plan 04 Task 1A). Safe because clients are constructed inside route handlers AFTER the middleware runs (the earlier "one request per fpm process" rationale was wrong and is corrected in the plan).
2. **D-06 cutover mechanism (A4)** — RESOLVED: idempotent in-container migration `db/migrate-phase1.sql` (Plan 05 Task 2) applied to the running mariadb during deploy, with a mandatory pre-wipe `mysqldump` backup (Plan 06). No volume deletion; init scripts updated for fresh environments too.
3. **Live VPS `.env` edit timing** — RESOLVED: Plan 06 adds a deploy-script preflight that fails loudly if any required `*_SVC_DB_PASS` is missing, and documents the manual VPS `.env` edit as an explicit pre-deploy step before the GitHub Actions deploy runs.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker + Compose | whole stack | ✓ (VPS + CI) | — | — (hard requirement, already in use) |
| MariaDB 10.11 (container) | all service DBs | ✓ | 10.11 image | — (own container, not host DB) |
| PHP 8.2 (CI lint) | `php -l` | ✓ | 8.2 via setup-php | — |
| Composer (build-time) | image build | ✓ | from getcomposer.org installer in Dockerfile | — |
| GitHub Actions → SSH → VPS | deploy | ✓ | existing workflow | — |
| Cloudflare + host nginx | HTTPS edge | ✓ | unchanged | — |

**Missing dependencies with no fallback:** None — this is an additive refactor on an already-running stack. No new runtime tools required.
**Missing dependencies with fallback:** None.

## Validation Architecture

> `workflow.nyquist_validation = true` (`.planning/config.json:19`). Included.

### Test Framework
| Property | Value |
|----------|-------|
| Framework | **None present** (no PHPUnit/Pest; only `php -l` lint in CI — STACK.md:41). For Phase 1, validation = bash/`curl` smoke tests against the running stack, matching the existing CI smoke approach. |
| Config file | none — see Wave 0 |
| Quick run command | `curl -sf http://127.0.0.1:8000/api/health` (local) |
| Full suite command | `docker compose up -d --wait && bash scripts/smoke-phase1.sh` (new script, see Wave 0) |

> **Researcher view on PHPUnit (deferred idea):** STATE.md and STACK.md flag "add PHPUnit before a big refactor." For Phase 1, the units changed are thin (clients, a health controller, DI wiring) and the meaningful behavior is integration-level (does the stack boot healthy? does login still work?). **Bash/curl smoke tests give far higher signal-per-effort here than unit tests** and match what CI already does (`deploy.yml:52-64`). Recommend NOT introducing PHPUnit in Phase 1; revisit when business logic lands (Phase 3+). This honors CONTEXT "không bắt buộc Phase 1."

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|--------------|
| PLAT-05 | Stack boots clean, all containers healthy, ≤9 | smoke | `docker compose up -d --wait` then `test $(docker compose ps --services \| wc -l) -le 9` | ❌ Wave 0 (`scripts/smoke-phase1.sh`) |
| PLAT-05 / PLAT-01 | Each service `/health` returns 200 `{status,db,ts}` with `db:ok` | smoke | `for s in profile connection feed search notification; do docker compose exec -T gateway wget -qO- http://$s-service/health; done` | ❌ Wave 0 |
| PLAT-01 / D-10 | Gateway `/api/health` fan-out reports all 5 services ok | smoke | `curl -sf http://127.0.0.1:8000/api/health \| grep -q '"status":"ok"'` and assert 5 service keys present | ❌ Wave 0 |
| PLAT-02 | `/api/profiles/{id}` routes to profile-service, returns a profile | smoke | `curl -sf http://127.0.0.1:8000/api/profiles/2 \| grep -q duyet` | ❌ Wave 0 |
| PLAT-03 | Protected route rejects missing/invalid JWT; services trust X-User-Id | smoke | `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/api/me` → expect 401 | ❌ Wave 0 |
| PLAT-04 | Response carries `X-Request-Id`; downstream receives it (D-12) | smoke | `curl -sD- http://127.0.0.1:8000/api/health \| grep -i x-request-id`; verify in stub logs that inbound rid matches | ❌ Wave 0 (log assertion manual) |
| PLAT-06 | Public HTTPS health green after deploy | smoke (CI) | `curl -sf https://soa.duyet.vn/api/health` (already in `deploy.yml:52-64`) | ✅ exists in CI |
| PROF-01 | Register + login still work end-to-end (JWT issued) | smoke | `curl -sf -XPOST .../api/auth/login -d '{"login":"duyet","password":"demo@123**"}' \| grep -q token` | ❌ Wave 0 |
| D-06 | 5 demo accounts exist post-reseed; login works for each | smoke | login loop over demo/duyet/long/diep/tai | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php -l` on touched files (matches CI lint) + `docker compose config -q` (compose validity).
- **Per wave merge:** `docker compose up -d --wait` + the relevant subset of `scripts/smoke-phase1.sh`.
- **Phase gate:** full `scripts/smoke-phase1.sh` green locally, then push → CI deploy → `https://soa.duyet.vn/api/health` green, before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `scripts/smoke-phase1.sh` — single script asserting: container count ≤9, all 5 `/health` ok, gateway `/api/health` ok with 5 service keys, login works for all 5 demo accounts, `/api/me` 401 without token, `X-Request-Id` present. Covers PLAT-01..06 + PROF-01 + D-06/D-10.
- [ ] No framework install needed — bash + curl + docker compose only (all present).

*(Note: the existing CI already smoke-tests `/api/health`; Wave 0 adds the local pre-push smoke script so failures are caught before deploy.)*

## Security Domain

> `security_enforcement` not set in config → treated as enabled.

### Applicable ASVS Categories
| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | firebase/php-jwt HS256 at gateway (`JwtAuthMiddleware.php`); bcrypt password_hash in profile-service (`UserController.php:70`). Unchanged in Phase 1 — must not regress. |
| V3 Session Management | yes | Stateless JWT, 24h TTL (`AuthController::signToken:70-80`). No server sessions. Unchanged. |
| V4 Access Control | yes | Trust-by-network + `X-User-Id` ownership checks at service (`UserController::update:170`). Stubs have no business access control yet (no routes). Gateway is sole auth point. |
| V5 Input Validation | yes (existing) | Per-service validation in controllers (`UserController::create:40-54`). Stubs have no inputs (health only). Keep pattern for future phases. |
| V6 Cryptography | yes | bcrypt for passwords (never hand-rolled), HS256 for tokens. `JWT_SECRET` ≥16 chars enforced at boot (`public/index.php:25`). Unchanged. |

### Known Threat Patterns for this stack
| Pattern | STRIDE | Standard Mitigation (already in place / to preserve) |
|---------|--------|------------------------------------------------------|
| SQL injection | Tampering | Raw PDO with **real prepared statements** (`Db.php:31` `EMULATE_PREPARES=false`); no string-built SQL. Preserve in any new code. |
| Origin bypass (direct VPS hit) | Spoofing | Host nginx `$cf_allowed` gate → `return 444` for non-Cloudflare IPs (`nginx-soa.duyet.vn.conf:50-52`). Unchanged. |
| Spoofed `X-User-Id` from outside | Spoofing/Elevation | Services unreachable except via gateway on `blog-net` (no host port); gateway sets the header after JWT verify. **Must keep services off host ports** (PLAT-03 invariant). |
| Spoofed `X-Forwarded-For` / IP for rate-limit | Spoofing | Rate-limiter trusts only `CF-Connecting-IP`→`X-Real-IP`→`REMOTE_ADDR`, never raw XFF (RateLimitMiddleware, ARCHITECTURE.md:224). Unchanged. |
| Secret leakage | Info disclosure | `.env` git-ignored; `JWT_SECRET` gateway-only, never in services. New `*_SVC_DB_PASS` follow same pattern. |
| DoS via unbounded fan-out | DoS | Guzzle `connect_timeout=2s`/`timeout=5s` (`HttpClient.php:22-23`); `settle` degrades. 5-way health fan-out stays bounded. |

**Phase-1 security note:** the only new attack surface is 4 stub `/health` endpoints with no auth and no inputs (status/db/ts only) — negligible risk, and they're internal-only (no host port). No new secrets beyond DB passwords following the existing scoped-user model (D-13).

## Sources

### Primary (HIGH confidence — verified in-repo this session)
- `docker-compose.yml` — service topology, healthcheck anchor, mariadb volume/network (the decisive D-06 de-risk).
- `db/00-init.sh`, `db/01-schema-users.sql`, `db/99-seed.sql`, `db/02-schema-posts.sql` — provisioning + seed + fresh-volume-only behavior.
- `scripts/deploy.sh`, `.github/workflows/deploy.yml` — CI/CD flow, `--remove-orphans`, smoke test, lint globs.
- `gateway/public/index.php`, `gateway/src/routes.php` — DI wiring + route registration (where new clients/controllers/routes plug in).
- `gateway/src/Services/HttpClient.php`, `UserClient.php`, `PostClient.php` — typed client clone pattern + X-User-Id injection.
- `gateway/src/Controllers/HealthController.php`, `AuthController.php`, `UsersController.php` — fan-out pattern, auth flow, thin pass-through controller template.
- `gateway/src/Middleware/RequestIdMiddleware.php`, `JwtAuthMiddleware.php` — request-id (D-12 gap) + JWT verify.
- `services/user-service/` (all files) — the skeleton to clone; `Db.php` ping works on empty schema; `UserController::health` minimal health.
- `deploy/nginx-soa.duyet.vn.conf`, `deploy/README.md` — Cloudflare edge, origin gating (unchanged by phase).
- `.env.example`, `.gitignore` — env var inventory + git-ignored `.env` hazard.
- `.planning/codebase/ARCHITECTURE.md` (esp. lines 236-264) — the canonical "new service recipe" + extension caveats.
- `.planning/config.json` — nyquist_validation=true, no security_enforcement override.

### Secondary (MEDIUM)
- `.planning/codebase/STACK.md`, `INTEGRATIONS.md` — locked versions, init-script-once behavior, no test framework.

### Tertiary (LOW)
- None — no web/training claims used. This phase is fully evidenced by repository source.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — locked in committed lockfiles, read directly.
- Architecture / new-service recipe: HIGH — documented in-repo and proven by 3 identical services.
- DB wipe mechanism (D-06): HIGH on topology/risk-containment (verified own-container/own-volume); MEDIUM on chosen cutover mechanism (two safe options, recommendation given, A4 needs user confirmation).
- Pitfalls: HIGH — derived from explicit code comments and Docker init semantics.
- X-Request-Id forwarding: MEDIUM — multiple valid implementations, recommendation given (A2).

**Research date:** 2026-06-06
**Valid until:** stable indefinitely for this brownfield codebase (no fast-moving external deps); re-verify only if `docker-compose.yml`, `db/`, or `gateway/public/index.php` change before planning.
