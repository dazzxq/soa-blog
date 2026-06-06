---
phase: 05-t-m-ki-m-th-ng-b-o
plan: 06
subsystem: web-ui
tags: [search, notifications, alpine, ui, cache-bust]
requires:
  - "GET /api/search?q= (gateway SearchController, Plan 05-04)"
  - "POST /api/connections/requests (Plan 03)"
  - "GET /api/notifications + POST .../{id}/read + .../read-all (gateway NotificationsController, Plan 05-05)"
provides:
  - "web/search.html — search page with relationship-aware quick-connect cards"
  - "window.notificationBell factory in app.js — 15s-poll unread badge + mark-read/all"
  - "navbar search box + notification bell on feed/connections/index/search"
affects:
  - "web/feed.html, web/connections.html, web/index.html navbars"
  - "all 8 HTML pages (app.js cache-bust token)"
tech-stack:
  added: []
  patterns:
    - "Alpine factory exported on window (notificationBell) reused across pages, defined once in app.js"
    - "setInterval ~15s polling for unread badge (D-06, no WebSocket)"
    - "x-text only for all user/notification content (XSS-safe, T-05-21)"
    - "global cache-bust token bump on EVERY page loading the edited shared app.js"
key-files:
  created:
    - web/search.html
  modified:
    - web/assets/app.js
    - web/feed.html
    - web/connections.html
    - web/index.html
    - web/login.html
    - web/register.html
    - web/profile.html
    - web/profile-edit.html
decisions:
  - "Bell + search-box partial added inline in each navbar (HTML cannot share partials without a build step); the BEHAVIOR (notificationBell) is the shared single source in app.js"
  - "Cache-bust bumped to ph5-06 on ALL 8 pages that load app.js, not only the 4 in the plan's files_modified — any stale page would serve old app.js and break notificationBell (Phase 1/2 lesson)"
  - "x-cloak attribute left as a harmless no-op (no CSS rule) — dropdown stays hidden via x-show=false default; not adding a styles.css rule avoids a second cache-bust token bump (scope control)"
metrics:
  duration: ~6min
  tasks: 2
  files: 9
  completed: 2026-06-06
---

# Phase 5 Plan 06: Search & Notification UI Summary

Shipped the D-10 user-facing surface: a `search.html` page (query → relationship-aware quick-connect cards) and a navbar notification bell (unread badge polling ~15s, mark-read / mark-all-read) wired into feed/connections/index, with the shared `notificationBell` factory added once to `app.js` and the cache-bust token bumped to `ph5-06` on every page that loads it.

## What Was Built

### Task 1 — web/search.html (commit c914464)
- `searchPage()` Alpine component cloning the connections.html shell + feed.html navbar.
- Search input bound to `q`; reads initial `?q=` from the URL via `x-init="init()"` and runs on load.
- `run()` → `api.get('/search?q=' + encodeURIComponent(q))`; sets `results`, surfaces `meta.degraded` as a subtle Vietnamese notice ("Một số trạng thái quan hệ tạm thời không tải được").
- Result cards: avatar (`:src` with initial fallback), `x-text` name + headline, and a status-aware quick-connect button covering all 5 `connection_status` values (none → "Kết nối"; pending_outgoing → disabled "Đã gửi lời mời"; pending_incoming → "Chờ bạn phản hồi" link; connected → "✓ Đã kết nối"; unknown → "Kết nối" + "Trạng thái chưa rõ" note).
- `connect(u)` → `api.post('/connections/requests', { target_id: u.id })` with optimistic flip to `pending_outgoing`.
- Empty state ("Không tìm thấy người dùng nào"), auth-guarded (redirect to /login.html). x-text only.

### Task 2 — notification bell factory + navbar partials + cache-bust (commit d540c4d)
- `window.notificationBell` added ONCE to `web/assets/app.js`: `load()` (GET /api/notifications, reads `meta.unread_count`, degrades silently), `start()` (load + `setInterval(load, 15000)`), `markOne(id)` (POST /notifications/{id}/read), `markAll()` (POST /notifications/read-all), `message(n)` (Vietnamese per type invite/reaction/comment + fallback).
- Bell dropdown + compact search box (`@submit.prevent="location.href='/search.html?q='+…"`) inserted into the logged-in `<template x-if="isLoggedIn">` span of feed.html, connections.html, index.html (and search.html's own navbar). Unread items bold via `:class`, badge `x-show="unread > 0"`. x-text only.
- Cache-bust `?v=` on the app.js script tag bumped to `ph5-06` on ALL 8 pages (feed, connections, index, search, login, register, profile, profile-edit).

### Task 3 — checkpoint:human-verify (blocking)
Resolved via static verification per environment constraints (Docker not installed locally; live browser UX deferred to the VPS Plan 07 cutover). See "Checkpoint Handling" below.

## Endpoints Wired (static-grep confirmed)
- `GET /api/search?q=` (search.html)
- `POST /api/connections/requests` (search.html quick-connect)
- `GET /api/notifications`, `POST /api/notifications/{id}/read`, `POST /api/notifications/read-all` (app.js bell)
- `setInterval(() => this.load(), 15000)` — ~15s poll (D-06)

## Deviations from Plan

### Auto-fixed / scope adjustments (no architectural change)

**1. [Rule 3 - Blocking] Cache-bust bumped on 4 EXTRA pages beyond files_modified**
- **Found during:** Task 2.
- **Issue:** The plan's `files_modified` lists 4 HTML pages, but login.html, register.html, profile.html, profile-edit.html also load the now-edited shared app.js with the old `?v=ph2-04`. A stale cache there would serve old app.js → `notificationBell` undefined / Alpine errors when a user navigates to those pages (Phase 1/2 lesson the plan itself cites).
- **Fix:** Bumped all 8 pages to `?v=ph5-06`.
- **Files modified:** web/login.html, web/register.html, web/profile.html, web/profile-edit.html (plus the 4 in plan).
- **Commit:** d540c4d.

**2. [Decision] x-cloak left as a no-op**
- The dropdown carries `x-cloak` but styles.css has no `[x-cloak]{display:none}` rule. The dropdown stays hidden anyway via `x-show="open"` (open defaults false), so there is no flash. Adding the CSS rule would force a second cache-bust on the separately-versioned styles.css across all pages — declined to keep scope tight.

## Checkpoint Handling

Task 3 is a `checkpoint:human-verify gate="blocking"` for live browser UX (search → connect, bell badge poll/mark-read, no PII, Vietnamese). Per the executor's environment constraints, Docker is not installed locally and runtime/browser verification is deferred to the VPS after the Plan 07 cutover. Static verification performed in lieu:
- Endpoints present and correct (search, connections/requests, notifications + read/read-all).
- `setInterval` interval = 15000ms.
- 5 `connection_status ===` branches in search.html.
- No `email`/PII references in search.html or app.js.
- Bell present on feed/connections/index/search.
- x-text only; no `x-html` anywhere.

The blocking human-verify (real login on https://soa.duyet.vn, badge increment within ~15s, mark-read, PII spot-check) remains to be performed by a human after Plan 07 deploy — tracked, not blocking this plan's code completion.

## Threat Surface
No new surface beyond the plan's `<threat_model>`. T-05-21 (XSS) mitigated by x-text-only rendering (grep-confirmed no x-html); T-05-22 (PII) — UI renders only gateway allowlisted fields, no `email` literal present; T-05-23 (poll DoS) — accepted, poll only when logged in at ~15s.

## Known Stubs
None. All data sources are wired to live gateway endpoints (search, connections, notifications).

## Self-Check: PASSED
- FOUND: web/search.html
- FOUND: web/assets/app.js
- FOUND: .planning/phases/05-t-m-ki-m-th-ng-b-o/05-06-SUMMARY.md
- FOUND commit: c914464 (Task 1)
- FOUND commit: d540c4d (Task 2)
