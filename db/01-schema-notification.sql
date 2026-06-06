-- Fresh-volume schema for notification-service (D-04). This file runs only on a
-- FRESH MariaDB volume (db/00-init.sh creates proconnect_notification + the
-- notification_svc grant before this sorts in). The live VPS volume already
-- exists and gets the SAME table via db/05-migrate-phase5.sql (RESEARCH Pitfall 1).
-- Structure-only — the demo notification seed lives in the migration flow,
-- mirroring how db/01-schema-connection.sql is structure-only. DDL is VERBATIM
-- the same as the notifications block in db/05-migrate-phase5.sql so fresh and
-- live volumes converge identically. `USE` selects the DB (the init runner pipes
-- without a CLI db arg), matching db/01-schema-feed.sql.
USE proconnect_notification;

CREATE TABLE IF NOT EXISTS notifications (
  id         BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  type       ENUM('invite','reaction','comment') NOT NULL,
  actor_id   BIGINT UNSIGNED NOT NULL,
  ref_id     BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at    TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_notif_user_read (user_id, read_at),
  INDEX idx_notif_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
