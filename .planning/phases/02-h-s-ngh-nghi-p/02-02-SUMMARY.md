---
phase: 02-h-s-ngh-nghi-p
plan: 02
subsystem: profile-service data layer
tags: [profile, crud, idor, composition, php, slim, pdo]
dependency_graph:
  requires:
    - "02-01: live phase-2 migration (users new cols + experience/education/skills tables)"
    - "01: profile-service skeleton (Db.php, Json, DomainError, X-User-Id trust model)"
  provides:
    - "GET /users/{id}/full — intra-service public-safe assembly (basic + experience + education + skills)"
    - "Owner-scoped experience/education/skills CRUD (8 routes), all keyed by X-User-Id"
    - "PATCH /users/{id} accepting 4 new basic fields (cover_url, headline, location, about)"
  affects:
    - "03: gateway composes /api/profiles/{id}/full across profile-service + connection-service on top of /users/{id}/full"
    - "03: gateway maps /api/profiles/me/* -> /users/{id}/* forwarding X-User-Id"
tech_stack:
  added: []
  patterns:
    - "Raw-PDO prepared statements (EMULATE_PREPARES=false), one bind per placeholder"
    - "Conditional-set PATCH (array_key_exists branches) mirroring existing update()"
    - "Owner-scope guard: callerId from X-User-Id header === path id, else 403 FORBIDDEN"
    - "IDOR-safe uniform 404 on 0-rowcount update/delete (no row-existence oracle)"
    - "Public projection allowlist (no email/password_hash) for /full"
key_files:
  created:
    - "services/profile-service/src/Controllers/ProfileController.php"
  modified:
    - "services/profile-service/src/Controllers/UserController.php"
    - "services/profile-service/src/routes.php"
decisions:
  - "Uniform 404 (never 403) on not-found vs not-owned for exp/edu/skills writes — closes IDOR row-existence oracle; no disambiguating second SELECT"
  - "full() emits a public column allowlist (no email/password_hash) — defense at the data layer; gateway adds a second allowlist in Plan 03"
  - "Writes scoped strictly by X-User-Id; user_id never read from request body (grep-enforced)"
metrics:
  duration: "~6min"
  completed: 2026-06-07
  tasks: 3
  files: 3
---

# Phase 2 Plan 02: profile-service data layer Summary

Extended profile-service to own its bounded context for Phase 2: basic-profile editing gained 4 new fields, new owner-scoped CRUD for experience/education/skills (IDOR-safe), and a public-safe `GET /users/{id}/full` assembly that the gateway will compose across services in Plan 03.

## What Was Built

**Task 1 — UserController extended (commit bbb6b01)**
- `update()` now handles `cover_url` (URL, ≤512), `headline` (≤160), `location` (≤128), `about` (≤5000) as nullable conditional-set branches, alongside the existing display_name/avatar_url. Owner-scope guard and "no fields → return existing" early-out preserved.
- New `full()` method: public projection `SELECT id, username, display_name, avatar_url, cover_url, headline, location, about, created_at` (NO email, NO password_hash) + child fetches for experience (ORDER start_date DESC), education (ORDER start_year DESC), skills (ORDER name). 404 USER_NOT_FOUND when the user row is absent. `find()` left untouched.

**Task 2 — ProfileController created (commit fec6a84)**
- 8 methods: add/update/delete for experience + education, add/delete for skills (skills have no update per D-08/D-10).
- Private `ownerOnly()` resolves `X-User-Id` and rejects mismatched/zero caller with 403 FORBIDDEN.
- All writes scoped by caller: INSERTs set `user_id = :caller`; UPDATE/DELETE use `WHERE id = :eid AND user_id = :caller`. Body `user_id` is never read (grep-enforced absent).
- Uniform 404 (EXPERIENCE_NOT_FOUND / EDUCATION_NOT_FOUND / SKILL_NOT_FOUND) on 0-rowcount update/delete — IDOR-safe, not-found and not-owned indistinguishable.
- Skills dedupe: INSERT wrapped in try/catch; SQLSTATE `23000` → 409 SKILL_EXISTS, other PDOExceptions rethrown.
- Date validation `YYYY-MM-DD` (preg), year validation 1900–2100, length checks. Vietnamese messages throughout.

**Task 3 — Routes registered (commit ed14477)**
- Added `use App\Controllers\ProfileController;` and 9 routes after the existing `/users/{id}` routes: `/users/{id}/full` (GET) + experience/education (POST/PATCH/DELETE) + skills (POST/DELETE), all with `{id:[0-9]+}`/`{eid:[0-9]+}`/`{sid:[0-9]+}` numeric constraints. Slim default callable resolver instantiates the controller (no DI, matching UserController).

## Threat Mitigations Applied

| Threat | Status | How |
|--------|--------|-----|
| T-02-01 IDOR | mitigated | Every write scoped by X-User-Id; uniform 404 on 0-rowcount; body user_id never read |
| T-02-02 info disclosure | mitigated | full() public column allowlist excludes email/password_hash |
| T-02-03 SQLi | mitigated | PDO prepared statements, EMULATE_PREPARES=false, no interpolation |
| T-02-04 dup skill | mitigated | UNIQUE(user_id,name) + catch 23000 → 409 SKILL_EXISTS |

## Verification

- `php -l` clean on all 3 files (UserController, ProfileController, routes.php).
- profile-service contains NO JWT references (`grep -r JWT|firebase|jwt_decode|JWT_SECRET services/profile-service/src` → none) — trust model intact: it trusts gateway-set X-User-Id.
- Acceptance greps all pass: full() projection no email; 4 new array_key_exists branches; 8 CRUD methods; 7 caller-scoped clauses; no `['user_id']` body access; SKILL_EXISTS+23000 present; full route + 8 CRUD routes + ProfileController use-statement.
- **Runtime CRUD round-trips, /full assembly, and IDOR/409 behavior deferred to VPS/CI** (Docker not installed locally; smoke-phase2.sh + Plan 05 verify on the deployed stack).

## Deviations from Plan

None — plan executed exactly as written. Minor hardening within plan intent: `cover_url` empty-string rejected as invalid (consistent with FILTER_VALIDATE_URL), and `validYear`/`validDate` rendered as small private helpers to avoid duplication across add/update. No behavioral change vs the plan's stated validation.

## Self-Check: PASSED
- FOUND: services/profile-service/src/Controllers/ProfileController.php
- FOUND: services/profile-service/src/Controllers/UserController.php (modified)
- FOUND: services/profile-service/src/routes.php (modified)
- FOUND commit: bbb6b01 (Task 1)
- FOUND commit: fec6a84 (Task 2)
- FOUND commit: ed14477 (Task 3)
