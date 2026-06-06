# Requirements: ProConnect

**Defined:** 2026-06-06
**Core Value:** API Gateway điều phối một hệ microservices đủ phong phú (profile, connection/graph, feed, search) sao cho thể hiện rõ ràng và thuyết phục các trách nhiệm cốt lõi của Gateway pattern.

## v1 Requirements

Requirements cho bản phát hành đầu của milestone ProConnect. Mỗi REQ map tới đúng một phase trong roadmap.

### Nền tảng & Gateway (PLAT)

- [x] **PLAT-01**: Hệ thống chạy thêm các microservice mới (profile, connection, feed, search) sau cùng một API Gateway, mỗi service có database riêng
- [x] **PLAT-02**: Gateway định tuyến `/api/*` tới đúng service và rewrite path nội bộ (giữ pattern hiện có)
- [x] **PLAT-03**: Xác thực JWT tập trung tại gateway; service nội bộ chỉ tin header `X-User-Id`, không tự verify JWT
- [x] **PLAT-04**: Gateway giữ rate limit, request-id, logging tập trung cho toàn bộ traffic mới
- [x] **PLAT-05**: Toàn bộ stack mới khởi động sạch bằng `docker compose up`, mọi container healthy, tổng số container ≤ 9 (giới hạn 2GB RAM)
- [ ] **PLAT-06**: Deploy lên `soa.duyet.vn` qua CI/CD hiện có (push main → GitHub Actions → VPS), truy cập HTTPS hợp lệ

### Tài khoản & Hồ sơ (PROF)

- [x] **PROF-01**: Người dùng đăng ký tài khoản và đăng nhập (tái dùng/ mở rộng auth hiện có)
- [x] **PROF-02**: Người dùng có hồ sơ nghề nghiệp gồm ảnh đại diện, ảnh bìa, chức danh, vị trí, tóm tắt (about) — chỉnh sửa được
- [x] **PROF-03**: Người dùng thêm/sửa/xoá các mục Kinh nghiệm làm việc (công ty, vị trí, mốc thời gian, mô tả)
- [x] **PROF-04**: Người dùng thêm/sửa/xoá các mục Học vấn (trường, ngành, mốc thời gian)
- [x] **PROF-05**: Người dùng thêm/xoá danh sách Kỹ năng
- [x] **PROF-06**: Người dùng xem hồ sơ công khai của người khác qua trang profile riêng
- [x] **PROF-07**: Gateway có endpoint composition trả về hồ sơ đầy đủ (thông tin cơ bản + experience + education + skills + trạng thái kết nối với người xem) trong một request

### Kết nối / Social Graph (CONN)

- [x] **CONN-01**: Người dùng gửi lời mời kết nối tới người dùng khác
- [x] **CONN-02**: Người dùng chấp nhận hoặc từ chối lời mời kết nối đến
- [x] **CONN-03**: Người dùng xem danh sách kết nối của mình
- [x] **CONN-04**: Người dùng xem danh sách lời mời đang chờ (đã nhận / đã gửi)
- [x] **CONN-05**: Trên hồ sơ người khác hiển thị đúng trạng thái quan hệ (chưa kết nối / đã gửi lời mời / chờ phản hồi / đã kết nối)
- [x] **CONN-06**: Hệ thống gợi ý "Người bạn có thể biết" (gợi ý kết nối) cho người dùng
- [x] **CONN-07**: Gateway chặn invariant cross-service hợp lý cho graph (vd không gửi lời mời tới user không tồn tại → 404; không gửi trùng/khi đã kết nối → 409)

### News Feed (FEED)

- [x] **FEED-01**: Người dùng đăng bài viết dạng text, tuỳ chọn kèm ảnh qua URL
- [x] **FEED-02**: Feed hiển thị timeline gồm bài của bản thân và những người đã kết nối, sắp xếp mới nhất
- [x] **FEED-03**: Người dùng thả reaction (like + một số loại cảm xúc) cho bài viết, mỗi người một reaction
- [x] **FEED-04**: Người dùng bình luận bài viết
- [x] **FEED-05**: Người dùng chia sẻ lại (repost) một bài viết lên feed của mình
- [x] **FEED-06**: Gateway có endpoint composition cho feed: mỗi bài kèm thông tin tác giả, số reaction, số comment, reaction của người xem — gộp từ nhiều service

### Tìm kiếm (SEARCH)

- [ ] **SEARCH-01**: Người dùng tìm người dùng theo tên, chức danh hoặc kỹ năng
- [ ] **SEARCH-02**: Kết quả tìm kiếm hiển thị thẻ người dùng (ảnh, tên, chức danh) kèm nút kết nối nhanh và trạng thái quan hệ

### Thông báo (NOTIF)

- [ ] **NOTIF-01**: Người dùng nhận thông báo khi có lời mời kết nối mới, reaction mới, hoặc comment mới trên bài của mình
- [ ] **NOTIF-02**: Giao diện cập nhật thông báo gần-thời-gian-thực bằng polling (badge số lượng chưa đọc), không dùng WebSocket
- [ ] **NOTIF-03**: Người dùng đánh dấu thông báo đã đọc

### Giao diện & Thương hiệu (UI)

- [ ] **UI-01**: Toàn bộ giao diện mang nhận diện ProConnect (navy #1e3a8a, logo mắt xích, tagline) — tự thiết kế, không dùng brand asset LinkedIn
- [ ] **UI-02**: Trang feed bố cục 3 cột kiểu mạng nghề nghiệp (thẻ hồ sơ trái, feed giữa, gợi ý/quảng cáo phải) — responsive
- [x] **UI-03**: Trang hồ sơ có ảnh bìa + ảnh đại diện + các section experience/education/skills
- [ ] **UI-04**: Thanh điều hướng có ô tìm kiếm, badge thông báo, badge lời mời kết nối, menu hồ sơ
- [ ] **UI-05**: Toàn bộ chữ tiếng Việt có dấu

## v2 Requirements

Hoãn sang phiên bản sau — theo dõi nhưng chưa vào roadmap.

### Jobs (JOB)
- **JOB-01**: Nhà tuyển dụng đăng tin việc làm
- **JOB-02**: Người dùng ứng tuyển / lưu tin việc làm

### Messaging (MSG)
- **MSG-01**: Nhắn tin 1-1 giữa các kết nối
- **MSG-02**: Trạng thái đã đọc / đang gõ

### Media (MEDIA)
- **MEDIA-01**: Upload ảnh trực tiếp (object storage) thay vì URL

## Out of Scope

| Feature | Reason |
|---------|--------|
| Jobs board | Hoãn v2 — không thêm nhiều giá trị showcase Gateway so với feed/connection; ưu tiên RAM |
| Messaging real-time | Hoãn v2 — WebSocket/long-poll nặng RAM, vượt ngân sách 2GB, không phải trọng tâm pattern |
| Notification qua WebSocket | Dùng polling thay thế ở v1 — WebSocket phức tạp & tốn tài nguyên |
| Upload ảnh binary | v1 dùng URL ảnh — object storage hoãn để tiết kiệm hạ tầng |
| Thanh toán / Premium | Đồ án môn học, không thương mại hoá |
| Mobile app | Chỉ web responsive |
| Brand assets của LinkedIn | Cố ý dùng thương hiệu riêng — tránh vi phạm SHTT |

## Traceability

Cập nhật bởi roadmapper sau khi tạo ROADMAP.md (2026-06-06).

| Requirement | Phase | Status |
|-------------|-------|--------|
| PLAT-01 | Phase 1 | Complete |
| PLAT-02 | Phase 1 | Complete |
| PLAT-03 | Phase 1 | Complete |
| PLAT-04 | Phase 1 | Complete |
| PLAT-05 | Phase 1 | Complete |
| PLAT-06 | Phase 1 | Pending |
| PROF-01 | Phase 1 | Complete |
| PROF-02 | Phase 2 | Complete |
| PROF-03 | Phase 2 | Complete |
| PROF-04 | Phase 2 | Complete |
| PROF-05 | Phase 2 | Complete |
| PROF-06 | Phase 2 | Complete |
| PROF-07 | Phase 2 | Complete |
| UI-03 | Phase 2 | Complete |
| CONN-01 | Phase 3 | Complete |
| CONN-02 | Phase 3 | Complete |
| CONN-03 | Phase 3 | Complete |
| CONN-04 | Phase 3 | Complete |
| CONN-05 | Phase 3 | Complete |
| CONN-06 | Phase 3 | Complete |
| CONN-07 | Phase 3 | Complete |
| FEED-01 | Phase 4 | Complete |
| FEED-02 | Phase 4 | Complete |
| FEED-03 | Phase 4 | Complete |
| FEED-04 | Phase 4 | Complete |
| FEED-05 | Phase 4 | Complete |
| FEED-06 | Phase 4 | Complete |
| SEARCH-01 | Phase 5 | Pending |
| SEARCH-02 | Phase 5 | Pending |
| NOTIF-01 | Phase 5 | Pending |
| NOTIF-02 | Phase 5 | Pending |
| NOTIF-03 | Phase 5 | Pending |
| UI-01 | Phase 6 | Pending |
| UI-02 | Phase 6 | Pending |
| UI-04 | Phase 6 | Pending |
| UI-05 | Phase 6 | Pending |

**Coverage:**
- v1 requirements: 36 total
- Mapped to phases: 36 ✓
- Unmapped: 0 ✓

**Phân bổ theo phase:** Phase 1 = 7 (PLAT-01..06, PROF-01) · Phase 2 = 7 (PROF-02..07, UI-03) · Phase 3 = 7 (CONN-01..07) · Phase 4 = 6 (FEED-01..06) · Phase 5 = 5 (SEARCH-01..02, NOTIF-01..03) · Phase 6 = 4 (UI-01, UI-02, UI-04, UI-05)

---
*Requirements defined: 2026-06-06*
*Last updated: 2026-06-06 after roadmap creation (traceability mapped, 36/36 covered)*
