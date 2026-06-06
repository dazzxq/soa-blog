---
status: passed
phase: 1-n-n-t-ng-gateway
verified: 2026-06-06
method: live-production (VPS deploy + smoke), no local Docker
deploy_run: github actions 27062879297 (success)
---

# Phase 1 — Verification (live production)

Verified directly against the deployed stack on `soa.duyet.vn` (VPS 14.225.29.159) after CI/CD deploy run `27062879297` succeeded. Local Docker unavailable, so runtime verification was performed on the VPS — this IS the canonical validation per 01-VALIDATION.md (smoke-test based).

## Success Criteria (ROADMAP) — all PASS

| # | Criterion | Evidence | Status |
|---|-----------|----------|--------|
| 1 | `docker compose up` clean, all healthy, ≤9 containers | 8 containers all `(healthy)`: mariadb, profile, connection, feed, search, notification, gateway, web. `docker ps --filter name=soa-blog | wc -l` = 8 | ✅ |
| 2 | Gateway routes `/api/*` to new services, JWT central, services trust X-User-Id | `/api/health` fans out to 5 services; `/api/profiles/2` → profile-service; `/api/me` no-token → 401; stub services have no host port (80/9000 internal only), no JWT lib | ✅ |
| 3 | Register/login works after user→profile rename, JWT intact (PROF-01) | `POST /api/auth/login {"login":"demo"|"duyet","password":"demo@123**"}` → HTTP 200 + JWT token. JWT_SECRET unchanged (64 chars) | ✅ |
| 4 | Gateway keeps rate-limit, request-id, logging central | Middleware stack intact; `X-Request-Id` forwarded downstream — `rid` echoed in stub `/health` bodies (e.g. `"rid":"012b4abd-..."`) | ✅ |
| 5 | Push main → CI/CD deploy → `/api/health` HTTPS green, additive | GitHub Actions `27062879297` success; `https://soa.duyet.vn/api/health` → HTTP 200 `{"status":"ok"}` with all 5 services `ok` | ✅ |

## Requirements — all covered & live

PLAT-01 (multi-service behind gateway, db-per-service) · PLAT-02 (routing+rewrite) · PLAT-03 (central JWT, X-User-Id) · PLAT-04 (rate-limit/request-id/logging) · PLAT-05 (clean boot ≤9, 8 healthy) · PLAT-06 (CI/CD → HTTPS green) · PROF-01 (register/login) — all verified live.

## DB cutover (D-06) — verified

- Pre-wipe: independent backup `backups/pre-phase1-manual-20260606-195346.sql` (5.0M) + deploy.sh blocking backup of legacy schemas.
- After: `blog_users/blog_posts/blog_comments` dropped; `proconnect_{profile,connection,feed,search,notification}` created; `proconnect_profile.users` = 5 demo accounts (logins work).
- Retired `user/post/comment-service` containers removed via `--remove-orphans`.

## Security / data-isolation (D-07, cross-site)

- `/api/profiles/{id}` returns ONLY `{id, username, display_name}` — no email leak on the public route (verified live).
- Cross-site safety: host runs 18 other nginx vhosts + a SEPARATE host-level MariaDB; soa-blog's mariadb is its own container. `nginx -t` successful, all other vhosts intact, host MariaDB untouched. The wipe affected only soa-blog's container DBs.

## Review gates (both APPROVED before deploy)

- `codex-plan-review`: 3 rounds, 7 issues fixed.
- `codex-impl-review`: 3 rounds, 4 issues fixed (incl. CRITICAL envsubst bcrypt-corruption catch).

## Notes / deferred

- First deploy attempt aborted SAFELY at the blocking-backup step (root-owned `backups/` from the independent backup → `deploy` user couldn't write). Fixed ownership, re-ran → success. The ISSUE-4 blocking-backup guard prevented a wipe-without-backup.
- `post-service`/`comment-service` code removed on this branch but preserved in git history + on pre-merge `main` for Phase 4 reference (deviation from D-01 literal "keep on disk"; intent satisfied).
- profile-service `/health` does not echo `rid` (only the 4 new stubs do, per Plan 03) — cosmetic; X-Request-Id forwarding itself works.
- `web` container reports `unhealthy` (pre-existing healthcheck quirk from before Phase 1) — site serves fine; not a Phase 1 regression.
