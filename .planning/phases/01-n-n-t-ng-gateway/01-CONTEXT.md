# Phase 1: Nền tảng & Gateway - Context

**Gathered:** 2026-06-06
**Status:** Ready for planning

<domain>
## Phase Boundary

Refactor brownfield `soa-blog` (3 service: user/post/comment) thành **nền microservices mở rộng được** cho ProConnect, giữ API Gateway pattern làm trọng tâm showcase:

- Đổi `user-service` → `profile-service` (giữ auth + dữ liệu tài khoản).
- **Retire** `post-service` + `comment-service` (blog cũ; feed-service sẽ thay ở Phase 4).
- Scaffold 4 service mới làm **stub**: `connection`, `feed`, `search`, `notification` + DB riêng mỗi service.
- Gateway: routing `/api/*` tới service mới + rewrite path, JWT verify tập trung, rate-limit, request-id (forward downstream), logging tập trung.
- Toàn stack khởi động sạch bằng `docker compose up`, mọi container healthy, **tổng ≤ 9 container** (mục tiêu thực tế = 8) trong ngân sách 2GB RAM.
- Deploy additive lên `soa.duyet.vn` qua CI/CD hiện có; register/login vẫn chạy (PROF-01).

**KHÔNG thuộc Phase 1:** business logic của connection/feed/search/notification (thuộc Phase 3/4/5), UI ProConnect (Phase 6), profile đầy đủ experience/education/skills (Phase 2).

**Requirements:** PLAT-01, PLAT-02, PLAT-03, PLAT-04, PLAT-05, PLAT-06, PROF-01.
</domain>

<decisions>
## Implementation Decisions

### Service Topology & Container Budget
- **D-01:** Retire `post-service` + `comment-service` ngay Phase 1. Blog cũ bị thay thế; giữ lại code 2 service này làm **tham khảo** cho Phase 4 (feed). Không giữ container chạy.
- **D-02:** Topology cuối Phase 1 = **8 container**: infra (`mariadb`, `gateway`, `web`) + services (`profile`, `connection`, `feed`, `search`, `notification`). Còn dư 1 slot dưới trần ≤9 → vừa ngân sách 2GB RAM.
- **D-03:** Scaffold **cả 4** service mới (`connection`, `feed`, `search`, `notification`) làm stub ngay Phase 1 — đúng tinh thần "nền mở rộng sẵn sàng"; các phase sau chỉ bơm logic.
- **D-04:** "Deploy additive / site không gãy" (PLAT-06, success criteria #5) định nghĩa = CI/CD deploy thành công + mọi container healthy + `/api/health` HTTPS xanh. **Chấp nhận** endpoint blog cũ (`/api/posts/*`, `/api/comments/*`) biến mất vì ProConnect thay thế blog trên cùng domain.

### Migration user-service → profile-service
- **D-05:** Dùng **git mv đổi tên tại chỗ**: `services/user-service` → `services/profile-service`; đổi container name, compose service, gateway client `UserClient`→`ProfileClient`, env `USER_SERVICE_URL`→`PROFILE_SERVICE_URL`. Giữ nguyên logic auth/user hiện có, mở rộng dần ở phase sau.
- **D-06:** DB = **wipe & reseed** (không migration giữ data). Drop schema cũ `blog_users`, tạo schema mới `proconnect_profile`, reseed 5 account demo (`demo`/`duyet`/`long`/`diep`/`tai`, pass `demo@123**`) từ `db/99-seed.sql`. Lý do: đây là đồ án, data demo tái tạo được; tránh migration mỏng manh trên live. Planner xử lý chủ động việc volume `mariadb_data` chỉ init khi mới (drop volume 1 lần khi deploy Phase 1, hoặc migration drop+create) — chọn cách an toàn nhất.
- **D-07:** Route surface gateway Phase 1: **giữ** `/api/auth/register`, `/api/auth/login`, `/api/me`; **đổi** `/api/users/{id}` → `/api/profiles/{id}` trả thông tin cơ bản (id, username, display_name). PROF-01 (register/login) bắt buộc sống.

### Service Stub Shape
- **D-08:** Mỗi stub là Slim app tối thiểu, chỉ endpoint `/health` trả `{status, db, ts}` **có check DB** (đúng pattern service hiện có). Chưa có route nghiệp vụ.
- **D-09:** Provision DB cho stub = tạo sẵn **schema rỗng + DB user scoped** cho từng service trong `db/00-init.sh`; **KHÔNG** tạo bảng nghiệp vụ (bảng được tạo ở phase của từng service). Giữ pattern database-per-service rõ từ đầu.
- **D-10:** Gateway `/api/health` **fan-out đủ 5 service** (profile+connection+feed+search+notification), báo trạng thái từng cái; mọi cái phải xanh (success criteria #1: "mọi container healthy").

### Gateway Wiring & DB Conventions
- **D-11:** Mỗi service có **typed client + controller riêng** (`ProfileClient`, `ConnectionClient`, `FeedClient`, `SearchClient`, `NotificationClient`) đúng pattern `UserClient`/`PostClient`/`CommentClient` hiện có. Stub client/controller giai đoạn này chỉ phục vụ health, nhưng đặt sẵn nền cho composition phase sau.
- **D-12:** **Bật forward `X-Request-Id`** từ gateway xuống mọi service qua Guzzle header ngay Phase 1 (ARCHITECTURE.md khuyên làm sớm khi graph còn nhỏ; tracing end-to-end; hợp PLAT-04 "logging tập trung").
- **D-13:** Quy ước DB: schema `proconnect_<svc>` (`proconnect_profile`, `proconnect_connection`, `proconnect_feed`, `proconnect_search`, `proconnect_notification`); DB user `<svc>_svc` (`profile_svc`, `connection_svc`, `feed_svc`, `search_svc`, `notification_svc`) với grant chỉ trên DB của mình.

### Claude's Discretion
- **Route path prefix** cho từng service (đề xuất: `/api/profiles`, `/api/connections`, `/api/feed`, `/api/search`, `/api/notifications`). Stub chưa lộ route nghiệp vụ nên prefix là "reserved"; chốt cụ thể khi mỗi phase build.
- **Cấu trúc thư mục stub**: tái dùng skeleton từ `services/user-service` (`public/index.php`, `src/routes.php`, `src/Db.php`, `Json`, `DomainError`, `JsonErrorHandler`, `Dockerfile`, `nginx.conf`, `supervisord.conf`).
- **Chi tiết healthcheck** (interval/timeout), container/compose service names cụ thể, supervisord/nginx config — tái dùng template hiện có, Claude chọn giá trị hợp lý.
- **Cách wipe volume trên VPS** cụ thể (drop `mariadb_data` một lần vs migration drop+create) — planner/researcher chọn cách an toàn, idempotent với CI/CD.

> **Ghi chú toàn quyền (user, 2026-06-06):** "thoải mái restructure như nào cũng được cho hợp lý, SOA hiện tại đổi tên hay làm gì cũng được, không cần bảo vệ nó." → Ưu tiên kiến trúc sạch nhất cho showcase; được phép xoá/đổi tên brownfield mạnh tay miễn giữ register/login sống và deploy xanh.
</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Kiến trúc & Stack hiện trạng (brownfield base)
- `.planning/codebase/ARCHITECTURE.md` — gateway pattern, composition (`AggregateController`), cross-service invariant, trust-by-network + X-User-Id, **Build Order Implications / Extension Points** (hướng dẫn thêm profile/connection/feed/search service).
- `.planning/codebase/STACK.md` — tech stack đầy đủ, reusable assets, gateway/service skeleton.
- `.planning/codebase/INTEGRATIONS.md` — database-per-service + scoped users (`db/00-init.sh`), JWT HS256 gateway-only, CI/CD GitHub Actions→VPS, Cloudflare edge, env vars.

### Vision & Requirements
- `.planning/PROJECT.md` — Core Value (showcase Gateway pattern), constraints (2GB RAM, ≤9 container, tiếng Việt), Key Decisions (post/comment tái dùng cho feed, polling thay WebSocket, 1 MariaDB nhiều schema).
- `.planning/REQUIREMENTS.md` — định nghĩa PLAT-01..06, PROF-01 và bảng phân bổ phase.
- `.planning/ROADMAP.md` §"Phase 1" — goal + 5 success criteria.

### Code cần đọc/tái dùng khi implement
- `gateway/src/Services/HttpClient.php`, `UserClient.php`, `PostClient.php`, `CommentClient.php` — pattern typed client (clone cho 5 service mới).
- `gateway/src/routes.php`, `gateway/public/index.php` — đăng ký route + DI wiring (nơi thêm route/client mới).
- `gateway/src/Middleware/JwtAuthMiddleware.php`, `RateLimitMiddleware.php`, `RequestIdMiddleware.php`, `LoggingMiddleware.php` — cross-cutting tái dùng; RequestId là nơi thêm forward downstream (D-12).
- `gateway/src/Controllers/AuthController.php` — auth register/login/me (PROF-01).
- `services/user-service/` — **skeleton để clone** cho profile + 4 stub (`public/index.php`, `src/routes.php`, `src/Db.php`, `src/Json.php`, `src/DomainError.php`, `src/JsonErrorHandler.php`, `Dockerfile`, `nginx.conf`, `supervisord.conf`).
- `db/00-init.sh`, `db/01-schema-users.sql`, `db/99-seed.sql` — provisioning + seed (nơi thêm 5 schema mới + đổi sang `proconnect_profile`).
- `docker-compose.yml` — định nghĩa service/container (thêm 4 service mới, đổi user→profile, xoá post/comment).
- `scripts/deploy.sh`, `.github/workflows/deploy.yml` — deploy + smoke test `/api/health` (PLAT-06).
- `deploy/nginx-soa.duyet.vn.conf` — host edge nginx (Cloudflare gate).
</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Gateway skeleton** (Slim 4 + PHP-DI): `gateway/public/index.php` — fail-fast `JWT_SECRET`, DI container, middleware stack. Mở rộng thêm client/route/controller mới.
- **Service skeleton** (raw PDO, không ORM): `services/user-service/*` — clone nguyên cho `profile` + 4 stub. Db.php = lazy PDO singleton, prepared statements thật.
- **Typed HTTP client pattern**: `HttpClient::create($baseUri)` (Guzzle, `http_errors=false`, connect 2s / timeout 5s, header `X-Forwarded-By`, biến thể `*Async` cho composition). Mỗi service 1 client.
- **Cross-cutting middleware**: Jwt (verify tập trung), RateLimit (per-IP, file-backed `/tmp`), RequestId (ramsey/uuid), Logging (Monolog). Tái dùng nguyên cho traffic mới (PLAT-03, PLAT-04).
- **Helpers**: `Json::ok/list/raw`, `DomainError` (code + message tiếng Việt), `JsonErrorHandler` — mirror sẵn ở mỗi service.
- **Packaging**: `Dockerfile` (php:8.2-fpm-alpine) + `supervisord.conf` (nginx+php-fpm) + `nginx.conf` — 3 bản giống hệt, clone cho service mới.
- **DB provisioning**: `db/00-init.sh` tạo schema + scoped user; chạy khi volume init.
- **CI/CD**: `.github/workflows/deploy.yml` (lint `php -l` → SSH deploy → smoke `/api/health`).

### Established Patterns (giữ nguyên)
- **Database-per-service**: schema riêng + DB user scoped, logical FK, no cross-DB physical FK.
- **Gateway-centric composition & invariant**: services "dumb" về nhau; join/invariant ở gateway.
- **Trust-by-network + X-User-Id**: chỉ gateway biết `JWT_SECRET`; service tin header `X-User-Id`.
- **Health contract**: service trả `{status, db, ts}` (check DB); gateway `/api/health` fan-out + trả `{status, services, ts}`.

### Integration Points (nơi code mới cắm vào)
- `gateway/src/routes.php` + `gateway/public/index.php` — thêm route + DI cho 5 client/controller.
- `db/00-init.sh` + file schema mới — thêm 5 schema `proconnect_*` + user `*_svc`.
- `docker-compose.yml` — thêm 4 service mới, rename user→profile, gỡ post/comment, inject `*_SERVICE_URL`.
- `gateway/src/Middleware/RequestIdMiddleware.php` + clients — forward `X-Request-Id` downstream (D-12).
</code_context>

<specifics>
## Specific Ideas

- **5 account demo** (giữ qua reseed): `demo`, `duyet`, `long`, `diep`, `tai`; password `demo@123**`.
- **Brand**: ProConnect, navy `#1e3a8a`, logo mắt xích — áp dụng UI ở Phase 6, không phải Phase 1.
- **Quy trình bắt buộc (CLAUDE.md / PROJECT.md):** code local → `/codex-plan-review` duyệt plan → code → `/codex-impl-review` duyệt → commit → push public GitHub → VPS auto-pull qua CI/CD. KHÔNG commit code khi chưa qua Codex review.
- **Constraint cứng:** ≤9 container, mỗi PHP service ~30-50MB idle, 2GB RAM chia sẻ.
</specifics>

<deferred>
## Deferred Ideas

- **Business logic** của connection / feed / search / notification — thuộc Phase 3 / 4 / 5 tương ứng (Phase 1 chỉ stub).
- **Profile đầy đủ** (ảnh bìa, chức danh, experience/education/skills + composition endpoint) — Phase 2.
- **Notification real-time / WebSocket** — Out of Scope; dùng polling ở Phase 5.
- **Object storage / upload binary** — Out of Scope v1; `avatar_url` vẫn là chuỗi URL.
- **Redis / cache dùng chung** — chưa cần; rate-limit vẫn file-backed per-gateway.
- **Tách MariaDB nhiều instance** — giữ 1 instance nhiều schema (Key Decision PROJECT.md).
- **PHPUnit/Pest test framework** — chưa có; STACK.md gợi ý thêm trước refactor lớn, nhưng không bắt buộc Phase 1 (cân nhắc ở researcher).

*Không có scope creep phát sinh — thảo luận giữ trong ranh giới phase.*
</deferred>

---

*Phase: 01-n-n-t-ng-gateway*
*Context gathered: 2026-06-06*
