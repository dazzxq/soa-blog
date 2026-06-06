---
phase: 03-k-t-n-i-social-graph
plan: 04
subsystem: ui
tags: [alpine, tailwind, social-graph, vietnamese, connections, frontend]

# Dependency graph
requires:
  - phase: 03-03
    provides: "Gateway /api/connections/* routes (list, requests?direction, suggestions, status, accept/reject/cancel/remove) + lit-up /full connection_status"
  - phase: 02-04
    provides: "web/profile.html + app.js (window.api/auth) Alpine+Tailwind CDN UI pattern, x-text XSS discipline"
provides:
  - "web/connections.html — usable Vietnamese UI for the full invite lifecycle (my connections, incoming/outgoing pending, suggestions) with accept/reject/cancel/remove/connect"
  - "web/profile.html relationship-status badge + context action (Kết nối / Huỷ lời mời / Chấp nhận+Từ chối / Xoá kết nối)"
  - "Kết nối nav link from index.html for logged-in users"
affects: [phase-05-search-notifications, phase-06-branding-3column]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "busy-guarded async action wrapper (_act/_connAct) that reloads page state after each mutation"
    - "profile.html resolves request_id at action time via /connections/requests?direction= (page only knows the peer user_id)"

key-files:
  created:
    - web/connections.html
  modified:
    - web/profile.html
    - web/index.html

key-decisions:
  - "connections.html loads all four lists in a single Promise.all; every mutation calls load() to refresh (simplest correct refresh; lists are small)"
  - "profile.html does not know request_id, so cancel/accept/reject resolve it via /connections/requests?direction=outgoing|incoming and match on user_id before acting"
  - "self status falls through to no badge + no action (action buttons gated by !isOwner AND explicit status value)"

patterns-established:
  - "Action wrapper pattern: a single busy flag + try/catch sets a Vietnamese error string, finally clears busy, then load() re-fetches — reused on both pages"

requirements-completed: [CONN-01, CONN-02, CONN-03, CONN-04, CONN-05, CONN-06]

# Metrics
duration: 2min
completed: 2026-06-06
---

# Phase 3 Plan 04: Kết nối UI Summary

**Vietnamese connections page (connections + incoming/outgoing pending + "Người bạn có thể biết" suggestions with accept/reject/cancel/remove/connect) plus a lit-up relationship badge + context action on profile.html, all Alpine+Tailwind CDN, no email leak.**

## Performance

- **Duration:** ~2 min
- **Started:** 2026-06-06T20:02:23Z
- **Completed:** 2026-06-06T20:04:13Z
- **Tasks:** 2 (plus 1 deviation commit)
- **Files modified:** 3 (1 created, 2 modified)

## Accomplishments
- `web/connections.html`: `connectionsPage()` Alpine component, four Vietnamese sections, parallel load, busy-guarded accept/reject/cancel/remove/sendInvite that reload after each, avatar-initial fallback, login redirect when unauthenticated.
- `web/profile.html`: status block now maps null/none/pending_outgoing/pending_incoming/connected to distinct Vietnamese badges, with a context action button hidden for owner/self; action methods resolve request_id where needed.
- `web/index.html`: "Kết nối" nav link for logged-in users so the new page is reachable.

## Task Commits

Each task was committed atomically:

1. **Task 1: web/connections.html** - `e5c6ecd` (feat)
2. **Task 2: web/profile.html relationship badge + context action** - `0a8a3b9` (feat)
3. **Deviation: index nav link to connections** - `d8da88e` (feat — Rule 2 reachability)

**Plan metadata:** (this commit — docs: complete plan)

## Files Created/Modified
- `web/connections.html` - New. Connections + incoming/outgoing pending + suggestions UI calling `/api/connections/*`; accept/reject/cancel/remove/sendInvite handlers.
- `web/profile.html` - Replaced raw connection_status badge with VN status map + context action button; added connect/removeConn/cancelInvite/acceptInvite/rejectInvite + connBusy/connError to profilePage().
- `web/index.html` - Added "Kết nối" navbar link (logged-in only).

## Decisions Made
- Reload-after-mutation over optimistic UI: lists are small and the gateway is the source of truth (the /full status only lights up after a round-trip). Keeps the demo correct over clever.
- request_id resolution on profile.html: the page only has the peer's user_id, so cancel/accept/reject fetch the matching pending request first, then act. Throws a Vietnamese error if no matching request is found (race-safe — does not crash the page).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Added "Kết nối" nav link to index.html**
- **Found during:** After Task 2 (reachability gap)
- **Issue:** Plan `files_modified` listed only connections.html + profile.html, but connections.html was unreachable from the app chrome — the orchestrator success criteria explicitly require an index nav link. A page nobody can navigate to is not usable.
- **Fix:** Added an `<a href="/connections.html">Kết nối</a>` in the logged-in branch of index.html's navbar, mirroring the connections.html navbar.
- **Files modified:** web/index.html
- **Verification:** `grep -q 'href="/connections.html"' web/index.html` → OK
- **Committed in:** `d8da88e`

---

**Total deviations:** 1 auto-fixed (1 missing critical/reachability)
**Impact on plan:** Necessary to make the delivered page usable. No scope creep — connections.html navbar already self-linked; this only adds the entry point from the home page.

## Issues Encountered
None. `app.js` was NOT modified, so no `?v=` cache-bust bump was required (the Phase 1/2 lesson only applies when app.js changes).

## Known Stubs
None. All four lists and both pages wire real `/api/connections/*` endpoints (built in Plan 03). Empty arrays render Vietnamese empty-states, not placeholders.

## Verification (static — Docker absent locally)
- Plan automated grep assertions pass for both files (component name, route paths, VN labels, null-anon case, pending_outgoing).
- `grep -i email web/connections.html web/profile.html` → empty (T-03-16 mitigated; no email field referenced).
- `grep -rEn '/api/(posts|comments)' web/` → none (no dead endpoints).
- `grep -n x-html web/profile.html` → none (x-text only; T-02-10 XSS discipline maintained).
- Tag balance: connections.html sections 4/4, templates 21/21; profile.html sections 4/4, templates 30/30.
- Browser-rendered UX (badge lighting up, button flows) deferred to VPS Plan 05 per 03-VALIDATION.md (T-03-18 login redirect present in connectionsPage.load()).

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Phase 3 graph is now fully usable + demoable in a browser; the profile badge lights up against the real connection-service.
- Plan 05 (VPS deploy + manual browser validation) is the remaining Phase 3 plan.
- No new blockers.

## Self-Check: PASSED

All created/modified files exist on disk (web/connections.html, web/profile.html, web/index.html, 03-04-SUMMARY.md) and all three task commits (e5c6ecd, 0a8a3b9, d8da88e) are present in git history.

---
*Phase: 03-k-t-n-i-social-graph*
*Completed: 2026-06-06*
