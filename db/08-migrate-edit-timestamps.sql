-- Phase: edit posts/comments + WYSIWYG content. Idempotent + NON-destructive.
-- - updated_at: set thủ công = NOW() trong UPDATE (chỉ đánh dấu khi sửa nội dung thật,
--   KHÔNG dùng ON UPDATE CURRENT_TIMESTAMP để tránh đụng khi cập nhật cột khác).
-- - content_format: trust boundary do server cấp. Bài cũ = 'md' (render qua renderRich,
--   escape-first); create/update set 'html' sau khi HTMLPurifier sanitize (render x-html).
USE proconnect_feed;

ALTER TABLE posts    ADD COLUMN IF NOT EXISTS updated_at     TIMESTAMP NULL DEFAULT NULL AFTER created_at;
ALTER TABLE posts    ADD COLUMN IF NOT EXISTS content_format VARCHAR(8) NOT NULL DEFAULT 'md' AFTER content;
ALTER TABLE comments ADD COLUMN IF NOT EXISTS updated_at     TIMESTAMP NULL DEFAULT NULL AFTER created_at;
