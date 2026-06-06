# Architecture

**Analysis Date:** 2026-06-06

## Pattern Overview

**Overall:** API Gateway over a set of database-per-service microservices (Service-Oriented Architecture showcase). All written in PHP (Slim 4 + Guzzle), orchestrated with Docker Compose, fronted by nginx + Cloudflare in production.

**Key Characteristics:**
- **Single public entry point.** Only the `gateway` (`127.0.0.1:8000`) and the static `web` UI (`127.0.0.1:8080`) are bound to the host. The three domain services (`user-service`, `post-service`, `comment-service`) are reachable ONLY on the internal Docker network `blog-net` — they have no `ports:` mapping. See `docker-compose.yml`.
- **Database-per-service.** Three logically isolated MariaDB databases (`blog_users`, `blog_posts`, `blog_comments`), each with its own dedicated DB user (`user_svc`, `post_svc`, `comment_svc`) that can only access its own schema. Provisioned by `db/00-init.sh`. There are NO cross-database physical foreign keys — references like `posts.author_id` and `comments.post_id`/`comments.author_id` are logical FKs only.
- **Gateway-centric composition.** Services are deliberately "dumb" about each other. Cross-service joins, invariants, and enrichment all happen at the gateway via HTTP composition, not in the data layer.
- **Trust-by-network + header identity.** The gateway is the only component that knows the `JWT_SECRET`. Services never validate JWTs. The gateway authenticates the caller, then forwards a trusted `X-User-Id` header to the service. Services trust this header because they are unreachable except via the gateway on the isolated network.
- **Stateless services, shared DB host.** Each service is a thin Slim app talking to its own database via a lazy PDO singleton (`services/*/src/Db.php`). All three databases live in one `mariadb` container.

## Layers

**Edge / Reverse proxy (production only):**
- Purpose: TLS termination, Cloudflare IP allow-listing, sets `X-Real-IP`, forwards to gateway.
- Location: `deploy/nginx-soa.duyet.vn.conf`, `deploy/cloudflare-ips.conf`, `deploy/cloudflare-geo.conf`.
- Depends on: gateway container port.
- Used by: public internet via Cloudflare.

**API Gateway (public surface):**
- Purpose: routing + path rewriting, central authentication, API composition, cross-service invariants, rate limiting, request-id propagation, logging, CORS.
- Location: `gateway/` (Slim app). Entry `gateway/public/index.php`; routes `gateway/src/routes.php`.
- Contains: Controllers (`gateway/src/Controllers/`), Guzzle service clients (`gateway/src/Services/`), middleware (`gateway/src/Middleware/`).
- Depends on: the three domain services over HTTP (`http://user-service:80`, etc., injected via env in `docker-compose.yml`).
- Used by: `web` static frontend and any external API client.

**Domain services (internal only):**
- Purpose: own a single bounded context and its database; expose a private REST API.
- Location: `services/user-service/`, `services/post-service/`, `services/comment-service/`.
- Each contains: `public/index.php` (bootstrap), `src/routes.php`, `src/Controllers/*Controller.php`, `src/Db.php` (PDO singleton), shared `Json`/`DomainError`/`JsonErrorHandler` helpers.
- Depends on: its own MariaDB database only.
- Used by: the gateway only (no host port binding).

**Data layer:**
- Purpose: persistence, one schema per service.
- Location: `db/01-schema-users.sql`, `db/02-schema-posts.sql`, `db/03-schema-comments.sql`, init in `db/00-init.sh`, seed in `db/99-seed.sql`.
- One `mariadb:10.11` container, volume `mariadb_data`.

**Static web client:**
- Purpose: demo SPA-ish UI (vanilla HTML/JS) calling the gateway API.
- Location: `web/` (`index.html`, `login.html`, `register.html`, `post.html`, `compose.html`, `assets/app.js`), served by `nginx:alpine` (`web/nginx.conf`).

## Component Diagram

```
                          Internet
                             │  (production: Cloudflare → host nginx, TLS,
                             │   X-Real-IP / CF-Connecting-IP)
                             ▼
                  ┌──────────────────────┐         ┌────────────────────┐
   host :8080 ───►│  web (nginx:alpine)  │         │  external API      │
   (127.0.0.1)    │  static HTML/JS      │         │  client            │
                  └───────────┬──────────┘         └─────────┬──────────┘
                              │  fetch /api/*                 │
                              ▼                               ▼
                       ┌──────────────────────────────────────────┐
        host :8000 ───►│              GATEWAY (Slim 4)             │
        (127.0.0.1)    │  Middleware (outer→inner):                │
                       │   Logging → CORS → RateLimit → RequestId  │
                       │   → Routing → [JwtAuth on protected]      │
                       │  Controllers: Auth, Users, Posts,         │
                       │   Aggregate, Health                       │
                       │  Holds JWT_SECRET. Signs/verifies tokens. │
                       │  Composition + invariants live here.      │
                       └───┬──────────────┬──────────────┬─────────┘
                           │ Guzzle        │ Guzzle        │ Guzzle
                           │ X-User-Id     │ X-User-Id     │ X-User-Id
                           │ (http_errors  │               │
                           │  =false,      │               │
                           │  2s connect / │               │
                           │  5s timeout)  │               │
              ┌────────────▼───┐  ┌────────▼───────┐  ┌────▼──────────────┐
              │ user-service   │  │ post-service   │  │ comment-service   │
              │ (Slim 4, :80)  │  │ (Slim 4, :80)  │  │ (Slim 4, :80)     │
              │ NOT host-bound │  │ NOT host-bound │  │ NOT host-bound    │
              └────────┬───────┘  └────────┬───────┘  └────────┬──────────┘
                       │ PDO               │ PDO               │ PDO
                       │ user_svc          │ post_svc          │ comment_svc
              ┌────────▼──────────────────────────────────────────────────┐
              │                    mariadb:10.11                           │
              │  blog_users        blog_posts        blog_comments         │
              │  (logical FKs only — no cross-DB physical foreign keys)    │
              └────────────────────────────────────────────────────────────┘

   All boxes share Docker network `blog-net` (driver: bridge).
   Only `web` and `gateway` publish a host port (both 127.0.0.1-bound).
```

## Internal API Surfaces

**Gateway public surface** (`gateway/src/routes.php`, all under `/api`):
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

**user-service** (`services/user-service/src/routes.php`) — internal, no `/api` prefix:
`GET /health`, `GET /users` (supports `?ids=` batch), `POST /users`, `POST /users/verify-credentials`, `GET /users/{id}`, `PATCH /users/{id}`.

**post-service** (`services/post-service/src/routes.php`):
`GET /health`, `GET /posts` (supports `?ids=` batch, `?author_id=`, pagination), `POST /posts`, `GET /posts/{id}`, `PATCH /posts/{id}`, `DELETE /posts/{id}`.

**comment-service** (`services/comment-service/src/routes.php`):
`GET /health`, `GET /comments` (`?post_id=`), `GET /comments/count` (`?post_id=`), `POST /comments`, `DELETE /comments/{id}`.

**Path rewriting:** the gateway strips the `/api` prefix and remaps to internal verbs via the service clients in `gateway/src/Services/`. E.g. `POST /api/posts/{id}/comments` → post existence check `GET /posts/{id}` then `POST /comments` with `{post_id, body}`. There is no transparent reverse-proxy pass-through; every route is an explicit controller method that calls typed client methods.

## Data Flow

### Flagship aggregation: `GET /api/posts/{id}/full`
Implemented in `gateway/src/Controllers/AggregateController.php`. API Composition pattern.

1. **Step 1 (sync, core):** gateway calls `PostClient::get(id)` → `GET post-service /posts/{id}`.
   - 404 → throw `DomainError(404, POST_NOT_FOUND)` immediately (post is the spine of the response).
   - non-200 (other) → pass upstream payload/status through verbatim.
   - 200 → extract `data`, read `author_id`.
2. **Step 2 (parallel):** `GuzzleHttp\Promise\Utils::settle([...])->wait()` issues two concurrent requests:
   - `UserClient::getAsync(author_id)` → `GET user-service /users/{author_id}`.
   - `CommentClient::listByPostAsync(id, {per_page:100})` → `GET comment-service /comments?post_id={id}&per_page=100`.
   - `settle` (not `unwrap`) means a rejected/failed promise does NOT throw — each part degrades independently.
3. **Step 3 (sync, batch):** collect unique `author_id`s from the comments, call `UserClient::batch(ids)` → `GET user-service /users?ids=1,2,3`. Wrapped in try/catch; a user-service hiccup must NOT 500 the page.
4. **Step 4 (assemble):** attach `post.author`, and `comments[*].author` (keyed by id, `null` if missing). Emit `{data: post}`. If any part failed, also emit `meta: {degraded:true, parts:[...]}`.

**Degraded-mode contract:** the endpoint returns 200 with partial data plus a `meta.degraded` marker rather than failing the whole request when a non-core dependency is unavailable. The post itself is the only hard dependency.

```
client ──GET /api/posts/42/full──► gateway
                                     │ 1. GET post-svc /posts/42        (sync, hard dep)
                                     │
                                     ├─► 2a. GET user-svc /users/{authId}  ┐ parallel
                                     └─► 2b. GET comment-svc /comments?...  ┘ (settle)
                                     │ 3. GET user-svc /users?ids=...   (batch comment authors)
                                     │ 4. merge → {data:{...post, author, comments[*].author}}
                                     ▼
                                   {data: {...}, meta?: {degraded, parts}}
```

### Cross-service invariants enforced at gateway
These are business rules that span service boundaries; because there are no cross-DB FKs, the gateway owns them. See `gateway/src/Controllers/PostsController.php`.

- **Comment requires existing post** (`createComment`): gateway calls `GET post-service /posts/{id}` first. 404 → `DomainError(404, POST_NOT_FOUND)` before any comment is created.
- **Post delete blocked while comments exist** (`delete`): gateway calls `GET comment-service /comments/count?post_id={id}`. If the count call fails or is non-200 → hard `503 COMMENT_SERVICE_UNAVAILABLE` (refuses to delete on incomplete info, avoiding orphaned comments). If `count > 0` → `409 POST_HAS_COMMENTS`. Only `count === 0` proceeds to `DELETE post-service /posts/{id}`.

### Enrichment (N→author join at gateway)
`PostsController::index`, `::show`, and `::comments` enrich rows with their author by collecting `author_id`s and batch-fetching via `UserClient::batch()` (`fetchAuthors()` helper). A failed user-service batch degrades to `author: null` rather than erroring.

### Auth flow
`gateway/src/Controllers/AuthController.php` + `gateway/src/Middleware/JwtAuthMiddleware.php`:
1. Register/login → gateway calls user-service (`POST /users` or `POST /users/verify-credentials`). user-service owns password hashing/verification.
2. On success the gateway signs an HS256 JWT (`iss=soa-blog-gateway`, `sub=user.id`, `username`, 24h TTL) using `JWT_SECRET`. Token returned to client.
3. Protected routes run `JwtAuthMiddleware`: parse `Authorization: Bearer`, verify signature, set `request->user_id` attribute. Controllers read `user_id` and forward it downstream as `X-User-Id`.
4. Services read `X-User-Id` (e.g. `PostController::create/update/delete`) and enforce ownership (e.g. `403 FORBIDDEN` if `X-User-Id !== post.author_id`). Services never see or validate the JWT.

**State Management:** services are stateless per request (lazy PDO singleton per worker, `services/*/src/Db.php`). The only gateway-side state is the rate-limit counter persisted to `/tmp/rate-limit.json` (flock-guarded, shared across php-fpm workers) — see `gateway/src/Middleware/RateLimitMiddleware.php`.

## Key Abstractions

**Service client (Guzzle wrapper):**
- Purpose: typed, per-service HTTP client that hides URLs and header injection from controllers.
- Examples: `gateway/src/Services/UserClient.php`, `PostClient.php`, `CommentClient.php`, factory `HttpClient.php`.
- Pattern: each client constructs a Guzzle client from `HttpClient::create($baseUri)` with `http_errors=false` (status inspected manually), `connect_timeout=2s`, `timeout=5s`, default `Accept: application/json` + `X-Forwarded-By: soa-blog-gateway`. Sync + `*Async` variants for composition. Mutating calls inject `X-User-Id`.

**Response envelope:**
- Success single/object: `{ "data": ... }` (`Json::ok`).
- Success list: `{ "data": [...], "meta": {...} }` (`Json::list`).
- Error: `{ "error": { "code": "...", "message": "..." } }` (via `DomainError` + `JsonErrorHandler`).
- Health endpoints return raw `{ "status", "db", "ts" }` (services) / `{ "status", "services", "ts" }` (gateway) — NOT wrapped in `data`.
- Defined in `gateway/src/Json.php` and mirrored in each `services/*/src/Json.php`.

**Domain error:**
- Purpose: typed HTTP error with a stable machine code + Vietnamese user message.
- Location: `gateway/src/DomainError.php` and `services/*/src/DomainError.php`; rendered by `JsonErrorHandler.php`.
- Codes seen: `UNAUTHORIZED`, `INVALID_TOKEN`, `VALIDATION_FAILED`, `POST_NOT_FOUND`, `POST_HAS_COMMENTS`, `COMMENT_SERVICE_UNAVAILABLE`, `USER_EXISTS`, `FORBIDDEN`, `TOO_MANY_IDS`, `RATE_LIMITED`, `CONFIG_ERROR`, `UPSTREAM_ERROR`.

**Batch/`?ids=` convention:**
- Services accept `?ids=1,2,3` on their list endpoints to support gateway-side enrichment without N+1 fan-out. post-service caps at 100 ids (`TOO_MANY_IDS`).

## Entry Points

**Gateway bootstrap:**
- Location: `gateway/public/index.php`.
- Triggers: every `/api/*` HTTP request (nginx → php-fpm via `gateway/nginx.conf` + `gateway/supervisord.conf`).
- Responsibilities: fail-fast if `JWT_SECRET` < 16 chars; build PHP-DI container; register singleton clients + controllers + `JwtAuthMiddleware`; install body parsing, middleware stack, routing, JSON error handler; load routes; run.

**Service bootstrap (×3):**
- Location: `services/*/public/index.php` → `src/routes.php`.
- Triggers: internal HTTP from gateway over `blog-net`.

**Middleware order (gateway, `gateway/public/index.php`):**
Slim runs middleware LIFO, so the registered order (`Logging`, `CORS`, `RateLimit`, `RequestId`) yields request flow **outer→inner: Logging → CORS → RateLimit → RequestId → Routing → (JwtAuth on protected routes)**. `JwtAuthMiddleware` is attached per-route via `->add($jwtMw)` in `routes.php`, not globally.

## Error Handling

**Strategy:** controllers throw `DomainError(status, code, message)`; `JsonErrorHandler` renders the `{error:{code,message}}` envelope with the right HTTP status. Guzzle clients use `http_errors=false`, so upstream non-2xx is inspected explicitly and either passed through (`Json::raw`) or translated into a gateway-owned `DomainError`.

**Patterns:**
- **Pass-through:** when the gateway has no opinion, it forwards the upstream service's `{data}`/`{error}` body and status verbatim (`Json::raw($res, decode(upstream), upstream->status)`).
- **Translate:** when the gateway owns the invariant, it replaces upstream status with its own (e.g. comment-count failure → `503 COMMENT_SERVICE_UNAVAILABLE`).
- **Degrade:** non-core composition failures yield partial `data` + `meta.degraded` instead of an error (aggregation + enrichment paths).
- **Fail-fast config:** missing `JWT_SECRET` returns `500 CONFIG_ERROR` at boot.

## Cross-Cutting Concerns

**Logging:** `gateway/src/Middleware/LoggingMiddleware.php` writes one line per request to stderr (`[gateway] IP METHOD PATH -> STATUS Nms rid=...`), forwarded by supervisord to docker logs.

**Request correlation:** `gateway/src/Middleware/RequestIdMiddleware.php` honors an inbound `X-Request-Id` or generates a UUIDv4, stores it on the request, and echoes it on the response. NOTE: the request id is currently NOT propagated downstream to the services (the Guzzle clients do not forward `X-Request-Id`). This is an extension gap if end-to-end tracing is wanted.

**Rate limiting:** `gateway/src/Middleware/RateLimitMiddleware.php` — per-IP fixed 1-minute window, default 120/min (`RATE_LIMIT_PER_MIN`). Client IP resolved from trusted headers only, in order: `CF-Connecting-IP` → `X-Real-IP` → `REMOTE_ADDR` (never raw `X-Forwarded-For`). State in `/tmp/rate-limit.json` (flock). Emits `X-RateLimit-*` and `Retry-After`.

**CORS:** `gateway/src/Middleware/CorsMiddleware.php` — reflects `Origin`, allows credentials, short-circuits `OPTIONS` preflight with 204.

**Authentication:** centralized at gateway (JWT HS256, `JWT_SECRET` only on gateway). Services authorize via trusted `X-User-Id` header + network isolation.

**Validation:** each service validates its own inputs (e.g. `UserController::create`, `PostController::create`). The gateway does light pre-checks (login/password presence, post existence) but does not duplicate full domain validation.

## Build Order Implications / Extension Points

This section is for the LinkedIn-style expansion (profile, connection/graph, feed, search services).

**Repeatable "new service" recipe** (mirror an existing service folder, e.g. `services/post-service/`):
1. Scaffold `services/<name>-service/` with `Dockerfile`, `nginx.conf`, `supervisord.conf`, `composer.json`, `public/index.php`, `src/routes.php`, `src/Controllers/`, `src/Db.php`, and shared `Json`/`DomainError`/`JsonErrorHandler` (copy from any service).
2. Add a database: new `db/0X-schema-<name>.sql`, and extend `db/00-init.sh` to `CREATE DATABASE blog_<name>` + a dedicated `<name>_svc` DB user with grants scoped to that schema only. Add the `<NAME>_SVC_DB_PASS` env var to `mariadb` service and `.env.example`.
3. Register the service in `docker-compose.yml`: `build:`, `DB_*` env, `networks: [blog-net]`, `depends_on: mariadb (service_healthy)`, the shared `*svc-healthcheck`. **Do NOT add a host `ports:` mapping** — keep it internal, consistent with the trust model.
4. Add `<NAME>_SERVICE_URL: http://<name>-service:80` to the gateway's env and a `gateway depends_on` condition.
5. Create a gateway client `gateway/src/Services/<Name>Client.php` (clone `PostClient.php`; reuse `HttpClient::create`, inject `X-User-Id` on mutations, add `*Async` variants for any composition).
6. Register the client + controller as container singletons in `gateway/public/index.php`, add routes in `gateway/src/routes.php` under `/api`, protecting mutations with `->add($jwtMw)`.

**Identity / trust model is the key constraint:**
- The gateway is the only JWT-aware component. New services MUST authorize via the forwarded `X-User-Id` header, never by parsing tokens. Keep `JWT_SECRET` gateway-only.
- If finer-grained authorization is needed (e.g. who-can-view-whose-profile), put cross-service authorization at the gateway (it already does this for post/comment ownership), or forward additional trusted claims (e.g. add `X-Username`, roles) the same way `X-User-Id` is forwarded.

**Composition vs. data duplication (decide per feature):**
- **Feed** is read-heavy and joins posts + authors + connection graph. The current pattern is synchronous gateway composition (`settle` + batch `?ids=`). This works for small fan-out but will not scale to a real feed. Likely needs either (a) a dedicated `feed-service` that maintains a materialized timeline (write fan-out / fan-out-on-read cache), or (b) introducing async messaging. Today there is NO message broker, NO event bus, NO cache — all coupling is synchronous HTTP. Adding one is a deliberate architectural step, not a drop-in.
- **Connection / graph service** introduces a many-to-many relationship (`connections(user_a, user_b, status)`) in its own DB. Invariants like "can only connect to an existing user" mirror the existing "comment requires existing post" pattern — enforce at the gateway by checking user-service before writing.
- **Profile service**: either extend user-service or split a `profile-service`. If split, the gateway's existing author-enrichment (`UserClient::batch`) must be generalized to also batch-fetch profile data, or the aggregate/enrich helpers (`PostsController::fetchAuthors`, `AggregateController`) extended to merge profile fields.
- **Search service** typically owns an index (e.g. fed from other services). With no event bus today, the simplest first cut is gateway-orchestrated search that queries an index the search-service builds via periodic pulls; a proper design needs change events from post/user/profile services.

**Concrete extension points to touch for any cross-service feature:**
- `gateway/src/routes.php` — new public routes + auth attachment.
- `gateway/src/Services/*Client.php` — new typed clients; keep `?ids=` batch + `*Async`.
- `gateway/src/Controllers/AggregateController.php` and `PostsController::fetchAuthors` — enrichment/composition logic (this is where new "joins" go).
- `gateway/public/index.php` — DI wiring + middleware (consider forwarding `X-Request-Id` downstream now, before the graph of services grows, to get end-to-end tracing cheaply).
- `db/00-init.sh` + new `db/0X-schema-*.sql` — per-service schema/user.

**Scaling/coupling caveats to flag for the roadmapper:**
- All synchronous composition: any added service becomes a latency dependency of the endpoints that compose it. The 5s Guzzle timeout and `settle`-based degradation are the only resilience primitives — there is no retry, circuit breaker, or cache.
- Single shared MariaDB container hosts all per-service databases; isolation is logical (separate DBs + users), not physical. Real scale would split DB hosts.
- Rate limiting is per-IP, per-gateway-container, file-backed in `/tmp` — does not coordinate across multiple gateway replicas.

---

*Architecture analysis: 2026-06-06*
