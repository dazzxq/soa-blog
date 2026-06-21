-- Migration 10 — chốt invariant: posts.post_id NOT NULL (sau khi db/09 đã backfill).
--
-- Idempotent: MODIFY sang NOT NULL khi cột đã NOT NULL là no-op.
--
-- Vì sao tách khỏi db/09 (two-phase): db/09 chạy TRƯỚC khi feed-service mới khởi
-- động (để cột tồn tại), lúc đó trên LIVE feed-service CŨ vẫn chạy và INSERT bài
-- KHÔNG set post_id → nếu ép NOT NULL ngay sẽ vỡ. Vì vậy NOT NULL chỉ áp khi đã
-- cutover (không còn writer cũ).
--
-- Áp ở đâu:
--   * Fresh volume: initdb chạy file này SAU db/09 (thứ tự alphabet). DB chưa phục
--     vụ, không có writer → an toàn. Đây là cách fresh volume CŨNG có invariant
--     (không chỉ dựa vào deploy script).
--   * Live volume: scripts/deploy.sh áp file này ở BƯỚC POST-CUTOVER (sau
--     up --remove-orphans + re-backfill + guard COUNT(NULL)=0), khi feed-service
--     cũ đã bị thay. KHÔNG áp trong cửa sổ build.

USE proconnect_feed;

ALTER TABLE posts MODIFY COLUMN post_id BIGINT UNSIGNED NOT NULL;
