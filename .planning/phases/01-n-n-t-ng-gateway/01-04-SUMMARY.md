---
phase: 01-n-n-t-ng-gateway
plan: 04
subsystem: gateway-convergence
tags: [gateway, docker-compose, routing, x-request-id, profiles, health, retirement]
requires:
  - "ProfileClient (Plan 02)"
  - "ConnectionClient/FeedClient/SearchClient/NotificationClient + 4 stub services echoing rid (Plan 03)"
  - "proconnect_* DB naming + 5 *_SVC_DB_PASS env vars (Plan 05)"
provides:
  - "8-container docker-compose topology (mariadb, gateway, web + 5 services)"
  - "gateway routing: /api/health, /api/auth/*, /api/me, /api/profiles/{id}"
  - "5-way health fan-out surfacing each downstream body (rid receipt proof)"
  - "X-Request-Id downstream forwarding (D-12)"
  - "/api/profiles/{id} D-07 whitelist {id,username,display_name}"
affects:
  - "docker-compose.yml"
  - "gateway DI + routes + controllers + HttpClient + RequestIdMiddleware"
tech-stack:
  added: []
  patterns:
    - "Request-scoped X-Request-Id via static set by RequestIdMiddleware, read at lazy client construction"
    - "Positive allowlist (array_intersect_key) for public-route field trimming"
key-files:
  created:
    - gateway/src/Controllers/ProfilesController.php
  modified:
    - gateway/src/Services/HttpClient.php
    - gateway/src/Middleware/RequestIdMiddleware.php
    - gateway/src/Controllers/HealthController.php
    - gateway/src/Controllers/AuthController.php
    - gateway/public/index.php
    - gateway/src/routes.php
    - docker-compose.yml
    - .env.example
  removed:
    - gateway/src/Services/UserClient.php
    - gateway/src/Services/PostClient.php
    - gateway/src/Services/CommentClient.php
    - gateway/src/Controllers/UsersController.php
    - gateway/src/Controllers/PostsController.php
    - gateway/src/Controllers/AggregateController.php
    - services/post-service/ (entire dir)
    - services/comment-service/ (entire dir)
decisions:
  - "X-Request-Id safety justified by lazy per-request client construction (NOT the false 'one request per process' claim)"
  - "/api/profiles/{id} trims success body via positive allowlist so future service fields never leak on the public route"
  - "Fully retired post/comment: gateway code + compose blocks + service dirs git rm'd in one retirement commit"
metrics:
  duration_min: 3
  tasks: 3
  files_changed: 39
  completed: 2026-06-06
---

# Phase 01 Plan 04: Gateway Convergence Summary

Rewired the gateway to register all 5 typed clients and expose a clean ProConnect surface (`/api/health`, `/api/auth/*`, `/api/me`, `/api/profiles/{id}`), added X-Request-Id downstream forwarding with a corrected lazy-construction safety invariant, made `/api/health` a 5-way fan-out that surfaces each downstream body (so the stub-echoed `rid` is provable), trimmed the public `/api/profiles/{id}` to the D-07 whitelist, fully retired post/comment (gateway code + compose + service dirs), and assembled `docker-compose.yml` into the 8-container topology with exactly 2 published ports.

## What Was Built

### Task 1 — X-Request-Id + ProfilesController + 5-way HealthController + AuthController retype (commit bc0f448)
- **HttpClient**: added `private static ?string $requestId` + `setRequestId()`; injects `X-Request-Id` into the default header set. The safety comment states the REAL invariant — php-fpm workers are reused, so the static is not reset by process isolation; it is safe because clients are constructed lazily per-request inside route handlers, strictly after RequestIdMiddleware overwrites the static. No false "one request per process" claim.
- **RequestIdMiddleware**: calls `HttpClient::setRequestId($rid)` at request start; existing request attribute + response header behavior unchanged.
- **ProfilesController** (new): `show()` calls `ProfileClient::get()`, and on 2xx trims `data` to `array_intersect_key(..., array_flip(['id','username','display_name']))`. Error envelopes pass through unchanged. `email`/`avatar_url` cannot appear (positive allowlist).
- **HealthController**: constructor now takes the 5 service clients; `services.<svc>` carries the decoded downstream `/health` body verbatim (status/db/rid/ts) — required for the D-12 receipt proof. 200 iff all 5 ok, else 503.
- **AuthController**: `UserClient` -> `ProfileClient` (property `$users` unchanged; signatures identical so call sites untouched).

### Task 2 — Gateway DI + routes rewire; retire post/comment/user (commit 79661cf)
- **index.php**: registers 5 clients; AuthController/HealthController/ProfilesController DI wired to them; removed Users/Posts/Aggregate registrations + imports. Middleware add block (Logging -> CORS -> RateLimit -> RequestId) untouched.
- **routes.php**: only `/api/health`, `/api/auth/register`, `/api/auth/login`, `/api/me` (JWT), `/api/profiles/{id:[0-9]+}`. All `/posts`, `/comments`, `/users` routes removed.
- `git rm` of 6 gateway files (UserClient/PostClient/CommentClient, UsersController/PostsController/AggregateController) and `git rm -r services/post-service services/comment-service`. `services/` now holds exactly profile/connection/feed/search/notification.

### Task 3 — docker-compose.yml 8-container topology + .env.example (commit dc5639e)
- mariadb env: 5 `*_SVC_DB_PASS` (profile/connection/feed/search/notification).
- profile-service (renamed from user-service): `proconnect_profile`/`profile_svc`, no host port.
- 4 stub service blocks added (connection/feed/search/notification): `proconnect_<svc>`/`<svc>_svc`, no host port.
- post-service + comment-service blocks removed.
- gateway: 5 `*_SERVICE_URL`, `depends_on` all 5 services `service_healthy`; keeps `127.0.0.1:8000`.
- Final: exactly 8 containers, exactly 2 published ports (gateway 8000, web 8080).
- `.env.example`: 5 `*_SVC_DB_PASS`; `JWT_SECRET` placeholder unchanged.

## Verification

| Check | Result |
|-------|--------|
| `php -l` on all changed/created gateway PHP | PASS (HttpClient, RequestIdMiddleware, ProfilesController, HealthController, AuthController, index.php, routes.php) |
| HttpClient X-Request-Id + setRequestId + invariant comment, no false php-fpm claim | PASS |
| ProfilesController whitelist (array_intersect_key, display_name present, no `'email'`) | PASS |
| HealthController 5 keys + no UserClient/PostClient/CommentClient | PASS |
| index.php registers 5 clients, no retired refs; middleware order intact | PASS |
| routes.php only profiles/auth/me/health; no /posts /comments /users | PASS |
| Retired files + service dirs removed; `grep -rl` retired refs = none | PASS |
| docker-compose.yml YAML parses (python3 yaml.safe_load) | PASS |
| 8 services; exactly 2 published ports; 5 services no host port; gateway depends_on 5 healthy | PASS |
| .env.example 5 *_SVC_DB_PASS, no old 3; JWT_SECRET unchanged | PASS |

### Deferred to VPS/CI (Docker not installed locally)
- `docker compose config -q` exit 0 — substituted by python3 `yaml.safe_load` + structural assertions locally.
- `docker compose up -d --wait` + `bash scripts/smoke-phase1.sh` — full functional gate (5-way health ok, `/api/profiles/2` returns duyet but not email, `/api/me` 401, X-Request-Id downstream receipt #6, 5-account login). Requires a fresh local volume + booted stack.
- `docker compose config --services` count / `published` count — verified statically via YAML parse instead.

## Deviations from Plan

### Auto-fixed / scope adjustments

**1. [Rule 3 - Blocking] Full retirement of post/comment service directories**
- **Found during:** Task 2
- **Issue:** Plan Task 2 step 3 says "keep service-folder code on disk", but the plan objective + environment constraints (and `files_modified` retirement intent) explicitly require `git rm -r services/post-service services/comment-service` so `services/` ends with exactly 5 services. Compose Task 3 also removes their blocks, leaving orphaned untracked dirs otherwise.
- **Fix:** `git rm -r` both dirs; cleaned leftover untracked `vendor/` via `git clean -fdx` scoped to those two paths only. Committed with Task 2.
- **Files removed:** services/post-service/, services/comment-service/
- **Commit:** 79661cf

**2. [Static substitution] Docker-runtime verification replaced with static YAML validation**
- **Reason:** Docker is not installed in the local environment.
- **Action:** Used `python3 yaml.safe_load` plus structural assertions (service count, published-port count, no-host-port on services, depends_on conditions) in place of `docker compose config -q`. Runtime smoke checks marked deferred to VPS/CI. No self-check failure attributed to these.

## Known Stubs
None introduced by this plan. The 4 stub services (connection/feed/search/notification) are intentionally health-only per Plan 03; this plan only wires their clients into gateway DI/health, which is the intended deliverable.

## Self-Check: PASSED
