---
phase: 03-k-t-n-i-social-graph
plan: 02
subsystem: connection-service
tags: [connection-service, pdo, social-graph, status, idor]
requirements: [CONN-01, CONN-02, CONN-03, CONN-04, CONN-05, CONN-06, CONN-07]
dependency_graph:
  requires:
    - "03-01: connections table + uq_conn_pair + uq_pair (deterministic statusBetween)"
    - "gateway ConnectionClient::statusForAsync (locked GET /connections/status?viewer=&target= path)"
  provides:
    - "connection-service graph CRUD: create/accept/delete-request/remove-edge"
    - "viewer-relative statusBetween + GET /connections/status → {data:{status}}"
    - "listAccepted / listPending / suggestions (ids-only, gateway enriches)"
  affects:
    - "03-03: gateway ConnectionsController will consume these routes + the status invariant"
    - "Phase 2 /full: lights up connection_status with zero gateway rework (D-05)"
tech_stack:
  added: []
  patterns:
    - "raw-PDO X-User-Id-scoped writes (cloned from profile-service ProfileController)"
    - "uniform-404 IDOR doctrine: scoped WHERE → 0 rows → 404, no 403 oracle"
    - "scoped-SELECT existence after UPDATE (not rowCount), rowCount===0→404 on DELETE"
    - "PDOException 23000 → 409 backstop (covers same- AND opposite-direction races)"
    - "native prepared statements: duplicate-bind viewer/target/caller (:v1/:v2 …)"
key_files:
  created:
    - "services/connection-service/src/Controllers/ConnectionController.php"
  modified:
    - "services/connection-service/src/routes.php"
decisions:
  - "reject(addressee) + cancel(requester) collapse to ONE pending-scoped DELETE (either party) — Codex-approved"
  - "suggestions exclusion set computed locally; candidate universe supplied by gateway (Pitfall 6 / Open Q1a)"
  - "single 23000 catch backstops both uq_conn_pair (same-dir dup) and uq_pair (opposite-dir race)"
metrics:
  duration: 2 min
  tasks: 2
  files: 2
  completed: 2026-06-06
---

# Phase 3 Plan 02: connection-service graph CRUD + status Summary

Built connection-service from a /health-only stub into the real social-graph data layer: a raw-PDO `ConnectionController` (X-User-Id scoped, uniform-404 IDOR-safe) plus the internal route set — crucially the locked D-05 `GET /connections/status?viewer=&target=` returning the exact `{"data":{"status":"none|pending_outgoing|pending_incoming|connected|self"}}` shape the gateway already consumes.

## What Was Built

**Task 1 — `ConnectionController` status + mutations (commit `8095e44`):**
- `statusBetween(viewer, target)`: the ONE viewer-relative status computation. Single bidirectional OR query with `LIMIT 1`, provably deterministic thanks to Plan 01's `uq_pair` (≤1 row per unordered pair). Returns `self` | `none` | `connected` | `pending_outgoing` | `pending_incoming`.
- `status()`: D-05 route — `Json::ok($res, ['status' => …])` → `{"data":{"status":…}}` (exact key `AggregateController::profileFull` reads; any other key silently leaves /full at `none`).
- `create()` (CONN-01): requester from X-User-Id (NEVER body), addressee from body. Validates requester>0, addressee>0, requester≠addressee → 400. `PDOException 23000 → 409 REQUEST_EXISTS`, backstopping BOTH the same-direction duplicate (uq_conn_pair) and the opposite-direction concurrent invite (uq_pair).
- `accept()` (CONN-02): addressee-scoped UPDATE + scoped-SELECT existence proof; non-addressee → uniform 404, no IDOR oracle.
- `deleteRequest()` (CONN-02/03): reject/cancel collapse to one pending-scoped DELETE where caller is requester OR addressee; rowCount===0 → 404.
- `removeEdge()` (CONN-03): remove accepted edge, either side; scoped DELETE; rowCount===0 → 404.

**Task 2 — lists/suggestions + routes (commit `c8112c8`):**
- `listAccepted()` (CONN-03): accepted edges touching `?user=`, returns the OTHER party's `user_id` + `status` (ids only).
- `listPending()` (CONN-04): `?user=&direction=incoming|outgoing` — incoming filters `addressee_id` (returns requester), outgoing filters `requester_id` (returns addressee); includes `request_id` + `direction`. Default incoming.
- `suggestions()` (CONN-06): `?user=&candidates=&limit=` — exclusion set (self + all edged users) computed locally; candidate universe supplied by the gateway. Empty candidates → empty list. Cap default 10. Returns `{user_id}` only.
- `routes.php`: literal `/connections/{status,pending,suggestions}` registered BEFORE bare GET `/connections` (FastRoute literal-first); `{id:[0-9]+}` / `{userId:[0-9]+}` constraints; `/health` kept. `public/index.php` needs no change (PSR-4 autoload, manual wiring).

## Verification

- `php -l` clean on every `.php` in `services/connection-service/src/` (full sweep).
- grep gates: status shape `Json::ok($res, ['status'`, accept scope `addressee_id=:caller AND status='pending'`, `'23000'` backstop, bidirectional `requester_id = :v1 AND addressee_id = :t1`, `candidates` param, route order, `/health` retained.
- No JWT / firebase / JWT_SECRET references anywhere in connection-service (X-User-Id trust only).
- IDOR audit: requester is sourced ONLY from X-User-Id; the only `$row['requester_id']` read is a DB result inside `statusBetween` (not body) — no body-sourced owner.
- No profile/email/display_name/avatar/username fields in any response (T-03-09 — gateway enriches).
- **Runtime checks (endpoint behavior, status values, 409 races, seed graph) deferred to VPS/CI** — Docker is not installed locally; smoke-phase3.sh on the deployed stack (Plan 05) covers runtime.

## Deviations from Plan

None — plan executed exactly as written. Both tasks implemented per spec; the only divergence from the RESEARCH snippet was the 409 message ("Đã tồn tại lời mời giữa hai người." per the plan's `create` spec, not the snippet's "theo hướng này") — this follows the PLAN.md `<action>` text, which intentionally reflects that the single catch now covers both races.

## Known Stubs

None. All methods are fully wired to the `connections` table; no placeholder/empty-data returns. `suggestions` returns an empty list only when the gateway supplies no candidates, which is the intended contract (connection-service cannot enumerate all users — Pitfall 6).

## Self-Check: PASSED

- FOUND: services/connection-service/src/Controllers/ConnectionController.php
- FOUND: services/connection-service/src/routes.php
- FOUND commit: 8095e44 (Task 1)
- FOUND commit: c8112c8 (Task 2)
