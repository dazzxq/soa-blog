<!-- GSD:project-start source:PROJECT.md -->
## Project

**ProConnect — Mạng xã hội nghề nghiệp (SOA showcase)**

**ProConnect** là một nền tảng mạng xã hội nghề nghiệp (professional networking) theo phong cách LinkedIn — hồ sơ nghề nghiệp, kết nối giữa người dùng, news feed, tìm kiếm — nhưng có thương hiệu và thiết kế riêng. Đây là đồ án môn **INT1448 - Phát triển phần mềm hướng dịch vụ** (PTIT), nâng cấp từ project "SOA Blog" 3-service hiện có lên một hệ thống microservices phong phú hơn nhiều, nhằm showcase **kiến trúc microservices + API Gateway pattern** ở mức ấn tượng (giảng viên chê bản blog cũ quá đơn giản).

Đối tượng người dùng (trong bối cảnh demo): sinh viên / người đi làm muốn xây dựng hồ sơ nghề nghiệp, kết nối đồng nghiệp, chia sẻ và tương tác nội dung chuyên môn.

**Core Value:** **API Gateway điều phối một hệ microservices đủ phong phú (profile, connection/graph, feed, search) sao cho thể hiện rõ ràng và thuyết phục các trách nhiệm cốt lõi của Gateway pattern** — routing, xác thực tập trung, API composition, đảm bảo invariant cross-service. Mọi tính năng LinkedIn-style đều là phương tiện để làm nổi bật điều này; nếu phải hy sinh, giữ lại tính minh hoạ rõ ràng của pattern hơn là độ đầy đủ tính năng.

### Constraints

- **Tech stack**: PHP 8.2 + Slim 4 + Guzzle + firebase/php-jwt v7 + MariaDB 10.11 + Docker Compose — giữ nguyên (đội ngũ đã quen, VPS chạy ổn, brownfield). Frontend HTML + Alpine.js + Tailwind (có thể nâng cấp Tailwind nhưng giữ no-heavy-build).
- **Hạ tầng**: VPS `14.225.29.159`, Ubuntu 24.04, **2GB RAM CHIA SẺ** với nhiều site khác + MariaDB cùng host — Why: giới hạn cứng. Tổng số container phải ≤ ~9-10; mục tiêu v1 ~6-8 container. Mỗi service PHP ~30-50MB idle.
- **Deploy**: Cùng domain `soa.duyet.vn` qua Cloudflare (Full strict, origin chỉ nhận CF). CI/CD GitHub Actions hiện có.
- **Ngôn ngữ**: Toàn bộ UI + nội dung + error message tiếng Việt có dấu.
- **Pháp lý**: Không sao chép trademark/brand assets LinkedIn — Why: tránh vi phạm SHTT; thương hiệu riêng ProConnect.
- **Trọng tâm môn học**: Không được làm mờ API Gateway pattern — Why: đây là tiêu chí chấm điểm cốt lõi.
<!-- GSD:project-end -->

<!-- GSD:stack-start source:codebase/STACK.md -->
## Technology Stack

## Languages
- **PHP 8.2** — all backend services and the gateway. `declare(strict_types=1)` used throughout (`gateway/public/index.php`, `services/*/src/*.php`). Required constraint `"php": ">=8.2"` in every `composer.json`. **[REUSABLE]**
- **SQL (MariaDB dialect)** — schema and seed in `db/*.sql`. DDL is plain InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`. **[REUSABLE pattern, BLOG-SPECIFIC tables]**
- **JavaScript (vanilla ES, no build)** — frontend logic in `web/assets/app.js`, loaded as a classic script before Alpine. **[REUSABLE wrapper, BLOG-SPECIFIC pages]**
- **Bash** — operational scripts: `scripts/deploy.sh`, `scripts/seed.sh`, `scripts/demo.sh`, `scripts/cloudflare-dns.sh`, and DB init `db/00-init.sh`. **[REUSABLE]**
## Runtime
- **PHP 8.2 FPM on Alpine** — base image `php:8.2-fpm-alpine` for gateway and all 3 services (`gateway/Dockerfile`, `services/*/Dockerfile`, identical).
- Each container runs **nginx + php-fpm together under supervisord** (PID 1). See `services/user-service/supervisord.conf`: `php-fpm -F` (priority 10) + `nginx -g "daemon off;"` (priority 20). nginx listens on `:80`, forwards `.php` to php-fpm on `127.0.0.1:9000` via FastCGI (`services/user-service/nginx.conf`).
- **Compiled PHP extensions** (installed in Dockerfile via `docker-php-ext-install`): `pdo`, `pdo_mysql`, `opcache`, `mbstring` (with oniguruma). `wget` is added for container healthchecks.
- **Static web** served by stock `nginx:alpine` (the `web` service in `docker-compose.yml`), bind-mounting `./web` read-only.
- **Composer** (installed at build time from getcomposer.org installer). Build runs `composer install --no-dev --optimize-autoloader --no-interaction --no-progress`.
- **Lockfile: present and committed** for every PHP module (`gateway/composer.lock`, `services/*/composer.lock`) — versions are reproducible across builds. Dockerfiles copy `composer.json composer.lock` before installing.
- No JS package manager — frontend pulls libraries from CDN (no `package.json`, no `node_modules`).
## Frameworks
- **Slim 4** (`slim/slim ^4.12`, locked **4.15.1**) — HTTP framework for the gateway and all 3 services. PSR-7 via `slim/psr7 ^1.6` (locked **1.8.0**). Routing via `nikic/fast-route v1.3.0`. **[REUSABLE]**
- **PHP-DI** (`php-di/php-di ^7.0`, locked **7.1.1**) — DI container, **gateway only**. Services wire dependencies manually (no container). See `gateway/public/index.php` container setup. **[REUSABLE]**
- **Alpine.js 3.x** — loaded via `https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js` in each HTML page (`web/index.html` etc.). **[REUSABLE approach]**
- **Tailwind CSS (Play CDN)** — `https://cdn.tailwindcss.com`, runtime compilation in-browser. **No build step.** **[REUSABLE approach, but note: Play CDN is not production-grade for a larger app]**
- **None detected.** No PHPUnit, Pest, or any test runner in any `composer.json`; no `tests/` directory; `require-dev` is absent. The only automated check is `php -l` syntax linting in CI. **[GAP for upgrade]**
- **Docker Compose** (`docker-compose.yml`) — single orchestration file, 6 services. **[REUSABLE]**
- **opcache** enabled in images for runtime perf. No asset bundler/transpiler anywhere.
## Key Dependencies
- **`firebase/php-jwt` ^7.0** (locked **v7.0.5**) — JWT encode/decode, HS256. JWT auth is **centralized at the gateway only** (`gateway/src/Middleware/JwtAuthMiddleware.php`, `gateway/src/Controllers/AuthController.php`). Token claims: `sub`, `username`, `iat`, `exp`. **[REUSABLE]**
- **`guzzlehttp/guzzle` ^7.8** (locked **7.10.0**, with `guzzlehttp/promises 2.3.0`, `guzzlehttp/psr7 2.9.0`) — gateway → internal service HTTP. Configured in `gateway/src/Services/HttpClient.php`: `http_errors=false`, `connect_timeout=2.0s`, `timeout=5.0s`, injects `X-Forwarded-By: soa-blog-gateway`. **[REUSABLE]**
- **`ramsey/uuid` ^4.7** (with `brick/math 0.14.8`) — gateway only, used for request IDs / correlation. **[REUSABLE]**
- **`monolog/monolog` ^3.0** (locked **3.10.0**) — logging, in **all** modules. **[REUSABLE]**
- Services are **dependency-minimal and identical** across user/post/comment: `slim/slim ^4.12`, `slim/psr7 ^1.6`, `monolog/monolog ^3.0`, plus `ext-mbstring` and `ext-pdo`. **No ORM, no Guzzle, no JWT lib.** Data access is **raw PDO** (`services/user-service/src/Db.php`): lazy PDO singleton, `mysql:` DSN, `charset=utf8mb4`, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false` (real prepared statements). **[REUSABLE pattern]**
- `ext-mbstring` (all modules — used by `mb_strlen`/`mb_strtolower` in controllers).
- `ext-pdo` (services; gateway does not declare it — gateway has no DB access).
## Configuration
- All config via **environment variables**, no config files baked in. Template: `.env.example` (committed). Real `.env` is git-ignored and **required by `scripts/deploy.sh`** (it aborts if missing). `.env` contents were NOT read (secrets).
- **Env keys** (from `.env.example`): `DB_ROOT_PASSWORD`, `USER_SVC_DB_PASS`, `POST_SVC_DB_PASS`, `COMMENT_SVC_DB_PASS`, `JWT_SECRET`, `RATE_LIMIT_PER_MIN`.
- Per-service DB wiring is set in `docker-compose.yml` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`). Internal service URLs injected into gateway: `USER_SERVICE_URL`, `POST_SERVICE_URL`, `COMMENT_SERVICE_URL` (all `http://<svc>:80`).
- **Fail-fast config guard**: `gateway/public/index.php` exits with `CONFIG_ERROR` if `JWT_SECRET` < 16 chars. `RATE_LIMIT_PER_MIN` defaults to `120`. **[REUSABLE]**
- `gateway/Dockerfile`, `services/*/Dockerfile` (3 identical copies), `docker-compose.yml`.
- Per-container nginx: `services/*/nginx.conf` (app vhost → php-fpm). Static-web nginx: `web/nginx.conf`. Host edge nginx: `deploy/nginx-soa.duyet.vn.conf`.
## Platform Requirements
- Docker + Docker Compose. `docker compose build` then `docker compose up -d`. No local PHP/Node toolchain required to run.
- Local port bindings (loopback only): gateway `127.0.0.1:8000:80`, web `127.0.0.1:8080:80`. MariaDB and the 3 services have **no host ports** — internal `blog-net` bridge network only.
- **VPS** `14.225.29.159`, **Ubuntu 24.04, 2 GB RAM** (small — relevant for the upgrade's resource budget).
- Public URL **https://soa.duyet.vn** behind **Cloudflare** (orange-cloud proxy, SSL Full-strict; origin cert under `/etc/ssl/cloudflare/`). Host nginx (`deploy/nginx-soa.duyet.vn.conf`) terminates TLS, gates non-Cloudflare IPs (`return 444`/`$cf_allowed`), and reverse-proxies to the loopback containers.
- **CI/CD**: `.github/workflows/deploy.yml` — on push to `main`: (1) lint job = `php -l` over all `*.php`; (2) deploy job = SSH to VPS, run `scripts/deploy.sh` (`git pull --ff-only` → `docker compose build --pull` → `up -d`), then verify `https://soa.duyet.vn/api/health`. **[REUSABLE]**
## Stack Reusability Summary (for the upgrade)
- PHP 8.2 + Slim 4 + PHP-DI gateway pattern; raw-PDO service pattern; Guzzle internal client; firebase/php-jwt HS256 auth; Monolog logging; Docker Compose + supervisord(nginx+fpm) packaging; GitHub Actions → VPS deploy; Cloudflare edge.
- **No test framework** — add PHPUnit/Pest before significant refactors.
- **Tailwind Play CDN + no JS build** — fine for a demo blog, will not scale to a richer SPA; expect a build step.
- **2 GB VPS** — a professional network (feed, connections, search) needs a capacity plan; current 6-container footprint already has DB + 3 PHP services.
- **`X-User-Id` trust model** (services blindly trust the gateway-set header; JWT verified only at gateway) — acceptable inside a private network but a real security boundary concern as services grow.
<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->
## Conventions

Conventions not yet established. Will populate as patterns emerge during development.
<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->
## Architecture

## Pattern Overview
- **Single public entry point.** Only the `gateway` (`127.0.0.1:8000`) and the static `web` UI (`127.0.0.1:8080`) are bound to the host. The three domain services (`user-service`, `post-service`, `comment-service`) are reachable ONLY on the internal Docker network `blog-net` — they have no `ports:` mapping. See `docker-compose.yml`.
- **Database-per-service.** Three logically isolated MariaDB databases (`blog_users`, `blog_posts`, `blog_comments`), each with its own dedicated DB user (`user_svc`, `post_svc`, `comment_svc`) that can only access its own schema. Provisioned by `db/00-init.sh`. There are NO cross-database physical foreign keys — references like `posts.author_id` and `comments.post_id`/`comments.author_id` are logical FKs only.
- **Gateway-centric composition.** Services are deliberately "dumb" about each other. Cross-service joins, invariants, and enrichment all happen at the gateway via HTTP composition, not in the data layer.
- **Trust-by-network + header identity.** The gateway is the only component that knows the `JWT_SECRET`. Services never validate JWTs. The gateway authenticates the caller, then forwards a trusted `X-User-Id` header to the service. Services trust this header because they are unreachable except via the gateway on the isolated network.
- **Stateless services, shared DB host.** Each service is a thin Slim app talking to its own database via a lazy PDO singleton (`services/*/src/Db.php`). All three databases live in one `mariadb` container.
## Layers
- Purpose: TLS termination, Cloudflare IP allow-listing, sets `X-Real-IP`, forwards to gateway.
- Location: `deploy/nginx-soa.duyet.vn.conf`, `deploy/cloudflare-ips.conf`, `deploy/cloudflare-geo.conf`.
- Depends on: gateway container port.
- Used by: public internet via Cloudflare.
- Purpose: routing + path rewriting, central authentication, API composition, cross-service invariants, rate limiting, request-id propagation, logging, CORS.
- Location: `gateway/` (Slim app). Entry `gateway/public/index.php`; routes `gateway/src/routes.php`.
- Contains: Controllers (`gateway/src/Controllers/`), Guzzle service clients (`gateway/src/Services/`), middleware (`gateway/src/Middleware/`).
- Depends on: the three domain services over HTTP (`http://user-service:80`, etc., injected via env in `docker-compose.yml`).
- Used by: `web` static frontend and any external API client.
- Purpose: own a single bounded context and its database; expose a private REST API.
- Location: `services/user-service/`, `services/post-service/`, `services/comment-service/`.
- Each contains: `public/index.php` (bootstrap), `src/routes.php`, `src/Controllers/*Controller.php`, `src/Db.php` (PDO singleton), shared `Json`/`DomainError`/`JsonErrorHandler` helpers.
- Depends on: its own MariaDB database only.
- Used by: the gateway only (no host port binding).
- Purpose: persistence, one schema per service.
- Location: `db/01-schema-users.sql`, `db/02-schema-posts.sql`, `db/03-schema-comments.sql`, init in `db/00-init.sh`, seed in `db/99-seed.sql`.
- One `mariadb:10.11` container, volume `mariadb_data`.
- Purpose: demo SPA-ish UI (vanilla HTML/JS) calling the gateway API.
- Location: `web/` (`index.html`, `login.html`, `register.html`, `post.html`, `compose.html`, `assets/app.js`), served by `nginx:alpine` (`web/nginx.conf`).
## Component Diagram
```
```
## Internal API Surfaces
| Method | Path | Auth | Controller |
|--------|------|------|------------|
| GET | `/api/health` | public | `HealthController::check` (fans out to 3 backends) |
| POST | `/api/auth/register` | public | `AuthController::register` |
| POST | `/api/auth/login` | public | `AuthController::login` |
| GET | `/api/me` | JWT | `AuthController::me` |
| GET | `/api/users/{id}` | public | `UsersController::show` |
| GET | `/api/posts` | public | `PostsController::index` (enriched w/ authors) |
| GET | `/api/posts/{id}` | public | `PostsController::show` (+author) |
| GET | `/api/posts/{id}/full` | public | `AggregateController::postFull` (flagship composition) |
| POST | `/api/posts` | JWT | `PostsController::create` |
| PATCH | `/api/posts/{id}` | JWT | `PostsController::update` |
| DELETE | `/api/posts/{id}` | JWT | `PostsController::delete` (invariant: blocks if comments exist) |
| GET | `/api/posts/{id}/comments` | public | `PostsController::comments` (+authors) |
| POST | `/api/posts/{id}/comments` | JWT | `PostsController::createComment` (invariant: post must exist) |
| DELETE | `/api/comments/{id}` | JWT | `PostsController::deleteComment` |
## Data Flow
### Flagship aggregation: `GET /api/posts/{id}/full`
```
```
### Cross-service invariants enforced at gateway
- **Comment requires existing post** (`createComment`): gateway calls `GET post-service /posts/{id}` first. 404 → `DomainError(404, POST_NOT_FOUND)` before any comment is created.
- **Post delete blocked while comments exist** (`delete`): gateway calls `GET comment-service /comments/count?post_id={id}`. If the count call fails or is non-200 → hard `503 COMMENT_SERVICE_UNAVAILABLE` (refuses to delete on incomplete info, avoiding orphaned comments). If `count > 0` → `409 POST_HAS_COMMENTS`. Only `count === 0` proceeds to `DELETE post-service /posts/{id}`.
### Enrichment (N→author join at gateway)
### Auth flow
## Key Abstractions
- Purpose: typed, per-service HTTP client that hides URLs and header injection from controllers.
- Examples: `gateway/src/Services/UserClient.php`, `PostClient.php`, `CommentClient.php`, factory `HttpClient.php`.
- Pattern: each client constructs a Guzzle client from `HttpClient::create($baseUri)` with `http_errors=false` (status inspected manually), `connect_timeout=2s`, `timeout=5s`, default `Accept: application/json` + `X-Forwarded-By: soa-blog-gateway`. Sync + `*Async` variants for composition. Mutating calls inject `X-User-Id`.
- Success single/object: `{ "data": ... }` (`Json::ok`).
- Success list: `{ "data": [...], "meta": {...} }` (`Json::list`).
- Error: `{ "error": { "code": "...", "message": "..." } }` (via `DomainError` + `JsonErrorHandler`).
- Health endpoints return raw `{ "status", "db", "ts" }` (services) / `{ "status", "services", "ts" }` (gateway) — NOT wrapped in `data`.
- Defined in `gateway/src/Json.php` and mirrored in each `services/*/src/Json.php`.
- Purpose: typed HTTP error with a stable machine code + Vietnamese user message.
- Location: `gateway/src/DomainError.php` and `services/*/src/DomainError.php`; rendered by `JsonErrorHandler.php`.
- Codes seen: `UNAUTHORIZED`, `INVALID_TOKEN`, `VALIDATION_FAILED`, `POST_NOT_FOUND`, `POST_HAS_COMMENTS`, `COMMENT_SERVICE_UNAVAILABLE`, `USER_EXISTS`, `FORBIDDEN`, `TOO_MANY_IDS`, `RATE_LIMITED`, `CONFIG_ERROR`, `UPSTREAM_ERROR`.
- Services accept `?ids=1,2,3` on their list endpoints to support gateway-side enrichment without N+1 fan-out. post-service caps at 100 ids (`TOO_MANY_IDS`).
## Entry Points
- Location: `gateway/public/index.php`.
- Triggers: every `/api/*` HTTP request (nginx → php-fpm via `gateway/nginx.conf` + `gateway/supervisord.conf`).
- Responsibilities: fail-fast if `JWT_SECRET` < 16 chars; build PHP-DI container; register singleton clients + controllers + `JwtAuthMiddleware`; install body parsing, middleware stack, routing, JSON error handler; load routes; run.
- Location: `services/*/public/index.php` → `src/routes.php`.
- Triggers: internal HTTP from gateway over `blog-net`.
## Error Handling
- **Pass-through:** when the gateway has no opinion, it forwards the upstream service's `{data}`/`{error}` body and status verbatim (`Json::raw($res, decode(upstream), upstream->status)`).
- **Translate:** when the gateway owns the invariant, it replaces upstream status with its own (e.g. comment-count failure → `503 COMMENT_SERVICE_UNAVAILABLE`).
- **Degrade:** non-core composition failures yield partial `data` + `meta.degraded` instead of an error (aggregation + enrichment paths).
- **Fail-fast config:** missing `JWT_SECRET` returns `500 CONFIG_ERROR` at boot.
## Cross-Cutting Concerns
## Build Order Implications / Extension Points
- The gateway is the only JWT-aware component. New services MUST authorize via the forwarded `X-User-Id` header, never by parsing tokens. Keep `JWT_SECRET` gateway-only.
- If finer-grained authorization is needed (e.g. who-can-view-whose-profile), put cross-service authorization at the gateway (it already does this for post/comment ownership), or forward additional trusted claims (e.g. add `X-Username`, roles) the same way `X-User-Id` is forwarded.
- **Feed** is read-heavy and joins posts + authors + connection graph. The current pattern is synchronous gateway composition (`settle` + batch `?ids=`). This works for small fan-out but will not scale to a real feed. Likely needs either (a) a dedicated `feed-service` that maintains a materialized timeline (write fan-out / fan-out-on-read cache), or (b) introducing async messaging. Today there is NO message broker, NO event bus, NO cache — all coupling is synchronous HTTP. Adding one is a deliberate architectural step, not a drop-in.
- **Connection / graph service** introduces a many-to-many relationship (`connections(user_a, user_b, status)`) in its own DB. Invariants like "can only connect to an existing user" mirror the existing "comment requires existing post" pattern — enforce at the gateway by checking user-service before writing.
- **Profile service**: either extend user-service or split a `profile-service`. If split, the gateway's existing author-enrichment (`UserClient::batch`) must be generalized to also batch-fetch profile data, or the aggregate/enrich helpers (`PostsController::fetchAuthors`, `AggregateController`) extended to merge profile fields.
- **Search service** typically owns an index (e.g. fed from other services). With no event bus today, the simplest first cut is gateway-orchestrated search that queries an index the search-service builds via periodic pulls; a proper design needs change events from post/user/profile services.
- `gateway/src/routes.php` — new public routes + auth attachment.
- `gateway/src/Services/*Client.php` — new typed clients; keep `?ids=` batch + `*Async`.
- `gateway/src/Controllers/AggregateController.php` and `PostsController::fetchAuthors` — enrichment/composition logic (this is where new "joins" go).
- `gateway/public/index.php` — DI wiring + middleware (consider forwarding `X-Request-Id` downstream now, before the graph of services grows, to get end-to-end tracing cheaply).
- `db/00-init.sh` + new `db/0X-schema-*.sql` — per-service schema/user.
- All synchronous composition: any added service becomes a latency dependency of the endpoints that compose it. The 5s Guzzle timeout and `settle`-based degradation are the only resilience primitives — there is no retry, circuit breaker, or cache.
- Single shared MariaDB container hosts all per-service databases; isolation is logical (separate DBs + users), not physical. Real scale would split DB hosts.
- Rate limiting is per-IP, per-gateway-container, file-backed in `/tmp` — does not coordinate across multiple gateway replicas.
<!-- GSD:architecture-end -->

<!-- GSD:skills-start source:skills/ -->
## Project Skills

No project skills found. Add skills to any of: `.claude/skills/`, `.agents/skills/`, `.cursor/skills/`, or `.github/skills/` with a `SKILL.md` index file.
<!-- GSD:skills-end -->

<!-- GSD:workflow-start source:GSD defaults -->
## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:
- `/gsd-quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd-debug` for investigation and bug fixing
- `/gsd-execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->



<!-- GSD:profile-start -->
## Developer Profile

> Profile not yet configured. Run `/gsd-profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->
