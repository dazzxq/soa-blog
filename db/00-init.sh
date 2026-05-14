#!/bin/bash
# MariaDB official image runs files in /docker-entrypoint-initdb.d/ alphabetically
# on first volume init. This script creates 3 separate databases + 3 dedicated
# DB users (database-per-service pattern).
#
# IMPORTANT: requires MARIADB_ROOT_PASSWORD and the three *_DB_PASS env vars
# to be present in the container environment.
set -euo pipefail

: "${MARIADB_ROOT_PASSWORD:?MARIADB_ROOT_PASSWORD must be set}"
: "${USER_SVC_DB_PASS:?USER_SVC_DB_PASS must be set}"
: "${POST_SVC_DB_PASS:?POST_SVC_DB_PASS must be set}"
: "${COMMENT_SVC_DB_PASS:?COMMENT_SVC_DB_PASS must be set}"

mysql -uroot -p"$MARIADB_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS blog_users    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS blog_posts    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS blog_comments CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'user_svc'@'%'    IDENTIFIED BY '${USER_SVC_DB_PASS}';
CREATE USER IF NOT EXISTS 'post_svc'@'%'    IDENTIFIED BY '${POST_SVC_DB_PASS}';
CREATE USER IF NOT EXISTS 'comment_svc'@'%' IDENTIFIED BY '${COMMENT_SVC_DB_PASS}';

GRANT ALL PRIVILEGES ON blog_users.*    TO 'user_svc'@'%';
GRANT ALL PRIVILEGES ON blog_posts.*    TO 'post_svc'@'%';
GRANT ALL PRIVILEGES ON blog_comments.* TO 'comment_svc'@'%';

FLUSH PRIVILEGES;
SQL

echo "00-init.sh: 3 databases + 3 service users created"
