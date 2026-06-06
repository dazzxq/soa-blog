# Phase 5: Tìm kiếm & Thông báo - Context

**Gathered:** 2026-06-07
**Status:** Ready for planning
**Note:** User-delegated ("cứ suggest, keep simple, demo microservices"). Claude defaults — builds the last 2 stub services (search, notification) for real; gateway composes search + centrally coordinates notifications.

<domain>
## Phase Boundary
Tìm người dùng theo tên/chức danh/kỹ năng (kết quả kèm trạng thái quan hệ + nút kết nối nhanh); thông báo near-real-time qua POLLING (badge chưa đọc) khi có lời mời kết nối / reaction / comment mới, đánh dấu đã đọc. Gateway compose search + điều phối notification tập trung. Build search-service + notification-service (đang /health stub).
Requirements: SEARCH-01, SEARCH-02, NOTIF-01, NOTIF-02, NOTIF-03. Depends Phase 4.
**OUT:** WebSocket (dùng polling — PROJECT out-of-scope), branding/3-cột (Phase 6), full-text engine, push notifications.

<decisions>
## Implementation Decisions

### A. Search (SEARCH-01/02, gateway-composed)
- **D-01:** search-service owns `proconnect_search.search_index(user_id BIGINT PK, username VARCHAR(64), display_name VARCHAR(128), headline VARCHAR(160) NULL, location VARCHAR(128) NULL, skills_text TEXT NULL)` — denormalized copy for LIKE search. (DB-per-service: search-service cannot read profile-service; it keeps its own index.)
- **D-02:** Index populated by `POST /api/search/reindex` (gateway pulls profile-service `/users` + each user's skills, upserts into the index) + the migration seeds the 5 demo users' index rows. **Honest tradeoff (no event bus):** the index is eventually-consistent — a profile edit isn't reflected until reindex. Documented; reindex refreshes; acceptable for the demo (keep simple, matches ARCHITECTURE's "search-service owns an index fed by pull").
- **D-03:** `GET /api/search?q=` → search-service LIKE on display_name/username/headline/skills_text (case-insensitive, cap results) → **gateway composes** each hit with the viewer's relationship status (connection-service statusFor) → returns quick-connect-ready cards `{id, username, display_name, headline, avatar_url, connection_status}` (email ALLOWLIST — no email). Degrade-safe. This is the SEARCH composition showcase (search + relationship status).

### B. Notifications (NOTIF-01/02/03, gateway-coordinated)
- **D-04:** notification-service owns `proconnect_notification.notifications(id, user_id BIGINT [recipient], type ENUM('invite','reaction','comment'), actor_id BIGINT, ref_id BIGINT NULL [post/request id], created_at, read_at TIMESTAMP NULL, INDEX(user_id,read_at), INDEX(user_id,created_at))`.
- **D-05:** **Creation = GATEWAY orchestrates centrally** (ROADMAP SC#4): after a successful connection invite (ConnectionsController.sendRequest) → notify addressee (type 'invite'); after reaction (FeedController.react) → notify post author (type 'reaction'); after comment (FeedController.addComment) → notify post author (type 'comment'). Skip self-notify (actor==recipient). **Best-effort**: a notification-create failure NEVER fails the main action (try/catch GuzzleException, swallow) — degrade silently. The gateway is the single coordination point (no service-to-service eventing).
- **D-06:** `GET /api/notifications` → recipient's notifications newest-first + `unread_count`; gateway enriches `actor` (profile basics, allowlist). `POST /api/notifications/read-all` (mark all read) + `POST /api/notifications/{id}/read`. Frontend POLLS every ~15s for the unread badge.
- **D-07:** notification-service trusts X-User-Id (NO JWT/host port), all reads/writes scoped to recipient user_id, raw-PDO, Vietnamese.

### C. Infra
- **D-08:** Idempotent NON-destructive `db/05-migrate-phase5.sql` (CREATE TABLE IF NOT EXISTS search_index in proconnect_search + notifications in proconnect_notification + seed: index rows for the 5 demo users + a couple demo notifications for duyet) wired BLOCKING into deploy.sh after phase-4 migrate; fresh-volume schema files. proconnect_search/notification + their *_svc users already provisioned (Phase 1).
- **D-09:** Both services clone the connection/feed PDO doctrine (X-User-Id scoped, uniform 404, Vietnamese DomainError). No new container/secret/grant.

### D. UI
- **D-10:** Search box (navbar input → results list or search.html) with quick-connect buttons (reuse connection actions). Notification bell with unread badge (polls /api/notifications every ~15s), dropdown/list with mark-read + mark-all-read. Alpine+Tailwind CDN, Vietnamese, minimal (NO branding/3-cột — Phase 6). x-text.

### Claude's Discretion
- Search result cap/ordering, LIKE vs FULLTEXT (LIKE keep-simple), reindex auth (any logged-in user or admin), poll interval, notification message phrasing, where the search box lives (navbar vs page).
</decisions>

<canonical_refs>
## Canonical References
- `.planning/phases/04-news-feed/04-*.md` + `03-*.md` (composition+settle+degrade+enrich, idempotent migration wired to deploy.sh, owner-scoping, smoke pattern, PDO doctrine).
- `.planning/phases/03-k-t-n-i-social-graph/03-CONTEXT.md` (ConnectionClient.statusFor for search relationship status; the invariant/gateway-orchestration pattern).
- `.planning/codebase/ARCHITECTURE.md` (search-service owns index fed by pull; gateway-orchestrated notifications — no event bus today).
- `.planning/PROJECT.md` (notification = polling not WebSocket; keep simple), REQUIREMENTS.md (SEARCH-01/02, NOTIF-01/02/03), ROADMAP.md §Phase 5.
- Code: services/search-service/* + services/notification-service/* (stubs to build), gateway/src/Services/SearchClient.php + NotificationClient.php + ProfileClient.php (batch/allUsers) + ConnectionClient.php (statusFor), gateway/src/Controllers/ConnectionsController.php (add invite→notify) + FeedController.php (add reaction/comment→notify), gateway/src/routes.php+public/index.php, db/04-migrate-phase4.sql (idempotent pattern) + scripts/deploy.sh, scripts/smoke-phase4.sh (smoke pattern), web/connections.html + app.js (UI patterns).
</canonical_refs>

<code_context>
## Existing Code Insights
- search-service + notification-service stubs (Db/Json/DomainError skeleton + /health).
- SearchClient + NotificationClient gateway stubs (health/healthAsync) — extend.
- ProfileClient.allUsers (Phase 3) → reindex source; ProfileClient.batch → actor/result enrichment.
- ConnectionClient.statusFor → search result relationship status.
- ConnectionsController.sendRequest + FeedController.react/addComment → inject best-effort notify calls.
- Idempotent migration + deploy.sh wiring + smoke pattern (Phase 2-4).
- proconnect_search/notification + *_svc users provisioned (Phase 1).
</code_context>

<specifics>
## Specific Ideas
- Demo seed: search index for 5 demo users + a couple notifications for duyet (an invite from demo, a reaction) so the bell badge + search show content immediately.
- Presentation angles: search = gateway compose (search hit + relationship status); notifications = gateway as central coordinator (it fires notify after invite/reaction/comment, best-effort degrade) — both reinforce the Gateway pattern.
- Mandatory: /codex-plan-review before code, /codex-impl-review before commit.
</specifics>

<deferred>
## Deferred Ideas
- WebSocket/real push (polling only); branding/3-cột (Phase 6); full-text search engine; event-bus-driven index freshness (manual/triggered reindex instead); notification for repost.
