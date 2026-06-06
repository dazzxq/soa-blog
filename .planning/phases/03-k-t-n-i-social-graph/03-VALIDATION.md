---
phase: 3
slug: k-t-n-i-social-graph
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-07
---

# Phase 3 — Validation Strategy

> Smoke-test based (bash/curl), no PHPUnit — consistent with Phases 1-2. Source: 03-RESEARCH.md §Validation Architecture. Runtime validation on the VPS; local gate = `php -l` + greps.

## Test Infrastructure
| Property | Value |
|----------|-------|
| Framework | bash + curl smoke (`scripts/smoke-phase3.sh`, clone of smoke-phase2.sh) |
| Quick run | `bash scripts/smoke-phase3.sh` (against running stack) |
| Full suite | `bash scripts/smoke-phase1.sh && bash scripts/smoke-phase2.sh && bash scripts/smoke-phase3.sh` (no regressions) |
| Static gate | `php -l` over changed `*.php` |

## Per-Requirement Verification Map
| Req | Behavior | Assertion | Status |
|-----|----------|-----------|--------|
| CONN-07 (invariant) | invite missing user → 404 | `POST /api/connections/requests {target_id:999999}` → 404 PROFILE_NOT_FOUND | ⬜ W0 |
| CONN-07 | self-invite → 400 | `POST .../requests {target_id:<self>}` → 400 | ⬜ W0 |
| CONN-07 | duplicate/existing → 409 | invite twice / already-connected → 409 | ⬜ W0 |
| CONN-01 | send invite | `POST .../requests` → 200/201; in outgoing | ⬜ W0 |
| CONN-02 | accept → both connected | addressee accepts → status connected both sides | ⬜ W0 |
| CONN-02 | reject/cancel → gone | reject(addressee)/cancel(requester) → not in pending | ⬜ W0 |
| CONN-04 | incoming/outgoing lists | demo→X: X incoming has demo, demo outgoing has X | ⬜ W0 |
| CONN-03 | connections list enriched | `GET /api/connections` has display_name; NO email/@ | ⬜ W0 |
| CONN-06 | suggestions exclude connected/self | `GET /api/connections/suggestions` excludes connected + self | ⬜ W0 |
| CONN-05 | status values | `/connections/status/{id}` → none/pending_outgoing/pending_incoming/connected as appropriate | ⬜ W0 |
| **PAYOFF (D-05)** | `/full` real connection_status | as duyet token, `GET /api/profiles/<connected>/full` → `"connection_status":"connected"` (not none/null), connection part NOT degraded | ⬜ W0 |
| Ownership | non-addressee cannot accept | other user accepts someone's invite → 404/403 | ⬜ W0 |
| Security | no email leak in cards | connection/suggestion/list bodies contain no email/@ | ⬜ W0 |
| Regression | Phases 1+2 green | smoke-phase1.sh && smoke-phase2.sh pass | ⬜ |

## Wave 0 Requirements
- [ ] `scripts/smoke-phase3.sh` (clone smoke-phase2 structure; pass/fail/FAILURES; NON-DESTRUCTIVE — clean up edges it creates, do not delete the demo-seed fixtures duyet↔long / demo→duyet, or recreate them).
- [ ] `db/03-migrate-phase3.sql` idempotent (`CREATE TABLE IF NOT EXISTS connections` + guarded demo seed) wired BLOCKING into deploy.sh before runtime verify.

## Manual-Only
| Behavior | Why | Steps |
|----------|-----|-------|
| connections.html + profile status badge UX | browser | open on VPS: send/accept/reject/cancel + suggestions render; profile shows correct badge |

## Sign-Off
- [ ] All reqs have smoke assertion or Wave 0 dep · no 3 consecutive unverified · latency <60s · `nyquist_compliant: true` after task-id wiring

**Approval:** pending
