---
phase: 06-giao-di-n-proconnect
plan: 03
subsystem: web-ui
tags: [ui, proconnect, navbar, alpine, tailwind, brand, navy]
requires:
  - "window.proNav (Plan 06-01)"
  - ".pro-btn / .pro-* helpers + navy CSS vars (Plan 06-01, styles.css)"
  - "GET /api/me, /api/auth/*, /api/profiles/{id}/full, /api/profiles/me/*, /api/search, /api/connections/*"
provides:
  - "7 trang (index, login, register, profile, profile-edit, connections, search) dùng navbar chung proNav(active) + thương hiệu navy nhất quán"
  - "Toàn ứng dụng (8 trang gồm feed của Plan 02) đồng nhất brand navy + navbar chung — hoàn tất D-04"
affects:
  - web/index.html
  - web/login.html
  - web/register.html
  - web/profile.html
  - web/profile-edit.html
  - web/connections.html
  - web/search.html
tech-stack:
  added: []
  patterns:
    - "Inline tailwind.config navy token per-page (Play CDN, D-01) ngay sau Tailwind CDN"
    - "Navbar chung qua <div id=\"pronav\"></div> + DOMContentLoaded → window.proNav(active) (D-02)"
    - "Primary CTA dùng .pro-btn navy; badge trạng thái quan hệ giữ màu ngữ nghĩa (amber/green/slate)"
    - "Cache-bust REPLACE token đầy đủ → ?v=ph6-01 cho app.js + styles.css (D-05)"
key-files:
  created: []
  modified:
    - web/index.html
    - web/login.html
    - web/register.html
    - web/profile.html
    - web/profile-edit.html
    - web/connections.html
    - web/search.html
decisions:
  - "login/register dùng proNav('') — logged-out variant tự hiển thị Đăng nhập/Đăng ký; bỏ link chéo trong navbar vì proNav đã lo điều hướng (link chéo còn nằm trong thân login/register là không bắt buộc, không thêm lại)"
  - "profile.html nâng từ navbar rút gọn (chỉ Xin chào/Đăng xuất) lên navbar đầy đủ proNav('profile') — đồng nhất 7 trang"
  - "profile-edit.html có 3 nút primary Lưu (basic + inline sửa exp/edu) — tất cả chuyển .pro-btn; nút Thêm/Huỷ giữ border secondary"
metrics:
  duration: ~7min
  completed: 2026-06-07
  tasks: 3
  files: 7
---

# Phase 6 Plan 03: ProConnect Brand + Shared Navbar trên 7 trang còn lại Summary

Áp navbar dùng chung (`window.proNav` từ Plan 01) + thương hiệu navy `#1e3a8a` lên 7 trang còn lại (index, login, register, profile, profile-edit, connections, search). Mỗi trang: xoá `<nav>` cũ → `<div id="pronav"></div>` + gọi `proNav(active)`, thêm inline `tailwind.config` navy token sau Tailwind CDN, đổi nút primary sang `.pro-btn`, REPLACE token cache-bust cũ (`ph5-06` / `v1778837733`) → `?v=ph6-01`. Toàn bộ tính năng + endpoint giữ nguyên (restyle, không rewrite). Cùng với feed.html (Plan 02), cả 8 trang nay đồng nhất brand — hoàn tất D-04.

## What Was Built

**Task 1 — index + login + register (proNav('')):**
- `<head>`: inline `tailwind.config` navy token (DEFAULT #1e3a8a, dark #172f6b, light #3b5bbd) ngay sau Tailwind Play CDN; cache-bust `app.js?v=ph5-06` → `?v=ph6-01`, `styles.css?v1778837733` → `styles.css?v=ph6-01`.
- Xoá `<nav>` cũ (index dùng navbar() đầy đủ; login/register dùng navbar logo+1-link) → `<div id="pronav"></div>` + script `DOMContentLoaded → window.proNav('')` trước `</body>`. logged-out variant hiển thị Đăng nhập/Đăng ký.
- index hero "ProConnect — nền tảng đang được xây dựng" → "Chào mừng tới ProConnect" + mô tả mạng nghề nghiệp (tiếng Việt có dấu); giữ section "Hồ sơ của bạn" + logic indexPage.
- Nút primary navy: CTA index (Đăng nhập / Hồ sơ của tôi), nút submit Đăng nhập (login), Đăng ký (register) → `.pro-btn`; nút Đăng ký secondary giữ border. Chuỗi demo account giữ nguyên.

**Task 2 — profile + profile-edit (proNav('profile')):**
- Cùng head (navy token + cache-bust ph6-01).
- Xoá `<nav x-data="navbar()">` (profile.html nâng từ navbar rút gọn lên navbar đầy đủ) → `#pronav` + `proNav('profile')`.
- Nút navy: profile (Kết nối / Chấp nhận) → `.pro-btn`; profile-edit (3 nút Lưu: basic + inline sửa exp/edu) → `.pro-btn`. Badge trạng thái quan hệ (Đã kết nối/Đã gửi lời mời/Chờ phản hồi) giữ màu amber/green/slate ngữ nghĩa. Giữ container max-w-3xl (hồ sơ không cần 3 cột). Toàn bộ profilePage()/profileEditPage() logic + form CRUD giữ nguyên.

**Task 3 — connections + search (proNav('connections'|'search')):**
- Cùng head (navy token + cache-bust ph6-01).
- Xoá `<nav x-data="navbar()">` đầy đủ (search box + bell + invite badge nay đã nằm trong proNav, không mất chức năng) → `#pronav` + `proNav('connections')` / `proNav('search')`.
- Nút navy: connections (Chấp nhận / Kết nối) → `.pro-btn`; search (Tìm + 2 nút Kết nối branch none/unknown) → `.pro-btn`. Nút secondary (Từ chối/Huỷ lời mời/Xoá kết nối) giữ border. Ô tìm kiếm lớn trong thân search giữ nguyên (trang kết quả).
- Endpoint giữ nguyên: `api.get('/search?q=...')` (search), `/connections`, `/connections/requests`, `/connections/suggestions` (connections).

## Verification

Docker không cài cục bộ → xác minh tĩnh (grep + tidy HTML). Runtime/visual deferred to VPS/CI.

Full 7-page audit — tất cả PASS:
- `#pronav` + `proNav(active)` đúng mỗi trang (index/login/register='', profile/profile-edit='profile', connections='connections', search='search').
- inline `tailwind.config` + `navy:` token mỗi trang.
- cache-bust `app.js?v=ph6-01` + `styles.css?v=ph6-01`; KHÔNG còn token cũ `ph5-06` / `v1778837733`.
- `.pro-btn` mỗi trang; KHÔNG còn `<nav `, `x-data="navbar()"`, `bg-slate-900` trên nút primary.
- KHÔNG `x-html`, KHÔNG `linkedin`, `lang="vi"` mỗi trang.
- KHÔNG endpoint blog chết (`/api/posts|comments`); endpoint search + connections còn nguyên.
- `tidy -q -e` không báo error trên cả 7 trang (chỉ warning Alpine attr — bình thường).
- Source scope: `git diff HEAD~3 HEAD` chỉ liệt kê 7 file web/*.html — KHÔNG đụng backend.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Nút "Kết nối" branch `unknown` trong search.html bị bỏ sót ở lần replace_all đầu**
- **Found during:** Task 3 (verify phát hiện residual `bg-slate-900` ở search.html dòng 90)
- **Issue:** Hai nút "Kết nối" trong search.html có thụt lề khác nhau (branch `none` vs branch `unknown` lồng sâu hơn) nên một replace_all theo chuỗi chỉ khớp branch `none`, để sót branch `unknown` vẫn dùng `bg-slate-900`.
- **Fix:** Edit riêng nút branch `unknown` → `.pro-btn`; re-grep xác nhận không còn `bg-slate-900` trên cả connections + search.
- **Files modified:** web/search.html
- **Commit:** e20e677

## Known Stubs

None — đây là restyle thuần; mọi trang giữ nguồn dữ liệu + endpoint thật đã có từ các phase trước.

## Self-Check: PASSED
- web/index.html, login.html, register.html, profile.html, profile-edit.html, connections.html, search.html — FOUND
- Commits 397fa8d, fbb06f2, e20e677 — FOUND
