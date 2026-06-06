---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: executing
stopped_at: Completed 03-03-PLAN.md
last_updated: "2026-06-06T20:01:18.647Z"
last_activity: 2026-06-06
progress:
  total_phases: 6
  completed_phases: 2
  total_plans: 16
  completed_plans: 14
  percent: 88
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-06-06)

**Core value:** API Gateway điều phối một hệ microservices đủ phong phú (profile, connection/graph, feed, search) thể hiện rõ và thuyết phục các trách nhiệm cốt lõi của Gateway pattern.
**Current focus:** Phase 3 — Kết nối / Social Graph

## Current Position

Phase: 3 (Kết nối / Social Graph) — EXECUTING
Plan: 4 of 5
Status: Ready to execute
Last activity: 2026-06-06

Progress: [░░░░░░░░░░] 0%

## Performance Metrics

**Velocity:**

- Total plans completed: 11
- Average duration: —
- Total execution time: 0 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 1 | 6 | - | - |
| 2 | 5 | - | - |

**Recent Trend:**

- Last 5 plans: —
- Trend: —

*Updated after each plan completion*
| Phase 01 P01 | 8 | 1 tasks | 1 files |
| Phase 01 P02 | 6 min | 2 tasks | 13 files |
| Phase 01 P05 | 2 min | 2 tasks | 6 files |
| Phase 01 P03 | 5min | 2 tasks | 52 files |
| Phase 01 P04 | 3m | 3 tasks | 39 files |
| Phase 01 P06 | 3min | 2 tasks | 4 files |
| Phase 02 P01 | 4min | 3 tasks | 5 files |
| Phase 02 P02 | 6min | 3 tasks | 3 files |
| Phase 02 P03 | 3min | 3 tasks | 7 files |
| Phase 02-h-s-ngh-nghi-p P04 | 3min | 2 tasks | 6 files |
| Phase 03 P01 | 3 min | 3 tasks | 4 files |
| Phase 03 P02 | 2 min | 2 tasks | 2 files |
| Phase 03 P03 | 6min | 3 tasks | 5 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- [Roadmap]: Brownfield — `user-service` tiến hoá thành `profile-service`; thêm connection/feed/search/notification-service, mỗi bounded context 1 service.
- [Roadmap]: Giữ stack ≤ 9 container (ngân sách 2GB RAM); 1 MariaDB nhiều schema; notification polling (không WebSocket).
- [Roadmap]: UI tính năng (UI-03) fold vào Phase 2; chrome thương hiệu (UI-01/02/04) gom vào Phase 6 sau khi mọi tính năng tồn tại để navbar/badge nối đúng.
- [Roadmap]: Mỗi phase tính năng thêm 1 endpoint composition hoặc invariant tại gateway để pattern luôn nổi bật.
- [Phase 01]: Smoke script written first (Wave 0) as the automated gate every later wave runs after docker compose up -d --wait
- [Phase 01]: X-Request-Id smoke assertion proves DOWNSTREAM receipt (D-12): gateway response-header id must equal a rid echoed in the /api/health body, not bare header presence
- [Phase 01]: 01-02: user-service renamed to profile-service via git mv (history preserved, D-05); ProfileClient added reading PROFILE_SERVICE_URL; gateway DI/compose rewire deferred to Plan 04
- [Phase 01]: 01-05: live cutover named db/migrate-phase1.sql.tmpl (NOT .sql) so MariaDB initdb glob never auto-runs it with un-substituted ${VAR} passwords on fresh volume (ISSUE-7); retired blog post/comment schema files git-rm'd since db/ is the initdb mount (D-01)
- [Phase 01]: 01-03: scaffolded 4 stub services (connection/feed/search/notification) cloned from canonical profile-service; each /health echoes X-Request-Id as rid (D-12); vendor excluded, composer.lock verbatim; 4 health-only gateway clients added, not yet DI-registered (Plan 04 wires)
- [Phase 01]: X-Request-Id forwarded downstream via static set by RequestIdMiddleware, safe due to lazy per-request client construction (not the false php-fpm claim)
- [Phase 01]: Public /api/profiles/{id} trimmed to D-07 allowlist {id,username,display_name} via array_intersect_key; email never exposed
- [Phase 01]: post/comment fully retired: gateway code + compose blocks + service dirs git rm'd; services/=5
- [Phase 01]: 01-06: deploy.sh no longer self-pulls (workflow does git pull --ff-only first so the new cutover logic runs on the first deploy); order = mariadb-only up+healthy -> BLOCKING pre-wipe mysqldump (abort on fail/empty) -> envsubst migrate -> full up --remove-orphans; public health gate now asserts status:ok for all 5 services.
- [Phase 02]: 02-01: phase-2 live migration is a PLAIN .sql (no envsubst/secrets) applied BLOCKING in deploy.sh after the phase-1 cutover; idempotent ADD/CREATE IF NOT EXISTS + guarded demo seed inside it for the live volume, mirrored to 01-schema/99-seed for fresh volumes.
- [Phase 02]: 02-01: smoke-phase2.sh mutates ONLY headline (seed-guaranteed non-null) with trap restore EXIT registered before capture + RESTORE_READY gate + jesc; non-destructive under set -euo pipefail. It is the Phase-2 gate; runtime run deferred to VPS (Docker absent locally).
- [Phase 02]: 02-02: exp/edu/skills writes return UNIFORM 404 on 0-rowcount (never 403) — not-found vs not-owned indistinguishable, closing the IDOR row-existence oracle; no disambiguating SELECT
- [Phase 02]: 02-02: /users/{id}/full emits a public column allowlist (no email/password_hash) at the profile-service data layer; gateway adds a second allowlist in Plan 03
- [Phase 02]: 02-02: every write scoped strictly by X-User-Id header; user_id never read from body (grep-enforced); profile-service still verifies NO JWT
- [Phase 02]: 02-03: flagship GET /api/profiles/{id}/full = 2-way parallel Utils::settle fan-out (profile-full hard dep + connection degradable); meta.degraded on stub 404 (D-03); public+auth-aware via new OptionalJwtMiddleware (invalid token -> anonymous, never 401); allowlist excludes email
- [Phase 02]: 02-03: /api/profiles/me/* owner CRUD maps JWT user_id -> path id + X-User-Id (body user_id never trusted, no IDOR surface); updateBasic re-applies email/password_hash allowlist to success body since profile-service update() returns find() (SELECTs email)
- [Phase 02-h-s-ngh-nghi-p]: 02-04: UI = two files (profile.html view via /full + profile-edit.html owner CRUD via /me/*), Alpine+Tailwind CDN, x-text only (no x-html, T-02-10), app.js cache-bust v=ph2-04 bumped on all 5 HTML; browser verify deferred to VPS Plan 05
- [Phase 03]: connections uses STORED pair_lo/pair_hi + uq_pair to make opposite-direction invite race impossible (deterministic statusBetween)
- [Phase 03]: reject(addressee)+cancel(requester) collapse to one pending-scoped DELETE (either party) — Codex-approved
- [Phase 03]: single 23000 catch backstops both uq_conn_pair (same-dir dup) and uq_pair (opposite-dir race) → 409
- [Phase 03]: 03-03: sendRequest mirrors delete()'s 503-on-incomplete (NOT createComment >=500 passthrough) on BOTH cross-service checks — never writes a connection on unverified profile/status info
- [Phase 03]: 03-03: any non-200 (not just >=500) profile/status response is treated as incomplete -> 503 no-write; suggestions candidate universe composed at gateway via ProfileClient::allUsers (graph service cannot enumerate users)
- [Phase 03]: 03-03: D-05 /full connection_status now lights up with ZERO AggregateController change; all connection cards email-allowlisted to id/username/display_name/avatar_url

### Pending Todos

[From .planning/todos/pending/ — ideas captured during sessions]

None yet.

### Blockers/Concerns

[Issues that affect future work]

- Ngân sách RAM cứng (2GB chia sẻ, ≤ 9 container): theo dõi số container ở mỗi phase thêm service mới — Phase 1, 3, 4, 5 đều thêm service.
- Chưa có test framework (chỉ `php -l` ở CI): cân nhắc thêm PHPUnit trước refactor lớn ở Phase 1.
- Tailwind Play CDN không production-grade cho UI phong phú hơn: cân nhắc build step ở Phase 6.
- 01-06 PLAT-06 pending: live VPS .env edit (5 shell-safe *_SVC_DB_PASS) + production DB cutover is a human-action checkpoint, not yet performed. deploy.sh + workflow are wired and statically verified.

## Session Continuity

Last session: 2026-06-06T20:01:12.075Z
Stopped at: Completed 03-03-PLAN.md
Resume file: None
