-- Fresh-volume schema for search-service (D-01). This file runs only on a FRESH
-- MariaDB volume (db/00-init.sh creates proconnect_search + the search_svc grant
-- before this sorts in). The live VPS volume already exists and gets the SAME
-- table via db/05-migrate-phase5.sql (RESEARCH Pitfall 1).
-- Structure-only — the demo search index seed lives in the migration flow,
-- mirroring how db/01-schema-connection.sql is structure-only. DDL is VERBATIM
-- the same as the search_index block in db/05-migrate-phase5.sql so fresh and
-- live volumes converge identically. `USE` selects the DB (the init runner pipes
-- without a CLI db arg), matching db/01-schema-feed.sql.
USE proconnect_search;

CREATE TABLE IF NOT EXISTS search_index (
  user_id      BIGINT UNSIGNED PRIMARY KEY,
  username     VARCHAR(64)  NOT NULL,
  display_name VARCHAR(128) NOT NULL,
  headline     VARCHAR(160) NULL,
  location     VARCHAR(128) NULL,
  skills_text  TEXT NULL,
  avatar_url   VARCHAR(512) NULL,
  INDEX idx_search_display (display_name),
  INDEX idx_search_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
