# Phase 2: Hồ sơ nghề nghiệp - Context

**Gathered:** 2026-06-06
**Status:** Ready for planning
**Note:** User delegated implementation decisions ("cứ suggest sao cho fit nhu cầu demo microservices cho đồ án môn học, keep simple"). Decisions below are Claude-recommended defaults optimised for **showcasing the API Gateway pattern simply and clearly**, locked unless the user objects before planning.

<domain>
## Phase Boundary

Người dùng có hồ sơ nghề nghiệp đầy đủ (ảnh đại diện/bìa, chức danh, vị trí, about, experience/education/skills) **chỉnh sửa được**, **xem công khai** hồ sơ người khác, và **gateway phục vụ hồ sơ đầy đủ qua MỘT endpoint composition** — đây là endpoint composition đầu tiên của ProConnect (AggregateController cũ đã gỡ ở Phase 1).

Requirements: PROF-02, PROF-03, PROF-04, PROF-05, PROF-06, PROF-07, UI-03.

**NGOÀI scope:** business logic connection thực (Phase 3), branding/layout 3 cột (Phase 6), feed (Phase 4), upload ảnh binary (out-of-scope v1).
</domain>

<decisions>
## Implementation Decisions

### A. Composition Endpoint — the showcase centerpiece
- **D-01:** Gateway thực hiện **composition thật** (không passthrough). Endpoint **`GET /api/profiles/{id}/full`** là "flagship" composition của ProConnect. Gateway gọi **song song** (Guzzle `*Async` + `settle`, đúng pattern AggregateController cũ) tới **≥2 nguồn** rồi gộp thành MỘT response:
  - `profile-service` → hồ sơ đầy đủ (basic + experience + education + skills).
  - `connection-service` → trạng thái kết nối với người xem.
- **D-02:** **Degrade an toàn** (success criterion #4): nếu một nguồn lỗi/timeout/chưa sẵn sàng, gateway vẫn trả phần còn lại + `meta.degraded: true` thay vì fail toàn bộ.
- **D-03:** **Xử lý connection-service còn stub (cross-phase):** Phase 2 VẪN gọi connection-service cho `connection_status`, nhưng vì nó là stub (chưa có data) → degrade → `connection_status: "none"` + `meta.degraded`. **Lợi ích:** (1) code composition + degrade dựng ngay Phase 2 (đúng là phần demo của criterion #4 — "degrade khi một phần lỗi"); (2) Phase 3 dựng connection-service xong thì endpoint **tự sáng** với status thật, **không phải sửa gateway**. Không hoãn field, không stub giả.
- **D-04:** `/api/profiles/{id}/full` **public-readable nhưng auth-aware**: JWT optional. Có token → biết "người xem" → kèm `connection_status` (so với người xem). Không token → `connection_status: null`. Hồ sơ cơ bản luôn xem được công khai (PROF-07).

### B. Data Model (tất cả trong `proconnect_profile`, logical FK `user_id`, theo pattern raw-PDO hiện có)
- **D-05:** Mở rộng bảng `users` thêm cột: `cover_url VARCHAR(512) NULL`, `headline VARCHAR(160) NULL`, `location VARCHAR(128) NULL`, `about TEXT NULL`. (Giữ trong `users` cho đơn giản — 1 service, 1 bounded context; không tách bảng `profiles` 1-1 để tránh join thừa.)
- **D-06:** Bảng mới `experience` (`id, user_id, company VARCHAR(160), title VARCHAR(160), start_date DATE, end_date DATE NULL, description TEXT NULL`). `end_date NULL` = "hiện tại".
- **D-07:** Bảng mới `education` (`id, user_id, school VARCHAR(160), degree VARCHAR(160) NULL, field VARCHAR(160) NULL, start_year SMALLINT NULL, end_year SMALLINT NULL`).
- **D-08:** Bảng mới `skills` (`id, user_id, name VARCHAR(80)`, UNIQUE(user_id, name)). **List đơn giản, KHÔNG endorsement** (giữ đơn giản; endorsement không thêm giá trị showcase Gateway).
- **D-09:** Ảnh đại diện + ảnh bìa = **URL string** (kế thừa Phase 1 / PROJECT.md: v1 không upload binary). Người dùng dán URL.

### C. Editing Model (CRUD endpoints — owner-only)
- **D-10:** Endpoint **granular, RESTful, dùng `/me`** (tránh IDOR — không sửa người khác qua id). Tại gateway:
  - `PATCH /api/profiles/me` — sửa basic (display_name, avatar_url, cover_url, headline, location, about).
  - `POST | PATCH | DELETE /api/profiles/me/experience[/{id}]`
  - `POST | PATCH | DELETE /api/profiles/me/education[/{id}]`
  - `POST | DELETE /api/profiles/me/skills[/{id}]` (skills chỉ thêm/xoá, không sửa).
- **D-11:** Auth: gateway verify JWT, set `X-User-Id`; profile-service tin header, **scope mọi ghi theo `X-User-Id`** (không nhận user_id từ body). Người dùng chỉ sửa hồ sơ của chính mình.
- **D-12:** profile-service internal mirror các route trên dưới `/users/{id}/experience|education|skills` (services "dumb", gateway map `/me`→id). Giữ pattern Json/DomainError tiếng Việt hiện có.

### D. UI Scope (UI-03)
- **D-13:** UI Phase 2 = **chức năng, style tối thiểu** (Alpine.js + Tailwind CDN như Phase 1; KHÔNG branding navy / layout 3 cột — để Phase 6). Hai màn:
  - `profile.html` — xem hồ sơ (của mình hoặc người khác) qua `GET /api/profiles/{id}/full`: cover+avatar+headline+location+about + sections experience/education/skills; hiện `connection_status` nếu có (Phase 2 sẽ là "none"/degraded).
  - `profile-edit.html` (hoặc chế độ edit trên profile.html) — sửa basic + thêm/sửa/xoá experience/education + thêm/xoá skills; dán URL ảnh. Tiếng Việt có dấu.
- **D-14:** Liên kết tối thiểu từ `index.html` (Phase 1) → trang profile của mình sau khi đăng nhập.

### Claude's Discretion
- Tên cụt thể các route nội bộ, format JSON section, thứ tự field hiển thị, validation cụ thể (độ dài, định dạng URL), index DB phụ — Claude chọn hợp lý khi plan/implement.
- Cách tổ chức profile.html/profile-edit.html (1 file 2 mode vs 2 file) — Claude chọn.
</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 1 nền tảng (kế thừa)
- `.planning/phases/01-n-n-t-ng-gateway/01-CONTEXT.md` — D-01..D-13 Phase 1 (typed client, X-User-Id trust, proconnect_* naming, image-as-URL, keep-simple, 5 service).
- `.planning/phases/01-n-n-t-ng-gateway/01-VERIFICATION.md` — trạng thái đã ship (8 container live).
- `.planning/codebase/ARCHITECTURE.md` — composition/degrade pattern (settle, meta.degraded), extension points.

### Vision & Requirements
- `.planning/PROJECT.md` — Core Value (showcase Gateway: routing + auth + **composition** + invariant), out-of-scope (no binary upload), keep-simple.
- `.planning/REQUIREMENTS.md` — PROF-02..07, UI-03.
- `.planning/ROADMAP.md` §"Phase 2" — goal + 4 success criteria.

### Code cần đọc/sửa khi implement
- `db/01-schema-profile.sql` — bảng `users` hiện tại (thêm cột + 3 bảng mới ở đây).
- `services/profile-service/src/routes.php`, `src/Controllers/UserController.php`, `src/Db.php` — thêm CRUD experience/education/skills + mở rộng user.
- `gateway/src/routes.php`, `gateway/public/index.php` — thêm route `/api/profiles/{id}/full` + `/api/profiles/me/*` + DI.
- `gateway/src/Controllers/ProfilesController.php` — mở rộng (composition + CRUD passthrough).
- `gateway/src/Services/ProfileClient.php`, `ConnectionClient.php` — thêm method cho profile-full + experience/education/skills + connection-status; dùng `*Async` cho composition.
- `web/index.html`, `web/assets/app.js` — thêm `profile.html` / `profile-edit.html`.
- Tham khảo pattern composition cũ đã gỡ: `git show <phase-1-parent>:gateway/src/Controllers/AggregateController.php` (settle + degrade).
</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **ProfileClient** (Phase 1) — thêm `getFull`/`experience`/`education`/`skills` + `*Async` variants cho composition.
- **ConnectionClient** (stub, Phase 1) — đã có `health`/`healthAsync`; thêm `statusFor(viewerId, targetId)` (sẽ degrade tới Phase 3).
- **HttpClient** (`settle`, `http_errors=false`, timeout) — nền cho fan-out song song + degrade.
- **Json::ok/list + meta.degraded** + **DomainError** (tiếng Việt) — dùng cho response composition.
- profile-service raw-PDO skeleton + `Db.php` — pattern cho các bảng mới.

### Established Patterns
- Database-per-service (proconnect_profile), logical FK, no cross-DB physical FK.
- Gateway-centric composition + degrade (settle), X-User-Id trust, health contract.

### Integration Points
- gateway routes/DI; profile-service routes + schema; web pages.
</code_context>

<specifics>
## Specific Ideas

- **Flagship endpoint** Phase 2 = `GET /api/profiles/{id}/full` — câu chuyện để thuyết trình: "1 request → gateway fan-out song song profile + connection → gộp 1 hồ sơ → degrade an toàn nếu 1 service lỗi". Đây là minh hoạ API Composition trực tiếp.
- 5 account demo (demo/duyet/long/diep/tai) — seed thêm vài experience/education/skills mẫu để demo có nội dung.
- Quy trình bắt buộc: `/codex-plan-review` trước khi code, `/codex-impl-review` trước khi commit (CLAUDE.md).
</specifics>

<deferred>
## Deferred Ideas
- `connection_status` thật (lời mời/đã kết nối) — Phase 3 (connection-service). Phase 2 chỉ build chỗ ghép + degrade.
- Endorsement skills, "People you may know" — Phase 3+.
- Branding ProConnect (navy #1e3a8a, logo, 3 cột) — Phase 6.
- Upload ảnh binary / object storage — out-of-scope v1.

*Không scope creep — thảo luận trong ranh giới phase.*
</deferred>

---

*Phase: 02-h-s-ngh-nghi-p*
*Context gathered: 2026-06-06 (Claude-recommended defaults, user-delegated)*
