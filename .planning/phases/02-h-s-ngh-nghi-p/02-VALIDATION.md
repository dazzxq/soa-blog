---
phase: 2
slug: h-s-ngh-nghi-p
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-06
---

# Phase 2 — Validation Strategy

> Smoke-test based (bash/curl), no PHPUnit — consistent with Phase 1. Source: 02-RESEARCH.md §Validation Architecture.
> Runtime validation runs on the VPS-deployed stack (Docker unavailable locally); local gate = `php -l` + static greps.

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | bash + curl smoke test (no PHP test runner) |
| **Config file** | none — `scripts/smoke-phase1.sh` is the template |
| **Quick run** | `bash scripts/smoke-phase2.sh` (against running stack) |
| **Full suite** | `bash scripts/smoke-phase1.sh && bash scripts/smoke-phase2.sh` (Phase 2 must not regress Phase 1) |
| **Static gate (local)** | `php -l` over changed `*.php` (mirrors CI lint) |
| **Estimated runtime** | ~30–60s on the deployed stack |

## Sampling Rate
- **Per task commit:** `php -l` on changed files + (phase gate) `/codex-impl-review` before commit (CLAUDE.md).
- **Per wave merge:** `bash scripts/smoke-phase1.sh && bash scripts/smoke-phase2.sh` green on deployed stack.
- **Before `/gsd-verify-work`:** full smoke green.
- **Max latency:** ~60s.

## Per-Task Verification Map

| Req | Behavior | Test Type | Automated Assertion | File | Status |
|-----|----------|-----------|---------------------|------|--------|
| PROF-07 | `/api/profiles/{id}/full` composes sections + degrades | smoke | body has `experience`,`education`,`skills`,`connection_status` AND `meta.degraded:true` (connection stub) | ❌ W0 smoke-phase2.sh | ⬜ |
| PROF-06 | public view w/o token | smoke | no-auth `GET .../full` → 200, `connection_status:null` | ❌ W0 | ⬜ |
| PROF-04 | auth-aware status | smoke | with token → `connection_status:"none"` (not null) | ❌ W0 | ⬜ |
| PROF-02 | edit basic | smoke | login → `PATCH /api/profiles/me` → 200 → reflected in `/full` | ❌ W0 | ⬜ |
| PROF-03 | experience CRUD round-trip | smoke | `POST .../me/experience` → id → in `/full` → `DELETE .../{id}` → gone | ❌ W0 | ⬜ |
| PROF-04 | education CRUD round-trip | smoke | same as PROF-03 for education | ❌ W0 | ⬜ |
| PROF-05 | skills add/remove + dedupe | smoke | `POST .../me/skills` → in `/full`; dup → 409; `DELETE` → gone | ❌ W0 | ⬜ |
| PROF-02..05 | owner-scoping (no IDOR) | smoke | only `/me` mutates at gateway; other user's `/full` unchanged | ❌ W0 | ⬜ |
| UI-03 | profile view + edit pages functional | manual | open profile.html / profile-edit.html on VPS, eyeball | manual | ⬜ |
| Security | no email leak on `/full` | smoke | `/full` body MUST NOT contain `email`/`@` | ❌ W0 | ⬜ |
| Regression | Phase 1 still green | smoke | `bash scripts/smoke-phase1.sh` passes | ✅ exists | ⬜ |

## Wave 0 Requirements
- [ ] `scripts/smoke-phase2.sh` — covers PROF-02..07 + email-leak + owner-scope + degrade (clone smoke-phase1.sh structure: pass/fail/FAILURES + GW env).
- [ ] `db/02-migrate-phase2.sql` (idempotent: ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS) applied via deploy.sh BEFORE any `/full` smoke runs (Pitfall 1).
- [ ] No framework install needed.

## Manual-Only Verifications
| Behavior | Req | Why Manual | Instructions |
|----------|-----|------------|--------------|
| Profile view/edit pages render & work | UI-03 | Browser UX | Open https://soa.duyet.vn profile pages, edit own profile, confirm changes persist |
| Live HTTPS additive deploy | PROF-* | Live VPS | After deploy, smoke-phase2.sh green on VPS + Phase 1 smoke still green |
| Live-volume migration applied | PROF-02..05 | DB on live volume | Confirm new columns/tables exist in proconnect_profile after deploy |

## Validation Sign-Off
- [ ] All tasks have automated smoke verify or Wave 0 dependency
- [ ] No 3 consecutive tasks without automated verify
- [ ] Wave 0 (smoke-phase2.sh + migrate-phase2.sql) covers MISSING refs
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set after planner wires task IDs

**Approval:** pending
