---
phase: 06-giao-di-n-proconnect
plan: 01
subsystem: web-ui
tags: [brand, navbar, alpine, no-build, wave-0, ui-01, ui-04, ui-05]
requires:
  - web/assets/app.js (auth, api, notificationBell from Phases 1-5)
  - /api/connections/requests?direction=incoming (Phase 3)
  - /api/notifications via notificationBell (Phase 5)
provides:
  - window.proNav(active) shared navbar (one markup for all 8 pages, D-02 DRY)
  - window.proNavData(active) Alpine component (login state, invite badge poll)
  - navy #1e3a8a brand tokens + .pro-* helpers + [x-cloak] in styles.css
  - scripts/smoke-phase6.sh (Wave 0 static asserts + RUN_HTTP page-200)
affects:
  - Plans 02/03 (drop <div id="pronav"> + call proNav(); bump cache-bust to ?v=ph6-01)
  - Plan 04 (RUN_HTTP=1 smoke cutover on VPS)
tech-stack:
  added: []
  patterns:
    - "Inject-then-initTree: set x-data/x-init + innerHTML on #pronav, then Alpine.initTree(el) so an after-init subtree is reactive regardless of call ordering"
    - "Static dev-authored markup string + x-text only (no user-data interpolation, no x-html) — XSS-safe navbar"
    - "Per-component CSS helpers (.pro-*) + CSS vars alongside Tailwind Play CDN (no @apply, no build)"
key-files:
  created:
    - scripts/smoke-phase6.sh
    - .planning/phases/06-giao-di-n-proconnect/06-01-SUMMARY.md
  modified:
    - web/assets/app.js
    - web/assets/styles.css
decisions:
  - "proNav reactivity guaranteed by Alpine.initTree(el), NOT by set-attribute+innerHTML alone (T-06-19); safe before AND after Alpine init"
  - "Token architecture (D-01): navy Tailwind utility token declared per-page inline tailwind.config in Plans 02/03; styles.css supplies --navy* vars + .pro-* helpers — both serve D-01, no conflict"
  - "Chain-link logo = two interlocking navy rounded <rect> rings (self-designed inline SVG), no LinkedIn assets (T-06-04)"
  - "Legacy window.navbar kept (harmless) for pages not yet migrated until Plans 02/03"
  - "Removed literal 'x-html'/'linkedin' tokens from app.js comments so the smoke no-x-html / no-linkedin grep guards stay clean on the shared assets"
metrics:
  duration: 2min
  tasks: 3
  files: 4
  completed: 2026-06-06
---

# Phase 6 Plan 01: Brand Foundation Summary

Shared ProConnect navbar (`window.proNav`) — chain-link inline-SVG logo, navy `#1e3a8a` brand, Vietnamese tagline, search box, invite-badge poll, notification bell, profile menu — injected into `<div id="pronav">` and made reactive via `Alpine.initTree(el)` regardless of init ordering; plus navy brand tokens in styles.css and the Wave 0 `smoke-phase6.sh` static gate.

## What Was Built

- **Task 1 — `scripts/smoke-phase6.sh`** (`147c6ad`): bash `set -euo pipefail`, `pass()/fail()` mirroring smoke-phase5. **Static block (Wave 0 gate):** for the 8 pages asserts `id="pronav"`, navy token (`navy|1e3a8a`), `lang="vi"`, no untranslated English UI labels, no `linkedin`, no dead `/api/posts|comments` endpoints, `app.js?v=ph6*` + `styles.css?v=ph6*` cache-bust, `<body>`/`</body>` balance. Asserts shared assets carry `window.proNav`, `1e3a8a`, `<svg`, tagline, `connections/requests?direction=incoming`, `search.html?q=`, `notificationBell`, `auth.logout`, `initTree`, no `x-html`, no `linkedin`. **Page-200 block** gated behind `RUN_HTTP=1` for the Plan 04 VPS cutover.
- **Task 2 — navy tokens in `web/assets/styles.css`** (`7f7ffdd`): `--navy #1e3a8a / --navy-hover #172f6b / --navy-accent #3b5bbd / --navy-surface #eef2ff` CSS vars, `.pro-btn/.pro-link-active/.pro-badge/.pro-surface` helpers, `[x-cloak]` hide rule. Existing Inter font, `.prose-vn`, `.subtle`, `.fade-in` preserved.
- **Task 3 — `window.proNav` + `window.proNavData` in `web/assets/app.js`** (`f3cac40`): static `PRONAV_HTML` (chain-link SVG navy, brand + tagline "Kết nối chuyên nghiệp", logged-in search/links/invite-badge/bell/profile-menu, logged-out Đăng nhập/Đăng ký). `proNavData()` polls `/api/connections/requests?direction=incoming` every 15s for the invite badge and `logout()` calls `auth.logout()` then redirects. `proNav()` sets `x-data/x-init` + `innerHTML` then `Alpine.initTree(el)` (the reviewed reactivity fix). Legacy `window.navbar` kept.

## Verification

- `bash -n scripts/smoke-phase6.sh` clean; `node -e "new Function(...app.js)"` parses clean.
- All shared-asset smoke asserts PASS (proNav/proNavData/initTree/navy/SVG/tagline/invite-poll/search/bell/logout/no-x-html/no-linkedin), confirmed by running `bash scripts/smoke-phase6.sh`.
- Per-page cache-bust asserts FAIL at Wave 0 **as documented** — Plans 02/03 migrate the 8 pages (`<div id="pronav">` + `?v=ph6-01`). This mirrors the smoke-phase5 "written first, green later" pattern.
- Runtime / page-200 / live browser reactivity **deferred to VPS/CI** (Docker not installed locally) — Plan 04 runs `RUN_HTTP=1 BASE=https://soa.duyet.vn bash scripts/smoke-phase6.sh`.
- Scope: source changes limited to `web/assets/*` + `scripts/*`. No gateway/services/db/docker-compose/backend file touched.

## Threat Model Coverage

- **T-06-01 (XSS):** all user data via `x-text`; `PRONAV_HTML` static, no interpolation; no `x-html` (smoke-guarded).
- **T-06-02 (token in markup):** no token written to HTML; token stays in localStorage + Bearer header.
- **T-06-03 (logout):** `proNavData.logout()` → `auth.logout()` removes `soa_blog_token`+`soa_blog_user` then redirects.
- **T-06-04 (trademark):** self-designed inline-SVG chain-link logo; no `linkedin` string (smoke-guarded on every file).
- **T-06-19 (inert UI):** `Alpine.initTree(el)` makes the injected subtree reactive whether proNav runs before or after Alpine init.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Removed literal `x-html` / `linkedin` tokens from app.js comments**
- **Found during:** Task 3 verification
- **Issue:** Explanatory comments contained the literal strings `x-html` ("NEVER x-html") and `LinkedIn` ("no LinkedIn assets"), which tripped the plan's own `! grep -qi 'x-html'` and `! grep -qi 'linkedin'` guard greps (and smoke-phase6 asserts) against app.js — producing false-positive failures even though no actual `x-html` directive or LinkedIn asset exists.
- **Fix:** Rephrased the two comments ("the unsafe HTML-binding directive", "no third-party brand assets") so the guards stay clean while preserving intent.
- **Files modified:** web/assets/app.js
- **Commit:** f3cac40 (folded into Task 3 commit)

## Known Stubs

None — proNav wires to live Phase 3/5 endpoints (`/api/connections/requests?direction=incoming`, `/api/notifications`). The pages that consume `proNav()` are placeholders only in the sense that they are migrated in Plans 02/03 (by design, documented in the smoke as expected Wave-0 failures).

## Notes for Next Plans

- **Plan 02/03:** each page must (a) add `<div id="pronav"></div>`, (b) call `proNav('<active>')` (e.g. `'feed'`, `'connections'`, `'search'`, `'profile'`), (c) add the inline `tailwind.config` navy token snippet, (d) replace cache-bust to `?v=ph6-01` on BOTH `app.js` and `styles.css` (old tokens: `app.js?v=ph5-06`, `styles.css?v1778837733` — note no `=` on the old styles token).
- **Plan 04:** run `RUN_HTTP=1 BASE=https://soa.duyet.vn bash scripts/smoke-phase6.sh` on VPS for page-200 + the now-green cache-bust asserts.

## Self-Check: PASSED

All created/modified files exist on disk; all 3 task commits (147c6ad, 7f7ffdd, f3cac40) present in git history.
