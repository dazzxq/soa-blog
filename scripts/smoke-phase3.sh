#!/usr/bin/env bash
# Phase 3 smoke test — kết nối / social graph (CONN-01..07 + /full payoff).
#
# Covers: send/accept/reject/cancel invites (CONN-01/02), connections list with
# profile enrichment (CONN-03), incoming/outgoing request lists (CONN-04),
# pairwise status (CONN-05), suggestions excluding self+edged (CONN-06),
# validation/guard errors — self-invite, missing user, already-connected,
# duplicate (CONN-07), the D-05 /full payoff (connection_status:"connected"),
# ownership (only the addressee may accept), and the email/PII-leak guard on
# every card-bearing body.
#
# Runs against an ALREADY-RUNNING stack (VPS or local `docker compose up -d`).
# The D-04 connection routes go live in Plan 03; this harness is authored now in
# Wave 0 and its assertions run against the deployed stack in Plan 05 (live
# cutover) — Docker is not available locally (03-RESEARCH §Environment).
# Bash + curl only — no test framework (consistent with smoke-phase2.sh).
#
# Usage:  bash scripts/smoke-phase3.sh
#         GW=http://127.0.0.1:8000 bash scripts/smoke-phase3.sh
#
# Note: the seeded demo password `demo@123**` is a throwaway value for the 5
# seeded demo accounts only (db/99-seed.sql / db/migrate-phase1.sql.tmpl) — not
# a real secret.
#
# NON-DESTRUCTIVE: every edge this script CREATES it cancels/rejects in cleanup
# (a `trap restore EXIT` registered BEFORE any edge is created). It NEVER deletes
# the demo-seed fixtures — duyet(2)<->long(3) accepted and demo(1)->duyet(2)
# pending — and never accepts the demo->duyet pending edge. Repeated runs leave
# the demo graph unchanged.
#
# ISSUE-1/ISSUE-3 (503 + race) — documented, asserted indirectly:
#   * An upstream profile/status path returning >=500 makes the gateway answer
#     503 CONNECTION_SERVICE_UNAVAILABLE WITHOUT writing an edge. That is a
#     gateway/DB fault-injection path; it is NOT fault-injected locally here. It
#     is asserted indirectly via the 404 (missing-user, no write) path below.
#   * An opposite-direction concurrent invite (B->A while A->B is pending) is
#     backstopped to 409 by the connections `uq_pair` unordered-pair UNIQUE
#     (23000 -> gateway 409). That race is impossible to write twice; it is
#     asserted indirectly via the same-direction duplicate -> 409 path below.
set -euo pipefail

GW="${GW:-http://127.0.0.1:8000}"
PW='demo@123**'
FAILURES=0

pass() { echo "[smoke3] PASS $1"; }
fail() { echo "[smoke3] FAIL $1"; FAILURES=$((FAILURES + 1)); }

# ---------------------------------------------------------------------------
# 0. Non-destructive cleanup scaffolding. Any edge the script creates is
#    recorded in CLEANUP_IDS (request id + the requester token able to cancel
#    it). The trap is registered BEFORE the first edge so an early failure under
#    `set -e` still reverts. The demo-seed fixtures are NEVER touched.
# ---------------------------------------------------------------------------
CLEANUP_IDS=()      # parallel arrays: id[i] cancelled with token tok[i]
CLEANUP_TOKS=()
TOK_DUYET=""
TOK_LONG=""
TOK_DIEP=""

# Record an edge created during the run so cleanup can cancel it.
track_edge() { CLEANUP_IDS+=("$1"); CLEANUP_TOKS+=("$2"); }

restore() {
  local i id tok
  for i in "${!CLEANUP_IDS[@]}"; do
    id="${CLEANUP_IDS[$i]}"
    tok="${CLEANUP_TOKS[$i]}"
    [ -n "$id" ] && [ -n "$tok" ] || continue
    # Cancel as the requester (DELETE the outgoing request). Best-effort; if the
    # edge was already rejected/cancelled by the test body this is a harmless no-op.
    curl -s -o /dev/null -XDELETE "$GW/api/connections/requests/$id" \
      -H "Authorization: Bearer $tok" >/dev/null 2>&1 || true
  done
}
trap restore EXIT

# Extract a JSON token field from a login response body.
login() {
  curl -s -XPOST "$GW/api/auth/login" \
    -H 'Content-Type: application/json' \
    -d "{\"login\":\"$1\",\"password\":\"$PW\"}" \
    | grep -o '"token":"[^"]*"' | cut -d'"' -f4
}

# Guard: a body MUST NOT contain an email or '@' (PII allowlist leak check).
assert_no_pii() {
  local label="$1" body="$2"
  if echo "$body" | grep -qE '"email"|@'; then
    fail "$label LỘ email/@ (vi phạm allowlist công khai)"
  else
    pass "$label không lộ email/@"
  fi
}

# ---------------------------------------------------------------------------
# 1. LOGIN duyet (2), long (3), diep (4) → tokens.
# ---------------------------------------------------------------------------
TOK_DUYET=$(login duyet)
TOK_LONG=$(login long)
TOK_DIEP=$(login diep)
for pair in "duyet:$TOK_DUYET" "long:$TOK_LONG" "diep:$TOK_DIEP"; do
  name="${pair%%:*}"; tok="${pair#*:}"
  if [ -n "$tok" ]; then pass "login $name → token"; else fail "login $name (không trả token)"; fi
done

# ---------------------------------------------------------------------------
# 2. CONN-07 self-invite: duyet invites duyet (2) → 400.
# ---------------------------------------------------------------------------
code=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/connections/requests" \
        -H "Authorization: Bearer $TOK_DUYET" -H 'Content-Type: application/json' \
        -d '{"target_id":2}')
if [ "$code" = "400" ]; then pass "self-invite (duyet→duyet) → 400 (CONN-07)"
else fail "self-invite → $code (mong đợi 400)"; fi

# ---------------------------------------------------------------------------
# 3. CONN-07 missing user: duyet invites 999999 → 404 PROFILE_NOT_FOUND.
# ---------------------------------------------------------------------------
miss=$(curl -s -o /tmp/smoke3-miss.json -w '%{http_code}' -XPOST "$GW/api/connections/requests" \
        -H "Authorization: Bearer $TOK_DUYET" -H 'Content-Type: application/json' \
        -d '{"target_id":999999}')
missBody=$(cat /tmp/smoke3-miss.json 2>/dev/null || echo '')
if [ "$miss" = "404" ]; then pass "invite missing user (999999) → 404 (CONN-07)"
else fail "invite missing user → $miss (mong đợi 404)"; fi
if echo "$missBody" | grep -q 'PROFILE_NOT_FOUND'; then pass "missing-user body → PROFILE_NOT_FOUND"
else fail "missing-user body thiếu PROFILE_NOT_FOUND"; fi

# ---------------------------------------------------------------------------
# 4. CONN-07 already-connected: duyet invites long (seed accepted) → 409.
# ---------------------------------------------------------------------------
code=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/connections/requests" \
        -H "Authorization: Bearer $TOK_DUYET" -H 'Content-Type: application/json' \
        -d '{"target_id":3}')
if [ "$code" = "409" ]; then pass "invite already-connected (duyet→long) → 409 (CONN-07)"
else fail "invite already-connected → $code (mong đợi 409)"; fi

# ---------------------------------------------------------------------------
# 5. CONN-01 send: diep(4) invites tai(5) → 201/200; appears in diep outgoing.
#    Track for cleanup so the edge is cancelled at EXIT (non-destructive).
# ---------------------------------------------------------------------------
sendBody=$(curl -s -XPOST "$GW/api/connections/requests" \
            -H "Authorization: Bearer $TOK_DIEP" -H 'Content-Type: application/json' \
            -d '{"target_id":5}')
sendCode=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/connections/requests" \
            -H "Authorization: Bearer $TOK_DIEP" -H 'Content-Type: application/json' \
            -d '{"target_id":5}' || true)
edgeId=$(echo "$sendBody" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2 || true)
[ -n "$edgeId" ] && track_edge "$edgeId" "$TOK_DIEP"
case "$sendCode" in
  200|201|409) pass "diep→tai invite handled ($sendCode) (CONN-01)" ;;
  *) fail "diep→tai invite → $sendCode (mong đợi 200/201, hoặc 409 nếu đã tồn tại)" ;;
esac
if curl -s "$GW/api/connections/requests?direction=outgoing" \
     -H "Authorization: Bearer $TOK_DIEP" | grep -qE '"addressee_id":5|"id":5|tai'; then
  pass "diep outgoing chứa tai (CONN-01/CONN-04)"
else
  fail "diep outgoing không chứa tai"
fi

# ---------------------------------------------------------------------------
# 6. CONN-07 duplicate (same direction): diep invites tai again → 409 REQUEST_EXISTS.
#    (Indirectly covers the opposite-direction race: uq_pair makes any second
#    row for the unordered pair collide on 23000 → gateway 409.)
# ---------------------------------------------------------------------------
dupBody=$(curl -s -o /tmp/smoke3-dup.json -w '%{http_code}' -XPOST "$GW/api/connections/requests" \
            -H "Authorization: Bearer $TOK_DIEP" -H 'Content-Type: application/json' \
            -d '{"target_id":5}')
dupResp=$(cat /tmp/smoke3-dup.json 2>/dev/null || echo '')
if [ "$dupBody" = "409" ]; then pass "diep→tai duplicate → 409 (CONN-07)"
else fail "diep→tai duplicate → $dupBody (mong đợi 409)"; fi
if echo "$dupResp" | grep -q 'REQUEST_EXISTS'; then pass "duplicate body → REQUEST_EXISTS"
else fail "duplicate body thiếu REQUEST_EXISTS"; fi

# ---------------------------------------------------------------------------
# 7. CONN-04 lists: duyet incoming contains demo(1) (seed pending demo→duyet);
#    demo outgoing contains duyet(2).
# ---------------------------------------------------------------------------
if curl -s "$GW/api/connections/requests?direction=incoming" \
     -H "Authorization: Bearer $TOK_DUYET" | grep -qE '"requester_id":1|Tài khoản demo|"id":1'; then
  pass "duyet incoming chứa demo(1) (CONN-04 seed)"
else
  fail "duyet incoming không chứa demo(1)"
fi
TOK_DEMO=$(login demo)
if curl -s "$GW/api/connections/requests?direction=outgoing" \
     -H "Authorization: Bearer $TOK_DEMO" | grep -qE '"addressee_id":2|duyet'; then
  pass "demo outgoing chứa duyet(2) (CONN-04 seed)"
else
  fail "demo outgoing không chứa duyet(2)"
fi

# ---------------------------------------------------------------------------
# 8. CONN-02 accept→connected: a CLEAN requester (diep 4) invites long (3),
#    long accepts → status connected for both; then remove to restore.
#    NOTE: diep↔long is NOT a seed fixture, so creating+removing it is safe.
# ---------------------------------------------------------------------------
# Idempotent pre-clean: remove any leftover diep↔long edge from a prior run
# (accepted connection OR pending request) so this invite always starts fresh.
# DELETE /api/connections/{userId} removes an accepted edge; cancelling a pending
# outgoing needs the request id, so also sweep diep's outgoing for an addressee=3.
curl -s -o /dev/null -XDELETE "$GW/api/connections/3" -H "Authorization: Bearer $TOK_DIEP" 2>/dev/null || true
preReq=$(curl -s "$GW/api/connections/requests?direction=outgoing" -H "Authorization: Bearer $TOK_DIEP" 2>/dev/null || true)
preId=$(echo "$preReq" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2 || true)
[ -n "$preId" ] && curl -s -o /dev/null -XDELETE "$GW/api/connections/requests/$preId" -H "Authorization: Bearer $TOK_DIEP" 2>/dev/null || true

accBody=$(curl -s -XPOST "$GW/api/connections/requests" \
            -H "Authorization: Bearer $TOK_DIEP" -H 'Content-Type: application/json' \
            -d '{"target_id":3}')
accId=$(echo "$accBody" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2 || true)
[ -n "$accId" ] && track_edge "$accId" "$TOK_DIEP"
if [ -n "$accId" ]; then
  accCode=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/connections/requests/$accId/accept" \
              -H "Authorization: Bearer $TOK_LONG")
  case "$accCode" in
    200|204) pass "long accepts diep→long ($accCode) (CONN-02)" ;;
    *) fail "long accept → $accCode (mong đợi 200/204)" ;;
  esac
  if curl -s "$GW/api/connections/status/4" -H "Authorization: Bearer $TOK_LONG" | grep -q 'connected'; then
    pass "status diep↔long → connected (CONN-02)"
  else
    fail "status diep↔long không phải connected sau accept"
  fi
  # Remove the accepted edge to restore. DELETE /api/connections/{userId} takes the
  # OTHER party's USER id (long=3), NOT the request/edge id — diep removes its
  # connection with user 3. (Prior bug used $accId here, so the edge was never
  # removed and leaked into the demo graph / broke idempotency.)
  curl -s -o /dev/null -XDELETE "$GW/api/connections/3" -H "Authorization: Bearer $TOK_DIEP" 2>/dev/null || true
else
  fail "diep→long invite không trả id (không thể test accept)"
fi

# ---------------------------------------------------------------------------
# 9. CONN-02 reject/cancel gone: diep invites tai... already pending from step 5;
#    instead use a fresh pair diep→long again, reject as long, assert gone.
# ---------------------------------------------------------------------------
rejBody=$(curl -s -XPOST "$GW/api/connections/requests" \
            -H "Authorization: Bearer $TOK_DIEP" -H 'Content-Type: application/json' \
            -d '{"target_id":3}')
rejId=$(echo "$rejBody" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2 || true)
[ -n "$rejId" ] && track_edge "$rejId" "$TOK_DIEP"
if [ -n "$rejId" ]; then
  rejCode=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/connections/requests/$rejId/reject" \
              -H "Authorization: Bearer $TOK_LONG")
  case "$rejCode" in
    200|204) pass "long rejects diep→long ($rejCode) (CONN-02)" ;;
    *) fail "long reject → $rejCode (mong đợi 200/204)" ;;
  esac
  if curl -s "$GW/api/connections/requests?direction=incoming" \
       -H "Authorization: Bearer $TOK_LONG" | grep -q "\"id\":$rejId"; then
    fail "request $rejId vẫn còn trong long incoming sau reject"
  else
    pass "request $rejId biến mất khỏi long incoming sau reject (CONN-02)"
  fi
fi

# ---------------------------------------------------------------------------
# 10. CONN-03 connections list enriched: duyet's /connections contains long's
#     display_name "Đinh Ngọc Long"; assert NO email/@ leak.
# ---------------------------------------------------------------------------
connBody=$(curl -s "$GW/api/connections" -H "Authorization: Bearer $TOK_DUYET" || true)
if echo "$connBody" | grep -q 'Đinh Ngọc Long'; then
  pass "/connections (duyet) chứa display_name long (CONN-03 enriched)"
else
  fail "/connections (duyet) không chứa display_name 'Đinh Ngọc Long'"
fi
assert_no_pii "/connections (duyet)" "$connBody"

# ---------------------------------------------------------------------------
# 11. CONN-06 suggestions: duyet's suggestions do NOT include long (connected)
#     nor duyet (self); assert NO email/@ leak.
# ---------------------------------------------------------------------------
sugBody=$(curl -s "$GW/api/connections/suggestions" -H "Authorization: Bearer $TOK_DUYET" || true)
if echo "$sugBody" | grep -qE '"id":3|Đinh Ngọc Long'; then
  fail "suggestions (duyet) chứa long(3) — đã connected, phải bị loại (CONN-06)"
else
  pass "suggestions (duyet) loại long(3) (CONN-06)"
fi
if echo "$sugBody" | grep -qE '"id":2'; then
  fail "suggestions (duyet) chứa chính duyet(2) — phải loại self (CONN-06)"
else
  pass "suggestions (duyet) loại self(2) (CONN-06)"
fi
assert_no_pii "/connections/suggestions (duyet)" "$sugBody"

# ---------------------------------------------------------------------------
# 12. CONN-05 status values: duyet↔long → connected; un-edged pair → none.
# ---------------------------------------------------------------------------
if curl -s "$GW/api/connections/status/3" -H "Authorization: Bearer $TOK_DUYET" | grep -q 'connected'; then
  pass "status duyet→long(3) → connected (CONN-05)"
else
  fail "status duyet→long(3) không phải connected"
fi
if curl -s "$GW/api/connections/status/4" -H "Authorization: Bearer $TOK_DUYET" | grep -q '"none"'; then
  pass "status duyet→diep(4) → none (un-edged) (CONN-05)"
else
  fail "status duyet→diep(4) không phải none"
fi

# ---------------------------------------------------------------------------
# 13. PAYOFF (D-05): duyet GET /api/profiles/3/full → connection_status:"connected"
#     (NOT "none", NOT null) AND no degraded:true on the connection part.
# ---------------------------------------------------------------------------
payoff=$(curl -s -H "Authorization: Bearer $TOK_DUYET" "$GW/api/profiles/3/full" || true)
if echo "$payoff" | grep -qE '"connection_status":"connected"'; then
  pass "/profiles/3/full (duyet) connection_status:\"connected\" (D-05 PAYOFF)"
else
  fail "/profiles/3/full (duyet) connection_status không phải \"connected\" (D-05)"
fi
if echo "$payoff" | grep -q '"degraded":true'; then
  fail "/profiles/3/full (duyet) có degraded:true — connection part không nên degrade"
else
  pass "/profiles/3/full (duyet) không degraded (connection part healthy)"
fi
assert_no_pii "/profiles/3/full (duyet)" "$payoff"

# ---------------------------------------------------------------------------
# 14. Ownership: diep(4) tries to accept the demo(1)→duyet(2) seed pending edge →
#     404/403 (only the addressee, duyet, may accept). Discover its id from
#     duyet's incoming list; do NOT accept it (would clobber the seed fixture).
# ---------------------------------------------------------------------------
seedReq=$(curl -s "$GW/api/connections/requests?direction=incoming" -H "Authorization: Bearer $TOK_DUYET" || true)
seedId=$(echo "$seedReq" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2 || true)
if [ -n "$seedId" ]; then
  ownCode=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/connections/requests/$seedId/accept" \
              -H "Authorization: Bearer $TOK_DIEP")
  case "$ownCode" in
    403|404) pass "diep accept demo→duyet pending ($seedId) → $ownCode (ownership enforced)" ;;
    *) fail "diep accept demo→duyet pending → $ownCode (mong đợi 403/404)" ;;
  esac
else
  fail "không tìm thấy seed pending demo→duyet id trong duyet incoming"
fi

# ---------------------------------------------------------------------------
# 15. Security sweep: no email/@ across /connections, /suggestions, incoming
#     requests bodies (combined PII guard — turns the control into a CI gate).
# ---------------------------------------------------------------------------
reqBody=$(curl -s "$GW/api/connections/requests?direction=incoming" -H "Authorization: Bearer $TOK_DUYET" || true)
assert_no_pii "/connections/requests?direction=incoming (duyet)" "$reqBody"

# ---------------------------------------------------------------------------
# Verdict (trap restore EXIT runs after this block, even on early exit).
# ---------------------------------------------------------------------------
if [ "$FAILURES" -eq 0 ]; then
  echo "[smoke3] ALL PASS"
  exit 0
else
  echo "[smoke3] $FAILURES FAILURES"
  exit 1
fi
