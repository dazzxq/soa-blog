#!/usr/bin/env bash
# Manual / CI deploy script. Run on the VPS inside the project directory.
# Idempotent: safe to invoke repeatedly.
#
# NOTE (ISSUE-2): this script no longer pulls the repo. The CI workflow
# (.github/workflows/deploy.yml) does the fast-forward pull in the VPS repo dir
# BEFORE invoking this script, so the NEW deploy.sh runs on the FIRST cutover
# (not the next one). When running by hand, fast-forward the repo first yourself.
set -euo pipefail

PROJECT_DIR="${PROJECT_DIR:-$(pwd)}"
cd "$PROJECT_DIR"

# 1) .env MISSING ABORT --------------------------------------------------------
if [[ ! -f .env ]]; then
  echo "[deploy] FATAL: .env is missing. Create it from .env.example first." >&2
  exit 1
fi

# 2) ENV PREFLIGHT (RESEARCH Pitfall 2) ----------------------------------------
# Source env shell-safely and assert the required vars are non-empty. The 5
# *_SVC_DB_PASS values are constrained to [A-Za-z0-9]{32} by the human cutover
# checkpoint (ISSUE-6), so `set -a; . ./.env; set +a` sourcing cannot break on
# special characters.
set -a
# shellcheck disable=SC1091
. ./.env
set +a
for v in DB_ROOT_PASSWORD PROFILE_SVC_DB_PASS CONNECTION_SVC_DB_PASS FEED_SVC_DB_PASS SEARCH_SVC_DB_PASS NOTIFICATION_SVC_DB_PASS JWT_SECRET; do
  if [ -z "${!v:-}" ]; then
    echo "[deploy] FATAL: $v missing in .env" >&2
    exit 3
  fi
done
echo "[deploy] env preflight OK (all required secrets present)"

# 3) WEB-CHANGE DETECTION (reworked, ISSUE-2) ----------------------------------
# The workflow already pulled, so there is no in-script before/after SHA.
# Detect whether web/ changed in the just-pulled range using the reflog:
# HEAD@{1} is the pre-pull tip recorded by the workflow's fast-forward pull. If
# the reflog is unavailable (e.g. shallow clone / first run), default
# WEB_TOUCHED=1 to be safe.
if git rev-parse 'HEAD@{1}' >/dev/null 2>&1; then
  if git diff --name-only 'HEAD@{1}' HEAD | grep -qE '^web/'; then
    WEB_TOUCHED=1
  else
    WEB_TOUCHED=0
  fi
else
  WEB_TOUCHED=1
fi

# 4) BUILD ---------------------------------------------------------------------
echo "[deploy] docker compose build"
docker compose build --pull

# 5) MARIADB-FIRST BOOT (ISSUE-3 step 1) ---------------------------------------
# Bring up ONLY mariadb and wait for healthy BEFORE migrating, so the
# destructive migration runs against a ready DB and DB-dependent services do
# NOT boot against non-existent proconnect_* DBs/users.
echo "[deploy] docker compose up -d mariadb (DB-first boot)"
docker compose up -d mariadb
echo "[deploy] waiting for mariadb healthy (up to 90s)"
for i in $(seq 1 18); do
  if docker compose exec -T mariadb healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
    echo "[deploy] mariadb healthy after ${i}x5s"
    break
  fi
  sleep 5
  if [ "$i" -eq 18 ]; then
    echo "[deploy] FATAL: mariadb not healthy after 90s" >&2
    docker compose ps
    exit 4
  fi
done

# 6) PRE-WIPE BACKUP — BLOCKING (ISSUE-4, ISSUE-3 step 2) ----------------------
# The migration DROPs all three legacy schemas (blog_users, blog_posts,
# blog_comments), so the backup must cover ALL of them — not just blog_users
# (ISSUE-3). Discover which legacy schemas actually exist via information_schema
# and dump exactly those. NO `|| true`: a dump failure aborts the deploy, and the
# destructive migration runs ONLY after the backup file is confirmed present AND
# non-empty (`test -s`). Backup is skipped ONLY when NO legacy schema exists.
LEGACY=$(docker compose exec -T mariadb mysql -uroot -p"$DB_ROOT_PASSWORD" -N -e \
  "SELECT schema_name FROM information_schema.schemata WHERE schema_name IN ('blog_users','blog_posts','blog_comments');" 2>/dev/null \
  | tr -d '\r' | tr '\n' ' ')
LEGACY=$(echo "$LEGACY" | xargs)   # trim surrounding whitespace
if [ -n "$LEGACY" ]; then
  mkdir -p backups
  BACKUP_FILE="backups/pre-phase1-$(date +%Y%m%d-%H%M%S).sql"
  echo "[deploy] backing up legacy schemas ($LEGACY) to $BACKUP_FILE (blocking)"
  # shellcheck disable=SC2086 # $LEGACY is a deliberate space-separated arg list
  if ! docker compose exec -T mariadb mysqldump -uroot -p"$DB_ROOT_PASSWORD" --databases $LEGACY > "$BACKUP_FILE"; then
    echo "[deploy] FATAL: pre-wipe backup failed — aborting before destructive migration" >&2
    rm -f "$BACKUP_FILE"
    exit 5
  fi
  if [ ! -s "$BACKUP_FILE" ]; then
    echo "[deploy] FATAL: backup file $BACKUP_FILE is empty — aborting before destructive migration" >&2
    exit 5
  fi
  echo "[deploy] backup OK ($(wc -c < "$BACKUP_FILE") bytes)"
else
  echo "[deploy] no legacy blog_* schemas present — skipping backup (already migrated)"
fi

# 7) APPLY MIGRATION (ISSUE-3 step 3; idempotent; RESEARCH Pitfall 1 option B) -
# Substitute ${VAR} placeholders from the sourced .env via envsubst, then pipe
# into mysql. The migration drops blog_*, creates proconnect_* + scoped users
# (ALTER USER makes the password update idempotent), builds the profile table,
# and reseeds the 5 demo accounts.
echo "[deploy] applying db/migrate-phase1.sql.tmpl"
if ! command -v envsubst >/dev/null 2>&1; then
  echo "[deploy] FATAL: envsubst not found (install gettext-base)" >&2
  exit 6
fi
# WHITELIST MANDATORY (ISSUE-1): restrict envsubst to ONLY the 5 DB-password
# placeholders. Unrestricted envsubst would also try to expand the bcrypt seed
# hash tokens ($2y$12$ALzJ3z...) as shell variables and replace them with empty
# strings, corrupting all 5 demo-account password hashes and breaking login
# (PROF-01). The single-quoted whitelist makes envsubst leave every other
# $-sequence (the bcrypt hashes) byte-for-byte intact.
envsubst '${PROFILE_SVC_DB_PASS} ${CONNECTION_SVC_DB_PASS} ${FEED_SVC_DB_PASS} ${SEARCH_SVC_DB_PASS} ${NOTIFICATION_SVC_DB_PASS}' < db/migrate-phase1.sql.tmpl | docker compose exec -T mariadb mysql -uroot -p"$DB_ROOT_PASSWORD"
echo "[deploy] phase-1 DB cutover applied"

# 7b) PHASE-2 ADDITIVE MIGRATION (idempotent; RESEARCH Pitfall 1) -------------
# db/*.sql run ONLY on a fresh volume; the live volume already exists, so the
# new columns/tables + demo seed are applied here against the running DB.
# Non-destructive (ADD/CREATE IF NOT EXISTS) and idempotent — safe to re-run.
# Plain .sql (no envsubst — no secret placeholders). No `|| true`: a migration
# failure must surface, and mariadb is already confirmed healthy at this point.
echo "[deploy] applying db/02-migrate-phase2.sql (additive)"
docker compose exec -T mariadb mysql -uroot -p"$DB_ROOT_PASSWORD" proconnect_profile < db/02-migrate-phase2.sql
echo "[deploy] phase-2 additive migration applied"

# 7c) PHASE-3 ADDITIVE MIGRATION (idempotent; RESEARCH Pitfall 1) -------------
# Adds the connections table + demo graph seed to the live proconnect_connection
# volume. Non-destructive (CREATE IF NOT EXISTS + guarded seed), safe to re-run.
# Plain .sql (no secret placeholders). No `|| true`: a migration failure must
# surface and block deploy BEFORE full-topology up, so connection-service never
# boots against a missing `connections` table.
echo "[deploy] applying db/03-migrate-phase3.sql (additive)"
docker compose exec -T mariadb mysql -uroot -p"$DB_ROOT_PASSWORD" proconnect_connection < db/03-migrate-phase3.sql
echo "[deploy] phase-3 additive migration applied"

# 7d) PHASE-4 ADDITIVE MIGRATION (idempotent; RESEARCH Pitfall 1) -------------
# Adds posts/reactions/comments + demo feed seed to the live proconnect_feed
# volume. Non-destructive (CREATE IF NOT EXISTS + guarded seed), safe to re-run.
# Plain .sql (no secret placeholders). No `|| true`: a migration failure must
# surface and block deploy BEFORE full-topology up, so feed-service never boots
# against missing posts/reactions/comments tables.
echo "[deploy] applying db/04-migrate-phase4.sql (additive)"
docker compose exec -T mariadb mysql -uroot -p"$DB_ROOT_PASSWORD" proconnect_feed < db/04-migrate-phase4.sql
echo "[deploy] phase-4 additive migration applied"

# 7e) PHASE-5 ADDITIVE MIGRATION (idempotent; RESEARCH Pitfall 1) -------------
# Creates search_index (proconnect_search) + notifications (proconnect_notification)
# + demo seed in the live volume. Spans TWO DBs, so applied WITHOUT a DB arg —
# the file switches DBs internally via USE blocks (mirrors migrate-phase1.sql.tmpl).
# Non-destructive (CREATE IF NOT EXISTS + guarded seed), safe to re-run. No `|| true`:
# a failure must surface and block deploy BEFORE full-topology up, so search-service
# and notification-service never boot against missing tables.
echo "[deploy] applying db/05-migrate-phase5.sql (additive)"
docker compose exec -T mariadb mysql -uroot -p"$DB_ROOT_PASSWORD" < db/05-migrate-phase5.sql
echo "[deploy] phase-5 additive migration applied"

# 8) FULL-TOPOLOGY UP (ISSUE-3 step 4) -----------------------------------------
# NOW bring up the rest of the 8-container stack — the proconnect_* DBs/users
# exist, so the 5 PHP services can boot healthy. --remove-orphans drops the
# retired post/comment containers.
echo "[deploy] docker compose up -d --remove-orphans (full topology)"
docker compose up -d --remove-orphans

# Web container uses bind-mounted static files. New file content from git
# pull won't be visible until the container restarts (it tracks the old
# inode). Restart only when web/ actually changed.
if [[ $WEB_TOUCHED -eq 1 ]]; then
  echo "[deploy] web/ changed — restarting web container so new mounts apply"
  docker compose restart web
fi

# 9) GATEWAY HEALTH WAIT (ISSUE-3 step 5) --------------------------------------
echo "[deploy] waiting for gateway healthcheck (up to 90s)"
for i in $(seq 1 18); do
  if curl -sf http://127.0.0.1:8000/api/health > /dev/null; then
    echo "[deploy] gateway healthy after ${i}x5s"
    break
  fi
  sleep 5
  if [[ $i -eq 18 ]]; then
    echo "[deploy] FATAL: gateway not healthy after 90s" >&2
    docker compose ps
    exit 2
  fi
done

# 10) HOST NGINX SYNC ----------------------------------------------------------
# Sync host nginx config — required prerequisites for nginx-soa.duyet.vn.conf:
#   /etc/nginx/cloudflare-ips.conf              (set_real_ip_from)
#   /etc/nginx/conf.d/00-cloudflare-geo.conf    (geo $cf_allowed)
# Reload only when any of the 3 files actually changed (idempotent).
sync_nginx_file() {
  local src=$1 dst=$2
  if [[ ! -f "$src" ]]; then return 1; fi
  if [[ -f "$dst" ]] && diff -q "$src" "$dst" >/dev/null 2>&1; then
    return 1   # unchanged
  fi
  sudo cp "$src" "$dst"
  return 0     # changed
}

if command -v nginx >/dev/null 2>&1; then
  CHANGED=0
  sync_nginx_file deploy/nginx-soa.duyet.vn.conf /etc/nginx/sites-available/nginx-soa.duyet.vn.conf && CHANGED=1
  sync_nginx_file deploy/cloudflare-ips.conf      /etc/nginx/cloudflare-ips.conf                    && CHANGED=1
  sync_nginx_file deploy/cloudflare-geo.conf      /etc/nginx/conf.d/00-cloudflare-geo.conf          && CHANGED=1

  sudo ln -sf /etc/nginx/sites-available/nginx-soa.duyet.vn.conf \
              /etc/nginx/sites-enabled/soa.duyet.vn

  if [[ $CHANGED -eq 1 ]]; then
    echo "[deploy] nginx config changed — testing & reloading"
    sudo nginx -t && sudo systemctl reload nginx
  fi
fi

echo "[deploy] done."
docker compose ps
