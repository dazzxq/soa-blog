---
phase: 04-news-feed
plan: 04
subsystem: ui
tags: [alpine, tailwind, feed, vanilla-js, xss-safe]

requires:
  - phase: 04-news-feed (Plan 03)
    provides: "GET /api/feed composition + /api/posts* CRUD (reactions/comments/repost/delete) at the gateway"
  - phase: 03-k-t-n-i-social-graph (Plan 04)
    provides: "connections.html Alpine page shell + _act(busy) pattern + window.api/auth/navbar/formatDate"
provides:
  - "web/feed.html — compose box (text + image URL) + newest-first timeline UI"
  - "Per-item rendering: author/content/image/reaction_count/comment_count/my_reaction/repost-source"
  - "react (emotion picker), comment (expand/add/delete), repost, delete-own actions via window.api"
  - "index.html 'Bảng tin' nav link to the feed"
affects: [05-search-notifications, 06-branding-chrome]

tech-stack:
  added: []
  patterns:
    - "feedPage() Alpine component mirrors connectionsPage() _act(busy) reload-after-mutation guard"
    - "x-text for ALL user-supplied strings (post/comment/original content + author names) — never x-html (XSS-safe, D-10)"
    - "Owner-only UI gating: delete buttons rendered only when author_id === auth.user.id (T-04-21)"

key-files:
  created:
    - web/feed.html
  modified:
    - web/index.html

key-decisions:
  - "feed.html uses app.js?v=ph4-01 cache-buster per plan (app.js unchanged this plan, so no cross-HTML bump needed)"
  - "Emotion picker labels are localized Vietnamese with emoji (👍 Thích, ❤️ Yêu thích, …) via reactionLabel()"
  - "Comment input also submits on Enter (@keydown.enter) for UX; addComment/deleteComment carry their own busy guard so reload re-fetches both comments and the feed counts"

patterns-established:
  - "Feed timeline card layout: author header + repost banner + content + image + reaction row + action row + collapsible comments"
  - "Repost source nested card via p.original (display_name + content + optional image), falling back to 'Bài viết gốc đã bị xoá' when p.original is null"

requirements-completed: [FEED-01, FEED-02, FEED-03, FEED-04, FEED-05]

duration: 4min
completed: 2026-06-07
---

# Phase 4 Plan 4: Feed UI Summary

**web/feed.html — Alpine compose box + newest-first timeline rendering author/content/image/reaction_count/comment_count/my_reaction/repost-source, with emotion-picker reactions, expandable comments, repost, and owner-only delete — all via window.api, x-text only (XSS-safe), Vietnamese.**

## Performance

- **Duration:** ~4 min
- **Started:** 2026-06-07
- **Completed:** 2026-06-07
- **Tasks:** 2 auto + 1 checkpoint (static-verified)
- **Files modified:** 2 (1 created, 1 modified)

## Accomplishments
- Shipped web/feed.html: compose card (textarea + image URL input + "Đăng"), degraded banner, loading/error/empty states, and a newest-first timeline of feed items.
- Each card renders author (avatar/display_name) + time, repost source (nested original card or "Bài viết gốc đã bị xoá"), content, image, a 6-emotion reaction row highlighting my_reaction, a comment toggle with collapsible list + add/delete, a "Chia sẻ" repost button, and an owner-only "Xoá" delete button.
- Wired feedPage() to the gateway: GET /api/feed?limit=20, POST /api/posts, POST/DELETE /api/posts/{id}/reactions, POST /api/posts/{id}/repost, DELETE /api/posts/{id}, GET/POST /api/posts/{id}/comments, DELETE /api/comments/{id}.
- Added a "Bảng tin" nav link to index.html's logged-in nav next to "Kết nối".

## Task Commits

1. **Task 1: web/feed.html (compose + timeline + react/comment/repost/delete)** - `f729966` (feat)
2. **Task 2: index.html nav link to feed** - `d86e386` (feat)

**Plan metadata:** (this commit) (docs: complete plan)

## Files Created/Modified
- `web/feed.html` - Feed UI: page shell cloned from connections.html (lang=vi, Tailwind+Alpine+app.js?v=ph4-01), feedPage() Alpine component, compose box, timeline cards, react/comment/repost/delete wiring.
- `web/index.html` - Added "Bảng tin" link to /feed.html in the logged-in navbar.

## Decisions Made
- Kept feed.html's app.js reference at `?v=ph4-01` per the plan's `<interfaces>`. app.js was NOT modified in this plan, so the existing pages stay on `?v=ph2-04` — no cross-HTML cache-bump was required (the bump rule only applies when app.js content changes).
- Localized the emotion picker with Vietnamese labels + emoji via a `reactionLabel()` helper rather than raw enum strings, for a presentable demo.
- Added Enter-to-submit on the comment input as a minor UX affordance; it routes through the same `addComment()` busy-guarded path.

## Deviations from Plan

None - plan executed exactly as written. The feedPage() component, render rules (x-text, repost fallback, my_reaction highlight, owner-only delete, degraded banner), endpoints, and nav link all match the plan/interfaces verbatim. The only additions (reactionLabel emoji labels, Enter-to-submit) are within Claude's stated discretion (reaction set / display details) and add no new endpoints or libraries.

## Issues Encountered
None.

## Known Stubs
None. All UI is wired to live gateway endpoints; no hardcoded/placeholder data sources.

## Threat Surface
No new threat surface beyond the plan's `<threat_model>`. Static verification confirmed the three registered mitigations:
- **T-04-19 (stored XSS):** `grep "x-html" web/feed.html` → none; all user content rendered via x-text.
- **T-04-20 (PII):** no `email` literal in feed.html; cards consume the gateway's email-allowlisted payload (Plan 03).
- **T-04-21 (delete others' content):** delete buttons gated on `author_id === auth.user?.id` (posts) and `c.author_id === auth.user?.id` (comments); server-side owner-scope enforced by feed-service regardless of UI.

## Checkpoint Resolution (Task 3 — human-verify, blocking)
Docker is not available locally (per environment constraints + 04-RESEARCH §Environment Availability), so the browser/runtime walk-through is deferred to the VPS deploy in Plan 05 (re-confirmed on the live stack there). In lieu of runtime verification, static verification passed: Task 1/Task 2 grep asserts (component, api calls, repost/reaction/comment wiring, lang=vi, no x-html, nav link) plus the threat-mitigation greps above. No blocking — proceeded per the executor directive.

## Next Phase Readiness
- The full feed UX (FEED-01..05) surface is shipped and statically verified.
- Runtime/visual verification on https://soa.duyet.vn is pending the Plan 05 deploy (the human-verify checkpoint walk-through).
- Phase 6 will add branding/3-column chrome; this plan deliberately kept the UI minimal.

## Self-Check: PASSED

- FOUND: web/feed.html
- FOUND: web/index.html
- FOUND: .planning/phases/04-news-feed/04-04-SUMMARY.md
- FOUND: commit f729966 (Task 1)
- FOUND: commit d86e386 (Task 2)

---
*Phase: 04-news-feed*
*Completed: 2026-06-07*
