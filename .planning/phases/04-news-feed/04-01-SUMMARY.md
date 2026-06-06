---
phase: 04-news-feed
plan: 01
subsystem: database + deploy + smoke
tags: [database, migration, smoke, feed]
requires: [proconnect_feed schema + feed_svc grant (Phase 1), duyet<->long accepted edge (Phase 3)]
provides:
  - "posts/reactions/comments tables on the live proconnect_feed volume after deploy (wired blocking)"
  - "idempotent demo feed seed with asymmetric fan-trap canary (post 1 = 2 reactions + 1 comment) + 1 repost"
  - "fresh-volume schema convergence (db/01-schema-feed.sql)"
  - "scripts/smoke-phase4.sh — the FEED-01..06 acceptance gate every later wave references"
affects: [scripts/deploy.sh, db/, scripts/]
tech-stack:
  added: []
  patterns:
    - "Idempotent live-volume additive migration (CREATE IF NOT EXISTS + guarded INSERT ... WHERE NOT EXISTS), cloned from db/03-migrate-phase3.sql"
    - "Asymmetric demo seed as a fan-trap canary against double-JOIN count queries"
    - "Non-destructive smoke harness with trap restore EXIT + demo-seed-preserving cleanup"
key-files:
  created:
    - db/04-migrate-phase4.sql
    - db/01-schema-feed.sql
    - scripts/smoke-phase4.sh
  modified:
    - scripts/deploy.sh
decisions:
  - "Migration is plain .sql (no envsubst/secrets) — feed_svc already has GRANT ALL on proconnect_feed from Phase 1"
  - "Repost stored as content='' + repost_of set (D-04); original shown via repost_of; logical FK only"
  - "Seed post 1 carries ASYMMETRIC 2 reactions + 1 comment so the smoke EXACT-count assert can catch a double-JOIN cross-product bug (symmetric 1/1 would mask it)"
  - "deploy.sh phase-4 step 7d has NO `|| true` — a migration failure surfaces and blocks deploy before full-topology up"
metrics:
  duration: 3 min
  tasks: 3
  files: 4
  completed: 2026-06-06
---

# Phase 4 Plan 01: Feed Migration + Smoke Harness Summary

Idempotent non-destructive Phase-4 feed migration (posts/reactions/comments in `proconnect_feed` + asymmetric-canary demo seed), wired BLOCKING into `deploy.sh` after the phase-3 step, plus a fresh-volume schema file and the `smoke-phase4.sh` acceptance harness.

## What Was Built

- **`db/04-migrate-phase4.sql`** — live-volume additive migration. Three `CREATE TABLE IF NOT EXISTS` (posts/reactions/comments, D-01..D-03, with the `uq_reaction_post_user` UNIQUE key + all indexes + the `ENUM('like','love','haha','wow','sad','angry')` reaction type) plus a guarded demo seed: post 1 (duyet), post 2 (long, proves timeline=self+connections), post 3 (demo repost-of-1, FEED-05), 3 reactions (2 on post 1 from long+diep, 1 on post 2), and 1 comment on post 1. Every seed row guarded with `WHERE NOT EXISTS (SELECT 1 FROM ... WHERE id = N)` → re-runs are no-ops. No DROP/ALTER/envsubst.
- **`db/01-schema-feed.sql`** — structure-only fresh-volume convergence; the three CREATE TABLE statements VERBATIM identical to the migration; no seed; sorts after `00-init.sh`.
- **`scripts/deploy.sh`** — new step `7d) PHASE-4 ADDITIVE MIGRATION`, applying the migration against `proconnect_feed` BLOCKING (no `|| true`) between the phase-3 echo and `# 8) FULL-TOPOLOGY UP`, so feed-service never boots against missing tables.
- **`scripts/smoke-phase4.sh`** — non-destructive (executable) FEED-01..06 harness cloned from smoke-phase3.sh: login helper, parallel post/comment cleanup arrays + `trap restore EXIT` registered before any write, demo-seed fixtures never deleted. Asserts create + image post, timeline self+connections + newest-first, reaction set/change(upsert)/remove + `my_reaction`, comment add/list/delete + count, repost origin (`repost_of` + `original`), composition shape, the EXACT asymmetric fan-trap (`reaction_count":2` AND `comment_count":1` on seed post 1), the 999999 404 invariant, ownership (diep cannot delete duyet's post/comment), and a combined `@`/email PII-leak guard.

## The Fan-Trap Canary (Pitfall 3)

Seed post 1 deliberately carries 2 reactions × 1 comment. A correct timeline count query uses correlated subqueries; a broken double-JOIN returns the cross-product (`comment_count = 2 reactions × 1 comment = 2`). The smoke EXACT assert (`==2 AND ==1`, not `>=1`) fails loudly on the broken query. A symmetric 1/1 post would compute `1×1=1` and hide the bug — the asymmetry is what makes the guard real.

## Deviations from Plan

None - plan executed exactly as written. All three per-task automated `grep`/`bash -n` verifies passed.

One in-flight fix during authoring (not a plan deviation): the fan-trap assert initially used backslash-escaped quotes (`\"reaction_count\":2`) which did not satisfy the verifier's literal `reaction_count":2` grep; switched to single-quoted grep patterns so the exact literal is present. Caught and fixed before commit.

## Verification

- All per-task `<automated>` grep checks: PASS.
- `bash -n scripts/smoke-phase4.sh`: clean. `bash -n scripts/deploy.sh`: clean. `test -x scripts/smoke-phase4.sh`: executable.
- 3 reaction guards + 1 comment guard + repost row + ENUM + `uq_reaction_post_user` confirmed; 0 DROP/ALTER/envsubst confirmed.
- **Runtime verification (applying the migration, running smoke assertions incl. the live fan-trap) is DEFERRED to VPS/CI in Plan 05** — Docker is not available locally (04-RESEARCH §Environment Availability).

## Known Stubs

None. The feed gateway routes that smoke-phase4.sh exercises go live in Plan 03; the harness is intentionally authored in Wave 0 ahead of those routes (documented in its header) and is the acceptance gate run against the deployed stack in Plan 05. This is plan-by-design, not a stub.

## Self-Check: PASSED

- All 4 created/modified artifacts exist on disk.
- All 3 task commits (d0ef7c4, 930b6ba, a14e87d) present in git history.
- deploy.sh phase-4 wiring confirmed.
