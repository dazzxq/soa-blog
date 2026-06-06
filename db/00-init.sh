#!/bin/bash
# MariaDB official image runs files in /docker-entrypoint-initdb.d/ alphabetically
# on first volume init. This script creates 5 separate databases + 5 dedicated
# DB users (database-per-service pattern) for ProConnect.
#
# Schemas: proconnect_profile (has the users table), and 4 stub schemas
# (proconnect_connection, proconnect_feed, proconnect_search, proconnect_notification)
# that intentionally start empty (D-09 — no business tables in Phase 1).
#
# After this script, the fresh-init set runs alphabetically:
#   00-init.sh -> 01-schema-profile.sql -> 99-seed.sql
# The live cutover template db/migrate-phase1.sql.tmpl is NOT part of this set:
# MariaDB's initdb glob matches *.sql/*.sql.gz/*.sh but NOT *.sql.tmpl, so the
# template (with its un-substituted ${*_SVC_DB_PASS} placeholders) never auto-runs
# on a fresh volume (ISSUE-7).
#
# IMPORTANT: requires MARIADB_ROOT_PASSWORD and the five *_SVC_DB_PASS env vars
# to be present in the container environment.
set -euo pipefail

: "${MARIADB_ROOT_PASSWORD:?MARIADB_ROOT_PASSWORD must be set}"
: "${PROFILE_SVC_DB_PASS:?PROFILE_SVC_DB_PASS must be set}"
: "${CONNECTION_SVC_DB_PASS:?CONNECTION_SVC_DB_PASS must be set}"
: "${FEED_SVC_DB_PASS:?FEED_SVC_DB_PASS must be set}"
: "${SEARCH_SVC_DB_PASS:?SEARCH_SVC_DB_PASS must be set}"
: "${NOTIFICATION_SVC_DB_PASS:?NOTIFICATION_SVC_DB_PASS must be set}"

mysql -uroot -p"$MARIADB_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS proconnect_profile      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS proconnect_connection   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS proconnect_feed         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS proconnect_search       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS proconnect_notification CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'profile_svc'@'%'      IDENTIFIED BY '${PROFILE_SVC_DB_PASS}';
CREATE USER IF NOT EXISTS 'connection_svc'@'%'   IDENTIFIED BY '${CONNECTION_SVC_DB_PASS}';
CREATE USER IF NOT EXISTS 'feed_svc'@'%'         IDENTIFIED BY '${FEED_SVC_DB_PASS}';
CREATE USER IF NOT EXISTS 'search_svc'@'%'       IDENTIFIED BY '${SEARCH_SVC_DB_PASS}';
CREATE USER IF NOT EXISTS 'notification_svc'@'%' IDENTIFIED BY '${NOTIFICATION_SVC_DB_PASS}';

GRANT ALL PRIVILEGES ON proconnect_profile.*      TO 'profile_svc'@'%';
GRANT ALL PRIVILEGES ON proconnect_connection.*   TO 'connection_svc'@'%';
GRANT ALL PRIVILEGES ON proconnect_feed.*         TO 'feed_svc'@'%';
GRANT ALL PRIVILEGES ON proconnect_search.*       TO 'search_svc'@'%';
GRANT ALL PRIVILEGES ON proconnect_notification.* TO 'notification_svc'@'%';

FLUSH PRIVILEGES;
SQL

echo "00-init.sh: 5 databases + 5 service users created"
