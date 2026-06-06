#!/usr/bin/env bash
# Phase 2 smoke test — hồ sơ nghề nghiệp (PROF-02..07 + UI-03 backing API).
#
# Covers: /full composition + degrade (D-01/D-02/D-03), public+auth-aware view
# (D-04/PROF-06), basic edit (PROF-02), experience/education/skills CRUD
# round-trips (PROF-03/04/05) + dedupe 409, owner-scope/no-IDOR (D-10/D-11),
# auth-required mutations, and the email-leak guard on the public /full route.
#
# Runs against an ALREADY-RUNNING stack (VPS or local `docker compose up -d`).
# Bash + curl only — no test framework (consistent with smoke-phase1.sh).
#
# Usage:  bash scripts/smoke-phase2.sh
#         GW=http://127.0.0.1:8000 bash scripts/smoke-phase2.sh
#
# Note: the seeded demo password `demo@123**` is a throwaway value for the 5
# seeded demo accounts only (db/99-seed.sql) — not a real secret.
#
# NON-DESTRUCTIVE: this script mutates ONLY user 2's `headline` (which the demo
# seed guarantees non-null) and restores it via a `trap restore EXIT` registered
# BEFORE the original value is captured. Experience/education/skills checks
# delete whatever they create. Repeated runs leave the demo data unchanged.
set -euo pipefail

GW="${GW:-http://127.0.0.1:8000}"
PW='demo@123**'
FAILURES=0

pass() { echo "[smoke2] PASS $1"; }
fail() { echo "[smoke2] FAIL $1"; FAILURES=$((FAILURES + 1)); }

# ---------------------------------------------------------------------------
# 0. Non-destructive restore scaffolding (registered BEFORE any capture so an
#    early failure under `set -e` still reverts). RESTORE_READY gates the
#    restore so it never writes an empty/garbage headline.
# ---------------------------------------------------------------------------
RESTORE_READY=0
ORIG_HL=""
TOK=""

# JSON-escape a string (backslash then double-quote) so the restore PATCH body
# is always valid JSON even if the original headline contains quotes/backslashes.
jesc() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }

restore() {
  [ "$RESTORE_READY" = 1 ] && [ -n "$TOK" ] || return 0
  curl -s -XPATCH "$GW/api/profiles/me" \
    -H "Authorization: Bearer $TOK" \
    -H 'Content-Type: application/json' \
    -d "{\"headline\":\"$(jesc "$ORIG_HL")\"}" >/dev/null 2>&1 || true
}
trap restore EXIT

# ---------------------------------------------------------------------------
# 1. LOGIN duyet (id 2) → token.
# ---------------------------------------------------------------------------
TOK=$(curl -s -XPOST "$GW/api/auth/login" \
        -H 'Content-Type: application/json' \
        -d "{\"login\":\"duyet\",\"password\":\"$PW\"}" \
      | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
if [ -n "$TOK" ]; then
  pass "login duyet → token"
else
  fail "login duyet (không trả token) — bỏ qua các bước cần auth"
fi

# ---------------------------------------------------------------------------
# 2. PROF-06 public view: /full KHÔNG kèm token → 200 + đủ section + connection_status null.
# ---------------------------------------------------------------------------
code=$(curl -s -o /tmp/smoke2-full-anon.json -w '%{http_code}' "$GW/api/profiles/2/full")
body=$(cat /tmp/smoke2-full-anon.json 2>/dev/null || echo '')
if [ "$code" = "200" ]; then
  pass "GET /api/profiles/2/full (anon) → 200"
else
  fail "GET /api/profiles/2/full (anon) → $code (mong đợi 200)"
fi
for k in '"experience"' '"education"' '"skills"' '"connection_status"'; do
  if echo "$body" | grep -q "$k"; then
    pass "/full chứa $k"
  else
    fail "/full thiếu $k"
  fi
done
if echo "$body" | grep -q '"connection_status":null'; then
  pass "/full (anon) connection_status:null (D-04)"
else
  fail "/full (anon) connection_status không phải null (D-04)"
fi

# ---------------------------------------------------------------------------
# 3. SECURITY: /full (anon) KHÔNG được lộ email/@ (PII leak — Pitfall 2).
# ---------------------------------------------------------------------------
if echo "$body" | grep -qE '"email"|@'; then
  fail "/full (anon) LỘ email/@ (vi phạm allowlist công khai)"
else
  pass "/full (anon) không lộ email/@"
fi

# ---------------------------------------------------------------------------
# 4. PROF-07 + degrade: connection-service là stub → meta.degraded:true + parts.
# ---------------------------------------------------------------------------
if echo "$body" | grep -q '"degraded":true'; then
  pass "/full meta.degraded:true (D-02/D-03 — connection stub)"
else
  fail "/full thiếu meta.degraded:true (degrade không hoạt động)"
fi
if echo "$body" | grep -qE '"parts"|connection'; then
  pass "/full degrade liệt kê phần lỗi (parts/connection)"
else
  fail "/full degrade không liệt kê phần lỗi"
fi

# ---------------------------------------------------------------------------
# 5. PROF-04/D-04 auth-aware: /full KÈM token → connection_status:"none" (không null).
# ---------------------------------------------------------------------------
authBody=$(curl -s -H "Authorization: Bearer $TOK" "$GW/api/profiles/2/full" || true)
if echo "$authBody" | grep -q '"connection_status":"none"'; then
  pass "/full (auth) connection_status:\"none\" (D-04 auth-aware)"
else
  fail "/full (auth) connection_status không phải \"none\" (D-04)"
fi

# ---------------------------------------------------------------------------
# 6. PROF-02 edit basic (headline-only, non-destructive). Capture ORIG_HL null-safe
#    then mark RESTORE_READY; trap (registered above) reverts at EXIT.
# ---------------------------------------------------------------------------
FULL2=$(curl -s "$GW/api/profiles/2/full" || true)
if [ -n "$FULL2" ]; then
  ORIG_HL=$(printf '%s' "$FULL2" | grep -o '"headline":"[^"]*"' | head -1 | cut -d'"' -f4 || true)
  [ -n "$ORIG_HL" ] && RESTORE_READY=1
fi
if [ "$RESTORE_READY" = 1 ]; then
  pass "capture original headline (restore gate armed)"
else
  fail "không lấy được headline gốc (seed thiếu? — restore sẽ bị bỏ qua)"
fi

patchBody=$(curl -s -XPATCH "$GW/api/profiles/me" \
              -H "Authorization: Bearer $TOK" \
              -H 'Content-Type: application/json' \
              -d '{"headline":"SMOKE-HL"}' || true)
patchCode=$(curl -s -o /dev/null -w '%{http_code}' -XPATCH "$GW/api/profiles/me" \
              -H "Authorization: Bearer $TOK" \
              -H 'Content-Type: application/json' \
              -d '{"headline":"SMOKE-HL"}')
if [ "$patchCode" = "200" ]; then
  pass "PATCH /api/profiles/me headline → 200 (PROF-02)"
else
  fail "PATCH /api/profiles/me headline → $patchCode (mong đợi 200)"
fi
# email-leak guard on the PATCH /me response too (gateway allowlist must strip it).
if echo "$patchBody" | grep -qE '"email"|@'; then
  fail "PATCH /me LỘ email/@ trong response"
else
  pass "PATCH /me không lộ email/@"
fi
if curl -s "$GW/api/profiles/2/full" | grep -q 'SMOKE-HL'; then
  pass "/full phản ánh headline mới (SMOKE-HL)"
else
  fail "/full không phản ánh headline mới"
fi

# ---------------------------------------------------------------------------
# 7. PROF-03 experience round-trip: POST → /full chứa → DELETE → biến mất.
# ---------------------------------------------------------------------------
expId=$(curl -s -XPOST "$GW/api/profiles/me/experience" \
          -H "Authorization: Bearer $TOK" \
          -H 'Content-Type: application/json' \
          -d '{"company":"SmokeCo","title":"Dev","start_date":"2020-01-01"}' \
        | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
if [ -n "$expId" ]; then
  pass "POST /me/experience → id=$expId"
else
  fail "POST /me/experience không trả id"
fi
if curl -s "$GW/api/profiles/2/full" | grep -q 'SmokeCo'; then
  pass "/full chứa SmokeCo (experience)"
else
  fail "/full không chứa SmokeCo"
fi
if [ -n "$expId" ]; then
  delCode=$(curl -s -o /dev/null -w '%{http_code}' -XDELETE "$GW/api/profiles/me/experience/$expId" \
              -H "Authorization: Bearer $TOK")
  case "$delCode" in
    200|204) pass "DELETE /me/experience/$expId → $delCode" ;;
    *) fail "DELETE /me/experience/$expId → $delCode (mong đợi 200/204)" ;;
  esac
  if curl -s "$GW/api/profiles/2/full" | grep -q 'SmokeCo'; then
    fail "/full vẫn còn SmokeCo sau DELETE"
  else
    pass "/full không còn SmokeCo sau DELETE"
  fi
fi

# ---------------------------------------------------------------------------
# 8. PROF-04 education round-trip.
# ---------------------------------------------------------------------------
eduId=$(curl -s -XPOST "$GW/api/profiles/me/education" \
          -H "Authorization: Bearer $TOK" \
          -H 'Content-Type: application/json' \
          -d '{"school":"SmokeUni","degree":"CNTT"}' \
        | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
if [ -n "$eduId" ]; then
  pass "POST /me/education → id=$eduId"
else
  fail "POST /me/education không trả id"
fi
if curl -s "$GW/api/profiles/2/full" | grep -q 'SmokeUni'; then
  pass "/full chứa SmokeUni (education)"
else
  fail "/full không chứa SmokeUni"
fi
if [ -n "$eduId" ]; then
  delCode=$(curl -s -o /dev/null -w '%{http_code}' -XDELETE "$GW/api/profiles/me/education/$eduId" \
              -H "Authorization: Bearer $TOK")
  case "$delCode" in
    200|204) pass "DELETE /me/education/$eduId → $delCode" ;;
    *) fail "DELETE /me/education/$eduId → $delCode (mong đợi 200/204)" ;;
  esac
  if curl -s "$GW/api/profiles/2/full" | grep -q 'SmokeUni'; then
    fail "/full vẫn còn SmokeUni sau DELETE"
  else
    pass "/full không còn SmokeUni sau DELETE"
  fi
fi

# ---------------------------------------------------------------------------
# 9. PROF-05 skills add/remove + dedupe (409 trên trùng).
# ---------------------------------------------------------------------------
skId=$(curl -s -XPOST "$GW/api/profiles/me/skills" \
         -H "Authorization: Bearer $TOK" \
         -H 'Content-Type: application/json' \
         -d '{"name":"SmokeSkill"}' \
       | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
if [ -n "$skId" ]; then
  pass "POST /me/skills → id=$skId"
else
  fail "POST /me/skills không trả id"
fi
if curl -s "$GW/api/profiles/2/full" | grep -q 'SmokeSkill'; then
  pass "/full chứa SmokeSkill"
else
  fail "/full không chứa SmokeSkill"
fi
dupCode=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/profiles/me/skills" \
            -H "Authorization: Bearer $TOK" \
            -H 'Content-Type: application/json' \
            -d '{"name":"SmokeSkill"}')
if [ "$dupCode" = "409" ]; then
  pass "POST /me/skills trùng → 409 (dedupe)"
else
  fail "POST /me/skills trùng → $dupCode (mong đợi 409)"
fi
if [ -n "$skId" ]; then
  delCode=$(curl -s -o /dev/null -w '%{http_code}' -XDELETE "$GW/api/profiles/me/skills/$skId" \
              -H "Authorization: Bearer $TOK")
  case "$delCode" in
    200|204) pass "DELETE /me/skills/$skId → $delCode" ;;
    *) fail "DELETE /me/skills/$skId → $delCode (mong đợi 200/204)" ;;
  esac
  if curl -s "$GW/api/profiles/2/full" | grep -q 'SmokeSkill'; then
    fail "/full vẫn còn SmokeSkill sau DELETE"
  else
    pass "/full không còn SmokeSkill sau DELETE"
  fi
fi

# ---------------------------------------------------------------------------
# 10. OWNER-SCOPE / no-IDOR: gateway KHÔNG mở route ghi theo numeric id.
#     PATCH /api/profiles/3 phải 404/405; và /full của user 3 không chứa HACK.
# ---------------------------------------------------------------------------
idorCode=$(curl -s -o /dev/null -w '%{http_code}' -XPATCH "$GW/api/profiles/3" \
             -H "Authorization: Bearer $TOK" \
             -H 'Content-Type: application/json' \
             -d '{"headline":"HACK"}')
case "$idorCode" in
  404|405) pass "PATCH /api/profiles/3 → $idorCode (không có route ghi theo id)" ;;
  *) fail "PATCH /api/profiles/3 → $idorCode (mong đợi 404/405 — nguy cơ IDOR)" ;;
esac
if curl -s "$GW/api/profiles/3/full" | grep -q 'HACK'; then
  fail "hồ sơ user 3 bị thay đổi (HACK) — IDOR!"
else
  pass "hồ sơ user 3 không đổi (không IDOR)"
fi

# ---------------------------------------------------------------------------
# 11. Mutations require auth: PATCH /me KHÔNG token → 401.
# ---------------------------------------------------------------------------
noauthCode=$(curl -s -o /dev/null -w '%{http_code}' -XPATCH "$GW/api/profiles/me" \
               -H 'Content-Type: application/json' \
               -d '{"headline":"X"}')
if [ "$noauthCode" = "401" ]; then
  pass "PATCH /api/profiles/me (no token) → 401"
else
  fail "PATCH /api/profiles/me (no token) → $noauthCode (mong đợi 401)"
fi

# ---------------------------------------------------------------------------
# Verdict (trap restore EXIT chạy sau khối này, kể cả khi exit sớm).
# ---------------------------------------------------------------------------
if [ "$FAILURES" -eq 0 ]; then
  echo "[smoke2] ALL PASS"
  exit 0
else
  echo "[smoke2] $FAILURES FAILURES"
  exit 1
fi
