-- =============================================================================
-- Phase 5 live-volume ADDITIVE migration (D-01, D-04, D-08) — search + notifications
-- =============================================================================
-- WHY THIS FILE EXISTS (RESEARCH Pitfall 1):
--   db/00-init.sh + db/*.sql run ONLY on a FRESH MariaDB volume. The live VPS
--   volume already exists, so the `proconnect_search` and `proconnect_notification`
--   schemas are present but EMPTY (no search_index / notifications tables). The
--   init glob silently no-ops there, so every search endpoint would 500 on
--   `SELECT ... FROM search_index` and every notification endpoint would 500 on
--   `SELECT ... FROM notifications`. This migration creates both tables + a small
--   demo seed in the ALREADY-RUNNING mariadb container, in place, WITHOUT
--   deleting any data.
--
-- WHY IT SPANS TWO DATABASES WITHOUT A CLI DB ARG (A3):
--   search_index lives in proconnect_search; notifications lives in
--   proconnect_notification. A single `mysql ... < this.sql` invocation cannot
--   carry two DB names on the CLI, so this file switches databases INTERNALLY
--   via explicit `USE` blocks (mirroring db/migrate-phase1.sql.tmpl, which also
--   spans many schemas through USE). deploy.sh therefore applies this file with
--   NO database argument — unlike the phase-2/3/4 single-DB migrations.
--
-- WHY IT IS A PLAIN .sql (NOT a templated .sql.tmpl):
--   This file carries NO dollar-brace substitution placeholders and NO secrets
--   (no new DB users/grants — the existing `GRANT ALL ON proconnect_search.*` for
--   search_svc and `GRANT ALL ON proconnect_notification.*` for notification_svc
--   from Phase 1 already cover tables created later). So it is a plain .sql
--   applied via deploy.sh against the running DB with no preprocessing step. It
--   is idempotent, so even if the MariaDB initdb glob were to run it on a fresh
--   volume it converges to the same end state.
--
-- SAFETY (threat T-05-01 — non-destructive):
--   - ONLY `CREATE TABLE IF NOT EXISTS` + guarded `INSERT ... WHERE NOT EXISTS`.
--     NO DROP, NO ALTER. Never touches mysql/information_schema/other schemas.
--   - Idempotent: safe to re-run (guarded seed via `WHERE NOT EXISTS`), so
--     repeated deploys are no-ops.
--
-- DENORMALIZED SEARCH INDEX (D-01, D-02):
--   search-service owns its own copy of the searchable profile fields (DB-per-
--   service: it cannot read profile-service). avatar_url (A1) is denormalized
--   here so the gateway-composed search card (D-03) can show it without a second
--   round trip; it is refreshed at reindex and may be NULL.
--
-- DEMO SEED (D-08 + CONTEXT "Specific Ideas"):
--   - 5 search_index rows (the 5 demo users) so `GET /api/search?q=...` shows
--     content immediately. duyet (id 2) carries display_name "Nguyễn Thế Duyệt"
--     and a skills_text containing "PHP" so smoke `q=duyet` and `q=PHP` both hit.
--   - 2 UNREAD notifications for duyet (id 2) so the bell badge shows immediately.
-- =============================================================================

-- =============================================================================
-- A) SEARCH — proconnect_search.search_index
-- =============================================================================
USE proconnect_search;

CREATE TABLE IF NOT EXISTS search_index (
  user_id      BIGINT UNSIGNED PRIMARY KEY,
  username     VARCHAR(64)  NOT NULL,
  display_name VARCHAR(128) NOT NULL,
  headline     VARCHAR(160) NULL,
  location     VARCHAR(128) NULL,
  skills_text  TEXT NULL,
  avatar_url   VARCHAR(512) NULL,           -- A1: card needs it (D-03), denormalized at reindex
  INDEX idx_search_display (display_name),
  INDEX idx_search_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the 5 demo users' index rows. Realistic Vietnamese values consistent with
-- the Phase-2 skills seed. user_id 2 (duyet) MUST be findable by name "Duyet"
-- AND by skill "PHP" (smoke SEARCH-01 covers both). avatar_url left NULL.
--   1=demo, 2=duyet, 3=long, 4=diep, 5=tai (db/migrate-phase1.sql.tmpl).
INSERT INTO search_index (user_id, username, display_name, headline, location, skills_text, avatar_url)
SELECT 1, 'demo', 'Tài khoản demo', 'Tài khoản trình diễn ProConnect', 'Hà Nội', 'Demo, Giới thiệu sản phẩm', NULL
WHERE NOT EXISTS (SELECT 1 FROM search_index WHERE user_id = 1);

INSERT INTO search_index (user_id, username, display_name, headline, location, skills_text, avatar_url)
SELECT 2, 'duyet', 'Nguyễn Thế Duyệt', 'Kỹ sư phần mềm — Kiến trúc hướng dịch vụ', 'Hà Nội', 'PHP, Docker, Kiến trúc hướng dịch vụ, API Gateway', NULL
WHERE NOT EXISTS (SELECT 1 FROM search_index WHERE user_id = 2);

INSERT INTO search_index (user_id, username, display_name, headline, location, skills_text, avatar_url)
SELECT 3, 'long', 'Đinh Ngọc Long', 'Lập trình viên Backend', 'Hồ Chí Minh', 'Java, Spring Boot, Microservices', NULL
WHERE NOT EXISTS (SELECT 1 FROM search_index WHERE user_id = 3);

INSERT INTO search_index (user_id, username, display_name, headline, location, skills_text, avatar_url)
SELECT 4, 'diep', 'Vũ Duy Điệp', 'Kỹ sư DevOps', 'Đà Nẵng', 'Kubernetes, CI/CD, Linux, Docker', NULL
WHERE NOT EXISTS (SELECT 1 FROM search_index WHERE user_id = 4);

INSERT INTO search_index (user_id, username, display_name, headline, location, skills_text, avatar_url)
SELECT 5, 'tai', 'Tạ Ngọc Tài', 'Lập trình viên Frontend', 'Hà Nội', 'JavaScript, Vue, Alpine, Tailwind', NULL
WHERE NOT EXISTS (SELECT 1 FROM search_index WHERE user_id = 5);

-- =============================================================================
-- B) NOTIFICATIONS — proconnect_notification.notifications
-- =============================================================================
USE proconnect_notification;

CREATE TABLE IF NOT EXISTS notifications (
  id         BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,                                  -- recipient
  type       ENUM('invite','reaction','comment') NOT NULL,
  actor_id   BIGINT UNSIGNED NOT NULL,
  ref_id     BIGINT UNSIGNED NULL,                                      -- post/request id
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at    TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_notif_user_read (user_id, read_at),
  INDEX idx_notif_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed two UNREAD notifications for duyet (id 2) so the bell badge shows content
-- immediately after deploy. Explicit ids + WHERE NOT EXISTS guards = no-op re-run.
--   id 1: invite   from demo (1)  — ref_id NULL, unread.
--   id 2: reaction from diep (4)  — ref_id 1 (post 1, authored by duyet), unread.
INSERT INTO notifications (id, user_id, type, actor_id, ref_id, read_at)
SELECT 1, 2, 'invite', 1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE id = 1);

INSERT INTO notifications (id, user_id, type, actor_id, ref_id, read_at)
SELECT 2, 2, 'reaction', 4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE id = 2);
