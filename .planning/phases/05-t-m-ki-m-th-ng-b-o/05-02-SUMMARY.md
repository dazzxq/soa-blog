---
phase: 05-t-m-ki-m-th-ng-b-o
plan: 02
subsystem: search-service
tags: [search, like, pdo, sqli-hardening, upsert, slim]
requires:
  - "05-01: search_index table (proconnect_search) + demo seed"
  - "search-service scaffold: App\\Db, App\\Json, App\\DomainError (Phase 1 01-03)"
provides:
  - "GET /search — parameterized case-insensitive 4-column LIKE over search_index (SEARCH-01 data engine)"
  - "POST /index — idempotent ON DUPLICATE KEY upsert sink for the gateway reindex (Plan 04)"
affects:
  - "gateway Plan 04 composes search cards on top of GET /search and feeds POST /index"
tech-stack:
  added: []
  patterns:
    - "raw-PDO native prepared statements; same LIKE term bound under 4 distinct names (:t1..:t4) — placeholder reuse forbidden"
    - "LIKE wildcard neutralization: escape-char-first str_replace(['\\\\','%','_']) + ESCAPE '\\\\' on every predicate (defense-in-depth over binding)"
    - "LIMIT bound PDO::PARAM_INT (string-bound LIMIT is quoted + rejected)"
    - "X-User-Id-free / JWT-free service (D-07 trust-by-network)"
key-files:
  created:
    - services/search-service/src/Controllers/SearchController.php
  modified:
    - services/search-service/src/routes.php
decisions:
  - "TDD task executed as static-spec satisfaction (php -l + grep): project has NO test framework (STACK.md), Docker absent locally; adding PHPUnit would be a Rule-4 architectural change. Behavior block satisfied by the plan's own automated verify."
  - "ESCAPE '\\\\' carried on ALL FOUR LIKE predicates (not just one) so a user-supplied %/_ is neutralized on every searched column."
metrics:
  duration: ~1 min
  completed: 2026-06-06
  tasks: 2
  files: 2
---

# Phase 05 Plan 02: search-service Search Engine Summary

Built the SEARCH-01 data engine: a `SearchController` with one parameterized, case-insensitive, capped 4-column LIKE `search()` over `search_index` (SQLi-safe + user-wildcard-neutralized) and one idempotent `upsert()` (ON DUPLICATE KEY UPDATE on the `user_id` PK) as the gateway reindex sink, plus the two routes — cloning the connection/feed-service raw-PDO doctrine, no JWT/X-User-Id.

## What Was Built

### Task 1 — SearchController (commit 9b33650)
`services/search-service/src/Controllers/SearchController.php` (namespace `App\Controllers`):

- **`search(Request,Response)`** — `GET /search?q=&limit=`:
  - `q` trimmed; empty `q` short-circuits to `Json::list([], {total:0,q:''})` with NO DB hit.
  - `mb_strlen($q) > 100` → `DomainError(400, VALIDATION_FAILED, 'Từ khoá tìm kiếm quá dài.')`.
  - `limit = min(50, max(1, (int)limit ?? 20))`.
  - Wildcard neutralization: `str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $q)` (escape-char-first) → wrapped `%…%` → bound as a VALUE.
  - SQL: `... WHERE display_name LIKE :t1 ESCAPE '\\' OR username LIKE :t2 ESCAPE '\\' OR headline LIKE :t3 ESCAPE '\\' OR skills_text LIKE :t4 ESCAPE '\\' ORDER BY display_name ASC LIMIT :lim`. Four distinct named binds; `:lim` bound `PDO::PARAM_INT`. Never interpolated.
  - Rows: `user_id` cast to int → `Json::list($res, $rows, ['total'=>count, 'q'=>$q])`.
- **`upsert(Request,Response)`** — `POST /index`:
  - `uid = (int)body.user_id`; `uid <= 0` → `DomainError(400, VALIDATION_FAILED, 'Thiếu user_id.')`.
  - `INSERT ... ON DUPLICATE KEY UPDATE` over all 6 non-PK columns; username/display_name default `''`, headline/location/skills_text/avatar_url default `null`.
  - Returns `Json::ok({user_id}, 200)`; idempotent on re-upsert.

### Task 2 — routes (commit 7732d3b)
`services/search-service/src/routes.php`: added `use App\Controllers\SearchController;` and registered `GET /search` → `search`, `POST /index` → `upsert`. `/health` retained first. No JWT/X-User-Id middleware (D-07).

## Verification

- `php -l` clean on both files.
- grep asserts PASS: `ON DUPLICATE KEY UPDATE`, `:t1`/`:t4`, `PDO::PARAM_INT`, `ESCAPE` count = 5 (≥4 required — 4 predicates + the `ESCAPE '\\'` mention; all four LIKE predicates confirmed carrying `ESCAPE '\\'` via `grep "LIKE :t. ESCAPE"`), the `str_replace(['\\','%','_']` escape routine, `Từ khoá` Vietnamese error, route literals `'/search'`/`'/index'`, `SearchController` reference.
- No-JWT confirmed: the only `X-User-Id`/`JWT` matches are documentation comments stating the absence; no `getHeaderLine`/`firebase` code.
- **Runtime deferred to VPS/CI (Plan 07):** Docker not installed locally. smoke-phase5 will exercise `GET /api/search?q=duyet`, `q=PHP` (skills_text hit), and `q=100%` (literal-match, no over-broad wildcard) through the gateway.

## Deviations from Plan

### Auto-handled

**1. [Rule 3 - Blocking] TDD task run as static-spec satisfaction, not RED/GREEN with a test runner**
- **Found during:** Task 1 (tagged `tdd="true"`).
- **Issue:** Project has NO test framework (STACK.md: "No PHPUnit, Pest, or any test runner… only `php -l`"); Docker/runtime absent locally (stated environment constraint). Standing up PHPUnit for one controller is a Rule-4 architectural change inconsistent with every prior phase.
- **Resolution:** Satisfied the `<behavior>` contract against the plan's own `<verify><automated>` block (`php -l` + grep over every behavioral invariant), mirroring how Phases 1–4 verified service code. Runtime behavior is covered by smoke-phase5 on the VPS (Plan 07). No test files created.
- **Files modified:** none beyond the planned two.

## Known Stubs

None. Both endpoints execute real PDO queries against `search_index`; no placeholder/empty-data paths.

## Threat Flags

None. No new trust-boundary surface beyond the plan's threat model (search/index already enumerated as T-05-05/06/07; no email column read or written; no new network path).

## Self-Check: PASSED
- FOUND: services/search-service/src/Controllers/SearchController.php
- FOUND: services/search-service/src/routes.php
- FOUND commit: 9b33650 (SearchController)
- FOUND commit: 7732d3b (routes)
