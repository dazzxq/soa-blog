---
phase: 01-n-n-t-ng-gateway
plan: 03
subsystem: infra
tags: [slim4, php, microservices, stub-service, guzzle, health-check, x-request-id]

# Dependency graph
requires:
  - phase: 01-02
    provides: canonical services/profile-service skeleton (git mv from user-service) cloned by this plan
provides:
  - Four stub microservices (connection, feed, search, notification), each a minimal Slim app exposing only GET /health with Db::ping()
  - Each stub /health echoes inbound X-Request-Id back as `rid` (D-12 downstream-receipt proof)
  - Four gateway typed clients (ConnectionClient/FeedClient/SearchClient/NotificationClient), health + healthAsync only
affects: [01-04, 01-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Stub-service clone recipe: copy profile-service skeleton, drop vendor, trim routes.php to /health, replace UserController with a tiny HealthController, edit Db.php defaults + composer name"
    - "X-Request-Id receipt echo: stub HealthController returns $req->getHeaderLine('X-Request-Id') as `rid` in the /health body"
    - "Health-only typed client: clone of ProfileClient/PostClient with only health()/healthAsync() reading <SVC>_SERVICE_URL"

key-files:
  created:
    - services/connection-service/** (12 files)
    - services/feed-service/** (12 files)
    - services/search-service/** (12 files)
    - services/notification-service/** (12 files)
    - gateway/src/Services/ConnectionClient.php
    - gateway/src/Services/FeedClient.php
    - gateway/src/Services/SearchClient.php
    - gateway/src/Services/NotificationClient.php
  modified: []

key-decisions:
  - "Cloned ONLY from canonical services/profile-service (Plan 02 git mv source); never referenced retired user-service"
  - "vendor/ NOT committed — rebuilt inside the image by composer install at docker compose build; composer.lock copied verbatim for reproducible builds"
  - "Stubs expose ONLY /health; no business routes, no business tables, no JWT, no host ports (trust-by-network preserved)"

patterns-established:
  - "Stub-service clone recipe (copy skeleton + trim to /health + per-service Db defaults proconnect_<svc>/<svc>_svc)"
  - "Stub /health echoes X-Request-Id as rid for downstream-receipt proof (D-12)"

requirements-completed: [PLAT-01, PLAT-05]

# Metrics
duration: 5min
completed: 2026-06-06
---

# Phase 1 Plan 03: Scaffold 4 Stub Services + Gateway Clients Summary

**Four DB-checked Slim stub services (connection/feed/search/notification) cloned from the canonical profile-service skeleton, each /health echoing X-Request-Id as `rid` (D-12), plus four health-only gateway typed clients mirroring ProfileClient.**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-06-06T12:05:09Z
- **Completed:** 2026-06-06T12:09:55Z
- **Tasks:** 2
- **Files modified:** 52 (48 stub files + 4 gateway clients)

## Accomplishments
- Scaffolded 4 new stub microservices by cloning the canonical `services/profile-service` skeleton, each trimmed to a single `GET /health` route backed by `Db::ping()`.
- Each stub `HealthController` echoes the inbound `X-Request-Id` header back as a `rid` field in the `/health` body — the concrete downstream-receipt proof for D-12 / ISSUE-5.
- Per-service `Db.php` defaults set to `proconnect_<svc>` schema and `<svc>_svc` DB user (D-13); `composer.json` name/description edited; `composer.lock` copied verbatim; `vendor/` excluded.
- Created 4 gateway typed clients (ConnectionClient/FeedClient/SearchClient/NotificationClient) with `health()`/`healthAsync()` only, each reading its `<SVC>_SERVICE_URL` (D-11).
- `docker-compose.yml` and gateway DI/routes left untouched (verified by sha + git status) — Plan 04 owns wiring.

## Task Commits

Each task was committed atomically (hooks on, no --no-verify):

1. **Task 1: Clone the four stub service trees (with X-Request-Id echo)** - `cb340b0` (feat)
2. **Task 2: Create the four gateway typed clients** - `06346a3` (feat)

## Files Created/Modified
- `services/{connection,feed,search,notification}-service/` - 12 files each: Dockerfile, nginx.conf, supervisord.conf, public/index.php, composer.json, composer.lock, src/{Db,Json,DomainError,JsonErrorHandler,routes}.php, src/Controllers/HealthController.php
- `gateway/src/Services/ConnectionClient.php` - typed client, reads CONNECTION_SERVICE_URL
- `gateway/src/Services/FeedClient.php` - typed client, reads FEED_SERVICE_URL
- `gateway/src/Services/SearchClient.php` - typed client, reads SEARCH_SERVICE_URL
- `gateway/src/Services/NotificationClient.php` - typed client, reads NOTIFICATION_SERVICE_URL

## Decisions Made
- **Clone source unambiguous:** copied only from `services/profile-service` (Plan 02 output); never touched the retired `user-service` (gone after git mv).
- **vendor handling:** the skeleton copy still carried a `vendor/` dir; `rm -rf` removed it per stub so it is never committed — `composer install` rebuilds it inside the image at build time; `composer.lock` copied verbatim keeps deps reproducible.
- **Health-only clients:** business methods deliberately omitted (deferred to Phase 3/4/5); clients are not yet DI-registered (Plan 04 owns gateway DI/routes).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Verification-spec defect] Task 2 acceptance check `! grep -qE '(create|update|delete|list|batch)'` is a false positive**
- **Found during:** Task 2 (gateway clients verification)
- **Issue:** The plan's acceptance regex flags any occurrence of `create`, matching the constructor line `HttpClient::create($base)` — the canonical baseline pattern used identically by the existing `ProfileClient`/`PostClient`. The check therefore "fails" on byte-perfect-correct clients.
- **Fix:** Re-ran the check anchored to public method declarations: `grep -E 'public function (create|update|delete|list|batch)'`. Confirmed each client has exactly three public functions (`__construct`, `health`, `healthAsync`) and zero business methods. No code change needed — the clients are correct; only the verification grep was naive.
- **Files modified:** none (verification-only)
- **Verification:** `grep -oE 'public function [a-zA-Z_]+'` on each client returns exactly `__construct health healthAsync`.
- **Committed in:** n/a (no code change)

---

**Total deviations:** 1 (verification-spec false positive; no code impact)
**Impact on plan:** No scope creep, no code change. The intended invariant (no business public methods on stub clients) holds.

## Issues Encountered
- None during planned work. (The Edit/Write tools required a prior Read for the cp'd skeleton files not yet tracked by the harness; resolved by writing per-service files fresh / using deterministic perl substitutions.)

## Deferred / Runtime acceptance criteria (deferred to VPS/CI)
Docker is not installed locally, so the following acceptance criteria are **deferred to VPS/CI** (verified by `docker compose up -d --wait && bash scripts/smoke-phase1.sh` after Plan 04 wires compose+DI and Plan 05 provisions DBs):
- Each stub `/health` returns 200 `{status,db,rid,ts}` with a live DB ping.
- Gateway `/api/health` 5-way fan-out surfaces each stub body and the echoed `rid` matches the gateway response `X-Request-Id` (D-12 smoke assertion).
- Stub container images build `vendor/` via `composer install`.

These were validated statically instead: `php -l` clean on all stub + client PHP; `grep` confirms each stub routes.php has only `/health`, each HealthController echoes `rid` / `X-Request-Id` and calls `Db::ping()`, each Db.php defaults to `proconnect_<svc>`/`<svc>_svc`, each composer name edited, `composer.lock` present + `vendor/` absent, no `firebase` dep, and the 4 gateway clients mirror ProfileClient.

## User Setup Required
None - no external service configuration required by this plan. (New `*_SVC_DB_PASS` / `*_SERVICE_URL` env wiring is owned by Plan 04/05/06.)

## Next Phase Readiness
- 4 stub service trees + 4 gateway clients exist and lint clean — ready for **Plan 04** to add compose service blocks, gateway DI registration, routes, and X-Request-Id downstream forwarding.
- `docker-compose.yml` and gateway DI/routes confirmed untouched (sha `cd715c33`), so Plan 04 starts from a clean wiring surface.
- No host ports / JWT on stubs (T-1-04 / T-1-05 mitigations preserved at the code level; compose-level `ports:` assertion is Plan 04's).

## Self-Check: PASSED
- Stub dirs: FOUND services/{connection,feed,search,notification}-service
- Client files: FOUND {Connection,Feed,Search,Notification}Client.php
- Commits: FOUND cb340b0, FOUND 06346a3

---
*Phase: 01-n-n-t-ng-gateway*
*Completed: 2026-06-06*
