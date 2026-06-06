---
phase: 01-n-n-t-ng-gateway
plan: 05
subsystem: database
tags: [mariadb, docker-entrypoint-initdb, envsubst, database-per-service, bcrypt, sql]

# Dependency graph
requires:
  - phase: 01-02
    provides: "user-service renamed to profile-service; establishes proconnect_profile as the profile bounded context"
provides:
  - "Fresh-volume provisioning of 5 proconnect_* schemas (profile/connection/feed/search/notification) + 5 scoped *_svc DB users in db/00-init.sh"
  - "db/01-schema-profile.sql (renamed from 01-schema-users.sql) — profile users table in proconnect_profile"
  - "db/99-seed.sql reseeds 5 demo accounts (demo/duyet/long/diep/tai) into proconnect_profile only"
  - "db/migrate-phase1.sql.tmpl — idempotent live-volume cutover template (blog_* -> proconnect_*) excluded from initdb glob"
  - "Retired blog post/comment schema files removed so a fresh init dir cannot recreate blog_posts/blog_comments"
affects: [01-04, 01-06]

# Tech tracking
tech-stack:
  added: [envsubst-templated SQL migration]
  patterns:
    - "database-per-service: 5 logical schemas + 5 scoped DB users, each granted ONLY its own proconnect_<svc>.* (D-13)"
    - "live-volume cutover via *.sql.tmpl excluded from MariaDB initdb glob; applied through envsubst against the running container (ISSUE-7)"
    - "secrets-as-placeholders: DB-user passwords are ${VAR} placeholders in git, never literals"

key-files:
  created:
    - db/migrate-phase1.sql.tmpl
  modified:
    - db/00-init.sh
    - db/01-schema-profile.sql
    - db/99-seed.sql
  removed:
    - db/02-schema-posts.sql
    - db/03-schema-comments.sql

key-decisions:
  - "01-05: Cutover file named db/migrate-phase1.sql.tmpl (NOT .sql) so MariaDB's initdb glob (*.sql/*.sql.gz/*.sh) never auto-runs it with un-substituted ${VAR} passwords on a fresh volume (ISSUE-7)"
  - "01-05: git rm of retired 02/03 schema files (not just service code) because db/ is the initdb mount — leftover files would recreate blog_posts/blog_comments on a fresh volume (D-01, ISSUE-1)"
  - "01-05: cutover template pairs CREATE USER IF NOT EXISTS with ALTER USER to make password (re)application idempotent on an existing live user"

patterns-established:
  - "Scoped grants only: every GRANT is ON proconnect_<svc>.*, never *.* (T-1-09 mitigation)"
  - "Destructive cutover scoped to explicit blog_* DROP DATABASE statements — never system schemas (T-1-08/T-1-11 mitigation)"

requirements-completed: [PLAT-01, PROF-01]

# Metrics
duration: 2min
completed: 2026-06-06
---

# Phase 1 Plan 05: ProConnect DB Provisioning Rewrite Summary

**Fresh-volume init now provisions 5 proconnect_* schemas + 5 scoped *_svc users (profile gets the users table + 5 reseeded demo accounts, 4 stubs stay empty), retired blog post/comment schema files are removed, and an idempotent envsubst-templated live-volume cutover (db/migrate-phase1.sql.tmpl) is shipped outside the initdb glob.**

## Performance

- **Duration:** 2 min
- **Started:** 2026-06-06T12:01:32Z
- **Completed:** 2026-06-06T12:03:55Z
- **Tasks:** 2
- **Files modified:** 6 (3 modified, 1 created, 2 removed)

## Accomplishments
- Rewrote `db/00-init.sh` from 3-DB/3-user (blog_*) to 5-DB/5-user (proconnect_*) with scoped grants and 5 env guards; `bash -n` clean.
- Renamed `01-schema-users.sql` -> `01-schema-profile.sql` via `git mv` (history preserved), retargeted to `proconnect_profile`, users table byte-for-byte unchanged.
- Removed retired `db/02-schema-posts.sql` + `db/03-schema-comments.sql` via `git rm` (D-01, ISSUE-1) so the active fresh-init SQL set is exactly `00-init.sh -> 01-schema-profile.sql -> 99-seed.sql`.
- Rewrote `db/99-seed.sql` to seed only the 5 demo accounts into `proconnect_profile` (dropped posts/comments seed blocks), shared bcrypt hash preserved.
- Created idempotent `db/migrate-phase1.sql.tmpl` cutover template: drops only `blog_*`, builds 5 schemas + scoped users with `${VAR}` password placeholders, profile table + 5-account reseed; `.tmpl` extension keeps it out of the initdb `*.sql` glob (ISSUE-7).

## Task Commits

Each task was committed atomically:

1. **Task 1: Rewrite 00-init.sh + rename profile schema + remove retired post/comment schema + rewrite seed** - `9261108` (feat)
2. **Task 2: Create db/migrate-phase1.sql.tmpl live-volume cutover template** - `4ab1206` (feat)

**Plan metadata:** see final docs commit below.

## Files Created/Modified
- `db/00-init.sh` - Rewritten: provisions 5 proconnect_* schemas + 5 scoped *_svc users; 5 *_SVC_DB_PASS env guards; no blog_* names.
- `db/01-schema-profile.sql` - Renamed from 01-schema-users.sql; `USE proconnect_profile`; users table unchanged.
- `db/99-seed.sql` - Reseeds 5 demo accounts into proconnect_profile only; posts/comments seed removed.
- `db/migrate-phase1.sql.tmpl` - NEW idempotent live-volume cutover template (blog_* -> proconnect_*), envsubst-applied, excluded from initdb glob.
- `db/02-schema-posts.sql` - REMOVED (git rm, D-01/ISSUE-1).
- `db/03-schema-comments.sql` - REMOVED (git rm, D-01/ISSUE-1).

## Decisions Made
- `.sql.tmpl` extension chosen for the cutover so it is semantically a template AND structurally excluded from MariaDB's initdb glob — the single mechanism that prevents literal `${VAR}` passwords on fresh volumes (ISSUE-7).
- Retired schema files removed at the file level (git rm), not just disabled, because the whole `db/` directory is the initdb mount.
- Paired `CREATE USER IF NOT EXISTS` + `ALTER USER` in the template for idempotent password (re)application on an already-existing live DB user.

## Deviations from Plan

None - plan executed exactly as written. No bugs, missing functionality, or blocking issues encountered; no architectural decisions required.

## Issues Encountered
- A verification `grep -q '${PROFILE_SVC_DB_PASS}'` initially reported a false negative due to shell `${...}` quoting interpretation. Re-checked with `grep -c 'PROFILE_SVC_DB_PASS'` (4 matches) and `grep -F '${PROFILE_SVC_DB_PASS}'` (exact literal found) — the placeholder is genuinely present. Tooling artifact, not a file defect.

## Verification

Static verification only — **Docker is not installed locally and the plan creates/edits SQL files only (no live DB)**:
- `bash -n db/00-init.sh` — clean.
- File existence/removal: `01-schema-profile.sql` present; `01-schema-users.sql`, `02-schema-posts.sql`, `03-schema-comments.sql` absent.
- `db/00-init.sh`: 5 `CREATE DATABASE`, 5 `CREATE USER`, 5 scoped `GRANT`, 5 env guards; no blog_*/old-svc names.
- `db/99-seed.sql`: `USE proconnect_profile`, 5 demo usernames, shared bcrypt hash, no blog_posts/blog_comments.
- `db/migrate-phase1.sql.tmpl`: 3 scoped `DROP DATABASE` (blog_* only, no system schemas), 5 schemas, 5 scoped grants, `${VAR}` placeholders, profile table + reseed, no post/comment/stub table DDL.
- Cross-file invariant: `! grep -lq '${' db/*.sql` holds — the init glob is exactly `01-schema-profile.sql` + `99-seed.sql`; the `.tmpl` (with placeholders) is excluded.

**Deferred to VPS/CI (Docker not available locally):**
- Fresh `docker compose up -d --wait` + `scripts/smoke-phase1.sh` (per-service db:ok + 5-account login) — runs after Plan 04 supplies the 5 *_SVC_DB_PASS env vars.
- Live-volume cutover application (envsubst + mysqldump backup) — executed during Plan 06 deploy.

## Threat Surface
All threat-register mitigations for this plan are present and statically verified: scoped grants only (T-1-09), blog_*-only drops (T-1-08/T-1-11), `${VAR}` placeholders not literals (T-1-10), retired schema files removed (T-1-11b), `.tmpl` excluded from initdb glob (T-1-11c). No new threat surface introduced beyond the plan's threat model.

## User Setup Required
None in this plan. Plan 04 adds the 5 `*_SVC_DB_PASS` env vars to the mariadb compose block + `.env.example`; Plan 06 flags the live `.env` password prerequisite + mandatory mysqldump backup before applying the cutover.

## Next Phase Readiness
- Fresh-init DB layer ready for Plan 04 (compose env wiring) and the smoke gate.
- Live cutover template ready for Plan 06 deploy (autonomous:false — manual backup + env prerequisite).
- Blocker/dependency: the 5 `*_SVC_DB_PASS` env vars do not yet exist in compose/.env.example — Plan 04 must add them before a fresh `docker compose up` will pass 00-init.sh's env guards.

## Self-Check: PASSED

- All created/modified files exist: `db/00-init.sh`, `db/01-schema-profile.sql`, `db/99-seed.sql`, `db/migrate-phase1.sql.tmpl`, `01-05-SUMMARY.md`.
- All removed files absent: `db/02-schema-posts.sql`, `db/03-schema-comments.sql`, `db/01-schema-users.sql`.
- All task commits exist: `9261108` (Task 1), `4ab1206` (Task 2).

---
*Phase: 01-n-n-t-ng-gateway*
*Completed: 2026-06-06*
