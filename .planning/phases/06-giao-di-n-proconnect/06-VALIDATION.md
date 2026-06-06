---
phase: 6
slug: giao-di-n-proconnect
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-07
---
# Phase 6 — Validation Strategy
> UI-only restyle. Validation = static markup/asset checks (local) + curl page-200 + manual visual (VPS). No new backend; no PHP. smoke-phase6.sh asserts brand+navbar+3-col+endpoints+no-dead-links+initTree+cache-bust-token; visual is human.

## Test Infrastructure
| Property | Value |
|----------|-------|
| Framework | bash+curl `scripts/smoke-phase6.sh` (static markup greps + page 200) |
| Static gate | grep over web/*.html + app.js/styles.css; HTML tag balance |

## Per-Requirement Verification Map
| Req | Behavior | Assertion | Status |
|-----|----------|-----------|--------|
| UI-01 | brand navy + logo + tagline | every page declares navy via inline `tailwind.config` navy token (`navy:` snippet → `bg-navy`/`text-navy`) AND app.js/styles.css carry `#1e3a8a` + `.pro-*` helpers; chain-link SVG/logo + tagline in proNav; no 'linkedin' asset string | ⬜ W0 |
| UI-02 | feed 3-column responsive | feed.html has 3 column containers + lg: breakpoint classes + suggestions + profile card | ⬜ W0 |
| UI-04 | navbar wired + reactive | shared navbar on all pages with search (→/search.html), notification bell, invite badge (→/connections.html), profile menu (logout); endpoints correct; proNav calls `Alpine.initTree` so injected subtree is reactive regardless of init ordering (app.js contains `initTree`) | ⬜ W0 |
| UI-05 | Vietnamese | no obvious untranslated English UI labels; pages have lang="vi"; diacritics present | ⬜ W0 |
| Integrity | no dead endpoints / pages load | `! grep /api/(posts|comments)$` ; each page GET 200 on VPS; cache-bust token REPLACED on every page → `app.js?v=ph6-01` + `styles.css?v=ph6-01` (no stale `v=ph5-06` / `v1778837733`) | ⬜ W0 |
| Regression | backend untouched | smoke-phase1..5 still pass. SOURCE/PRODUCTION changes limited to `web/*` + `scripts/*` (NO gateway/services/db/docker-compose/backend); `.planning/**` meta-docs (SUMMARY/STATE/ROADMAP/REQUIREMENTS) are normal GSD bookkeeping, exempt from the source-scope rule. Allowlist guard (Plan 04) FAILS if any changed path is outside `web/` `scripts/` `.planning/`. | ⬜ |

## Decision notes (consistency)
- **D-01 navy token:** chosen path = inline `tailwind.config` per page (Play CDN reads inline config per-page) defining `navy` color token, PLUS `.pro-*` CSS helper classes + `--navy*` vars in styles.css. Both serve D-01; `bg-navy`/`text-navy` work via the inline config, `.pro-btn`/`.pro-surface` provide ready-made components. Smoke accepts either `navy` token presence or `#1e3a8a`.
- **D-05 cache-bust:** current tokens are `app.js?v=ph5-06` and `styles.css?v1778837733` (the styles.css token has NO `=`). Plans do an explicit FULL-TOKEN REPLACE to `?v=ph6-01` (not a partial bump). Smoke greps the NEW tokens and asserts the old ones are gone.

## Wave 0
- [ ] `scripts/smoke-phase6.sh` (static markup asserts + curl page-200 for all 8 pages; non-destructive, read-only; asserts initTree + ph6-01 cache-bust tokens).

## Manual-Only (the real UI gate)
| Behavior | Why | Steps |
|----------|-----|-------|
| Brand + 3-col + navbar badges look/behave right | visual + runtime reactivity | open https://soa.duyet.vn on desktop+mobile: navy brand, chain logo, feed 3-col (stacks on mobile), navbar search/bell/invite-badge/profile-menu actually respond (reactive), all Vietnamese |

## Sign-Off
- [ ] static asserts pass + pages 200 + backend regression green; visual approved by human

**Approval:** pending
