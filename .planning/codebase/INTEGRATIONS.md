# External Integrations

**Analysis Date:** 2026-06-06

> External and infrastructure integrations for the **SOA Blog** showcase. The app is intentionally self-contained — it talks to almost no third-party APIs. The "integrations" are mostly internal service-to-service calls, the database, and the Cloudflare/VPS edge.

## APIs & External Services

**Third-party runtime APIs:**
- **None.** No payment, email, storage, AI, analytics, or social SDKs in any `composer.json`. The backend has zero outbound external API calls.

**CDN-delivered frontend libraries (browser-loaded, not server integrations):**
- **Tailwind CSS** — `https://cdn.tailwindcss.com` (Play CDN, runtime compile).
- **Alpine.js 3.x** — `https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js`.
- Referenced in every page under `web/` (`index.html`, `login.html`, `register.html`, `compose.html`, `post.html`).

## Internal Service-to-Service Integration

**Gateway → backend services (HTTP/JSON over `blog-net`):**
- Transport: **Guzzle** singleton (`gateway/src/Services/HttpClient.php`), `connect_timeout=2.0s`, `timeout=5.0s`, `http_errors=false`, header `X-Forwarded-By: soa-blog-gateway`.
- Typed clients: `gateway/src/Services/UserClient.php`, `PostClient.php`, `CommentClient.php`.
- Targets (from `docker-compose.yml`): `USER_SERVICE_URL=http://user-service:80`, `POST_SERVICE_URL=http://post-service:80`, `COMMENT_SERVICE_URL=http://comment-service:80`.
- **Identity propagation:** services do **not** verify JWT. The gateway authenticates and forwards user identity to services (X-User-Id / header trust model). Services receive a numeric user id and trust it.
- **Composition/aggregation** happens at the gateway: `gateway/src/Controllers/AggregateController.php` (e.g. `/api/posts/{id}/full` fans out to post + user + comment services).

## Data Storage

**Databases:**
- **MariaDB 10.11** (`image: mariadb:10.11` in `docker-compose.yml`).
- **Database-per-service** pattern, created by `db/00-init.sh` on first volume init:
  - `blog_users` — owner `user_svc` (`db/01-schema-users.sql`: `users` table — username, email, password_hash, display_name, avatar_url).
  - `blog_posts` — owner `post_svc` (`db/02-schema-posts.sql`: `posts` — author_id, title, slug, content).
  - `blog_comments` — owner `comment_svc` (`db/03-schema-comments.sql`: `comments` — post_id, author_id, body).
- **3 separate DB users with scoped grants** — each service user has `ALL PRIVILEGES` on **only its own** database (`db/00-init.sh`). No cross-database access. All `utf8mb4` / `utf8mb4_unicode_ci`, InnoDB.
- Client: **raw PDO** (`services/*/src/Db.php`), real prepared statements (`EMULATE_PREPARES=false`). No ORM, no migration tool — schema is applied once via MariaDB's `/docker-entrypoint-initdb.d` on first boot.
- Connection: `DB_HOST=mariadb` (internal DNS), credentials per-service via env (`DB_USER`/`DB_PASS`).
- Seed data: `db/99-seed.sql` (+ `scripts/seed.sh`). **[BLOG-SPECIFIC]**

**File Storage:**
- **Local filesystem only.** No object storage / S3. Persistent volume `mariadb_data` for the DB; `web/` is bind-mounted read-only. `avatar_url` is a plain `VARCHAR(512)` URL string, not an upload pipeline.

**Caching:**
- **None.** No Redis/Memcached. Rate limiting (`gateway/src/Middleware/RateLimitMiddleware.php`) is in-process/in-memory per gateway container — not a shared cache.

## Authentication & Identity

**Auth Provider:**
- **Custom, self-hosted JWT** (no external IdP). `firebase/php-jwt v7.0.5`, **HS256**, shared `JWT_SECRET` (gateway env only).
- Issued/validated at the gateway: `gateway/src/Controllers/AuthController.php` (`JWT::encode` with claims `sub`, `username`, `iat`, `exp`) and `gateway/src/Middleware/JwtAuthMiddleware.php` (`JWT::decode`, `Bearer` extraction, sets `user_id`/`username` request attributes).
- Passwords stored as `password_hash` (`VARCHAR(255)`) in `blog_users.users` — handled by user-service.
- Frontend stores token + user in `localStorage` (`soa_blog_token`, `soa_blog_user`) and sends `Authorization: Bearer` (`web/assets/app.js`).
- **Trust boundary:** downstream services trust gateway-forwarded identity; they do not re-verify the token. **[Security consideration for upgrade — see CONCERNS]**

## Monitoring & Observability

**Error Tracking:**
- **None** (no Sentry/Bugsnag). Errors surface as structured JSON via `gateway/src/JsonErrorHandler.php` and per-service `JsonErrorHandler.php`.

**Logs:**
- **Monolog ^3.0** in all modules. supervisord pipes php-fpm + nginx output to `/dev/stdout` / `/dev/stderr` → captured by Docker logs (`services/*/supervisord.conf`).
- Request logging + correlation IDs at gateway: `LoggingMiddleware.php`, `RequestIdMiddleware.php` (ramsey/uuid).
- Host nginx access/error logs: `/var/log/nginx/soa.duyet.vn-*.log` (`deploy/nginx-soa.duyet.vn.conf`).

## CI/CD & Deployment

**Hosting:**
- **Single VPS** `14.225.29.159` (Ubuntu 24.04, 2 GB RAM). All 6 containers + host nginx on one box.
- **Cloudflare** in front (proxy/orange-cloud, SSL Full-strict). Host nginx restricts origin access to Cloudflare IP ranges (`deploy/cloudflare-ips.conf`, `deploy/cloudflare-geo.conf`, `$cf_allowed` gate, `return 444`/`444`).

**CI Pipeline:**
- **GitHub Actions** — `.github/workflows/deploy.yml`. Trigger: push to `main` (+ manual `workflow_dispatch`). `concurrency` guards against overlapping deploys.
  - `lint` job: `shivammathur/setup-php@v2` (PHP 8.2) → `php -l` across `gateway/{src,public}` and `services/*/{src,public}`.
  - `deploy` job (needs lint, main only): adds SSH key from secrets, `ssh-keyscan`, runs `scripts/deploy.sh` on the VPS, then polls `https://soa.duyet.vn/api/health` (5 attempts) as a smoke test.
- Deploy script `scripts/deploy.sh`: requires `.env`, `git pull --ff-only`, `docker compose build --pull`, `up -d --remove-orphans`, conditional `web` restart (bind-mount inode caveat), gateway health wait, and idempotent host-nginx config sync.

## Environment Configuration

**Required env vars** (from `.env.example`; values not read):
- `DB_ROOT_PASSWORD` — MariaDB root.
- `USER_SVC_DB_PASS`, `POST_SVC_DB_PASS`, `COMMENT_SVC_DB_PASS` — per-service DB users.
- `JWT_SECRET` — gateway only; **must be ≥16 chars** or gateway fails to boot.
- `RATE_LIMIT_PER_MIN` — optional, defaults to `120`.

**Secrets location:**
- App secrets: git-ignored `.env` on the VPS (consumed by Compose + `db/00-init.sh`). `.env` was NOT read.
- CI secrets: GitHub Actions secrets `VPS_SSH_KEY`, `VPS_HOST`, `VPS_USER`, `VPS_DIR`.
- TLS: Cloudflare origin cert/key under `/etc/ssl/cloudflare/` on the VPS.
- Cloudflare global API key: macOS keychain (per project memory) — used by `scripts/cloudflare-dns.sh`, not by the running app.

## Webhooks & Callbacks

**Incoming:**
- **None** beyond the public REST API at `/api/*` (defined in `gateway/src/routes.php`: auth, users, posts, comments, aggregate, health). No third-party webhook receivers.

**Outgoing:**
- **None.** No outbound webhooks/callbacks to external systems.

## Integration Reusability Summary (for the upgrade)

**Reusable:** gateway-fronted internal HTTP composition, database-per-service with scoped DB users, custom JWT auth, Monolog→Docker logs, GitHub Actions→VPS→Cloudflare delivery.

**Will need new integrations for a professional network:** shared cache (Redis) for sessions/feed/rate-limit, object storage for media/avatars (current `avatar_url` is just a string), search infrastructure, and likely email/notifications — none of which exist today.

---

*Integration audit: 2026-06-06*
