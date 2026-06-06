---
phase: 01-n-n-t-ng-gateway
plan: 01
subsystem: testing
tags: [bash, curl, docker-compose, smoke-test, health-check, request-id, jwt]

# Dependency graph
requires: []
provides:
  - "scripts/smoke-phase1.sh — single local gate asserting the whole Phase 1 stack is healthy"
  - "Concrete D-12 downstream-receipt assertion (gateway X-Request-Id echoed as `rid` by a stub)"
  - "Automated <verify> harness referenced by Plans 02–06"
affects: [01-02, 01-03, 01-04, 01-05, 01-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Wave 0 smoke gate: bash + curl + docker compose, no test framework (per 01-VALIDATION.md)"
    - "Failure-counter pattern: each check prints [smoke] PASS/FAIL <name>, non-zero exit if any fail"
    - "Downstream-receipt proof for X-Request-Id (not bare header presence)"

key-files:
  created:
    - scripts/smoke-phase1.sh
  modified: []

key-decisions:
  - "Smoke script written first (Wave 0) as the missing automated check every later wave runs after `docker compose up -d --wait`"
  - "X-Request-Id assertion proves DOWNSTREAM receipt (D-12): gateway response-header id must equal a `rid` echoed inside the /api/health body, not just header presence"

patterns-established:
  - "Phase 1 validation is integration/smoke-level (no PHPUnit) — single bash gate"
  - "Demo password demo@123** literal in script matches db/99-seed.sql bcrypt hash (T-1-00 accepted, throwaway)"

requirements-completed: [PLAT-05, PLAT-01, PROF-01]

# Metrics
duration: ~8min
completed: 2026-06-06
---

# Phase 1 Plan 01: Smoke-test Wave 0 Summary

**Executable bash smoke gate (scripts/smoke-phase1.sh) asserting container count ≤9, all 5 stub /health, gateway 5-service fan-out, profile route, 401 auth guard, X-Request-Id downstream receipt (D-12), and login for all 5 demo accounts.**

## Performance

- **Duration:** ~8 min
- **Tasks:** 1
- **Files created:** 1

## Accomplishments
- Created `scripts/smoke-phase1.sh` (123 lines, executable) — the single command later waves run after `docker compose up -d --wait` and the pre-push gate before CI deploy.
- Implemented all 7 required assertions, each with a `[smoke] PASS/FAIL <name>` line, a failure counter, and `exit 1` if any fail.
- Encoded the concrete D-12 downstream-receipt proof: the gateway's `X-Request-Id` response header value must reappear as `"rid":"<id>"` inside the `/api/health` body (proving a downstream stub received the forwarded id), not a bare header-presence check.

## Task Commits

1. **Task 1: Write scripts/smoke-phase1.sh** - `b274680` (feat)

**Plan metadata:** committed with STATE/ROADMAP/REQUIREMENTS in final docs commit.

## Files Created/Modified
- `scripts/smoke-phase1.sh` - Phase 1 smoke gate covering PLAT-01..05 + PROF-01 + D-06/D-10/D-12. Base URL `GW=${GW:-http://127.0.0.1:8000}`. Seven assertions: (1) container count ≤9, (2) per-service `/health` `"db":"ok"` via `docker compose exec gateway wget`, (3) gateway `/api/health` fan-out status ok + all 5 service keys, (4) `/api/profiles/2` contains `duyet`, (5) `/api/me` without auth returns 401, (6) X-Request-Id downstream receipt, (7) login all 5 demo accounts returns a token.

## Decisions Made
- Wrote the smoke script against the Phase 1 **end-state** contract documented in the plan's `<interfaces>` (gateway forwarding X-Request-Id and stubs echoing `rid`). The current brownfield `HealthController.php` is still the 3-service blog version and does not yet emit `rid` or the 5-service shape — Plan 03 (stub `/health` echo) and Plan 04 (gateway forwarding) make assertion #6 pass at runtime. This is intentional: per Wave 0, the test exists first.

## Deviations from Plan
None - plan executed exactly as written.

## Issues Encountered
None.

## Verification

Static verification only — runtime checks (Docker/curl against a live stack) are **deferred to VPS/CI** after deploy, because Docker is not installed on this build machine.

- `bash -n scripts/smoke-phase1.sh` → 0 (valid syntax). PASS.
- `test -x scripts/smoke-phase1.sh` → executable bit set (mode 100755). PASS.
- Plan `<verify>` automated line (all greps: `api/profiles/2`, `demo@123`, `le 9`, `x-request-id`, `"rid"`) → PASS.
- Acceptance criteria greps: `"db":"ok"` count = 2 (≥1); 5-service loop present; 5-account login loop present; `%{http_code}` + `401` present; `x-request-id` + `"rid"` + `request-id-downstream` all present; `le 9` present. All PASS.
- Line count 123 ≥ 40 (artifact min_lines). PASS.

**Deferred to VPS/CI (require a running Docker stack):**
- Actual execution of `scripts/smoke-phase1.sh` ending in `[smoke] ALL PASS`.
- Runtime truth of assertion #6 (X-Request-Id downstream receipt) — depends on Plan 03 stub echo + Plan 04 gateway forwarding being in place.
- All container-count, health, fan-out, profile, auth-guard, and login checks against the live stack.

## Next Phase Readiness
- Smoke gate is in place; Plans 02–06 can reference it in their `<verify>` blocks.
- Assertion #6 will go green only after Plan 03 (stub `/health` echoes inbound X-Request-Id as `rid`) and Plan 04 (gateway forwards X-Request-Id downstream via Guzzle) land.
- No blockers introduced.

## Self-Check: PASSED

- `scripts/smoke-phase1.sh` — FOUND (executable, `bash -n` clean).
- Commit `b274680` — FOUND in git log.

---
*Phase: 01-n-n-t-ng-gateway*
*Completed: 2026-06-06*
