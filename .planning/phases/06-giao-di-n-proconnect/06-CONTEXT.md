# Phase 6: Giao diện ProConnect - Context

**Gathered:** 2026-06-07
**Status:** Ready for planning
**Note:** User-delegated ("cứ suggest, keep simple, presentable for class"). UI-ONLY phase — restyle the 8 existing functional web pages into the ProConnect brand + 3-column feed + shared navbar. NO new services/DB/migration/endpoints.

<domain>
## Phase Boundary
Apply ProConnect brand identity (navy #1e3a8a, chain-link logo, tagline) across ALL pages; feed page gets a 3-column professional-network layout (left profile card, center feed, right suggestions); shared navbar with search box + notification badge + connection-invite badge + profile menu, all wired to built features; responsive; all text Vietnamese có dấu. Self-designed (NO LinkedIn brand assets).
Requirements: UI-01, UI-02, UI-04, UI-05. Depends Phase 5.
**OUT:** any new backend; binary asset uploads; heavy build tooling (stay Tailwind Play CDN + Alpine, no build step).

<decisions>
## Implementation Decisions

### A. Brand identity (UI-01)
- **D-01:** Primary navy `#1e3a8a` (hover `#172f6b`, light accent `#3b5bbd`/`#eef2ff` surfaces), neutral grays, white cards. Define tokens ONCE: an inline `tailwind.config = { theme:{ extend:{ colors:{ navy:{...} } } } }` snippet (Play CDN supports inline config) + a few CSS vars/classes in `web/assets/styles.css`. Chain-link **logo as inline SVG** (two interlocking rings, navy) — reusable. Tagline (Vietnamese, e.g. "Kết nối chuyên nghiệp"). NO LinkedIn assets/trademarks.

### B. Shared navbar (UI-04) — DRY, no build
- **D-02:** ONE shared navbar rendered by a JS helper `window.proNav(active)` (in web/assets/app.js or a small nav include) injected into a `<div id="pronav">` placeholder on every page — avoids duplicating markup across 8 files (no build/templating). Contents (logged-in): logo+name → /feed.html; search box (Enter → /search.html?q=…); **notification bell + unread badge** (reuse Phase-5 `window.notificationBell`, ~15s poll); **connection-invite badge** (count of incoming pending, ~15s poll → /connections.html); profile menu (avatar → dropdown: Hồ sơ của tôi, Chỉnh sửa hồ sơ, Đăng xuất). Logged-out: Đăng nhập / Đăng ký. Active-page highlight. x-text (no XSS).

### C. 3-column feed (UI-02)
- **D-03:** `web/feed.html` → centered max-width container, 3 columns on `lg+`: LEFT profile summary card (avatar, name, headline, "Xem hồ sơ"); CENTER compose box + timeline (existing); RIGHT "Người bạn có thể biết" (reuse `/api/connections/suggestions`) + a small static info card ("Về ProConnect" / demo note — the "ad/quảng cáo" slot). Responsive: columns STACK on `< lg` (center first), navbar collapses gracefully on mobile.

### D. Consistency + language (UI-05)
- **D-04:** Apply the navy theme + shared navbar to ALL 8 pages (index, login, register, feed, profile, profile-edit, connections, search). Audit every visible string + error message is Vietnamese có dấu. Keep all existing functionality + endpoints intact (this is restyle, not rewrite).
- **D-05:** No build — Tailwind Play CDN + Alpine retained. Bump the `?v=` cache-bust on app.js AND styles.css across all pages (Phase 1/2 stale-cache lesson). Keep pages working (no dead endpoints).
- **D-06:** Invite badge source = `GET /api/connections/requests?direction=incoming` length, polled ~15s (mirror the notification bell pattern).

### Claude's Discretion
- Exact navy shades/spacing, logo SVG design, tagline wording, mobile breakpoints, whether the navbar lives in app.js vs a dedicated nav.js, the static right-column "ad" card content.
</decisions>

<canonical_refs>
## Canonical References
- `.planning/PROJECT.md` (brand: navy #1e3a8a, logo mắt xích, ProConnect identity; constraint: no LinkedIn assets; Tailwind no-heavy-build), REQUIREMENTS.md (UI-01/02/04/05), ROADMAP.md §Phase 6.
- Existing pages to restyle: web/index.html, login.html, register.html, feed.html, profile.html, profile-edit.html, connections.html, search.html; web/assets/app.js (has window.notificationBell, loadProfile, loadFull, connection actions, auth helpers), web/assets/styles.css, web/nginx.conf.
- All built endpoints the navbar/columns wire to: /api/me, /api/auth/*, /api/search, /api/notifications, /api/connections/requests?direction=incoming, /api/connections/suggestions, /api/profiles/{id}/full, /api/feed.
</canonical_refs>

<code_context>
## Existing Code Insights
- 8 functional pages already exist (Alpine + Tailwind Play CDN). This phase restyles them — reuse all existing Alpine components/fetch logic; wrap in the brand + shared navbar.
- window.notificationBell (Phase 5) → drop into the shared navbar.
- /api/connections/suggestions + /api/connections/requests?direction=incoming already exist (Phase 3) → right column + invite badge.
- styles.css + cache-bust convention (?v=) established.
- NO backend changes — gateway/services untouched.
</code_context>

<specifics>
## Specific Ideas
- Presentation: a coherent navy professional-network look with working navbar badges makes the Gateway-backed features tangible to the lecturer. Keep it clean, not flashy.
- Demo accounts already have profiles/posts/connections/notifications (seeded across phases) → the styled UI will show real content.
- Mandatory: /codex-plan-review before code, /codex-impl-review before commit.

<deferred>
## Deferred Ideas
- Dark mode, animations, custom fonts beyond system/Inter-CDN, image upload, a real ad system — out of scope. Milestone is feature-complete after this phase.
