---
phase: 4
slug: news-feed
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-07
---

# Phase 4 — Validation Strategy

> Smoke-test based (bash/curl), no PHPUnit — consistent with Phases 1-3. Runtime on VPS; local gate = php -l + greps.

## Test Infrastructure
| Property | Value |
|----------|-------|
| Framework | bash + curl (`scripts/smoke-phase4.sh`, clone smoke-phase3) |
| Quick run | `bash scripts/smoke-phase4.sh` |
| Full suite | `bash scripts/smoke-phase{1,2,3,4}.sh` (no regressions) |
| Static gate | `php -l` over changed `*.php` |

## Per-Requirement Verification Map (smoke-phase4.sh)
| Req | Behavior | Assertion | Status |
|-----|----------|-----------|--------|
| FEED-01 | post text(+image url) | `POST /api/posts {content}` → 200/201 + id; appears in `GET /api/feed` | ⬜ W0 |
| FEED-01 | timeline own + connections, newest first | duyet feed contains duyet's post AND long's post (duyet↔long connected); order newest-first | ⬜ W0 |
| FEED-02 | reaction set/change/remove, one per user | `POST /api/posts/{id}/reactions {type:like}`→ok; change to love → still 1 reaction; `DELETE` → my_reaction null | ⬜ W0 |
| FEED-02 | comment add/list/delete | `POST .../comments` → in `GET .../comments`; `DELETE /api/comments/{id}` (owner) → gone | ⬜ W0 |
| FEED-03 | repost shows origin | `POST /api/posts/{id}/repost` → feed item has repost_of + original author/content | ⬜ W0 |
| FEED-06 | feed composition | each `/api/feed` item has author{display_name}, reaction_count, comment_count, my_reaction; NO email/@ | ⬜ W0 |
| FEED-06 | safe degrade | composition returns partial + meta.degraded when a backend part fails (asserted indirectly) | ⬜ W0 |
| Invariant | react/comment/repost to missing post → 404 | `POST /api/posts/999999/reactions` → 404 | ⬜ W0 |
| Ownership | non-owner cannot delete post/comment | other user DELETE → 404/403 | ⬜ W0 |
| Security | no email leak in feed/author cards | feed body has no email/@ | ⬜ W0 |
| Regression | Phases 1-3 green | smoke-phase{1,2,3}.sh pass | ⬜ |

## Wave 0 Requirements
- [ ] `scripts/smoke-phase4.sh` (clone smoke-phase3; NON-DESTRUCTIVE — delete posts/comments/reactions it creates via trap; keep/recreate demo seed).
- [ ] `db/04-migrate-phase4.sql` idempotent (CREATE TABLE IF NOT EXISTS posts/reactions/comments + guarded demo seed incl. 1 repost) wired BLOCKING into deploy.sh before runtime verify.

## Manual-Only
| Behavior | Why | Steps |
|----------|-----|-------|
| feed.html compose/react/comment/repost UX | browser | open on VPS; post, react, comment, repost render correctly |

## Sign-Off
- [ ] All reqs smoke-asserted or Wave 0 dep · latency <60s · nyquist_compliant true after task-id wiring

**Approval:** pending
