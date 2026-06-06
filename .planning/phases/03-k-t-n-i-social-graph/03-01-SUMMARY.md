---
phase: 03-k-t-n-i-social-graph
plan: 01
subsystem: database / deploy / smoke
tags: [database, migration, smoke, social-graph]
requires: []
provides:
  - connections-table-live-migration
  - connections-fresh-volume-schema
  - deploy-phase3-migration-step
  - smoke-phase3-harness
affects:
  - db/
  - scripts/deploy.sh
tech-stack:
  added: []
  patterns:
    - "MariaDB STORED generated columns (LEAST/GREATEST) + second UNIQUE for unordered-pair uniqueness"
    - "Idempotent live-volume additive migration (CREATE IF NOT EXISTS + guarded WHERE NOT EXISTS seed)"
    - "Blocking deploy migration step (no '|| true') so failure aborts before full-topology up"
    - "Non-destructive smoke harness with trap-based cleanup of self-created edges"
key-files:
  created:
    - db/03-migrate-phase3.sql
    - db/01-schema-connection.sql
    - scripts/smoke-phase3.sh
  modified:
    - scripts/deploy.sh
decisions:
  - "Plan-revised DDL supersedes 03-RESEARCH base example: added STORED pair_lo/pair_hi + uq_pair to backstop the opposite-direction invite race (T-03-04b)"
  - "Kept BOTH uq_conn_pair (directional, for pending_outgoing vs pending_incoming) and uq_pair (unordered, for the race)"
  - "ProfileClient::allUsers NOT added here — plan 03-01 has only 3 tasks; allUsers belongs to a later plan (03-03)"
metrics:
  duration: 3 min
  completed: 2026-06-06
  tasks: 3
  files: 4
---

# Phase 3 Plan 01: Social-Graph Migration + Smoke Harness Summary

Idempotent non-destructive live migration that creates the `connections` table (directional requester/addressee + STORED generated `pair_lo`/`pair_hi` + `uq_pair` unordered-pair UNIQUE) and seeds the demo graph in `proconnect_connection`, a matching fresh-volume schema file, a BLOCKING phase-3 migration step wired into `deploy.sh` after step 7b, and a non-destructive `smoke-phase3.sh` harness covering CONN-01..07 + the `/full` connection-status payoff + PII-leak + ownership.

## What Was Built

- **db/03-migrate-phase3.sql** — Live-volume additive migration. `CREATE TABLE IF NOT EXISTS connections` with directional `uq_conn_pair (requester_id, addressee_id)` AND a STORED-generated-column `uq_pair (pair_lo, pair_hi)` that makes the unordered pair unique, so an opposite-direction concurrent invite collides on 23000 and the gateway returns 409 (deterministic `statusBetween`). Guarded `WHERE NOT EXISTS` demo seed: duyet(2)→long(3) accepted (id 1), demo(1)→duyet(2) pending (id 2). No DROP/ALTER, no substitution placeholders, idempotent.
- **db/01-schema-connection.sql** — Fresh-volume schema with the byte-identical `connections` DDL (verified via diff), structure-only (0 INSERTs). Sorts after `00-init.sh`, so a from-scratch `docker compose up` converges to the same schema as a migrated live volume.
- **scripts/deploy.sh** — New step 7c applies `db/03-migrate-phase3.sql` into `proconnect_connection` immediately after step 7b (phase-2 migration) and before step 8 full-topology up. No `|| true`, so a migration failure surfaces and blocks the deploy before connection-service boots against a missing table (T-03-02).
- **scripts/smoke-phase3.sh** — Executable, `bash -n`-clean harness cloned from `smoke-phase2.sh` (`set -euo pipefail`, `GW`/`PW`/`FAILURES`, `[smoke3]` pass/fail, login helper, `ALL PASS`/`N FAILURES` verdict). Implements all 15 assertions: self-invite 400, missing-user 404 PROFILE_NOT_FOUND, already-connected 409, send + outgoing list, duplicate 409 REQUEST_EXISTS, incoming/outgoing seed lists, accept→connected, reject→gone, enriched `/connections` with display_name, suggestions excluding self+edged, status connected/none, the D-05 `/full` `connection_status:"connected"` payoff, ownership (only addressee may accept), and a combined PII (`@`/`email`) sweep. Non-destructive: `trap restore EXIT` cancels only edges the script creates and never touches the demo-seed fixtures.

## How to Verify

Runtime verification (Docker stack) is **deferred to VPS/CI in Plan 05** — Docker is not installed locally (03-RESEARCH §Environment Availability). Static verification performed here:

- `grep` assertions on all three SQL/script artifacts (USE DB, CREATE IF NOT EXISTS, `uq_conn_pair`, `uq_pair`, STORED LEAST/GREATEST, demo seed, 0 DROP/ALTER/envsubst) — all pass.
- DDL byte-identical between `db/03-migrate-phase3.sql` and `db/01-schema-connection.sql` (diff confirmed).
- `db/01-schema-connection.sql` has 0 INSERTs (structure-only); `00-init.sh` sorts before it.
- `scripts/deploy.sh`: 7c present, uses `proconnect_connection`, no `|| true`, positioned between the 7b echo and `docker compose up -d --remove-orphans`; `bash -n` clean.
- `scripts/smoke-phase3.sh`: `bash -n` clean, executable, contains `connection_status...connected` and `@`/`email` guards, registers `trap restore EXIT`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Reworded migration comment to satisfy the literal verify grep**
- **Found during:** Task 1
- **Issue:** The plan's automated verify includes `! grep -qiE "...envsubst|\${"`. My initial header comment used the explanatory phrase "NO envsubst dollar-brace placeholders" (mirroring 02-migrate-phase2.sql), which the literal grep flagged even though no actual substitution is used.
- **Fix:** Reworded to "NO dollar-brace substitution placeholders" / "no preprocessing step" — same meaning, no flagged token. The file uses zero `${}` and zero envsubst.
- **Files modified:** db/03-migrate-phase3.sql
- **Commit:** 1580bc9

**2. [Rule 1 - Bug] Reverted premature CONN-01..07 requirement completion**
- **Found during:** state/requirements update
- **Issue:** Plan 03-01's frontmatter lists `requirements: [CONN-01..07]`, so the standard `requirements mark-complete` step checked them off. But these are end-to-end behaviors fulfilled by the gateway routes shipped in plans 03-02/03-03 (whose frontmatter ALSO claims the same CONN ids) — this infra-only plan ships no endpoints. Marking them Complete now overstates progress and would mislead the verifier.
- **Fix:** Reverted REQUIREMENTS.md CONN-01..07 back to `[ ]` / `Pending`; they will be completed by their owning behavior plans.
- **Files modified:** .planning/REQUIREMENTS.md
- **Commit:** (final docs commit)

### Intentional scope note (not a deviation)

`ProfileClient::allUsers(int $limit=100)` was NOT added. Plan 03-01 defines exactly 3 tasks (migration SQL, fresh schema, deploy.sh + smoke), none of which assign `allUsers`. Per the execution objective ("only do it if 03-01 says so; otherwise skip"), it is correctly deferred to its owning plan (suggestions/candidate-fetch, plan 03-03).

## Known Stubs

None. The smoke harness asserts against routes that go live in Plan 03; this is documented inline in the harness header (Wave 0 authors it, Plan 05 runs it on the deployed stack) and is not a code stub.

## Self-Check: PASSED

- FOUND: db/03-migrate-phase3.sql
- FOUND: db/01-schema-connection.sql
- FOUND: scripts/smoke-phase3.sh
- FOUND (modified): scripts/deploy.sh
- FOUND commit: 1580bc9 (Task 1 migration)
- FOUND commit: 49c7c96 (Task 2 fresh schema)
- FOUND commit: 4854f0d (Task 3 deploy.sh + smoke)
