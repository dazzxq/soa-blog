---
phase: 02-h-s-ngh-nghi-p
plan: 03
subsystem: gateway
tags: [api-gateway, composition, settle, optional-auth, idor, allowlist]
requires:
  - profile-service /users/{id}/full + experience/education/skills CRUD (Plan 02-02)
  - JwtAuthMiddleware, ProfileClient, ConnectionClient, HttpClient X-Request-Id forwarding (Phase 1)
provides:
  - GET /api/profiles/{id}/full flagship 2-way parallel composition (degrade-safe, auth-aware)
  - OptionalJwtMiddleware (public + viewer-aware routes, never 401)
  - /api/profiles/me/* owner-only CRUD passthrough (JWT user_id -> X-User-Id)
affects:
  - gateway/src/routes.php, gateway/public/index.php (DI)
tech-stack:
  added: []
  patterns:
    - "Utils::settle 2-way parallel fan-out (Option B: both calls async, profile = hard dep after settle)"
    - "OptionalJwtMiddleware: auth-aware public route, invalid token => anonymous"
    - "Defense-in-depth allowlist (array_intersect_key) on /full body AND PATCH /me success body"
    - "/me routing: JWT user_id -> path id + X-User-Id; body user_id never trusted"
key-files:
  created:
    - gateway/src/Middleware/OptionalJwtMiddleware.php
    - gateway/src/Controllers/AggregateController.php
  modified:
    - gateway/src/Services/ProfileClient.php
    - gateway/src/Services/ConnectionClient.php
    - gateway/src/Controllers/ProfilesController.php
    - gateway/src/routes.php
    - gateway/public/index.php
decisions:
  - "Option B parallel fan-out (both profile-full + connection async in settle) for genuine 2-way composition showcase"
  - "Invalid/expired token on /full treated as anonymous (RESEARCH A5) — stale token only reduces privilege"
  - "updateBasic re-applies the email/password_hash allowlist to its 2xx success body (profile-service update() returns find() which SELECTs email)"
metrics:
  duration: ~3min
  completed: 2026-06-06
  tasks: 3
  files: 7
---

# Phase 02 Plan 03: Flagship Composition + Owner CRUD Summary

API Gateway gains its Phase-2 showcase: `GET /api/profiles/{id}/full` composes profile-service `/users/{id}/full` and connection-service `/connections/status` via a genuine 2-way parallel `Utils::settle` fan-out, degrades safely (`meta.degraded`) when the connection stub 404s, is public + viewer-aware via a new `OptionalJwtMiddleware`, and never leaks email; plus owner-only `/api/profiles/me/*` CRUD that maps JWT `user_id` → `X-User-Id` (no IDOR surface).

## What Was Built

- **Task 1 (d6a9f78):** `ProfileClient::getFull/getFullAsync` + 8 owner-CRUD methods (experience/education/skills) each injecting `X-User-Id`; `ConnectionClient::statusForAsync` (Phase 2 stub 404 → degrade, D-03); new `OptionalJwtMiddleware` that sets `user_id` on a valid Bearer token and treats missing/invalid tokens as anonymous (never throws 401).
- **Task 2 (aaea8bf):** `AggregateController::profileFull` — both calls async in the `Utils::settle` array (Option B); profile-full is the hard dependency resolved after settle (404 → endpoint 404, non-200 → passthrough); connection degrades to `meta.degraded` + `connection_status` (`none` when logged in, `null` when anonymous); defense-in-depth `array_intersect_key` allowlist excludes email. `ProfilesController` gained 9 `/me` passthrough methods; `updateBasic` re-applies the allowlist to its success body so PATCH /me never echoes the caller's own email.
- **Task 3 (476b31c):** DI for `AggregateController` + `OptionalJwtMiddleware`; routes for `/profiles/{id:[0-9]+}/full` (optional JWT) + 9 `/profiles/me/*` (required JWT). Numeric `{id}` constraint prevents the literal `me` segment from colliding (Pitfall 5).

## Deviations from Plan

None — plan executed exactly as written.

## Threat Model Disposition

- **T-02-01 (IDOR):** Only `/me` write routes exist at the gateway; no numeric-id write routes. `callerId()` reads JWT `user_id`, forwards it as both path id and `X-User-Id`; body `user_id` never read (grep-enforced: `! grep "['user_id']"`).
- **T-02-02 (info disclosure):** `/full` uses `OptionalJwtMiddleware` (no 401) + allowlist excluding email/password_hash; PATCH `/me` success body re-allowlisted in `updateBasic`.
- **T-02-07 (DoS/availability):** `Utils::settle` (not unwrap) → connection failure degrades to `meta.degraded`, never 500s.
- **T-02-08 (spoofing):** invalid/expired token on `/full` → anonymous (accepted; reduces privilege only).

## Verification

- `php -l` clean on all 7 changed/new files.
- All Task 1/2/3 acceptance greps pass (settle present, both async keys, degrade+meta, allowlist no email, auth-aware default `viewerId > 0 ? 'none' : null`, 9 /me methods, no body user_id trust, flagship route + numeric constraint, DI registered).
- X-Request-Id downstream forwarding intact (HttpClient injects from RequestIdMiddleware static — untouched).
- **Runtime composition / degrade / auth-aware / owner-scope checks DEFERRED to VPS/CI** (Docker not installed locally; smoke-phase2.sh on the deployed stack in Plan 05).

## Self-Check: PASSED
