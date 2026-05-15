#!/usr/bin/env bash
# Manual / CI deploy script. Run on the VPS inside the project directory.
# Idempotent: safe to invoke repeatedly.
set -euo pipefail

PROJECT_DIR="${PROJECT_DIR:-$(pwd)}"
cd "$PROJECT_DIR"

if [[ ! -f .env ]]; then
  echo "[deploy] FATAL: .env is missing. Create it from .env.example first." >&2
  exit 1
fi

echo "[deploy] git pull --ff-only"
# Detect which areas changed BEFORE pulling, so we can force-restart
# containers whose only mounted file (nginx.conf, html, etc.) changed —
# bind mounts in Docker survive git updates by inode, so the container
# keeps reading the pre-pull file content otherwise.
BEFORE_SHA=$(git rev-parse HEAD)
git pull --ff-only
AFTER_SHA=$(git rev-parse HEAD)

WEB_TOUCHED=0
if [[ "$BEFORE_SHA" != "$AFTER_SHA" ]]; then
  if git diff --name-only "$BEFORE_SHA" "$AFTER_SHA" | grep -qE '^web/'; then
    WEB_TOUCHED=1
  fi
fi

echo "[deploy] docker compose build"
docker compose build --pull

echo "[deploy] docker compose up -d (with healthcheck wait)"
docker compose up -d --remove-orphans

# Web container uses bind-mounted static files. New file content from git
# pull won't be visible until the container restarts (it tracks the old
# inode). Restart only when web/ actually changed.
if [[ $WEB_TOUCHED -eq 1 ]]; then
  echo "[deploy] web/ changed — restarting web container so new mounts apply"
  docker compose restart web
fi

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
