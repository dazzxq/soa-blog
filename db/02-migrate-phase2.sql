-- =============================================================================
-- Phase 2 live-volume ADDITIVE migration (D-05..D-08) — hồ sơ nghề nghiệp
-- =============================================================================
-- WHY THIS FILE EXISTS (RESEARCH Pitfall 1):
--   db/00-init.sh + db/*.sql run ONLY on a FRESH MariaDB volume. The live VPS
--   volume already exists (proconnect_profile.users has 5 demo rows), so the
--   init scripts silently no-op there. This migration adds the 4 new profile
--   columns + 3 child tables (experience/education/skills) + a small demo seed
--   to the ALREADY-RUNNING mariadb container, in place, WITHOUT deleting data.
--
-- WHY IT IS A PLAIN .sql (NOT .sql.tmpl):
--   Unlike db/migrate-phase1.sql.tmpl, this file carries NO envsubst dollar-brace
--   placeholders and NO secrets (no new DB users/grants — the existing
--   `GRANT ALL ON proconnect_profile.*` already covers tables created later).
--   So it is a plain .sql applied via deploy.sh against the running DB. It is
--   idempotent, so even if the MariaDB initdb glob were to run it on a fresh
--   volume it converges to the same end state.
--
-- SAFETY (threat T-02-05 — non-destructive):
--   - ONLY `ADD COLUMN IF NOT EXISTS` / `CREATE TABLE IF NOT EXISTS` — NO DROP,
--     NO `ALTER ... DROP`. Never touches mysql/information_schema/other schemas.
--   - Idempotent: safe to re-run (guarded seed via `WHERE NOT EXISTS` + a
--     headline-IS-NULL guard on the UPDATE), so repeated deploys are no-ops.
--
-- requires MariaDB >= 10.0.2 for ADD COLUMN IF NOT EXISTS; deploy target is 10.11
-- =============================================================================

USE proconnect_profile;

-- 1) Extend users with the 4 new profile columns (D-05). Idempotent.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS cover_url VARCHAR(512) NULL AFTER avatar_url,
  ADD COLUMN IF NOT EXISTS headline  VARCHAR(160) NULL,
  ADD COLUMN IF NOT EXISTS location  VARCHAR(128) NULL,
  ADD COLUMN IF NOT EXISTS about     TEXT NULL;

-- 2) experience (D-06). end_date NULL = "hiện tại".
CREATE TABLE IF NOT EXISTS experience (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  company VARCHAR(160) NOT NULL,
  title   VARCHAR(160) NOT NULL,
  start_date DATE NOT NULL,
  end_date   DATE NULL,
  description TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_exp_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) education (D-07).
CREATE TABLE IF NOT EXISTS education (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  school VARCHAR(160) NOT NULL,
  degree VARCHAR(160) NULL,
  field  VARCHAR(160) NULL,
  start_year SMALLINT NULL,
  end_year   SMALLINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_edu_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) skills (D-08). UNIQUE(user_id,name) backs PROF-05 dedupe (409).
CREATE TABLE IF NOT EXISTS skills (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_skill_user_name (user_id, name),
  INDEX idx_skill_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 5) IDEMPOTENT DEMO SEED (CONTEXT "Specific Ideas" + RESEARCH Open Q2)
-- =============================================================================
-- The live volume's users already exist but have NULL headline/location/about,
-- and 99-seed.sql will NOT reach the existing volume (same Pitfall-1 reason), so
-- the demo content for the presentation is seeded HERE. Deterministic explicit
-- ids + guards make every statement a no-op on re-run.

-- 5a) Basic fields for users 1 (demo) and 2 (duyet). Guarded on headline IS NULL
--     so a re-run never clobbers a value the user later edited via PATCH /me.
UPDATE users
   SET headline = 'Tài khoản demo — ProConnect',
       location = 'Hà Nội, Việt Nam',
       about    = 'Tài khoản demo công khai để thử nghiệm ProConnect.'
 WHERE id = 1 AND headline IS NULL;

UPDATE users
   SET headline = 'Sinh viên PTIT — Software Engineer',
       location = 'Hà Nội, Việt Nam',
       about    = 'Sinh viên Công nghệ thông tin tại Học viện Công nghệ Bưu chính Viễn thông, quan tâm tới kiến trúc hướng dịch vụ và API Gateway.'
 WHERE id = 2 AND headline IS NULL;

-- 5b) experience — explicit ids, guarded so re-runs are no-ops.
INSERT INTO experience (id, user_id, company, title, start_date, end_date, description)
SELECT 1, 2, 'Học viện Công nghệ Bưu chính Viễn thông', 'Sinh viên Công nghệ thông tin', '2022-09-01', NULL, 'Học và thực hành kiến trúc microservices, API Gateway pattern (môn INT1448 SOA).'
WHERE NOT EXISTS (SELECT 1 FROM experience WHERE id = 1);

INSERT INTO experience (id, user_id, company, title, start_date, end_date, description)
SELECT 2, 2, 'ProConnect (đồ án môn học)', 'Backend Developer', '2024-02-01', '2024-06-30', 'Xây dựng gateway điều phối các microservices: profile, connection, feed, search.'
WHERE NOT EXISTS (SELECT 1 FROM experience WHERE id = 2);

INSERT INTO experience (id, user_id, company, title, start_date, end_date, description)
SELECT 3, 1, 'Công ty Demo', 'Kỹ sư phần mềm', '2023-01-01', NULL, 'Tài khoản demo — kinh nghiệm mẫu để minh hoạ hồ sơ.'
WHERE NOT EXISTS (SELECT 1 FROM experience WHERE id = 3);

-- 5c) education — explicit ids, guarded.
INSERT INTO education (id, user_id, school, degree, field, start_year, end_year)
SELECT 1, 2, 'Học viện Công nghệ Bưu chính Viễn thông', 'Kỹ sư', 'Công nghệ thông tin', 2022, 2027
WHERE NOT EXISTS (SELECT 1 FROM education WHERE id = 1);

INSERT INTO education (id, user_id, school, degree, field, start_year, end_year)
SELECT 2, 1, 'Trường Demo', 'Cử nhân', 'Khoa học máy tính', 2019, 2023
WHERE NOT EXISTS (SELECT 1 FROM education WHERE id = 2);

-- 5d) skills — explicit ids, guarded (UNIQUE(user_id,name) is a second guard).
INSERT INTO skills (id, user_id, name)
SELECT 1, 2, 'PHP'        WHERE NOT EXISTS (SELECT 1 FROM skills WHERE id = 1);
INSERT INTO skills (id, user_id, name)
SELECT 2, 2, 'Docker'     WHERE NOT EXISTS (SELECT 1 FROM skills WHERE id = 2);
INSERT INTO skills (id, user_id, name)
SELECT 3, 2, 'Kiến trúc hướng dịch vụ' WHERE NOT EXISTS (SELECT 1 FROM skills WHERE id = 3);
INSERT INTO skills (id, user_id, name)
SELECT 4, 1, 'JavaScript' WHERE NOT EXISTS (SELECT 1 FROM skills WHERE id = 4);
