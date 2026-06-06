---
phase: 01-n-n-t-ng-gateway
plan: 02
subsystem: profile-service + gateway client
tags: [refactor, rename, gateway, microservice, profile]
requires: []
provides:
  - services/profile-service (git-mv'd from user-service, auth/user logic + history intact)
  - gateway ProfileClient typed client reading PROFILE_SERVICE_URL
affects:
  - Plan 04 (gateway DI re-wire: swap UserClient->ProfileClient, USER_SERVICE_URL->PROFILE_SERVICE_URL, compose rename)
  - Plan 05 (DB provisioning: proconnect_profile / profile_svc)
tech-stack:
  added: []
  patterns:
    - "git mv in-place rename preserves history (D-05)"
    - "typed Guzzle client clone (HttpClient::create), internal /users path kept"
key-files:
  created:
    - gateway/src/Services/ProfileClient.php
  modified:
    - services/profile-service/composer.json (name -> soa-blog/profile-service)
    - services/profile-service/src/Db.php (defaults -> proconnect_profile / profile_svc)
  renamed:
    - services/user-service/** -> services/profile-service/** (12 files, git mv)
decisions:
  - "D-05: rename user-service->profile-service in place via git mv (history preserved)"
  - "Kept internal /users routes + UserController unchanged (gateway-facing /api/profiles surface lands in Plan 04)"
  - "ProfileClient is a verbatim UserClient clone (only class name + env var differ); UserClient NOT deleted — Plan 04 removes it atomically with PostClient/CommentClient"
metrics:
  duration: ~6 min
  completed: 2026-06-06
  tasks: 2
  files: 13
---

# Phase 01 Plan 02: Rename user-service → profile-service + gateway ProfileClient Summary

`git mv`'d `services/user-service` → `services/profile-service` (full auth/user logic + git history preserved), updated only the composer `name`/`description` and the two `Db.php` fallback defaults (`proconnect_profile`/`profile_svc`), and added a verbatim `ProfileClient` clone of `UserClient` in the gateway reading `PROFILE_SERVICE_URL`. No compose/routes/DI files touched — clean handoff to Plan 04.

## What Was Built

### Task 1 — git mv + internal identifier updates (commit bae4758)
- `git mv services/user-service services/profile-service` — all 12 files recorded as renames (R100 / RM), history continuity confirmed.
- `composer.json`: `"name"` → `soa-blog/profile-service`, `"description"` → `Profile microservice for ProConnect`. `require`/`autoload`/`config` and `composer.lock` left verbatim (no `composer update`).
- `src/Db.php`: only the two fallback defaults changed — `getenv('DB_NAME') ?: 'proconnect_profile'`, `getenv('DB_USER') ?: 'profile_svc'` (D-13). DSN structure and PDO options unchanged. Real values are injected by compose env in Plan 04.
- Internal `/users` routes, `UserController` class name, and all auth/user logic left exactly as-is (D-05 anti-churn).

### Task 2 — gateway ProfileClient (commit 231134e)
- `gateway/src/Services/ProfileClient.php` is a byte-for-byte clone of `UserClient` with exactly two changes: `final class ProfileClient` and `getenv('PROFILE_SERVICE_URL') ?: 'http://profile-service:80'`.
- All 9 methods preserved with identical signatures/bodies: `health`, `healthAsync`, `get`, `getAsync`, `batch`, `batchAsync`, `create`, `verifyCredentials`, `update` (10 `public function` declarations incl. constructor). Internal URLs (`/users`, `/users/verify-credentials`) unchanged. Namespace `App\Services`.
- `UserClient.php` deliberately NOT deleted — Plan 04 removes it together with PostClient/CommentClient for atomic DI removal.

## Verification

| Check | Result |
|-------|--------|
| `test -d services/profile-service && test ! -d services/user-service` | PASS |
| git records all 12 files as renames (history preserved) | PASS (R100/RM in `git diff --find-renames`) |
| composer name = `soa-blog/profile-service` | PASS |
| Db.php defaults = `proconnect_profile` / `profile_svc`; no `blog_users` | PASS |
| profile-service internal `/users` routes intact | PASS |
| `php -l` on profile-service public/index.php, Db.php, UserController.php | PASS (no syntax errors) |
| `php -l` on ProfileClient.php | PASS |
| ProfileClient: `final class ProfileClient`, `PROFILE_SERVICE_URL`, namespace, 10 public fns, `/users` + verify-credentials | PASS |
| ProfileClient body identical to UserClient except class+env (diff after normalize) | PASS |
| gateway routes.php / public/index.php / docker-compose.yml / .env.example UNTOUCHED | PASS (clean handoff to Plan 04) |
| UserClient.php preserved (Plan 04 removes) | PASS |

### Deferred to VPS/CI (Docker not installed locally)
Runtime checks are out of scope for this static refactor and deferred to the Phase-1 smoke gate after Plan 04 (gateway re-wire) + Plan 05 (DB provisioning):
- `docker compose up -d --wait` clean boot, all containers healthy
- `/api/profiles/{id}` routes through profile-service
- register/login end-to-end (PROF-01) — JWT flow preserved at gateway (AuthController, JWT_SECRET all untouched here)
- `bash scripts/smoke-phase1.sh`

## JWT / Auth Integrity (PROF-01)
No auth code touched. AuthController, JwtAuthMiddleware, and JWT_SECRET are entirely out of this plan's diff. profile-service still has no JWT/firebase dependency in composer.json (T-1-01 mitigation preserved — auth stays at the gateway). password hash/verify logic in `UserController` is byte-identical post-rename.

## Deviations from Plan

None — plan executed exactly as written. Tasks 1 and 2 met every acceptance criterion; no Rule 1-4 deviations and no auth gates encountered.

## Threat Model Adherence
- **T-1-01 (EoP):** profile-service composer.json has no JWT lib; no JWT logic added. Mitigation intact.
- **T-1-02 (Spoofing):** docker-compose.yml not touched — profile compose block (no host `ports:`) inherited unchanged; flagged for Plan 04.
- **T-1-03 (Tampering):** UserController prepared-statement queries unchanged (EMULATE_PREPARES=false); no new query code.

No new threat surface introduced (rename + verbatim client clone only).

## Commits
- `bae4758` refactor(01-02): rename user-service to profile-service (git mv)
- `231134e` feat(01-02): add gateway ProfileClient (clone of UserClient)

## Self-Check: PASSED
- FOUND: services/profile-service/src/Db.php (proconnect_profile)
- FOUND: services/profile-service/composer.json (soa-blog/profile-service)
- FOUND: gateway/src/Services/ProfileClient.php (PROFILE_SERVICE_URL)
- MISSING: services/user-service (expected — renamed away)
- FOUND commit: bae4758
- FOUND commit: 231134e
