---
phase: 5
slug: t-m-ki-m-th-ng-b-o
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-07
---
# Phase 5 — Validation Strategy
> Smoke-based (bash/curl), no PHPUnit. Runtime on VPS; local gate = php -l + greps. NON-DESTRUCTIVE smoke w/ pre-clean (Phase 3/4 lesson).

## Test Infrastructure
| Property | Value |
|----------|-------|
| Framework | bash+curl `scripts/smoke-phase5.sh` (clone smoke-phase4) |
| Full suite | `bash scripts/smoke-phase{1,2,3,4,5}.sh` |
| Static gate | `php -l` over changed `*.php` |

## Per-Requirement Verification Map (smoke-phase5.sh)
| Req | Behavior | Assertion | Status |
|-----|----------|-----------|--------|
| SEARCH (reindex) | reindex populates index | `POST /api/search/reindex` → ok; index has demo users | ⬜ W0 |
| SEARCH-01 | search by name | `GET /api/search?q=duyet` → result with id 2, display_name; NO email/@ | ⬜ W0 |
| SEARCH-01 | search by skill/headline | `GET /api/search?q=PHP` (or a seeded skill) → returns a user | ⬜ W0 |
| SEARCH-02 | result has relationship status (quick-connect) | search hit has `connection_status` (viewer-relative) | ⬜ W0 |
| NOTIF-01 | notify on invite | after demo→X invite, X's `GET /api/notifications` has type invite (unread) | ⬜ W0 |
| NOTIF-01 | notify on reaction/comment | after react/comment on duyet's post (by other), duyet notifications gain reaction/comment | ⬜ W0 |
| NOTIF-02 | unread_count | `GET /api/notifications` returns unread_count > 0; actor enriched, NO email | ⬜ W0 |
| NOTIF-03 | mark read | `POST /api/notifications/{id}/read` (or read-all) → unread_count decreases | ⬜ W0 |
| Best-effort | main action survives notify failure | invite/reaction/comment still 2xx even if notify path errors (indirect) | ⬜ W0 |
| Security | no email leak in search/notif cards | bodies have no email/@ | ⬜ W0 |
| Regression | Phases 1-4 green | smoke-phase{1,2,3,4}.sh pass | ⬜ |

## Wave 0
- [ ] `scripts/smoke-phase5.sh` (clone smoke-phase4; NON-DESTRUCTIVE pre-clean of test notifications/invites; keep/recreate seed).
- [ ] `db/05-migrate-phase5.sql` idempotent (CREATE search_index + notifications IF NOT EXISTS, multi-USE, + seed) wired BLOCKING into deploy.sh.

## Manual-Only
| Behavior | Why | Steps |
|----------|-----|-------|
| search box + notification bell polling/mark-read UX | browser | open on VPS; search, see bell badge update, mark read |

## Sign-Off
- [ ] All reqs smoke-asserted or W0 dep · latency <60s · nyquist_compliant true after task-id wiring

**Approval:** pending
