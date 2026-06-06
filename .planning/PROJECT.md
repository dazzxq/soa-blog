# ProConnect — Mạng xã hội nghề nghiệp (SOA showcase)

## What This Is

**ProConnect** là một nền tảng mạng xã hội nghề nghiệp (professional networking) theo phong cách LinkedIn — hồ sơ nghề nghiệp, kết nối giữa người dùng, news feed, tìm kiếm — nhưng có thương hiệu và thiết kế riêng. Đây là đồ án môn **INT1448 - Phát triển phần mềm hướng dịch vụ** (PTIT), nâng cấp từ project "SOA Blog" 3-service hiện có lên một hệ thống microservices phong phú hơn nhiều, nhằm showcase **kiến trúc microservices + API Gateway pattern** ở mức ấn tượng (giảng viên chê bản blog cũ quá đơn giản).

Đối tượng người dùng (trong bối cảnh demo): sinh viên / người đi làm muốn xây dựng hồ sơ nghề nghiệp, kết nối đồng nghiệp, chia sẻ và tương tác nội dung chuyên môn.

## Core Value

**API Gateway điều phối một hệ microservices đủ phong phú (profile, connection/graph, feed, search) sao cho thể hiện rõ ràng và thuyết phục các trách nhiệm cốt lõi của Gateway pattern** — routing, xác thực tập trung, API composition, đảm bảo invariant cross-service. Mọi tính năng LinkedIn-style đều là phương tiện để làm nổi bật điều này; nếu phải hy sinh, giữ lại tính minh hoạ rõ ràng của pattern hơn là độ đầy đủ tính năng.

## Requirements

### Validated

<!-- Đã ship & xác nhận hoạt động ở bản SOA Blog (brownfield base). -->

- ✓ API Gateway pattern: routing + path rewriting, JWT auth tập trung, rate limit, request-id, logging — existing (soa-blog)
- ✓ Database-per-service với DB user riêng từng schema, logical FK, no cross-DB physical FK — existing
- ✓ API Composition endpoint (gateway gọi nhiều service song song rồi gộp) — existing (`/api/posts/{id}/full`)
- ✓ Cross-service invariant enforcement ở gateway (404 nếu reference không tồn tại, 409 nếu vi phạm ràng buộc) — existing
- ✓ JWT register/login, services trust `X-User-Id` (gateway giữ secret) — existing
- ✓ Docker Compose multi-container + healthcheck, deploy CI/CD lên VPS qua GitHub Actions — existing
- ✓ Frontend tĩnh (Alpine.js + Tailwind) gọi gateway, JWT localStorage — existing (sẽ thay UI)
- ✓ Cloudflare TLS + origin protection (`soa.duyet.vn`) — existing

### Active

<!-- Scope v1 của milestone ProConnect. Hypotheses cho tới khi ship. -->

**Hồ sơ nghề nghiệp (Profile)**
- [ ] Người dùng có hồ sơ nghề nghiệp: ảnh đại diện, ảnh bìa, chức danh, tóm tắt (about)
- [ ] Người dùng thêm/sửa/xoá mục Kinh nghiệm làm việc (công ty, vị trí, thời gian)
- [ ] Người dùng thêm/sửa/xoá mục Học vấn
- [ ] Người dùng thêm/xoá Kỹ năng (skills)
- [ ] Xem hồ sơ công khai của người khác

**Kết nối (Connection / social graph)**
- [ ] Gửi lời mời kết nối tới người dùng khác
- [ ] Chấp nhận / từ chối lời mời kết nối
- [ ] Xem danh sách kết nối của mình
- [ ] Gợi ý người để kết nối (People you may know)
- [ ] Hiển thị trạng thái quan hệ (chưa kết nối / đã gửi lời mời / đã kết nối) trên hồ sơ

**News Feed**
- [ ] Đăng bài viết (text, tuỳ chọn ảnh URL)
- [ ] Feed hiển thị bài của bản thân + người đã kết nối (timeline)
- [ ] Thả reaction (like + vài loại cảm xúc) cho bài viết
- [ ] Bình luận bài viết
- [ ] Chia sẻ (repost) bài viết

**Tìm kiếm (Search)**
- [ ] Tìm người dùng theo tên / chức danh / kỹ năng
- [ ] Kết quả tìm kiếm hiển thị thẻ người dùng + nút kết nối nhanh

**Nền tảng / Gateway (xuyên suốt — trọng tâm môn học)**
- [ ] Gateway có endpoint composition mới ghép profile + experience + skills + trạng thái kết nối trong 1 request
- [ ] Gateway composition cho feed (bài + tác giả + reaction count + comment count)
- [ ] Notification tối thiểu (near-real-time qua polling): khi có lời mời kết nối / reaction / comment mới
- [ ] Toàn bộ UI + nội dung tiếng Việt có dấu, nhận diện thương hiệu ProConnect (navy #1e3a8a, logo mắt xích)

### Out of Scope

<!-- Ranh giới rõ ràng để tránh phình scope. -->

- **Jobs board (đăng/ứng tuyển việc làm)** — Hoãn sang v2. Là tính năng LinkedIn lớn nhưng không thêm nhiều giá trị showcase Gateway so với feed/connection; ưu tiên RAM VPS.
- **Messaging (nhắn tin 1-1 real-time)** — Hoãn v2. Real-time chat cần WebSocket/long-poll + service riêng nặng; vượt ngân sách 2GB RAM và không phải trọng tâm pattern.
- **Notification real-time bằng WebSocket** — Dùng polling near-real-time thay thế ở v1. WebSocket phức tạp, tốn tài nguyên.
- **Thương mại hoá / thanh toán / Premium** — Đây là đồ án môn học, không thương mại hoá.
- **Mobile app** — Chỉ web responsive.
- **Tải file ảnh trực tiếp (upload binary)** — v1 dùng URL ảnh; object storage/upload service hoãn lại để tiết kiệm hạ tầng.
- **Sao chép logo/tên/brand assets của LinkedIn** — Cố ý dùng thương hiệu riêng (ProConnect) để tránh vi phạm sở hữu trí tuệ; chỉ học theo các UX pattern chung của mạng nghề nghiệp.

## Context

- **Brownfield:** Nâng cấp từ repo `soa-blog` (github.com/dazzxq/soa-blog, public), đang chạy production tại `https://soa.duyet.vn`. Tài liệu kiến trúc hiện trạng đã có ở `.planning/codebase/` (STACK.md, ARCHITECTURE.md, INTEGRATIONS.md).
- **Tái dùng tối đa:** Gateway pattern, Guzzle `*Client`, Dockerfile/supervisord/nginx base, `Db.php`/`Json`/`DomainError` helper, CI/CD, deploy scripts, Cloudflare setup — đều tái dùng được. `user-service` sẽ tiến hoá thành `profile-service`; `post-service`/`comment-service` tái dùng cho feed.
- **Đội ngũ:** Nhóm 4 người — Nguyễn Thế Duyệt, Đinh Ngọc Long, Vũ Duy Điệp, Tạ Ngọc Tài. 5 tài khoản seed (demo/duyet/long/diep/tai, pass `demo@123**`).
- **Quy trình bắt buộc:** code local → `/codex-plan-review` duyệt plan → code → `/codex-impl-review` duyệt → commit → push public GitHub → VPS auto-pull qua CI/CD. KHÔNG commit khi chưa qua Codex review.
- **Mục tiêu học thuật:** Gây ấn tượng giảng viên về độ phong phú microservices + sự rõ ràng của API Gateway pattern (theo sách Chris Richardson — tài liệu chính của môn).

## Constraints

- **Tech stack**: PHP 8.2 + Slim 4 + Guzzle + firebase/php-jwt v7 + MariaDB 10.11 + Docker Compose — giữ nguyên (đội ngũ đã quen, VPS chạy ổn, brownfield). Frontend HTML + Alpine.js + Tailwind (có thể nâng cấp Tailwind nhưng giữ no-heavy-build).
- **Hạ tầng**: VPS `14.225.29.159`, Ubuntu 24.04, **2GB RAM CHIA SẺ** với nhiều site khác + MariaDB cùng host — Why: giới hạn cứng. Tổng số container phải ≤ ~9-10; mục tiêu v1 ~6-8 container. Mỗi service PHP ~30-50MB idle.
- **Deploy**: Cùng domain `soa.duyet.vn` qua Cloudflare (Full strict, origin chỉ nhận CF). CI/CD GitHub Actions hiện có.
- **Ngôn ngữ**: Toàn bộ UI + nội dung + error message tiếng Việt có dấu.
- **Pháp lý**: Không sao chép trademark/brand assets LinkedIn — Why: tránh vi phạm SHTT; thương hiệu riêng ProConnect.
- **Trọng tâm môn học**: Không được làm mờ API Gateway pattern — Why: đây là tiêu chí chấm điểm cốt lõi.

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Nâng cấp brownfield trên soa-blog thay vì viết mới | Tái dùng Gateway pattern + CI/CD + hạ tầng đã chạy production; tiết kiệm thời gian | — Pending |
| Thương hiệu riêng "ProConnect" (navy #1e3a8a, logo mắt xích) | Tránh vi phạm SHTT LinkedIn; vẫn giữ UX pattern mạng nghề nghiệp | ✓ Good |
| Scope v1 = cốt lõi LinkedIn (profile + connection + feed + search), hoãn jobs/messaging | Cân ngân sách 2GB RAM; 4 trụ này đủ showcase Gateway phong phú | — Pending |
| `user-service` tiến hoá thành `profile-service`; thêm `connection-service` (graph), `feed-service`, `search-service` | Mỗi bounded context 1 service — đúng tinh thần microservices; connection graph là use case kinh điển cho service riêng | — Pending |
| Notification dùng polling near-real-time, không WebSocket | WebSocket nặng RAM + phức tạp, không phải trọng tâm pattern | — Pending |
| Giữ MariaDB 1 instance nhiều schema (không tách instance) | Tiết kiệm RAM; isolation đã đảm bảo bằng DB user riêng | — Pending |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-06-06 after initialization (ProConnect milestone, brownfield from soa-blog)*
