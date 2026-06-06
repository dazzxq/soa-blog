#!/usr/bin/env bash
# Phase 1 smoke test — the single local gate every later wave runs after
# `docker compose up -d --wait`, and the pre-push check before CI deploy.
#
# Covers PLAT-01..05 + PROF-01 + decisions D-06 / D-10 / D-12.
# Runs against an ALREADY-RUNNING stack (caller does `docker compose up -d --wait`
# first). Bash + curl + docker compose only — no test framework.
#
# Usage:  bash scripts/smoke-phase1.sh
#         GW=http://127.0.0.1:8000 bash scripts/smoke-phase1.sh
#
# Note: the seeded demo password `demo@123**` is a throwaway value for the 5
# seeded demo accounts only (see db/99-seed.sql) — not a real secret (T-1-00, accepted).
set -euo pipefail

GW="${GW:-http://127.0.0.1:8000}"
FAILURES=0

pass() { echo "[smoke] PASS $1"; }
fail() { echo "[smoke] FAIL $1"; FAILURES=$((FAILURES + 1)); }

# ---------------------------------------------------------------------------
# 1. CONTAINER COUNT (PLAT-05): tổng container ≤ 9 (mục tiêu 8) — ngân sách 2GB RAM.
# ---------------------------------------------------------------------------
count=$(docker compose ps --services | wc -l | tr -d ' ')
echo "[smoke] container count = $count (trần ≤ 9)"
if [ "$count" -le 9 ]; then
  pass "container-count ($count ≤ 9)"
else
  fail "container-count ($count > 9 — vượt ngân sách 2GB RAM)"
fi

# ---------------------------------------------------------------------------
# 2. PER-SERVICE HEALTH (PLAT-01 / PLAT-05): mỗi stub /health phải có "db":"ok".
#    Gọi nội bộ qua gateway container (services không expose host port).
# ---------------------------------------------------------------------------
for s in profile connection feed search notification; do
  if docker compose exec -T gateway wget -qO- "http://$s-service/health" 2>/dev/null | grep -q '"db":"ok"'; then
    pass "health $s-service"
  else
    fail "health $s-service (db không ok)"
  fi
done

# ---------------------------------------------------------------------------
# 3. GATEWAY FAN-OUT (PLAT-01 / D-10): /api/health gộp đủ 5 service và status ok.
# ---------------------------------------------------------------------------
if body=$(curl -sf "$GW/api/health"); then
  if echo "$body" | grep -q '"status":"ok"'; then
    pass "gateway /api/health status ok"
  else
    fail "gateway /api/health status không ok ($body)"
  fi
  for s in profile connection feed search notification; do
    if echo "$body" | grep -q "\"$s\""; then
      pass "gateway fan-out chứa $s"
    else
      fail "gateway fan-out thiếu $s"
    fi
  done
else
  fail "gateway /api/health không phản hồi 2xx"
fi

# ---------------------------------------------------------------------------
# 4. PROFILE ROUTE (PLAT-02): /api/profiles/2 trả về account 'duyet' (id 2).
# ---------------------------------------------------------------------------
if curl -sf "$GW/api/profiles/2" | grep -q duyet; then
  pass "profile route /api/profiles/2 -> duyet"
else
  fail "profile route /api/profiles/2 không chứa 'duyet'"
fi

# ---------------------------------------------------------------------------
# 5. AUTH GUARD (PLAT-03): /api/me không kèm Authorization phải trả 401.
# ---------------------------------------------------------------------------
code=$(curl -s -o /dev/null -w '%{http_code}' "$GW/api/me")
if [ "$code" = "401" ]; then
  pass "auth guard /api/me -> 401"
else
  fail "auth guard /api/me -> $code (mong đợi 401)"
fi

# ---------------------------------------------------------------------------
# 6. REQUEST-ID DOWNSTREAM RECEIPT (PLAT-04 / D-12 — ISSUE-5).
#    Một request tới gateway: gateway sinh X-Request-Id, forward xuống mỗi stub,
#    mỗi stub echo lại id đó dưới khoá "rid" trong body /health của mình; gateway
#    đẩy các body này lên services.<svc>. Ta chứng minh DOWNSTREAM nhận được id
#    bằng cách: id ở header response của gateway == "rid" xuất hiện trong body.
# ---------------------------------------------------------------------------
resp=$(curl -s -D /tmp/smoke-hdr.txt "$GW/api/health")
rid=$(grep -i '^x-request-id:' /tmp/smoke-hdr.txt | tr -d '\r' | awk '{print $2}')
if [ -z "$rid" ]; then
  fail "request-id-downstream (gateway không set header X-Request-Id trên response)"
elif echo "$resp" | grep -q "\"rid\":\"$rid\""; then
  pass "request-id-downstream (X-Request-Id=$rid được downstream echo lại trong body)"
else
  fail "request-id-downstream (gateway X-Request-Id=$rid not echoed by any service)"
fi

# ---------------------------------------------------------------------------
# 7. LOGIN ALL 5 ACCOUNTS (PROF-01 / D-06): mỗi tài khoản demo login ra token.
# ---------------------------------------------------------------------------
for u in demo duyet long diep tai; do
  if curl -sf -XPOST "$GW/api/auth/login" \
       -H 'Content-Type: application/json' \
       -d "{\"login\":\"$u\",\"password\":\"demo@123**\"}" | grep -q '"token"'; then
    pass "login $u"
  else
    fail "login $u (không trả token)"
  fi
done

# ---------------------------------------------------------------------------
# Verdict
# ---------------------------------------------------------------------------
if [ "$FAILURES" -eq 0 ]; then
  echo "[smoke] ALL PASS"
  exit 0
else
  echo "[smoke] $FAILURES FAILURES"
  exit 1
fi
