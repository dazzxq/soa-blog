# Phase 4: News Feed - Context

**Gathered:** 2026-06-07
**Status:** Ready for planning
**Note:** User-delegated ("cứ suggest, keep simple, demo microservices"). Claude-recommended defaults optimised to showcase API Composition (read-heavy multi-service join) simply.

<domain>
## Phase Boundary
Người dùng đăng bài (text + optional image URL), xem timeline (bài của mình + người đã kết nối, mới nhất trước), thả reaction, bình luận, chia sẻ lại (repost); **gateway phục vụ feed qua MỘT endpoint composition** ghép bài + tác giả + số reaction + số comment + reaction của người xem — fan-out song song nhiều service, degrade an toàn. Build feed-service (đang /health stub).
Requirements: FEED-01..06. Depends Phase 3.
**OUT:** search/notifications (Phase 5), branding/3-cột (Phase 6), upload ảnh binary, nested repost-of-repost.
</domain>

<decisions>
## Implementation Decisions

### A. Data model (feed-service, proconnect_feed, raw-PDO, logical FK)
- **D-01:** `posts(id, author_id BIGINT, content TEXT, image_url VARCHAR(512) NULL, repost_of BIGINT UNSIGNED NULL, created_at, INDEX(author_id,created_at), INDEX(created_at))`.
- **D-02:** `reactions(id, post_id, user_id, type ENUM('like','love','haha','wow','sad','angry') NOT NULL DEFAULT 'like', created_at, UNIQUE(post_id,user_id))` — MỘT reaction/người/bài; đổi = upsert (INSERT ... ON DUPLICATE KEY UPDATE type), gỡ = DELETE.
- **D-03:** `comments(id, post_id, author_id BIGINT, body TEXT, created_at, INDEX(post_id,created_at))`.
- **D-04:** Repost = `posts.repost_of` trỏ tới id bài gốc (NULL nếu thường). Repost hiển thị rõ bài gốc (tác giả + nội dung). Repost của repost → trỏ thẳng bài gốc (không lồng nhiều tầng — keep simple). Ảnh = URL string (no upload).

### B. Endpoints (gateway, owner-scoped via X-User-Id)
- **D-05:** `POST /api/posts {content, image_url?}`; `POST /api/posts/{id}/repost`; `DELETE /api/posts/{id}` (owner); `POST /api/posts/{id}/reactions {type}` (upsert) + `DELETE /api/posts/{id}/reactions` (gỡ của mình); `GET /api/posts/{id}/comments` + `POST /api/posts/{id}/comments {body}` + `DELETE /api/comments/{id}` (owner); `GET /api/posts/{id}` (1 bài, composed); `GET /api/feed` (timeline composition).
- Invariant nhẹ (tái dùng pattern Phase 3): comment/react/repost tới post không tồn tại → 404 (gateway hoặc feed-service kiểm tra).

### C. The Feed Composition — showcase centerpiece (FEED-06, D-05)
- **D-06:** `GET /api/feed` = gateway composition, fan-out song song (Utils::settle) + degrade:
  1. connection-service `listAccepted(viewer)` → author ids; author set = [viewer] + connections.
  2. feed-service `GET /posts?authors=<ids>&viewer=<id>&limit=N` → bài mới nhất của các author, MỖI bài kèm `reaction_count`, `comment_count`, `my_reaction` (feed-service tự COUNT trong DB của mình — rẻ), + repost_of.
  3. gateway batch-enrich tác giả (profile-service `?ids=` → id/username/display_name/avatar_url, NO email) + resolve bài gốc cho repost (author + content gốc).
  4. settle + `meta.degraded` nếu một phần lỗi; sắp xếp mới nhất trước.
  - Đây là minh hoạ API Composition đọc-nặng ghép ≥3 service (connection + feed + profile). Câu chuyện thuyết trình: "1 request → gateway hỏi song song connection (ai là bạn) + feed (bài + đếm) + profile (tác giả) → gộp 1 timeline → degrade nếu 1 phần lỗi."

### D. Service / infra
- **D-07:** feed-service trusts X-User-Id (NO JWT, no host port), raw-PDO, Json/DomainError tiếng Việt. Writes scoped by X-User-Id; owner-only delete (post/comment); reaction/comment scoped to caller. Clone profile/connection service doctrine (uniform 404, scoped SELECT existence, 23000 dedupe for reactions).
- **D-08:** Idempotent NON-destructive `db/04-migrate-phase4.sql` (CREATE TABLE IF NOT EXISTS posts/reactions/comments + guarded demo seed: vài bài của demo accounts, vài reaction/comment, 1 repost — để demo có nội dung) wired BLOCKING vào deploy.sh sau phase-3 migrate; sync fresh-volume schema. proconnect_feed + feed_svc đã có (Phase 1).
- **D-09:** content length cap (vd ≤5000), image_url ≤512; validation tiếng Việt.

### E. UI
- **D-10:** `web/feed.html` — ô soạn bài (text + URL ảnh) + timeline (bài kèm tác giả/ảnh/số reaction/số comment/reaction của tôi/nguồn repost) + nút react (chọn cảm xúc), comment (mở rộng), repost, xoá (bài của mình). Nav link từ index. Alpine+Tailwind CDN, tiếng Việt, minimal (NO branding/3-cột — Phase 6). x-text (no x-html).

### Claude's Discretion
- Bộ cảm xúc reaction cụ thể, limit/pagination timeline, độ sâu hiển thị comment, route nội bộ feed-service, cách đếm (inline COUNT vs subquery).
</decisions>

<canonical_refs>
## Canonical References
- `.planning/phases/03-k-t-n-i-social-graph/03-*.md` (composition+settle+degrade+enrich pattern, idempotent migration wired to deploy.sh, ConnectionClient.listAccepted, smoke pattern, PDO doctrine).
- `.planning/phases/02-h-s-ngh-nghi-p/02-RESEARCH.md` (the AggregateController settle/degrade + ProfileClient batch enrichment — the core composition mechanics).
- `.planning/codebase/ARCHITECTURE.md` (flagship aggregation `/api/posts/{id}/full` + N→author enrichment — the brownfield feed-ish pattern, recoverable from git 2f6ecf8).
- `.planning/PROJECT.md` (feed read-heavy composition is the richest Gateway demo; keep simple), REQUIREMENTS.md (FEED-01..06), ROADMAP.md §Phase 4.
- Code: gateway/src/Controllers/AggregateController.php + ProfilesController.php (settle/degrade/enrich), gateway/src/Services/ConnectionClient.php (listAccepted) + ProfileClient.php (batch), services/feed-service/* (stub to build), services/connection-service/src/Controllers/ConnectionController.php (PDO doctrine), db/03-migrate-phase3.sql (idempotent-migration pattern) + scripts/deploy.sh, scripts/smoke-phase3.sh (smoke pattern), git 2f6ecf8 PostsController/CommentController (posts+comments brownfield reference).
</canonical_refs>

<code_context>
## Existing Code Insights
- feed-service stub: Db.php/Json/DomainError/Controllers skeleton + /health.
- ConnectionClient.listAccepted(user) exists → timeline author universe.
- ProfileClient batch (?ids=) → author enrichment.
- Utils::settle + meta.degraded (AggregateController) → parallel fan-out + degrade.
- Idempotent migration + deploy.sh wiring (Phase 2/3) → clone for phase-4.
- Brownfield posts/comments code in git 2f6ecf8 (PostsController, CommentController, AggregateController postFull) — strong reference for the feed CRUD + composition.
- proconnect_feed + feed_svc provisioned (Phase 1).
</code_context>

<specifics>
## Specific Ideas
- Demo seed: a handful of posts by demo/duyet/long with a couple reactions, comments, and one repost, so the timeline + feed composition demo has visible content.
- Presentation: GET /api/feed is the richest composition demo (3-service parallel join + degrade). Reuse the "watch it degrade" story.
- Mandatory: /codex-plan-review before code, /codex-impl-review before commit.
</specifics>

<deferred>
## Deferred Ideas
- Search + notifications (Phase 5); branding/3-cột (Phase 6); binary image upload; nested repost chains; feed pagination beyond a simple limit; notification on reaction/comment (Phase 5).
