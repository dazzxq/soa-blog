-- =============================================================================
-- Phase 3 live-volume ADDITIVE migration (D-02, D-08) — social graph
-- =============================================================================
-- WHY THIS FILE EXISTS (RESEARCH Pitfall 1):
--   db/00-init.sh + db/*.sql run ONLY on a FRESH MariaDB volume. The live VPS
--   volume already exists, so the `proconnect_connection` schema is present but
--   EMPTY (no `connections` table). The init glob silently no-ops there, so
--   every connection endpoint would 500 on `SELECT ... FROM connections`. This
--   migration creates the `connections` table + a small demo graph seed in the
--   ALREADY-RUNNING mariadb container, in place, WITHOUT deleting any data.
--
-- WHY IT IS A PLAIN .sql (NOT a templated .sql.tmpl):
--   This file carries NO dollar-brace substitution placeholders and NO secrets
--   (no new DB users/grants — the existing `GRANT ALL ON proconnect_connection.*`
--   for connection_svc already covers tables created later). So it is a plain
--   .sql applied via deploy.sh against the running DB with no preprocessing step.
--   It is idempotent, so even if the MariaDB initdb glob were to run it on a
--   fresh volume it converges to the same end state.
--
-- SAFETY (threat T-03-01 — non-destructive):
--   - ONLY `CREATE TABLE IF NOT EXISTS` + guarded `INSERT ... WHERE NOT EXISTS`.
--     NO DROP, NO ALTER. Never touches mysql/information_schema/other schemas.
--   - Idempotent: safe to re-run (guarded seed via `WHERE NOT EXISTS`), so
--     repeated deploys are no-ops.
--
-- UNORDERED-PAIR UNIQUENESS (threat T-03-04b — correctness fix for the
-- opposite-direction invite race):
--   A directional `UNIQUE(requester_id, addressee_id)` alone lets two concurrent
--   opposite invites (A->B and B->A) BOTH observe `statusBetween='none'` and
--   insert two pending rows for the SAME unordered pair, leaving
--   `statusBetween ... LIMIT 1` ambiguous. We fix this with MariaDB STORED
--   generated columns `pair_lo`/`pair_hi` (LEAST/GREATEST of the two ids) plus a
--   second unique key `uq_pair (pair_lo, pair_hi)`. This guarantees AT MOST ONE
--   row per unordered pair regardless of direction — the reverse-invite race
--   hits 23000 and the gateway's existing 23000->409 backstop returns 409.
--   Direction (requester_id/addressee_id) is STILL needed for pending_outgoing
--   vs pending_incoming, so the directional `uq_conn_pair` is KEPT too. The
--   generated columns live INSIDE `CREATE TABLE IF NOT EXISTS` — there is NO
--   ALTER; the live volume's table is brand-new (Pitfall 1), so this is safe.
--
-- requires MariaDB >= 10.2 for STORED generated columns; deploy target is 10.11
-- =============================================================================

USE proconnect_connection;

-- 1) connections (D-02). Directional columns + STORED unordered-pair columns.
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

-- =============================================================================
-- 2) IDEMPOTENT DEMO SEED (D-08 + CONTEXT "Specific Ideas")
-- =============================================================================
-- 99-seed.sql will NOT reach the existing live volume (same Pitfall-1 reason),
-- so the demo graph for the presentation is seeded HERE. Deterministic explicit
-- ids + `WHERE NOT EXISTS` guards make every statement a no-op on re-run. Only
-- the base columns are inserted — pair_lo/pair_hi are computed automatically.
--
--   id 1: requester 2 (duyet) -> addressee 3 (long), status 'accepted'.
--   id 2: requester 1 (demo)  -> addressee 2 (duyet), status 'pending'.

INSERT INTO connections (id, requester_id, addressee_id, status)
SELECT 1, 2, 3, 'accepted'
WHERE NOT EXISTS (SELECT 1 FROM connections WHERE id = 1);

INSERT INTO connections (id, requester_id, addressee_id, status)
SELECT 2, 1, 2, 'pending'
WHERE NOT EXISTS (SELECT 1 FROM connections WHERE id = 2);
