# Plan 02-05 — Live Cutover Summary

**Status:** complete
**Executed by:** orchestrator (codex-impl-review gate → push → CI/CD deploy → live verify), not a subagent.

## What happened
1. **codex-impl-review** (branch phase-2-profile vs main): APPROVE after 2 rounds — 2 real bugs found & fixed (skill-seed dup-key on re-deploy → INSERT IGNORE; child PATCH false-404 via rowCount → scoped SELECT existence check). Commit `cd870fb`.
2. **Independent backup**: `/root/proconnect-backups/pre-phase2-*.sql` (5MB, --all-databases) — outside the project dir to avoid the Phase-1 backups/-ownership pitfall.
3. **Deploy**: merged phase-2-profile → main (ff), pushed → GitHub Actions run `27071277178` SUCCESS. deploy.sh correctly skipped its blog_* backup (none left), applied the idempotent phase-1 tmpl (no-op) + the new `db/02-migrate-phase2.sql` (ADD COLUMN/CREATE TABLE IF NOT EXISTS + INSERT IGNORE seed), brought up 8 containers, health-verified.
4. **No human-action needed**: Phase 2 added no new secrets/containers/grants (new tables live in proconnect_profile, already owned by profile_svc), so no live `.env` edit — fully CI-driven.

## Live verification (soa.duyet.vn)
- `GET /api/profiles/2/full` → composed full profile (basic + experience + education + skills, Vietnamese seed) with `connection_status:null` (anon) and `meta.degraded:true` (connection-service stub) — the flagship composition + safe degrade, working in production.
- `scripts/smoke-phase2.sh` on VPS: **ALL PASS (28 checks)** — PROF-02..07, auth-aware (null vs "none"), CRUD round-trips, skills 409 dedupe, IDOR (PATCH /api/profiles/3 → 405), 401 no-token, email-leak guards (/full + /me).
- `scripts/smoke-phase1.sh`: **ALL PASS** (no regression).
- 8 containers healthy; **web now healthy** (IPv6 localhost→127.0.0.1 healthcheck fix).
- proconnect_profile tables: users, experience, education, skills.

## Cross-site safety (shared VPS)
- 19 other nginx vhosts intact; `nginx -t` successful; host-level MariaDB + nginx active. Migration was non-destructive (0 DROP) and scoped to proconnect_profile — other sites untouched.

## Commits
Branch phase-2-profile (17 exec commits) merged to main; deploy commit range `212a98f..cd870fb`. Web healthcheck fix `be4e15e` included.
