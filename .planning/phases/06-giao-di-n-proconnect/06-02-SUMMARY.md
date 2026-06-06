---
phase: 06-giao-di-n-proconnect
plan: 02
subsystem: web-ui
tags: [ui, feed, proconnect, alpine, tailwind, responsive]
requires:
  - "window.proNav (Plan 06-01)"
  - "GET /api/me, /api/feed, /api/connections/suggestions, /api/connections/requests"
provides:
  - "feed.html: trang bảng tin 3 cột responsive mang thương hiệu ProConnect"
affects:
  - web/feed.html
tech-stack:
  added: []
  patterns:
    - "Inline tailwind.config navy token per-page (Play CDN, D-01)"
    - "Responsive 3-col grid lg:grid-cols-[260px_minmax(0,1fr)_300px], center order-1 on mobile (D-03)"
    - "Best-effort side-column fetch (try/catch riêng) — suggestions/me lỗi không chặn timeline"
key-files:
  created: []
  modified:
    - web/feed.html
decisions:
  - "Task 1+2 commit gộp làm một (cùng một file feed.html, hai task không tách được thành hai diff sạch) — deviation có chủ đích, ghi nhận dưới đây"
metrics:
  duration: ~6min
  completed: 2026-06-07
  tasks: 2
  files: 1
---

# Phase 6 Plan 02: Feed 3-Column ProConnect Layout Summary

feed.html restyle thành layout mạng nghề nghiệp 3 cột (trái thẻ hồ sơ /me, giữa compose+timeline nguyên bản, phải gợi ý kết nối + thẻ tĩnh "Về ProConnect") dùng navbar chung proNav('feed'), navy inline tailwind.config, cache-bust ?v=ph6-01; responsive xếp dọc với timeline hiện trước trên mobile.

## What Was Built

**Task 1 — Navbar chung + khung 3 cột (web/feed.html):**
- `<head>`: thêm inline `tailwind.config` navy token (DEFAULT #1e3a8a, dark #172f6b, light #3b5bbd) ngay sau Tailwind Play CDN; REPLACE token cache-bust cũ `app.js?v=ph5-06` → `?v=ph6-01` và `styles.css?v1778837733` → `styles.css?v=ph6-01` (không còn token cũ).
- Xoá toàn bộ `<nav x-data="navbar()">` cũ; thay bằng `<div id="pronav"></div>` + script `DOMContentLoaded → window.proNav('feed')` trước `</body>`.
- `<main>` đổi sang `max-w-5xl`, bọc nội dung trong grid `grid-cols-1 lg:grid-cols-[260px_minmax(0,1fr)_300px]`; cột giữa `order-1` (hiện trước trên mobile), cột trái `order-2 lg:order-1`, cột phải `order-3 hidden lg:block`.
- Các nút primary (Đăng, Gửi bình luận, reaction active) chuyển sang class `.pro-btn` navy.

**Task 2 — Cột trái + cột phải:**
- `feedPage()` thêm state `me`, `suggestions`; trong `load()` sau khi tải feed, fetch best-effort `/me` và `/connections/suggestions` (slice 5) trong try/catch riêng để lỗi không vỡ timeline.
- Cột trái: thẻ hồ sơ (banner pro-surface, avatar có fallback chữ cái, tên, headline, nút "Xem hồ sơ" → profile.html). Mọi field qua x-text.
- Cột phải: "Người bạn có thể biết" render từ suggestions (avatar + tên + nút "Kết nối" gọi `invite()` → POST /api/connections/requests) + thẻ tĩnh "Về ProConnect".
- Thêm method `invite(id)` tái dùng `_act` (refresh sau khi gửi).

## Deviations from Plan

### Process deviation

**1. [Process] Gộp Task 1 và Task 2 vào một commit**
- **Lý do:** Cả hai task chỉ sửa duy nhất `web/feed.html`; các thay đổi đan xen trong cùng các hunk (state `suggestions` của Task 2 nằm cùng vùng compose của Task 1, markup cột phải nối liền grid của Task 1). Không thể tách thành hai diff sạch atomically mà không phẫu thuật hunk giả tạo.
- **Xử lý:** Một commit `feat(06-02)` mô tả đầy đủ cả hai task. Mọi acceptance-criteria của cả hai task đều xanh trước commit.
- **Commit:** eb668d8

Ngoài việc trên, plan thực thi đúng như viết. Không auto-fix bug nào (Rule 1-3 không kích hoạt), không file backend bị đụng.

## Verification

Static (21/21 PASS): id="pronav", proNav('feed'), navbar cũ đã xoá, lg:grid-cols, order-1, app.js?v=ph6-01, styles.css?v=ph6-01, không còn token cũ (ph5-06 / v1778837733), tailwind.config + navy:, lang="vi", không LinkedIn, connections/suggestions, "Người bạn có thể biết", "Về ProConnect", "Xem hồ sơ", api.get('/me'), không x-html, order- ≥2, không endpoint blog chết. Tag balance: main 1/1, aside 2/2, grid mở/đóng khớp.

**Docker/runtime/visual verify deferred to VPS/CI** (Docker không cài local). Plan 06-04 (visual) sẽ xác nhận 3 cột desktop + stack mobile bằng mắt thường.

## Known Stubs

Thẻ tĩnh "Về ProConnect" là slot quảng cáo demo có chủ đích (D-03 "ad/quảng cáo" slot) — nội dung tĩnh, không cần data source. Không phải stub cần wiring.

## Threat Surface

Không có surface bảo mật mới ngoài threat_model của plan. Tất cả render user qua x-text (T-06-06 mitigate), side-column degrade an toàn (T-06-07 mitigate), không asset LinkedIn (T-06-08 mitigate), chỉ web/feed.html sửa (T-06-09 accept).

## Self-Check: PASSED

- FOUND: web/feed.html
- FOUND commit: eb668d8
