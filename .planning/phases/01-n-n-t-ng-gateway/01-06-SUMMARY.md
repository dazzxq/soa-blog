---
phase: 01-n-n-t-ng-gateway
plan: 06
subsystem: infra
tags: [docker-compose, deploy, ci-cd, github-actions, mariadb, mysqldump, envsubst, bash]

# Dependency graph
requires:
  - phase: 01-04
    provides: 8-container docker-compose topology + 5 *_SERVICE_URL + mariadb healthcheck
  - phase: 01-05
    provides: db/migrate-phase1.sql.tmpl (idempotent placeholdered cutover) + 5 *_SVC_DB_PASS in .env.example
provides:
  - "scripts/deploy.sh reordered for Phase-1 DB cutover: env preflight -> mariadb-first boot -> BLOCKING pre-wipe backup -> ordered idempotent migration -> full-topology up -> gateway health wait"
  - ".github/workflows/deploy.yml: git pull --ff-only before deploy.sh (first-cutover bootstrap) + all-5-services-ok public health gate"
  - ".env.example: documented shell-safe [A-Za-z0-9]{32} charset + urandom generator for the 5 *_SVC_DB_PASS"
affects: [phase-02-profile, deploy, operations]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "DB-first deploy ordering: bring up only mariadb + wait healthy, migrate, THEN full stack (avoids DB-dependent services booting against non-existent schemas/users)"
    - "Blocking pre-wipe mysqldump as a hard precondition for a destructive migration (no || true; abort on non-zero exit AND empty file)"
    - "Workflow owns the git pull so the NEW deploy.sh runs on the first cutover (script no longer self-pulls)"
    - "envsubst-substituted *.sql.tmpl applied to the running mariadb container via docker compose exec -T"

key-files:
  created: []
  modified:
    - scripts/deploy.sh
    - .github/workflows/deploy.yml
    - .env.example
    - .gitignore

key-decisions:
  - "deploy.sh no longer runs git pull — the CI workflow fast-forwards the VPS repo before invoking it, so the rewritten script runs on the FIRST cutover (ISSUE-2)"
  - "Pre-wipe mysqldump is BLOCKING: dump failure or an empty backup file aborts before the destructive migration (ISSUE-4)"
  - "Deploy order is mariadb-only-up+healthy -> backup -> migrate -> full up --remove-orphans -> gateway health (ISSUE-3)"
  - "Documented the [A-Za-z0-9]{32} charset + generator in .env.example so the human VPS .env edit produces shell/Compose-safe secrets (ISSUE-6)"

patterns-established:
  - "Pattern: env preflight loop hard-fails (exit 3) on any missing required secret before any docker action"
  - "Pattern: reflog HEAD@{1} diff for web-change detection now that the workflow (not the script) owns the pull"

requirements-completed: []  # PLAT-06 NOT yet complete — pending the human-action production cutover (Task 3)

# Metrics
duration: 3min
completed: 2026-06-06
---

# Phase 1 Plan 06: CI/CD Phase-1 DB Cutover Wiring Summary

**Reordered scripts/deploy.sh into a preflight -> mariadb-first -> BLOCKING backup -> idempotent migrate -> full-up cutover, and moved the git pull into the workflow so the new deploy logic runs on the first cutover; live production cutover (Task 3) is a pending human-action checkpoint.**

## Performance

- **Duration:** ~3 min
- **Started:** 2026-06-06T12:17:55Z
- **Completed (file tasks):** 2026-06-06T12:20:25Z
- **Tasks:** 2 of 3 complete (Task 3 = human-action checkpoint, NOT executed)
- **Files modified:** 4

## Accomplishments
- `scripts/deploy.sh` fully reordered for the Phase-1 cutover: `.env` abort -> env preflight (exit 3 on missing secret) -> reflog-based web-change detection -> build -> mariadb-only up + 90s health gate -> BLOCKING pre-wipe `mysqldump` (abort on failure or empty file) -> `envsubst` migration of `db/migrate-phase1.sql.tmpl` -> `docker compose up -d --remove-orphans` -> gateway health wait -> host nginx sync. Internal `git pull` removed.
- `.github/workflows/deploy.yml` now runs `git pull --ff-only && ... ./scripts/deploy.sh` over SSH (first-cutover bootstrap, ISSUE-2) and the public health verify now requires `"status":"ok"` (all 5 services), not just any HTTP 200.
- `.env.example` documents the ISSUE-6 shell-safe + Compose-safe charset `[A-Za-z0-9]{32}` and the `tr -dc 'A-Za-z0-9' </dev/urandom | head -c32` generator for the 5 `*_SVC_DB_PASS`.
- `backups/` added to `.gitignore` so pre-wipe dumps are never committed.

## Task Commits

1. **Task 1: Reorder deploy.sh (preflight + mariadb-first + blocking backup + ordered migrate + full up)** - `de2807e` (feat)
2. **Task 2: Workflow git pull --ff-only before deploy.sh + all-5-ok health gate** - `8164dbc` (feat)
3. **(Deviation Rule 2) Document [A-Za-z0-9]{32} charset + generator in .env.example** - `b2d047b` (docs)
4. **Task 3: Live VPS .env edit + production cutover** - NOT EXECUTED (human-action checkpoint, gate=blocking)

## Files Created/Modified
- `scripts/deploy.sh` - Reordered deploy flow for the Phase-1 DB cutover (preflight, mariadb-first, blocking backup, envsubst migration, full up, health wait); internal git pull removed.
- `.github/workflows/deploy.yml` - `git pull --ff-only` before deploy.sh (cutover bootstrap); public health verify asserts `"status":"ok"`.
- `.env.example` - Documented shell-safe charset + generator for the 5 `*_SVC_DB_PASS`.
- `.gitignore` - Added `backups/` (pre-wipe dumps never committed).

## Static Verification Performed
- `bash -n scripts/deploy.sh` — passes (Docker NOT installed locally; this machine is NOT the VPS, so no docker/compose/ssh/curl-to-prod was run — static checks only).
- `awk` line-order assertion: `up -d mariadb` (61) < `migrate-phase1.sql.tmpl` (108) < `up -d --remove-orphans` (116) — ISSUE-3 order holds.
- grep assertions: references `migrate-phase1.sql.tmpl`, `mysqldump`, `envsubst`, `mkdir -p backups`, `healthcheck.sh --connect --innodb_initialized`, `FATAL: pre-wipe backup failed`, non-empty `! -s "$BACKUP_FILE"` check; NO `mysqldump || true`; NO literal `git pull` anywhere in deploy.sh; `backups` present in `.gitignore`.
- `python3 -c "import yaml; yaml.safe_load(...)"` on the workflow — `YAML_OK`; `git pull --ff-only && PROJECT_DIR` precedes `./scripts/deploy.sh`; `"status":"ok"` asserted; SSH key/StrictHostKeyChecking/id_deploy wiring unchanged.

## Decisions Made
- Reworded deploy.sh comments to avoid the literal string `git pull` so the acceptance assertion `! grep -q 'git pull'` passes cleanly while preserving intent (comments now say "fast-forward pull" / "pre-pull tip"). No behavioral change — there was never an actual `git pull` command in the rewritten script.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Documented the ISSUE-6 charset + generator in .env.example**
- **Found during:** Post-Task-2 success-criteria check (objective + plan require "5 new *_SVC_DB_PASS documented with [A-Za-z0-9]{32} charset")
- **Issue:** `.env.example` listed the 5 `*_SVC_DB_PASS` (from Plan 05) with weak `change-me-*` placeholders and NO note of the shell-safe/Compose-safe constraint. A teammate copying it could pick a value with special chars that breaks `set -a; . ./.env` sourcing and Compose interpolation (T-1-25).
- **Fix:** Added a comment block above the MariaDB vars spelling out the `[A-Za-z0-9]{32}` charset, the forbidden characters, the unquoted-value rule, and the `tr -dc 'A-Za-z0-9' </dev/urandom | head -c32` generator.
- **Files modified:** .env.example
- **Verification:** `grep -q "A-Za-z0-9"` and `grep -q "head -c32"` pass; 5 `*_SVC_DB_PASS` still present.
- **Committed in:** b2d047b

---

**Total deviations:** 1 auto-fixed (1 missing-critical doc). Plus 1 cosmetic comment rewording (decision above).
**Impact on plan:** No scope creep; the doc edit directly satisfies the plan's ISSUE-6 mitigation and a stated success criterion.

## Issues Encountered
- The plan's Task-1 acceptance check `! grep -q 'git pull' scripts/deploy.sh` initially failed on explanatory comments that contained the literal phrase. Resolved by rewording the comments (no actual pull command existed in the rewritten script). Verified clean afterward.

## Checkpoint Reached — Task 3 (human-action) NOT executed

This plan is `autonomous: false`. Task 3 is a `checkpoint:human-action` (gate=blocking) covering the LIVE production cutover: a hand-edit of the git-ignored VPS `.env` to add the 5 shell-safe `*_SVC_DB_PASS`, then push -> CI -> destructive DB wipe + reseed -> HTTPS health verify. This was deliberately NOT performed by the executor (no push, no SSH, no live DB action, no /codex-impl-review). The exact manual steps and post-deploy verification are in the plan's Task 3 `<how-to-verify>` and echoed in the checkpoint return to the orchestrator.

## User Setup Required
**The live production cutover is a human-only step.** Before pushing `main`:
1. SSH to the VPS, add the 5 `*_SVC_DB_PASS` to the git-ignored `.env` using `tr -dc 'A-Za-z0-9' </dev/urandom | head -c32` (one distinct value each, unquoted); remove obsolete `USER/POST/COMMENT_SVC_DB_PASS`; keep `DB_ROOT_PASSWORD` + `JWT_SECRET` unchanged.
2. Verify charset + that `set -a; . ./.env; set +a` sources cleanly.
3. Local smoke (`docker compose up -d --wait && bash scripts/smoke-phase1.sh`) green + `/codex-impl-review` approval (CLAUDE.md), then push.
4. Post-deploy: `https://soa.duyet.vn/api/health` 200 with `"status":"ok"` and all 5 services; login + `/api/profiles/2` work.
Rollback: restore `backups/pre-phase1-<ts>.sql` and redeploy the previous commit.

## Next Phase Readiness
- The deploy machinery is wired and statically verified; PLAT-06 is NOT complete until the human cutover succeeds and public HTTPS health is green with all 5 services.
- Phase 1 cannot be marked done until the cutover is verified live.

## Self-Check: PASSED

All modified files exist on disk; all 3 task/doc commits (`de2807e`, `8164dbc`, `b2d047b`) present in git history. Task 3 (production cutover) intentionally NOT executed — pending human-action checkpoint.

---
*Phase: 01-n-n-t-ng-gateway*
*Completed (file tasks): 2026-06-06 — production cutover pending human action*
