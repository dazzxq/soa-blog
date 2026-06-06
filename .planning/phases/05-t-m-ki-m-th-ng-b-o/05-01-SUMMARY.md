---
phase: 05-t-m-ki-m-th-ng-b-o
plan: 01
subsystem: database + deploy + smoke
tags: [database, migration, smoke, search, notification]
requires:
  - "proconnect_search schema + search_svc grant (Phase 1)"
  - "proconnect_notification schema + notification_svc grant (Phase 1)"
  - "5 demo accounts (Phase 1), duyet<->long accepted edge (Phase 3), seed post 1 author=duyet (Phase 4)"
provides:
  - "search_index table (proconnect_search, incl avatar_url) on the live volume after deploy (wired blocking)"
  - "notifications table (proconnect_notification) on the live volume after deploy (wired blocking)"
  - "idempotent demo seed: 5 search_index rows (duyet findable by name + PHP skill) + 2 unread duyet notifications"
  - "fresh-volume schema convergence (db/01-schema-search.sql, db/01-schema-notification.sql)"
  - "scripts/smoke-phase5.sh — the SEARCH-01/02 + NOTIF-01/02/03 acceptance gate every later wave references"
affects: [scripts/deploy.sh, db/, scripts/]
tech-stack:
  added: []
  patterns:
    - "Multi-USE idempotent live-volume additive migration spanning TWO DBs, applied with NO CLI db arg (mirrors migrate-phase1.sql.tmpl)"
    - "Denormalized search_index with avatar_url for gateway-composed search cards (D-01/D-03)"
    - "Non-destructive smoke harness with trap restore EXIT + PRE-CLEAN of leftover test state (Phase 3/4 idempotency lesson)"
key-files:
  created:
    - db/05-migrate-phase5.sql
    - db/01-schema-search.sql
    - db/01-schema-notification.sql
    - scripts/smoke-phase5.sh
  modified:
    - scripts/deploy.sh
decisions:
  - "Migration is plain .sql (no envsubst/secrets) — search_svc/notification_svc already have GRANT ALL on their schemas from Phase 1"
  - "Single migration spans TWO databases via explicit USE blocks; deploy.sh applies it with NO DB arg (unlike phase-2/3/4 single-DB steps)"
  - "search_index carries denormalized avatar_url (A1, D-03) so the gateway search card needs no extra round trip; refreshed at reindex, may be NULL"
  - "Seed: duyet (id 2) has display_name 'Nguyễn Thế Duyệt' + skills_text containing 'PHP' so smoke q=duyet AND q=PHP both hit; 2 unread notifications for duyet so the bell badge shows immediately"
  - "deploy.sh phase-5 step 7e has NO `|| true` — a migration failure surfaces and blocks deploy before full-topology up, so search/notification services never boot against missing tables"
  - "Fresh-volume schema files KEEP the `USE` statement (matching db/01-schema-feed.sql) — the plan's literal 'no USE' would land the table in no/wrong DB during fresh init (deviation Rule 1)"
metrics:
  duration: 6 min
  tasks: 3
  files: 5
  completed: 2026-06-07
---

# Phase 5 Plan 01: Search/Notification Migration + Smoke Harness Summary

Idempotent non-destructive Phase-5 migration that creates `search_index` (in `proconnect_search`, with denormalized `avatar_url`) and `notifications` (in `proconnect_notification`) across two databases via a single multi-`USE` file plus a demo seed (5 search rows + 2 unread duyet notifications), wired BLOCKING into `deploy.sh` after the phase-4 step with no DB arg, two fresh-volume schema files, and the `smoke-phase5.sh` acceptance harness covering SEARCH-01/02 + NOTIF-01/02/03.

## What Was Built

### Task 1 — db/05-migrate-phase5.sql + fresh-volume schema (commit 5d651da)
- `USE proconnect_search;` → `CREATE TABLE IF NOT EXISTS search_index` (user_id PK, username, display_name, headline, location, skills_text, **avatar_url**, two indexes).
- 5 guarded `INSERT ... WHERE NOT EXISTS` seed rows for demo/duyet/long/diep/tai with realistic Vietnamese values. duyet's row carries display_name "Nguyễn Thế Duyệt" + skills_text "PHP, Docker, Kiến trúc hướng dịch vụ, API Gateway".
- `USE proconnect_notification;` → `CREATE TABLE IF NOT EXISTS notifications` (id PK, user_id recipient, type ENUM(invite/reaction/comment), actor_id, ref_id, created_at, read_at, two indexes).
- 2 guarded UNREAD notification seeds for duyet (id 1 = invite from demo; id 2 = reaction from diep on post 1).
- `db/01-schema-search.sql` + `db/01-schema-notification.sql` mirror the DDL for fresh volumes.
- Zero DROP/ALTER (grep-verified — only the safety comment mentions them).

### Task 2 — deploy.sh phase-5 step (commit 95b3579)
- Inserted step `7e)` after the phase-4 `echo`/before `# 8) FULL-TOPOLOGY UP`.
- `docker compose exec -T mariadb mysql -uroot -p"$DB_ROOT_PASSWORD" < db/05-migrate-phase5.sql` — **no DB arg** (file switches DBs via internal USE blocks).
- Blocking (no `|| true`): a failed migration halts deploy before services boot.

### Task 3 — scripts/smoke-phase5.sh (commit f38cff1)
- Clones smoke-phase4 discipline: `set -euo pipefail`, GW/PW vars, pass/fail counters, login helper, `trap restore EXIT` registered before the first write, `assert_no_pii`.
- PRE-CLEAN of any leftover demo→tai connection-request before the invite step.
- Assertions map 1:1 to the per-requirement table: reindex≥5, search by name, search by PHP skill, connection_status compose (SEARCH-02), invite/reaction/comment notifications with enriched actor + best-effort 2xx (NOTIF-01), newest-first + unread_count (NOTIF-02), mark-one-read + read-all (NOTIF-03), and a global PII sweep.
- Never deletes the demo seed (search rows, 2 demo notifications); marking seed read is non-destructive. 339 lines (min_lines 120).

## Verification

All static (Docker not installed locally — runtime deferred to VPS/CI in Plan 07):
- Task 1 grep: search_index + notifications CREATE IF NOT EXISTS, both USE blocks, avatar_url, PHP all present; zero DROP/ALTER.
- Task 2: phase-5 step present, `mysql -uroot` on the next line, NO `proconnect_*` DB arg on the pipe line, `bash -n scripts/deploy.sh` clean.
- Task 3: `bash -n scripts/smoke-phase5.sh` clean; trap + reindex + unread_count + notifications + PII guard all present.

Runtime (`bash scripts/smoke-phase5.sh` against the deployed stack) is **deferred to VPS/CI (Plan 07 live cutover)** — the search/notification gateway routes go live in Plans 04/05, and Docker is absent locally (05-RESEARCH §Environment).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fresh-volume schema files keep the `USE` statement**
- **Found during:** Task 1
- **Issue:** The plan's task-1 text says to create `db/01-schema-search.sql` / `db/01-schema-notification.sql` "no USE — the init system selects the DB". But the MariaDB initdb runner pipes each `*.sql` through `mysql` with NO database selected (00-init.sh just creates schemas), and the existing sibling `db/01-schema-feed.sql` DOES use `USE proconnect_feed;`. Omitting `USE` would create the table in no/the wrong database on a fresh volume.
- **Fix:** Included `USE proconnect_search;` / `USE proconnect_notification;` at the top of each fresh-volume schema file, matching the established `db/01-schema-feed.sql` convention.
- **Files modified:** db/01-schema-search.sql, db/01-schema-notification.sql
- **Commit:** 5d651da

No other deviations — the migration DDL, deploy wiring, and smoke structure follow the plan exactly.

## Authentication Gates

None — this plan is static SQL/bash authoring with no auth-requiring tooling.

## Self-Check: PASSED

- db/05-migrate-phase5.sql — FOUND
- db/01-schema-search.sql — FOUND
- db/01-schema-notification.sql — FOUND
- scripts/smoke-phase5.sh — FOUND
- scripts/deploy.sh (modified) — FOUND
- Commit 5d651da — FOUND
- Commit 95b3579 — FOUND
- Commit f38cff1 — FOUND
