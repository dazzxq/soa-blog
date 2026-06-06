---
phase: 03-k-t-n-i-social-graph
plan: 03
subsystem: gateway
tags: [gateway, invariant, composition, enrichment, social-graph]
requires:
  - connection-service /connections (status, pending, suggestions, by-user) — Plan 03-02
  - profile-service GET /users + GET /users/{id} (public-safe) — Phase 1/2
provides:
  - "gateway /api/connections/* surface (9 routes, JWT)"
  - "D-01 cross-service invariant on POST invite (uniform 503-on-incomplete)"
  - "email-allowlisted enriched connection/pending/suggestion cards"
  - "gateway-composed suggestions candidate universe (ProfileClient::allUsers)"
  - "D-05 /full connection_status payoff (AggregateController unchanged)"
affects:
  - gateway/src/Services/ConnectionClient.php
  - gateway/src/Services/ProfileClient.php
  - gateway/src/Controllers/ConnectionsController.php
  - gateway/src/routes.php
  - gateway/public/index.php
tech-stack:
  added: []
  patterns:
    - "503-on-incomplete cross-service invariant (mirror PostsController::delete)"
    - "positive email-dropping allowlist via array_intersect_key"
    - "gateway-composed candidate universe (profile-service supplies enumeration the graph service cannot)"
key-files:
  created:
    - gateway/src/Controllers/ConnectionsController.php
  modified:
    - gateway/src/Services/ConnectionClient.php
    - gateway/src/Services/ProfileClient.php
    - gateway/src/routes.php
    - gateway/public/index.php
decisions:
  - "sendRequest mirrors delete()'s 503-on-incomplete (NOT createComment's >=500 passthrough) on BOTH cross-service checks — a connection write on unverified info is the integrity hazard"
  - "Any non-200 profile/status response (not just >=500) is treated as incomplete info -> 503, never a write"
  - "suggestions candidate universe composed at the gateway via ProfileClient::allUsers (connection-service cannot enumerate users); self excluded as a guard"
metrics:
  duration: 6min
  completed: 2026-06-07
  tasks: 3
  files: 5
---

# Phase 3 Plan 03: Gateway Connection Layer Summary

The gateway connection centerpiece: a uniform 503-on-incomplete cross-service invariant on POST invite, email-allowlisted enriched lists/suggestions with a gateway-composed candidate universe, the ConnectionClient/ProfileClient extensions, and routes/DI — lighting up the Phase 2 `/full` connection_status with zero AggregateController change.

## What Was Built

**Task 1 — Clients (commit a3dbad3).** Extended `ConnectionClient` with the sync `statusFor` plus mutation/list methods (`createRequest`, `accept`, `deleteRequest`, `removeEdge`, `listAccepted`, `listPending`, `suggestions`), each mutation injecting `X-User-Id`. `statusForAsync` was left byte-for-byte unchanged (D-05 contract AggregateController depends on). Added `ProfileClient::allUsers(int $limit = 100): array` returning the public-safe `data` list (id/username/display_name/avatar_url — no email), degrading to `[]` on any non-200.

**Task 2 — ConnectionsController (commit 40f8c99).** The D-01 invariant in `sendRequest`, cloned from the git-history `PostsController::delete()` 503-on-incomplete rule, applied uniformly to BOTH cross-service checks:
- self/invalid target -> 400 `INVALID_TARGET`
- profile clean-404 -> 404 `PROFILE_NOT_FOUND`
- profile >=500 OR GuzzleException OR any other non-200 -> 503 `PROFILE_SERVICE_UNAVAILABLE`, **no write** (NOT a Json::raw passthrough)
- status connected -> 409 `ALREADY_CONNECTED`; pending_outgoing/incoming -> 409 `REQUEST_EXISTS`
- status >=500 OR GuzzleException OR non-200 -> 503 `CONNECTION_SERVICE_UNAVAILABLE`, **no write**
- only clean-200 profile + `'none'` status proceeds to `createRequest` (the 23000->409 DB backstop surfaces through the passthrough).

Plus `accept`/`reject`/`cancel`/`remove` (JWT->X-User-Id passthrough, body id never trusted), `listConnections`/`listPending`/`suggestions` enriched through a private `enrich()` that applies `array_intersect_key(..., array_flip(['id','username','display_name','avatar_url']))` (email dropped), `suggestions` composing the universe via `allUsers` (self excluded), and `statusVsMe` passing `{data:{status}}` straight through.

**Task 3 — Routes + DI (commit 3b89f64).** All 9 D-04 routes registered inside `/api`, each `->add($jwtMw)`, with `{id:[0-9]+}`/`{userId:[0-9]+}` constraints keeping the literal `requests`/`suggestions`/`status` segments unambiguous (Pattern 6). DI binds `ConnectionsController(ProfileClient, ConnectionClient)`. AggregateController, /profiles, /auth, RequestIdMiddleware, HttpClient all untouched — so the `/full` connection_status payoff goes live for free and X-Request-Id downstream forwarding stays intact.

## Deviations from Plan

None - plan executed exactly as written. (The plan-suggested "non-200 -> 503" disposition was made explicit for both checks: any profile/status response that is neither a clean 200 nor a clean 404 is treated as incomplete info and yields a 503 with no write, fully consistent with the threat model T-03-12.)

## Verification

- `php -l` clean on all 5 files (ConnectionClient, ProfileClient, ConnectionsController, routes.php, public/index.php).
- grep asserts pass: both `PROFILE_SERVICE_UNAVAILABLE` and `CONNECTION_SERVICE_UNAVAILABLE` present (uniform 503-on-incomplete); profile >=500 branch maps to a 503 DomainError, not a Json::raw passthrough; `array_intersect_key` allowlist present; no `'email'` literal anywhere in the controller; `allUsers` used for the suggestions universe; 9 ConnectionsController routes registered; DI wired; AggregateController byte-identical (git diff clean).
- **Runtime / Docker checks deferred to VPS/CI** — Docker is not installed locally. The live invariant (400/404/409/503), the no-email card assertion, and the `/full` payoff are exercised by smoke-phase3.sh on the deployed stack in Plan 05.

## Threat Surface

No new surface beyond the plan's `<threat_model>`. All `/api/connections/*` routes sit behind `JwtAuthMiddleware`; user identity derives from the verified token (mapped to X-User-Id by the clients), never from the request body. The invariant directly implements mitigations T-03-11/12 (integrity + incomplete-info), enrich/allUsers implement T-03-13 (PII), passthrough mutations implement T-03-14 (IDOR) and T-03-15 (spoofing).

## Self-Check: PASSED
