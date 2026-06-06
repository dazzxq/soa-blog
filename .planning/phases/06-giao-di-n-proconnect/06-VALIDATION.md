---
phase: 6
slug: giao-di-n-proconnect
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-07
---
# Phase 6 — Validation Strategy
> UI-only restyle. Validation = static markup/asset checks (local) + curl page-200 + manual visual (VPS). No new backend; no PHP. smoke-phase6.sh asserts brand+navbar+3-col+endpoints+no-dead-links; visual is human.

## Test Infrastructure
| Property | Value |
|----------|-------|
| Framework | bash+curl `scripts/smoke-phase6.sh` (static markup greps + page 200) |
| Static gate | grep over web/*.html + app.js/styles.css; HTML tag balance |

## Per-Requirement Verification Map
| Req | Behavior | Assertion | Status |
|-----|----------|-----------|--------|
| UI-01 | brand navy + logo + tagline | every page references navy `#1e3a8a` (or `navy` token) + the chain-link SVG/logo + tagline; no 'linkedin' asset string | ⬜ W0 |
| UI-02 | feed 3-column responsive | feed.html has 3 column containers + lg: breakpoint classes + suggestions + profile card | ⬜ W0 |
| UI-04 | navbar wired | shared navbar on all pages with search (→/search.html), notification bell, invite badge (→/connections.html), profile menu (logout); endpoints correct | ⬜ W0 |
| UI-05 | Vietnamese | no obvious untranslated English UI labels; pages have lang="vi"; diacritics present | ⬜ W0 |
| Integrity | no dead endpoints / pages load | `! grep /api/(posts|comments)$` ; each page GET 200 on VPS; app.js+styles.css cache-bust bumped | ⬜ W0 |
| Regression | backend untouched | smoke-phase1..5 still pass (no service/gateway files changed) | ⬜ |

## Wave 0
- [ ] `scripts/smoke-phase6.sh` (static markup asserts + curl page-200 for all 8 pages; non-destructive, read-only).

## Manual-Only (the real UI gate)
| Behavior | Why | Steps |
|----------|-----|-------|
| Brand + 3-col + navbar badges look/behave right | visual | open https://soa.duyet.vn on desktop+mobile: navy brand, chain logo, feed 3-col (stacks on mobile), navbar search/bell/invite-badge/profile-menu work, all Vietnamese |

## Sign-Off
- [ ] static asserts pass + pages 200 + backend regression green; visual approved by human

**Approval:** pending
