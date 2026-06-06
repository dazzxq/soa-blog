---
status: passed
phase: 2-h-s-ngh-nghi-p
verified: 2026-06-07
method: live-production (VPS deploy + smoke-phase2.sh ALL PASS)
deploy_run: github actions 27071277178 (success)
---

# Phase 2 — Verification (live production)

Verified against the deployed stack on soa.duyet.vn after CI/CD run `27071277178` succeeded. Validation per 02-VALIDATION.md (smoke-test based).

## Success Criteria (ROADMAP) — all PASS
| # | Criterion | Evidence | Status |
|---|-----------|----------|--------|
| 1 | Edit profile: avatar/cover/headline/location/about | `PATCH /api/profiles/me` → 200, reflected in /full (smoke PROF-02 PASS) | ✅ |
| 2 | Add/edit/delete experience+education, add/delete skills; show immediately | CRUD round-trips PASS (POST→/full→DELETE→gone); skills 409 dedupe | ✅ |
| 3 | View another's public profile (cover+avatar+exp/edu/skills) | `GET /api/profiles/2/full` anon → full composed profile, no email | ✅ |
| 4 | (Gateway demonstrated) ONE composition endpoint, multi-source, safe degrade | `/full` = 2-way parallel Utils::settle (profile + connection); `meta.degraded:true` + `connection_status:null/none` (connection stub) | ✅ |

## Requirements — all live: PROF-02, PROF-03, PROF-04, PROF-05, PROF-06, PROF-07, UI-03.

## Security / quality
- IDOR-safe: writes scoped by X-User-Id (`/me`), gateway exposes no numeric-id write route (`PATCH /api/profiles/3` → 405); user 3 unchanged. (smoke PASS)
- No email/password_hash leak on public `/full` OR on `PATCH /me` (allowlist; smoke double-guard PASS).
- Auth-aware: anon `connection_status:null`, token `connection_status:"none"` (smoke PASS).
- JWT boundary intact: OptionalJwtMiddleware only on `/full`; `/me/*` require JWT (401 no-token PASS); profile-service has 0 JWT refs.
- XSS: new profile pages use x-text only (no x-html).
- Migration non-destructive (0 DROP, ADD/CREATE IF NOT EXISTS, INSERT IGNORE) — verified on the live volume with existing data preserved.

## Review gates (both APPROVED)
- codex-plan-review: 5 rounds, 1 issue fixed (smoke demo-data restore hardening).
- codex-impl-review: 2 rounds, 2 issues fixed (skill-seed dup-key; child-PATCH false-404).

## Regression & cross-site
- smoke-phase1.sh: ALL PASS (no Phase 1 regression).
- 8 containers healthy (web healthcheck IPv6 fix → now healthy).
- 19 other VPS vhosts intact; host MariaDB/nginx active; migration scoped to proconnect_profile.
