---
phase: 05-t-m-ki-m-th-ng-b-o
plan: 03
subsystem: notification-service
tags: [notifications, raw-pdo, idor, x-user-id, recipient-scoped, slim4]
requires:
  - "notifications table (db/05-migrate-phase5.sql, Plan 05-01)"
  - "notification-service scaffold: App\\Db, App\\Json, App\\DomainError, App\\JsonErrorHandler (Plan 01-03)"
provides:
  - "POST /notifications — gateway-trusted create sink (recipient/actor/type/ref)"
  - "GET /notifications — recipient-scoped list newest-first + exact unread_count"
  - "POST /notifications/{id}/read — scoped mark-read (SELECT-existence, not rowCount)"
  - "POST /notifications/read-all — scoped mark-all-read"
affects:
  - "gateway NotificationClient + best-effort notify hooks (Plan 05-05)"
  - "smoke-phase5 list/mark-read/mark-all assertions (Plan 05-07)"
tech-stack:
  added: []
  patterns:
    - "connection-service ConnectionController doctrine cloned: X-User-Id scoping, scoped UPDATE + scoped SELECT existence (not rowCount), uniform 404"
    - "feed-service CommentController LIMIT PARAM_INT binding"
key-files:
  created:
    - services/notification-service/src/Controllers/NotificationController.php
  modified:
    - services/notification-service/src/routes.php
decisions:
  - "401 X-User-Id<=0 guard runs BEFORE any DB query on all three read/mark endpoints so a missing header is never coerced to user 0 (no masked 404)"
  - "markRead existence proven by scoped SELECT not rowCount — an already-read row returns 200, not a false 404 (T-05-11)"
  - "create() trusts the gateway body (recipient/actor) — trust-by-network, no host port (D-07/T-05-09); reads/marks never trust a body user_id (IDOR, T-05-08)"
  - "ref_id bound PDO::PARAM_NULL when absent; ref_id shaped to null (not 0) in index rows"
  - "TDD task verified statically (php -l + grep) — project has no test framework; runtime deferred to VPS smoke Plan 05-07 (Docker absent locally)"
metrics:
  duration: 1 min
  tasks: 2
  files: 2
  completed: 2026-06-07
---

# Phase 5 Plan 03: notification-service NotificationController Summary

Built notification-service for real (was a `/health` stub): a recipient-scoped raw-PDO `NotificationController` (create / index+unread_count / markRead / markAllRead) cloning the connection-service X-User-Id doctrine, plus wired routes. This is the NOTIF-02/03 data engine and the create sink for the gateway's best-effort notify hooks (Plan 05-05).

## What Was Built

- **`NotificationController`** (new):
  - `create(Request,Response)` — reads gateway body `user_id`(recipient)/`actor_id`/`type`/`ref_id`(nullable); validates `user_id>0 && actor_id>0` (else 400 VALIDATION_FAILED, Vietnamese) and `type ∈ {invite,reaction,comment}` (ENUM allowlist, else 400); INSERT then `Json::ok({id}, 201)`. ref_id bound PARAM_NULL/PARAM_INT.
  - `index(Request,Response)` — 401 guard, then `WHERE user_id = :me ORDER BY created_at DESC, id DESC LIMIT :lim` (PARAM_INT, clamped 1..50, default 30); a SEPARATE scoped `COUNT(*) WHERE read_at IS NULL` for the exact badge; `Json::list(rows, {unread_count, total})`. IDOR-safe — never returns another user's rows.
  - `markRead(Request,Response,$args)` — 401 guard FIRST (before any query), then scoped `UPDATE ... SET read_at = NOW() WHERE id=:id AND user_id=:u AND read_at IS NULL`, then scoped `SELECT id, read_at ... WHERE id=:id AND user_id=:u` proving existence; `false` → 404 NOTIFICATION_NOT_FOUND. Already-read row → 200 (not a false 404).
  - `markAllRead(Request,Response)` — 401 guard, scoped `UPDATE ... WHERE user_id=:u AND read_at IS NULL`; returns `{marked: rowCount}` (rowCount IS meaningful here = newly-read count).
- **`routes.php`** (extended): `/health` retained first; `POST /notifications`, `GET /notifications`, `POST /notifications/read-all` (literal, BEFORE the param route), `POST /notifications/{id:[0-9]+}/read`. No JWT/X-User-Id middleware in the service (controller reads the header directly, D-07).

## Deviations from Plan

None — plan executed exactly as written. The `tdd="true"` Task 1 was verified statically (`php -l` + grep assertions) following the established Phase 5 precedent (05-02): the project has no test framework (STACK.md GAP), so the grep contract is the RED/GREEN gate. Runtime behavioral verification is the Plan 05-07 gateway smoke (Docker absent locally).

## Verification

- `php -l` clean on both files.
- Task 1 grep contract: `read_at = NOW()`, `WHERE id = :id AND user_id = :u`, `unread_count`, `invite`, `NOTIFICATION_NOT_FOUND`, `UNAUTHORIZED` ≥3× — all pass.
- Task 2 grep/awk: `NotificationController` referenced, `read-all` + `{id:[0-9]+}/read` present, `/read-all` registered BEFORE `{id}/read` (awk line-order) — all pass.
- Global assertions: no JWT/firebase reference (only the "No JWT lib" doctrine comment); X-User-Id<=0→401 guard appears exactly 3× (index/markRead/markAllRead); reads/marks scoped by `WHERE user_id`.
- **Runtime checks deferred to VPS/CI (Docker not installed locally)** — list/mark-read/mark-all behavioral asserts run via the gateway in smoke-phase5 (Plan 05-07).

## Threat Coverage (from plan threat_model)

- **T-05-08 (IDOR)** mitigated: every read/UPDATE scoped by X-User-Id in the WHERE; 401-before-query on all three; uniform 404; body user_id never trusted on reads/marks.
- **T-05-09 (Spoofing create)** accepted by design: create trusts gateway body (trust-by-network, no host port).
- **T-05-10 (type tampering)** mitigated: type validated against the ENUM allowlist; ids cast to int; ref_id nullable int.
- **T-05-11 (markRead existence)** mitigated: scoped SELECT existence, not rowCount — already-read → 200.

No new threat surface beyond the plan's threat_model.

## Self-Check: PASSED
- FOUND: services/notification-service/src/Controllers/NotificationController.php
- FOUND: services/notification-service/src/routes.php (modified)
- FOUND commit d988f65 (Task 1)
- FOUND commit 7e023eb (Task 2)
