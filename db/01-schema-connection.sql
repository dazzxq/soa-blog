-- Fresh-volume schema for connection-service (D-02). This file runs only on a
-- FRESH MariaDB volume (db/00-init.sh creates proconnect_connection + the
-- connection_svc grant before this sorts in). The live VPS volume already exists
-- and gets the SAME table via db/03-migrate-phase3.sql (RESEARCH Pitfall 1).
-- Structure-only — the demo graph seed lives in the migration / 99-seed flow,
-- mirroring how db/01-schema-profile.sql is structure-only. DDL is VERBATIM the
-- same as db/03-migrate-phase3.sql (incl. the STORED pair_lo/pair_hi columns and
-- BOTH uq_conn_pair and uq_pair) so fresh and live volumes converge identically.
USE proconnect_connection;

CREATE TABLE IF NOT EXISTS connections (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  requester_id BIGINT UNSIGNED NOT NULL,
  addressee_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  pair_lo BIGINT UNSIGNED AS (LEAST(requester_id, addressee_id)) STORED,
  pair_hi BIGINT UNSIGNED AS (GREATEST(requester_id, addressee_id)) STORED,
  UNIQUE KEY uq_conn_pair (requester_id, addressee_id),
  UNIQUE KEY uq_pair (pair_lo, pair_hi),
  INDEX idx_conn_addressee (addressee_id),
  INDEX idx_conn_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
