---
phase: 02-h-s-ngh-nghi-p
plan: 04
subsystem: web-ui
tags: [frontend, alpine, tailwind, profile, composition]
requires:
  - "GET /api/profiles/{id}/full (Plan 03 flagship composition)"
  - "PATCH/POST/DELETE /api/profiles/me/* (Plan 03 owner CRUD)"
  - "GET /api/me, /api/auth/* (Phase 1)"
  - "web/assets/app.js helpers: api, auth, navbar, formatDate"
provides:
  - "web/profile.html — public profile view consuming /full"
  - "web/profile-edit.html — owner edit screen exercising every /me/* CRUD endpoint"
  - "index.html link to own profile when logged in"
  - "window.loadFull(id) app.js helper"
affects:
  - web/index.html
  - web/login.html
  - web/register.html
  - web/assets/app.js
tech-stack:
  added: []
  patterns:
    - "Alpine x-data page component + x-init loader (Phase 1 pattern)"
    - "x-text only for user-supplied strings (XSS mitigation, never x-html)"
    - "cache-bust ?v= on app.js bumped across ALL HTML when app.js changes"
key-files:
  created:
    - web/profile.html
    - web/profile-edit.html
  modified:
    - web/index.html
    - web/login.html
    - web/register.html
    - web/assets/app.js
decisions:
  - "Two separate files (profile.html view + profile-edit.html edit) over one dual-mode file (D-13 discretion) — clearer separation, simpler Alpine state."
  - "Owner detection on profile.html via myId===profile.id comparison (resolved through /api/me), gating the edit link."
  - "Skills add: 409 -> Vietnamese 'Kỹ năng đã tồn tại' toast; all mutations reload /full to stay authoritative."
  - "app.js cache-bust changed from v=558aeb6 to v=ph2-04 on all five HTML pages because app.js gained loadFull()."
metrics:
  duration: 3min
  tasks: 2
  files: 6
  completed: 2026-06-06
---

# Phase 2 Plan 04: Profile UI Summary

Built the minimal functional profile UI (D-13/D-14, UI-03): `profile.html` renders the flagship `/api/profiles/{id}/full` composition (cover + avatar + headline + location + about + Kinh nghiệm/Học vấn/Kỹ năng + connection_status badge + degraded notice), and `profile-edit.html` exercises every owner CRUD endpoint under `/api/profiles/me/*` (basic PATCH, experience/education add-edit-delete, skills add-delete with 409 handling). index.html links logged-in users to their own profile. Alpine.js + Tailwind CDN, Vietnamese with diacritics, no ProConnect branding (Phase 6).

## What Was Built

### Task 1 — web/profile.html (commit 54b7e9f)
- `profilePage()` Alpine component; `x-init="load()"` reads `?id=` from the query string, else resolves own id via `/api/me` when logged in, then `GET /api/profiles/{id}/full`.
- Renders cover banner (gray fallback), overlapping avatar (initials fallback), display_name, headline, location, about; three collapsible sections (experience/education/skills) hidden when empty.
- `connection_status`: `null` -> "Đăng nhập để xem trạng thái kết nối"; `"none"` -> "Chưa kết nối" badge; other values verbatim (Phase 3 will populate).
- `meta.degraded` -> subtle "Một số thông tin tạm thời không khả dụng" note (EXPECTED in Phase 2: connection-service is a stub, D-03).
- Owner (myId === profile.id) sees "Chỉnh sửa hồ sơ" link to /profile-edit.html.
- All user strings via `x-text` (T-02-10 XSS mitigation); `:src` for image URLs only.

### Task 2 — web/profile-edit.html + index link + app.js helper (commit 01faec8)
- `profileEditPage()`: login guard (redirect to /login.html if `!auth.isLoggedIn()`), resolves own id via `/api/me`, prefills from `/full`.
- Basic form (PROF-02): display_name/headline/location/avatar_url/cover_url/about -> `PATCH /api/profiles/me`; empty optional fields sent as `null`.
- Experience editor (PROF-03): inline list with Sửa/Xoá per item + add form; `POST/PATCH/DELETE /api/profiles/me/experience[/{id}]`; empty end_date -> null ("Hiện tại").
- Education editor (PROF-04): same pattern on `/api/profiles/me/education`; years as numbers.
- Skills editor (PROF-05): chips with ✕ delete + add input; `POST/DELETE /api/profiles/me/skills[/{id}]`; 409 -> "Kỹ năng đã tồn tại."
- Every mutation reloads `/full` to stay authoritative; toast feedback (Vietnamese, auto-dismiss).
- index.html: logged-in section gains "Hồ sơ của tôi" (-> /profile.html?id=) and "Chỉnh sửa hồ sơ" links (D-14).
- app.js: added `window.loadFull(id)` returning `{profile, degraded}`; existing helpers intact.
- Cache-bust bumped `app.js?v=558aeb6` -> `?v=ph2-04` on all five HTML pages (Phase-1 cache lesson).

## Deviations from Plan

None of Rules 1-4 triggered. Two in-scope adjustments worth noting (both within the plan's stated discretion / Phase-1 lessons):
- **Cache-bust on login.html + register.html:** the plan only called for bumping index.html, but app.js changed, so ALL pages referencing it were bumped to `v=ph2-04` for consistency (Phase-1 ISSUE: stale cached app.js). Within the environment-constraint instruction.
- **index.html `<dl>` wrapped in a `<div>`** to host the action links without breaking the existing `x-if` template's single-root requirement.

## Static Verification (browser deferred to VPS Plan 05)

Docker/browser not available locally; the human-verify checkpoint's live steps run post-deploy on https://soa.duyet.vn (Plan 05). Static checks performed and PASSED:
- Both files exist (profile.html 210 lines > 60 min; profile-edit.html 372 lines > 80 min).
- profile.html calls `/profiles/{id}/full`; profile-edit.html references all of `/profiles/me`, `/profiles/me/experience[/]`, `/profiles/me/education[/]`, `/profiles/me/skills[/]`.
- Both resolve own id via `/api/me`.
- No dead `/api/posts|comments` references anywhere in web/.
- Tag balance verified (template/section/main/script paired) on both pages; app.js valid JS (`node -c`).
- app.js cache-bust uniform `v=ph2-04` across all five HTML pages.
- No `x-html` anywhere (T-02-10 mitigation: all user strings via x-text).
- Vietnamese diacritics confirmed (Kinh nghiệm, Học vấn, Kỹ năng, Chỉnh sửa hồ sơ, Hồ sơ của tôi, đã tồn tại).

## To Confirm In-Browser (VPS, Plan 05)

1. profile.html?id=2 renders duyet's seeded cover/avatar/headline/location/about + 3 sections + a "Chưa kết nối"/degraded note (EXPECTED stub).
2. Login as duyet -> index "Hồ sơ của tôi" -> edit headline/about -> Lưu -> reload persists.
3. Add/delete an experience + education entry -> reflected on profile.html.
4. Add a skill, re-add same skill -> "Kỹ năng đã tồn tại" (409); delete a skill.
5. All text Vietnamese; no 3-column / navy branding (Phase 6).

## Self-Check: PASSED
- FOUND: web/profile.html
- FOUND: web/profile-edit.html
- FOUND commit 54b7e9f (Task 1)
- FOUND commit 01faec8 (Task 2)
