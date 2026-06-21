-- Migration 09 — Snowflake post_id (alias model).
--
-- THÊM cột post_id (Snowflake) làm ĐỊNH DANH CÔNG KHAI cho bài viết; giữ posts.id
-- auto-increment làm PK NỘI BỘ. comments.post_id / reactions.post_id / repost_of
-- vẫn tham chiếu id NỘI BỘ (feed-service dịch snowflake↔id ở biên).
--
-- File này CHỈ add (nullable) + backfill. KHÔNG ép NOT NULL ở đây — việc đó là
-- two-phase: deploy này giữ nullable (tránh vỡ INSERT của service CŨ trong cửa sổ
-- build), một migration sau (db/10) mới enforce NOT NULL khi đã 0 NULL ổn định.
--
-- Idempotent: chạy lại an toàn (ADD COLUMN IF NOT EXISTS + WHERE post_id IS NULL).
-- Áp ở scripts/deploy.sh như bước migration CUỐI, sau mọi seed/migration cũ và
-- TRƯỚC docker compose build + up.

USE proconnect_feed;

-- 1) Cột nullable + UNIQUE (nullable để seed/INSERT cũ không vỡ).
ALTER TABLE posts ADD COLUMN IF NOT EXISTS post_id BIGINT UNSIGNED NULL UNIQUE AFTER id;

-- 2) Backfill từ created_at + id. Epoch 2020-01-01 (1577836800000) đứng TRƯỚC mọi
--    dữ liệu; GREATEST(0, …) phòng hàng pre-epoch cho ra số âm. 22 bit thấp = id
--    (đóng vai sequence). Cùng layout/epoch với App\Snowflake ở runtime.
UPDATE posts
   SET post_id = (GREATEST(0, UNIX_TIMESTAMP(created_at) * 1000 - 1577836800000) << 22) | (id & 0x3FFFFF)
 WHERE post_id IS NULL;

-- 3) Backfill ref_id của thông báo cũ: đổi từ posts.id (auto-inc) sang posts.post_id
--    (snowflake) cho các loại thông báo trỏ tới BÀI VIẾT (reaction/comment). KHÔNG
--    đụng connection_request (ref_id của nó là request id, không phải post id).
--    Cùng MariaDB instance nên cross-DB UPDATE … JOIN chạy được (root).
UPDATE proconnect_notification.notifications n
   JOIN proconnect_feed.posts p ON p.id = n.ref_id
    SET n.ref_id = p.post_id
  WHERE n.type IN ('reaction', 'comment');
