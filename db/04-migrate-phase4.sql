-- =============================================================================
-- Phase 4 live-volume ADDITIVE migration (D-01..D-04, D-08) — news feed
-- =============================================================================
-- WHY THIS FILE EXISTS (RESEARCH Pitfall 1):
--   db/00-init.sh + db/*.sql run ONLY on a FRESH MariaDB volume. The live VPS
--   volume already exists, so the `proconnect_feed` schema is present but EMPTY
--   (no posts/reactions/comments tables). The init glob silently no-ops there,
--   so every feed endpoint would 500 on `SELECT ... FROM posts`. This migration
--   creates the three feed tables + a small demo feed seed in the ALREADY-RUNNING
--   mariadb container, in place, WITHOUT deleting any data.
--
-- WHY IT IS A PLAIN .sql (NOT a templated .sql.tmpl):
--   This file carries NO dollar-brace substitution placeholders and NO secrets
--   (no new DB users/grants — the existing `GRANT ALL ON proconnect_feed.*` for
--   feed_svc from Phase 1 already covers tables created later). So it is a plain
--   .sql applied via deploy.sh against the running DB with no preprocessing step.
--   It is idempotent, so even if the MariaDB initdb glob were to run it on a
--   fresh volume it converges to the same end state.
--
-- SAFETY (threat T-04-01 — non-destructive):
--   - ONLY `CREATE TABLE IF NOT EXISTS` + guarded `INSERT ... WHERE NOT EXISTS`.
--     NO DROP, NO ALTER. Never touches mysql/information_schema/other schemas.
--   - Idempotent: safe to re-run (guarded seed via `WHERE NOT EXISTS`), so
--     repeated deploys are no-ops.
--
-- REPOST SHAPE (D-04, A6):
--   `content TEXT NOT NULL` — a repost stores `content=''`; the original post is
--   shown via `repost_of`. `repost_of` is a LOGICAL FK only (no physical
--   constraint), matching the database-per-service doctrine.
--
-- ASYMMETRIC FAN-TRAP CANARY (RESEARCH Pitfall 3 — threat T-04-04):
--   The demo seed deliberately gives post 1 an ASYMMETRIC count: 2 reactions
--   (long + diep) + 1 comment (long). The Plan-02 timeline count query MUST use
--   correlated subqueries, not a double JOIN. A broken double-JOIN returns the
--   cross-product: on a symmetric 1-reaction/1-comment post it computes 1×1 = 1
--   for both counts and the bug stays INVISIBLE. With post 1 at 2 reactions × 1
--   comment, a double-JOIN yields comment_count = 2 (and/or inflated reaction
--   count), so smoke-phase4.sh's EXACT-count assert (reaction_count==2 AND
--   comment_count==1) fails loudly. The asymmetry is what makes the guard real.
-- =============================================================================

USE proconnect_feed;

-- 1) posts (D-01). Repost is a row with repost_of set + content=''.
CREATE TABLE IF NOT EXISTS posts (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  author_id BIGINT UNSIGNED NOT NULL,
  content TEXT NOT NULL,
  image_url VARCHAR(512) NULL,
  repost_of BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_posts_author_created (author_id, created_at),
  INDEX idx_posts_created (created_at),
  INDEX idx_posts_repost (repost_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) reactions (D-02). One reaction per (post,user) — uq backstops upsert + dedupe.
CREATE TABLE IF NOT EXISTS reactions (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  type ENUM('like','love','haha','wow','sad','angry') NOT NULL DEFAULT 'like',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reaction_post_user (post_id, user_id),
  INDEX idx_reaction_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) comments (D-03).
CREATE TABLE IF NOT EXISTS comments (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_comment_post_created (post_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4) IDEMPOTENT DEMO SEED (D-08 + CONTEXT "Specific Ideas")
-- =============================================================================
-- 99-seed.sql will NOT reach the existing live volume (same Pitfall-1 reason),
-- so the demo feed for the presentation is seeded HERE. Deterministic explicit
-- ids + `WHERE NOT EXISTS` guards make every statement a no-op on re-run.
--
-- Demo users: 1=demo, 2=duyet, 3=long, 4=diep, 5=tai (db/migrate-phase1.sql.tmpl).
-- Demo graph (Phase 3): duyet(2)<->long(3) ACCEPTED — so long's post 2 lands in
-- duyet's timeline (FEED-02 = self + connections).
--
-- Posts:
--   id 1: author 2 (duyet) — first demo post, no image, not a repost.
--   id 2: author 3 (long)  — connected to duyet, proves timeline = self+connections.
--   id 3: author 1 (demo)  — content '', repost_of 1 (demo reposts duyet's post 1, FEED-05).
-- Trigger: tự sinh post_id (snowflake) khi INSERT bỏ trống. Cột post_id là NOT NULL
-- (db/10) nên mọi seed INSERT cũ (không liệt kê post_id, gồm cả db/06) vẫn chạy được.
-- Idempotent (DROP + CREATE). Layout khớp backfill db/09: (ms từ 2020-01-01 << 22) | (id thấp).
-- App (feed-service) tự đặt post_id nên COALESCE giữ nguyên, trigger chỉ điền khi NULL.
DROP TRIGGER IF EXISTS trg_posts_postid_bi;
CREATE TRIGGER trg_posts_postid_bi BEFORE INSERT ON posts FOR EACH ROW
  SET NEW.post_id = COALESCE(
    NEW.post_id,
    ((CAST(UNIX_TIMESTAMP(NOW(3)) * 1000 AS UNSIGNED) - 1577836800000) << 22) | (NEW.id & 0x3FFFFF)
  );

INSERT INTO posts (id, author_id, content, image_url, repost_of)
SELECT 1, 2, 'Chào mừng đến với ProConnect! Đây là bài viết demo đầu tiên.', NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM posts WHERE id = 1);

INSERT INTO posts (id, author_id, content, image_url, repost_of)
SELECT 2, 3, 'Vừa hoàn thành một dự án microservices thú vị.', NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM posts WHERE id = 2);

INSERT INTO posts (id, author_id, content, image_url, repost_of)
SELECT 3, 1, '', NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM posts WHERE id = 3);

-- Reactions — THREE rows; TWO of them on post 1 (the asymmetric fan-trap canary).
--   id 1: post 1, user 3 (long), 'love'  ← post-1 reaction #1
--   id 2: post 2, user 2 (duyet), 'like'
--   id 3: post 1, user 4 (diep), 'like'  ← post-1 reaction #2 → post 1 = 2 reactions
-- Distinct users on post 1 keep the (post,user) UNIQUE key intact.
INSERT INTO reactions (id, post_id, user_id, type)
SELECT 1, 1, 3, 'love'
WHERE NOT EXISTS (SELECT 1 FROM reactions WHERE id = 1);

INSERT INTO reactions (id, post_id, user_id, type)
SELECT 2, 2, 2, 'like'
WHERE NOT EXISTS (SELECT 1 FROM reactions WHERE id = 2);

INSERT INTO reactions (id, post_id, user_id, type)
SELECT 3, 1, 4, 'like'
WHERE NOT EXISTS (SELECT 1 FROM reactions WHERE id = 3);

-- Comments — EXACTLY ONE row on post 1 (asymmetric vs its 2 reactions).
--   id 1: post 1, author 3 (long).
INSERT INTO comments (id, post_id, author_id, body)
SELECT 1, 1, 3, 'Chúc mừng bạn!'
WHERE NOT EXISTS (SELECT 1 FROM comments WHERE id = 1);
