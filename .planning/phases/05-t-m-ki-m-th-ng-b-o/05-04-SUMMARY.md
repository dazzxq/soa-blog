---
phase: 05-t-m-ki-m-th-ng-b-o
plan: 04
subsystem: gateway-search-composition
tags: [gateway, search, composition, connection-status, allowlist, degrade, reindex]
requires:
  - 05-02 (search-service GET /search + POST /index)
  - connection-service GET /connections/status (statusForAsync, Phase 3)
  - profile-service GET /users (allUsers) + GET /users/{id}/full (getFull)
provides:
  - "GET /api/search?q= → quick-connect cards with viewer-relative connection_status (parallel settle)"
  - "POST /api/search/reindex → rebuild search_index from profile-service (allUsers + getFull)"
  - "SearchClient::search($q,$limit) + SearchClient::upsert($row)"
affects:
  - gateway/src/Services/SearchClient.php
  - gateway/src/Controllers/SearchController.php
  - gateway/src/routes.php
  - gateway/public/index.php
tech-stack:
  added: []
  patterns:
    - "parallel Utils::settle(statusForAsync) per-hit status fan-out (≤20, degrade to 'unknown')"
    - "email-allowlisted explicit-key card build (defense-in-depth over a no-email index)"
    - "gateway-orchestrated reindex: allUsers universe + per-user getFull pull → upsert"
key-files:
  created:
    - gateway/src/Controllers/SearchController.php
  modified:
    - gateway/src/Services/SearchClient.php
    - gateway/src/routes.php
    - gateway/public/index.php
decisions:
  - "search() compose status via PARALLEL Utils::settle(statusForAsync) keyed by uid — the SEARCH-02 showcase; a rejected/non-200 promise degrades that card to connection_status='unknown' + meta.degraded.parts=['status'], never a 500 (T-05-13)."
  - "Cards emit only {id,username,display_name,headline,avatar_url,connection_status} via an explicit literal-key build (not array_intersect_key, since connection_status is composed, not a hit field); no 'email' key — defense-in-depth over a search_index that has no email column (T-05-12)."
  - "reindex() pulls headline/location/skills via getFull per user (Pitfall 6 — the /users list lacks them); a per-user getFull failure proceeds with basic fields (partial index beats none), a per-user upsert failure increments failed without aborting the run; returns {indexed,failed,total}."
  - "Both routes JWT-required; viewer for connection_status comes from me() (JWT-set user_id attribute), never a query/body id (T-05-15)."
  - "reindex authorization = any logged-in user (T-05-14 accept): idempotent ON DUPLICATE KEY UPDATE, reads public profile fields, writes only the index — low blast radius for a demo."
metrics:
  duration: ~6min
  tasks: 3
  files: 4
  completed: 2026-06-07
---

# Phase 5 Plan 4: Gateway Search Composition Summary

Wired the SEARCH-02 gateway composition: extended `SearchClient` (search + upsert), added a gateway `SearchController` whose `search()` composes each search-service hit with the viewer's `connection_status` via a parallel `Utils::settle(statusForAsync)` fan-out under an email allowlist + degrade, and whose `reindex()` pulls the user universe from profile-service and upserts each (headline/skills via `getFull`) into search-service. Routes + DI added (JWT).

## What Was Built

- **Task 1 — SearchClient** (`84b03fb`): added `search(string $q, int $limit=20): ResponseInterface` → `GET /search?q=&limit=` and `upsert(array $row): ResponseInterface` → `POST /index` (JSON body). Kept health/healthAsync. HttpClient is `http_errors=false`, so non-2xx returns a response; a network failure throws `GuzzleException` (callers handle it).
- **Task 2 — SearchController** (`ba92bef`):
  - `search()`: `me()` (JWT) → trim `q` (empty → empty list with `meta.q=''`) → `search->search($q,20)` (non-200 passthrough) → keep hits with valid uid ≠ viewer → build `statusForAsync($me,$uid)` per kept hit → `Utils::settle(...)->wait()` → per uid: fulfilled+200 → `data.status`; else → `'unknown'` + `degraded['status']=true` → emit allowlisted card `{id,username,display_name,headline,avatar_url,connection_status}` → `meta={q, [degraded,parts]}`.
  - `reindex()`: `me()` authn gate → `profiles->allUsers(100)` (degrades to [] safely) → per user: `getFull(uid)` for headline/location/skills (flatten `skills[].name` → `skills_text`, GuzzleException → basic fields only) → `search->upsert([...])` (200/201 → indexed, else/GuzzleException → failed) → `Json::raw({indexed,failed,total},200)`.
- **Task 3 — routes + DI** (`4416fbd`): `GET /api/search` + `POST /api/search/reindex` (both `->add($jwtMw)`); `SearchController` DI factory wired with `SearchClient`, `ProfileClient`, `ConnectionClient`. ConnectionsController/FeedController factories untouched (Plan 05 owns those edits — avoids same-wave conflict).

## Deviations from Plan

None — plan executed exactly as written.

Note (not a deviation): the per-hit card is built with an **explicit literal-key array** rather than `array_intersect_key`, because the plan's card spec (line 110) mixes hit fields with a composed `connection_status` value that does not exist in the hit. The plan text specifies exactly this literal shape; `! grep "'email'"` still passes and no email column exists upstream.

## Verification

Static only (Docker not installed locally — runtime deferred to VPS/CI Plan 07):

- `php -l` clean on all 4 changed/new files.
- grep asserts (all PASS): `statusForAsync`, `Utils::settle`, `connection_status`, `getFull`, `skills_text` present in SearchController; `function search` + `function upsert` + `'/index'` in SearchClient; `'/search'` + `'/search/reindex'` in routes; `SearchController::class` factory in public/index.php; **no `'email'` literal** in SearchController or SearchClient.
- X-Request-Id forwarding intact: no middleware files changed (existing RequestIdMiddleware static-set mechanism untouched).
- **Runtime (Docker/CI) deferred to VPS/CI Plan 07**: smoke-phase5 reindex + `q=duyet`/`q=PHP` returning `connection_status` with no PII leak.

## Threat Coverage

- **T-05-12 (PII leak)** mitigated: cards emit only the 6-key allowlist; `search_index` has no email column; explicit-key build emits nothing else.
- **T-05-13 (DoS via per-hit fan-out)** mitigated: result set capped at ≤20 + parallel `Utils::settle`; a failed/slow status promise degrades that card to `'unknown'` + `meta.degraded`, never a 500.
- **T-05-14 (reindex authz)** accepted: any logged-in user may trigger; idempotent, public-read/index-write only.
- **T-05-15 (viewer spoofing)** mitigated: `me()` reads the JWT-set `user_id` attribute, never a query/body id.

## Self-Check: PASSED

- FOUND: gateway/src/Controllers/SearchController.php
- FOUND: gateway/src/Services/SearchClient.php (modified)
- FOUND commit 84b03fb (Task 1)
- FOUND commit ba92bef (Task 2)
- FOUND commit 4416fbd (Task 3)
