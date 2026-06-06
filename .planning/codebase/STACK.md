# Technology Stack

**Analysis Date:** 2026-06-06

> Snapshot of the **SOA Blog** microservices showcase as it exists today. This is a small PHP/Slim showcase that will be the brownfield base for a LinkedIn-style professional network upgrade. Reusability notes for the upgrade are flagged inline as **[REUSABLE]** vs **[BLOG-SPECIFIC]**.

## Languages

**Primary:**
- **PHP 8.2** — all backend services and the gateway. `declare(strict_types=1)` used throughout (`gateway/public/index.php`, `services/*/src/*.php`). Required constraint `"php": ">=8.2"` in every `composer.json`. **[REUSABLE]**
- **SQL (MariaDB dialect)** — schema and seed in `db/*.sql`. DDL is plain InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`. **[REUSABLE pattern, BLOG-SPECIFIC tables]**

**Secondary:**
- **JavaScript (vanilla ES, no build)** — frontend logic in `web/assets/app.js`, loaded as a classic script before Alpine. **[REUSABLE wrapper, BLOG-SPECIFIC pages]**
- **Bash** — operational scripts: `scripts/deploy.sh`, `scripts/seed.sh`, `scripts/demo.sh`, `scripts/cloudflare-dns.sh`, and DB init `db/00-init.sh`. **[REUSABLE]**

## Runtime

**Environment:**
- **PHP 8.2 FPM on Alpine** — base image `php:8.2-fpm-alpine` for gateway and all 3 services (`gateway/Dockerfile`, `services/*/Dockerfile`, identical).
- Each container runs **nginx + php-fpm together under supervisord** (PID 1). See `services/user-service/supervisord.conf`: `php-fpm -F` (priority 10) + `nginx -g "daemon off;"` (priority 20). nginx listens on `:80`, forwards `.php` to php-fpm on `127.0.0.1:9000` via FastCGI (`services/user-service/nginx.conf`).
- **Compiled PHP extensions** (installed in Dockerfile via `docker-php-ext-install`): `pdo`, `pdo_mysql`, `opcache`, `mbstring` (with oniguruma). `wget` is added for container healthchecks.
- **Static web** served by stock `nginx:alpine` (the `web` service in `docker-compose.yml`), bind-mounting `./web` read-only.

**Package Manager:**
- **Composer** (installed at build time from getcomposer.org installer). Build runs `composer install --no-dev --optimize-autoloader --no-interaction --no-progress`.
- **Lockfile: present and committed** for every PHP module (`gateway/composer.lock`, `services/*/composer.lock`) — versions are reproducible across builds. Dockerfiles copy `composer.json composer.lock` before installing.
- No JS package manager — frontend pulls libraries from CDN (no `package.json`, no `node_modules`).

## Frameworks

**Core:**
- **Slim 4** (`slim/slim ^4.12`, locked **4.15.1**) — HTTP framework for the gateway and all 3 services. PSR-7 via `slim/psr7 ^1.6` (locked **1.8.0**). Routing via `nikic/fast-route v1.3.0`. **[REUSABLE]**
- **PHP-DI** (`php-di/php-di ^7.0`, locked **7.1.1**) — DI container, **gateway only**. Services wire dependencies manually (no container). See `gateway/public/index.php` container setup. **[REUSABLE]**

**Frontend (no framework, CDN-only):**
- **Alpine.js 3.x** — loaded via `https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js` in each HTML page (`web/index.html` etc.). **[REUSABLE approach]**
- **Tailwind CSS (Play CDN)** — `https://cdn.tailwindcss.com`, runtime compilation in-browser. **No build step.** **[REUSABLE approach, but note: Play CDN is not production-grade for a larger app]**

**Testing:**
- **None detected.** No PHPUnit, Pest, or any test runner in any `composer.json`; no `tests/` directory; `require-dev` is absent. The only automated check is `php -l` syntax linting in CI. **[GAP for upgrade]**

**Build/Dev:**
- **Docker Compose** (`docker-compose.yml`) — single orchestration file, 6 services. **[REUSABLE]**
- **opcache** enabled in images for runtime perf. No asset bundler/transpiler anywhere.

## Key Dependencies

**Critical (gateway, from `gateway/composer.lock`):**
- **`firebase/php-jwt` ^7.0** (locked **v7.0.5**) — JWT encode/decode, HS256. JWT auth is **centralized at the gateway only** (`gateway/src/Middleware/JwtAuthMiddleware.php`, `gateway/src/Controllers/AuthController.php`). Token claims: `sub`, `username`, `iat`, `exp`. **[REUSABLE]**
- **`guzzlehttp/guzzle` ^7.8** (locked **7.10.0**, with `guzzlehttp/promises 2.3.0`, `guzzlehttp/psr7 2.9.0`) — gateway → internal service HTTP. Configured in `gateway/src/Services/HttpClient.php`: `http_errors=false`, `connect_timeout=2.0s`, `timeout=5.0s`, injects `X-Forwarded-By: soa-blog-gateway`. **[REUSABLE]**
- **`ramsey/uuid` ^4.7** (with `brick/math 0.14.8`) — gateway only, used for request IDs / correlation. **[REUSABLE]**
- **`monolog/monolog` ^3.0** (locked **3.10.0**) — logging, in **all** modules. **[REUSABLE]**

**Critical (services, from `services/*/composer.lock`):**
- Services are **dependency-minimal and identical** across user/post/comment: `slim/slim ^4.12`, `slim/psr7 ^1.6`, `monolog/monolog ^3.0`, plus `ext-mbstring` and `ext-pdo`. **No ORM, no Guzzle, no JWT lib.** Data access is **raw PDO** (`services/user-service/src/Db.php`): lazy PDO singleton, `mysql:` DSN, `charset=utf8mb4`, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false` (real prepared statements). **[REUSABLE pattern]**

**Required PHP extensions (declared):**
- `ext-mbstring` (all modules — used by `mb_strlen`/`mb_strtolower` in controllers).
- `ext-pdo` (services; gateway does not declare it — gateway has no DB access).

## Configuration

**Environment:**
- All config via **environment variables**, no config files baked in. Template: `.env.example` (committed). Real `.env` is git-ignored and **required by `scripts/deploy.sh`** (it aborts if missing). `.env` contents were NOT read (secrets).
- **Env keys** (from `.env.example`): `DB_ROOT_PASSWORD`, `USER_SVC_DB_PASS`, `POST_SVC_DB_PASS`, `COMMENT_SVC_DB_PASS`, `JWT_SECRET`, `RATE_LIMIT_PER_MIN`.
- Per-service DB wiring is set in `docker-compose.yml` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`). Internal service URLs injected into gateway: `USER_SERVICE_URL`, `POST_SERVICE_URL`, `COMMENT_SERVICE_URL` (all `http://<svc>:80`).
- **Fail-fast config guard**: `gateway/public/index.php` exits with `CONFIG_ERROR` if `JWT_SECRET` < 16 chars. `RATE_LIMIT_PER_MIN` defaults to `120`. **[REUSABLE]**

**Build:**
- `gateway/Dockerfile`, `services/*/Dockerfile` (3 identical copies), `docker-compose.yml`.
- Per-container nginx: `services/*/nginx.conf` (app vhost → php-fpm). Static-web nginx: `web/nginx.conf`. Host edge nginx: `deploy/nginx-soa.duyet.vn.conf`.

## Platform Requirements

**Development:**
- Docker + Docker Compose. `docker compose build` then `docker compose up -d`. No local PHP/Node toolchain required to run.
- Local port bindings (loopback only): gateway `127.0.0.1:8000:80`, web `127.0.0.1:8080:80`. MariaDB and the 3 services have **no host ports** — internal `blog-net` bridge network only.

**Production:**
- **VPS** `14.225.29.159`, **Ubuntu 24.04, 2 GB RAM** (small — relevant for the upgrade's resource budget).
- Public URL **https://soa.duyet.vn** behind **Cloudflare** (orange-cloud proxy, SSL Full-strict; origin cert under `/etc/ssl/cloudflare/`). Host nginx (`deploy/nginx-soa.duyet.vn.conf`) terminates TLS, gates non-Cloudflare IPs (`return 444`/`$cf_allowed`), and reverse-proxies to the loopback containers.
- **CI/CD**: `.github/workflows/deploy.yml` — on push to `main`: (1) lint job = `php -l` over all `*.php`; (2) deploy job = SSH to VPS, run `scripts/deploy.sh` (`git pull --ff-only` → `docker compose build --pull` → `up -d`), then verify `https://soa.duyet.vn/api/health`. **[REUSABLE]**

## Stack Reusability Summary (for the upgrade)

**Keep as-is (solid foundation):**
- PHP 8.2 + Slim 4 + PHP-DI gateway pattern; raw-PDO service pattern; Guzzle internal client; firebase/php-jwt HS256 auth; Monolog logging; Docker Compose + supervisord(nginx+fpm) packaging; GitHub Actions → VPS deploy; Cloudflare edge.

**Likely to strain under a LinkedIn-style scope:**
- **No test framework** — add PHPUnit/Pest before significant refactors.
- **Tailwind Play CDN + no JS build** — fine for a demo blog, will not scale to a richer SPA; expect a build step.
- **2 GB VPS** — a professional network (feed, connections, search) needs a capacity plan; current 6-container footprint already has DB + 3 PHP services.
- **`X-User-Id` trust model** (services blindly trust the gateway-set header; JWT verified only at gateway) — acceptable inside a private network but a real security boundary concern as services grow.

---

*Stack analysis: 2026-06-06*
