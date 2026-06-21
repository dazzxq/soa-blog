-- Fresh-volume schema for feed-service (D-01..D-03). This file runs only on a
-- FRESH MariaDB volume (db/00-init.sh creates proconnect_feed + the feed_svc
-- grant before this sorts in). The live VPS volume already exists and gets the
-- SAME three tables via db/04-migrate-phase4.sql (RESEARCH Pitfall 1).
-- Structure-only — the demo feed seed lives in the migration / 99-seed flow,
-- mirroring how db/01-schema-connection.sql is structure-only. DDL is VERBATIM
-- the same as db/04-migrate-phase4.sql (incl. the reactions UNIQUE key + all
-- indexes) so fresh and live volumes converge identically.
USE proconnect_feed;

CREATE TABLE IF NOT EXISTS posts (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,            -- PK NỘI BỘ (không lộ ra ngoài)
  post_id BIGINT UNSIGNED NULL UNIQUE,                      -- Snowflake — định danh CÔNG KHAI (app set khi tạo; db/09 backfill)
  author_id BIGINT UNSIGNED NOT NULL,
  content TEXT NOT NULL,
  image_url VARCHAR(512) NULL,
  images JSON NULL,                 -- up to 9 image URLs (multi-image posts); image_url mirrors images[0]
  repost_of BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_posts_author_created (author_id, created_at),
  INDEX idx_posts_created (created_at),
  INDEX idx_posts_repost (repost_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reactions (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  type ENUM('like','love','haha','wow','sad','angry') NOT NULL DEFAULT 'like',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reaction_post_user (post_id, user_id),
  INDEX idx_reaction_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_comment_post_created (post_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
