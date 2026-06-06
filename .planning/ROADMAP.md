# Roadmap: ProConnect

## Overview

ProConnect nâng cấp brownfield từ "SOA Blog" (3 service) thành một mạng xã hội nghề nghiệp kiểu LinkedIn, giữ **API Gateway pattern** làm trọng tâm showcase. Hành trình đi từ **refactor nền tảng** (đổi `user-service` → `profile-service`, scaffold service + DB + route gateway mới, giữ site production sống) → **hồ sơ nghề nghiệp** (profile + composition endpoint đầu tiên) → **kết nối / social graph** (invariant cross-service kinh điển) → **news feed** (composition đọc-nặng ghép nhiều service) → **tìm kiếm + thông báo** (composition + polling) → **giao diện ProConnect** (3 cột, navy #1e3a8a, tiếng Việt có dấu). Mỗi phase tính năng đều bổ sung một endpoint composition hoặc invariant tại gateway để pattern luôn nổi bật, và mọi phase đều additive để `soa.duyet.vn` không gãy.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Nền tảng & Gateway** - Refactor brownfield: `user-service`→`profile-service`, scaffold service/DB/route mới, giữ stack ≤ 9 container và site production sống (completed 2026-06-06)
- [x] **Phase 2: Hồ sơ nghề nghiệp** - Profile đầy đủ (experience/education/skills) + endpoint composition profile tại gateway (completed 2026-06-06)
- [ ] **Phase 3: Kết nối / Social Graph** - Connection-service (lời mời, danh sách, gợi ý) + invariant graph cross-service tại gateway
- [ ] **Phase 4: News Feed** - Feed-service (bài, reaction, comment, repost) + composition feed ghép nhiều service
- [ ] **Phase 5: Tìm kiếm & Thông báo** - Tìm người dùng (composition) + notification near-real-time qua polling
- [ ] **Phase 6: Giao diện ProConnect** - Nhận diện thương hiệu navy #1e3a8a, layout 3 cột, navbar với badge, tiếng Việt có dấu

## Phase Details

### Phase 1: Nền tảng & Gateway
**Goal**: Hạ tầng microservices mở rộng sẵn sàng: `user-service` đã tiến hoá thành `profile-service`, các service mới (connection, feed, search, notification) + DB riêng được scaffold sau cùng một gateway, toàn stack khởi động sạch trong giới hạn 2GB RAM và site production vẫn sống.
**Depends on**: Nothing (first phase)
**Requirements**: PLAT-01, PLAT-02, PLAT-03, PLAT-04, PLAT-05, PLAT-06, PROF-01
**Success Criteria** (what must be TRUE):
  1. `docker compose up` khởi động sạch toàn stack mở rộng, mọi container healthy, tổng số container ≤ 9 (vừa ngân sách 2GB RAM)
  2. Gateway định tuyến `/api/*` tới đúng service mới (profile/connection/feed/search/notification), rewrite path nội bộ, JWT verify tập trung tại gateway, service nội bộ chỉ tin `X-User-Id` — không tự verify JWT
  3. Người dùng đăng ký và đăng nhập được như cũ; auth tái dùng/mở rộng từ `user-service` đã đổi tên thành `profile-service` mà không vỡ luồng JWT
  4. Gateway giữ rate limit, request-id, logging tập trung cho toàn bộ traffic mới
  5. Push `main` → CI/CD deploy lên `soa.duyet.vn` thành công, `/api/health` HTTPS xanh, site production không gãy (deploy additive)
**Plans**: 6 plans
Plans:
- [x] 01-01-PLAN.md — Wave 0: scripts/smoke-phase1.sh validation infra
- [x] 01-02-PLAN.md — Wave 1: git mv user-service→profile-service + ProfileClient
- [x] 01-03-PLAN.md — Wave 1: 4 stub services (connection/feed/search/notification) + 4 gateway clients
- [x] 01-04-PLAN.md — Wave 2: gateway re-wire (DI/routes/5-way health/X-Request-Id) + 8-container compose
- [x] 01-05-PLAN.md — Wave 1: DB provisioning (5 proconnect_* schemas + scoped users + reseed + idempotent migration)
- [x] 01-06-PLAN.md — Wave 3: CI/deploy + live-VPS cutover (autonomous: false)

### Phase 2: Hồ sơ nghề nghiệp
**Goal**: Người dùng có hồ sơ nghề nghiệp đầy đủ (ảnh đại diện/bìa, chức danh, vị trí, about, kinh nghiệm, học vấn, kỹ năng) chỉnh sửa được và xem công khai được hồ sơ người khác; gateway phục vụ hồ sơ đầy đủ qua MỘT endpoint composition.
**Depends on**: Phase 1
**Requirements**: PROF-02, PROF-03, PROF-04, PROF-05, PROF-06, PROF-07, UI-03
**Success Criteria** (what must be TRUE):
  1. Người dùng chỉnh sửa được hồ sơ: ảnh đại diện, ảnh bìa, chức danh, vị trí, tóm tắt (about)
  2. Người dùng thêm/sửa/xoá được mục Kinh nghiệm, Học vấn, và thêm/xoá được Kỹ năng; thay đổi hiển thị ngay trên trang hồ sơ
  3. Người dùng mở trang profile công khai của người khác và thấy ảnh bìa + ảnh đại diện + các section experience/education/skills
  4. **(Gateway demonstrated)** Gateway có endpoint composition trả về hồ sơ đầy đủ (cơ bản + experience + education + skills + trạng thái kết nối với người xem) trong MỘT request, ghép từ nhiều nguồn, degrade an toàn khi một phần lỗi
**Plans**: 5 plans
Plans:
- [x] 02-01-PLAN.md — Wave 0: db/02-migrate-phase2.sql (live additive migration) + smoke-phase2.sh, wired into deploy.sh
- [x] 02-02-PLAN.md — Wave 1: profile-service data layer (basic+exp+edu+skills CRUD, X-User-Id scoped) + /users/{id}/full assembly
- [x] 02-03-PLAN.md — Wave 2: gateway flagship /api/profiles/{id}/full composition (parallel settle+degrade) + OptionalJwtMiddleware + /me/* CRUD
- [x] 02-04-PLAN.md — Wave 3: UI profile.html (view via /full) + profile-edit.html (edit via /me/*) + index link (Vietnamese, minimal)
- [x] 02-05-PLAN.md — Wave 4: live cutover (CI/CD deploy + live-volume migration) + full smoke verify (autonomous: false)
**UI hint**: yes

### Phase 3: Kết nối / Social Graph
**Goal**: Người dùng xây dựng được mạng lưới: gửi/chấp nhận/từ chối lời mời kết nối, xem danh sách kết nối và lời mời đang chờ, nhận gợi ý "Người bạn có thể biết", và thấy đúng trạng thái quan hệ trên hồ sơ — với gateway chặn các invariant graph cross-service.
**Depends on**: Phase 2
**Requirements**: CONN-01, CONN-02, CONN-03, CONN-04, CONN-05, CONN-06, CONN-07
**Success Criteria** (what must be TRUE):
  1. Người dùng gửi lời mời kết nối, và người nhận chấp nhận hoặc từ chối được; sau khi chấp nhận, cả hai thấy nhau trong danh sách kết nối
  2. Người dùng xem được danh sách kết nối của mình và danh sách lời mời đang chờ (đã nhận / đã gửi)
  3. Trên hồ sơ người khác hiển thị đúng trạng thái quan hệ (chưa kết nối / đã gửi lời mời / chờ phản hồi / đã kết nối), và hệ thống gợi ý "Người bạn có thể biết"
  4. **(Gateway demonstrated)** Gateway chặn invariant cross-service cho graph: gửi lời mời tới user không tồn tại → 404; gửi trùng hoặc khi đã kết nối → 409 (kiểm tra profile-service trước khi ghi vào connection-service)
**Plans**: 5 plans
Plans:
- [x] 03-01-PLAN.md — Wave 0: db/03-migrate-phase3.sql (live additive connections table + demo seed) + smoke-phase3.sh, wired into deploy.sh
- [x] 03-02-PLAN.md — Wave 1: connection-service build-out (ConnectionController raw-PDO graph CRUD + viewer-relative status D-05, X-User-Id scoped) + routes
- [ ] 03-03-PLAN.md — Wave 2: gateway D-01 invariant (self/404/409/503-then-write) + enriched lists/suggestions (email allowlist) + ConnectionClient + routes/DI; lights up /full
- [ ] 03-04-PLAN.md — Wave 3: UI connections.html + profile.html relationship badge/actions (Vietnamese, minimal)
- [ ] 03-05-PLAN.md — Wave 4: live cutover (codex-impl-review + CI/CD deploy + live-volume migration + full smoke verify) (autonomous: false)
**UI hint**: yes

### Phase 4: News Feed
**Goal**: Người dùng đăng bài, xem timeline gồm bài của mình và người đã kết nối, thả reaction, bình luận và chia sẻ lại bài viết; gateway phục vụ feed qua một endpoint composition ghép bài + tác giả + số reaction + số comment + reaction của người xem.
**Depends on**: Phase 3
**Requirements**: FEED-01, FEED-02, FEED-03, FEED-04, FEED-05, FEED-06
**Success Criteria** (what must be TRUE):
  1. Người dùng đăng bài dạng text (tuỳ chọn kèm ảnh qua URL), và feed hiển thị timeline gồm bài của bản thân + người đã kết nối, sắp xếp mới nhất trước
  2. Người dùng thả reaction (like + một số cảm xúc, mỗi người một reaction), bình luận được bài viết
  3. Người dùng chia sẻ lại (repost) một bài viết lên feed của mình, hiển thị rõ nguồn gốc bài gốc
  4. **(Gateway demonstrated)** Gateway có endpoint composition feed: mỗi bài kèm thông tin tác giả, số reaction, số comment, và reaction của người xem — gộp song song từ nhiều service, degrade an toàn khi một phần lỗi
**Plans**: TBD
**UI hint**: yes

### Phase 5: Tìm kiếm & Thông báo
**Goal**: Người dùng tìm được người khác theo tên/chức danh/kỹ năng với kết quả kèm nút kết nối nhanh, và nhận thông báo near-real-time (qua polling) khi có lời mời kết nối / reaction / comment mới, đánh dấu đã đọc được.
**Depends on**: Phase 4
**Requirements**: SEARCH-01, SEARCH-02, NOTIF-01, NOTIF-02, NOTIF-03
**Success Criteria** (what must be TRUE):
  1. Người dùng tìm người dùng theo tên, chức danh hoặc kỹ năng; kết quả hiển thị thẻ người dùng (ảnh, tên, chức danh) kèm trạng thái quan hệ và nút kết nối nhanh
  2. Người dùng nhận thông báo khi có lời mời kết nối mới, reaction mới, hoặc comment mới trên bài của mình
  3. Giao diện cập nhật thông báo gần-thời-gian-thực bằng polling (badge số chưa đọc), không dùng WebSocket; người dùng đánh dấu thông báo đã đọc được
  4. **(Gateway demonstrated)** Kết quả tìm kiếm được gateway compose (gộp dữ liệu tìm kiếm với trạng thái quan hệ của người xem); thông báo được gateway điều phối/định tuyến tập trung
**Plans**: TBD
**UI hint**: yes

### Phase 6: Giao diện ProConnect
**Goal**: Toàn bộ ứng dụng mang nhận diện thương hiệu ProConnect (navy #1e3a8a, logo mắt xích, tagline) với layout feed 3 cột kiểu mạng nghề nghiệp, navbar có ô tìm kiếm + badge thông báo + badge lời mời + menu hồ sơ, responsive và toàn bộ chữ tiếng Việt có dấu.
**Depends on**: Phase 5
**Requirements**: UI-01, UI-02, UI-04, UI-05
**Success Criteria** (what must be TRUE):
  1. Toàn bộ giao diện mang nhận diện ProConnect (navy #1e3a8a, logo mắt xích, tagline) — tự thiết kế, không dùng brand asset LinkedIn
  2. Trang feed có bố cục 3 cột (thẻ hồ sơ trái, feed giữa, gợi ý/quảng cáo phải) và responsive trên màn hình nhỏ
  3. Thanh điều hướng có ô tìm kiếm, badge thông báo, badge lời mời kết nối, menu hồ sơ — tất cả nối đúng vào các tính năng đã build
  4. Toàn bộ chữ trong UI và error message là tiếng Việt có dấu
**Plans**: TBD
**UI hint**: yes

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Nền tảng & Gateway | 6/6 | Complete    | 2026-06-06 |
| 2. Hồ sơ nghề nghiệp | 5/5 | Complete    | 2026-06-06 |
| 3. Kết nối / Social Graph | 0/5 | Not started | - |
| 4. News Feed | 0/TBD | Not started | - |
| 5. Tìm kiếm & Thông báo | 0/TBD | Not started | - |
| 6. Giao diện ProConnect | 0/TBD | Not started | - |
