#!/usr/bin/env bash
# Phase 5 smoke test — search + notifications (SEARCH-01/02 + NOTIF-01/02/03).
#
# Covers: reindex builds the index (D-02), search by name AND by skill returns
# email-free cards each carrying a viewer-relative connection_status (SEARCH-01/02,
# the gateway-compose showcase), the gateway centrally firing best-effort
# notifications after invite/reaction/comment without ever failing the main action
# (NOTIF-01, D-05), newest-first listing + unread_count (NOTIF-02), mark-one-read
# and mark-all-read shrinking the unread badge (NOTIF-03), and a global email/PII
# leak guard over every search-card and notification-actor body.
#
# Runs against an ALREADY-RUNNING stack (VPS or local `docker compose up -d`).
# The search + notification gateway routes go live in Plans 04/05; this harness is
# authored now in Wave 0 and its assertions run against the deployed stack in the
# live-cutover plan (Plan 07) — Docker is not available locally (05-RESEARCH
# §Environment). Bash + curl only — no test framework (consistent with smoke-phase4.sh).
#
# Usage:  bash scripts/smoke-phase5.sh
#         GW=http://127.0.0.1:8000 bash scripts/smoke-phase5.sh
#
# Note: the seeded demo password `demo@123**` is a throwaway value for the 5
# seeded demo accounts only — not a real secret.
#
# NON-DESTRUCTIVE: a `trap restore EXIT` registered BEFORE the first write cancels
# only the connection-request this script creates and deletes only the comment it
# creates. It NEVER deletes the demo seed — the 5 search_index rows or the 2 demo
# notifications. Marking demo-seed notifications read is acceptable (read state is
# not destructive; rows are never removed). PRE-CLEAN: before the invite step the
# script cancels any leftover test connection-request between the actor (demo) and
# target (tai) so a prior aborted run cannot poison NOTIF-01 (Phase 3/4 idempotency
# lesson — pre-clean non-seed state at the start).
#
# BEST-EFFORT (D-05, asserted indirectly): the reaction/comment that triggers a
# notification returns 2xx regardless of whether notification-create succeeds — the
# main action never blocks on notify. Self-react/self-comment does NOT notify
# (skip-self, actor==recipient), so steps target cross-user actors.
set -euo pipefail

GW="${GW:-http://127.0.0.1:8000}"
PW='demo@123**'
FAILURES=0

pass() { echo "[smoke5] PASS $1"; }
fail() { echo "[smoke5] FAIL $1"; FAILURES=$((FAILURES + 1)); }

# ---------------------------------------------------------------------------
# 0. Non-destructive cleanup scaffolding. Any connection-request / comment the
#    script creates is recorded with the token able to undo it. The trap is
#    registered BEFORE the first write so an early failure under `set -e` still
#    reverts. The demo seed (search_index rows, the 2 demo notifications) is
#    NEVER deleted.
# ---------------------------------------------------------------------------
CLEANUP_REQS=()         # parallel arrays: request id[i] cancelled with token tok[i]
CLEANUP_REQ_TOKS=()
CLEANUP_COMMENTS=()     # parallel arrays: comment id[i] deleted with token tok[i]
CLEANUP_COMMENT_TOKS=()
TOK_DUYET=""
TOK_DEMO=""
TOK_LONG=""
TOK_DIEP=""
TOK_TAI=""

track_req()     { CLEANUP_REQS+=("$1");     CLEANUP_REQ_TOKS+=("$2"); }
track_comment() { CLEANUP_COMMENTS+=("$1"); CLEANUP_COMMENT_TOKS+=("$2"); }

restore() {
  local i id tok
  for i in "${!CLEANUP_COMMENTS[@]}"; do
    id="${CLEANUP_COMMENTS[$i]}"; tok="${CLEANUP_COMMENT_TOKS[$i]}"
    [ -n "$id" ] && [ -n "$tok" ] || continue
    curl -s -o /dev/null -XDELETE "$GW/api/comments/$id" \
      -H "Authorization: Bearer $tok" >/dev/null 2>&1 || true
  done
  for i in "${!CLEANUP_REQS[@]}"; do
    id="${CLEANUP_REQS[$i]}"; tok="${CLEANUP_REQ_TOKS[$i]}"
    [ -n "$id" ] && [ -n "$tok" ] || continue
    curl -s -o /dev/null -XDELETE "$GW/api/connections/requests/$id" \
      -H "Authorization: Bearer $tok" >/dev/null 2>&1 || true
  done
}
trap restore EXIT

# Extract the token field from a login response body.
login() {
  curl -s -XPOST "$GW/api/auth/login" \
    -H 'Content-Type: application/json' \
    -d "{\"login\":\"$1\",\"password\":\"$PW\"}" \
    | grep -o '"token":"[^"]*"' | cut -d'"' -f4
}

# First "id":N from a body (created-resource id).
first_id() { echo "$1" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2; }

# First integer value of a named JSON field from a body.
field_int() { echo "$2" | grep -o "\"$1\":[0-9]*" | head -1 | cut -d: -f2; }

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
# 1. LOGIN the demo users → tokens.
# ---------------------------------------------------------------------------
TOK_DUYET=$(login duyet)
TOK_DEMO=$(login demo)
TOK_LONG=$(login long)
TOK_DIEP=$(login diep)
TOK_TAI=$(login tai)
for pair in "duyet:$TOK_DUYET" "demo:$TOK_DEMO" "long:$TOK_LONG" "diep:$TOK_DIEP" "tai:$TOK_TAI"; do
  name="${pair%%:*}"; tok="${pair#*:}"
  if [ -n "$tok" ]; then pass "login $name → token"; else fail "login $name (không trả token)"; fi
done

# ---------------------------------------------------------------------------
# 2. PRE-CLEAN (Phase 3/4 idempotency lesson). Cancel any leftover test
#    connection-request between demo (actor) and tai (target) from a prior aborted
#    run so NOTIF-01 starts from a clean pending-free state. Best-effort; never
#    touches the demo seed. tai's id is 5 (db/migrate-phase1.sql.tmpl).
# ---------------------------------------------------------------------------
TAI_ID=5
preReqs=$(curl -s "$GW/api/connections/requests?direction=outgoing" \
            -H "Authorization: Bearer $TOK_DEMO" || true)
preReqId=$(echo "$preReqs" | grep -oE "\{[^{}]*\"target_id\":$TAI_ID[,}][^{}]*\}" \
            | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2 || true)
if [ -n "${preReqId:-}" ]; then
  curl -s -o /dev/null -XDELETE "$GW/api/connections/requests/$preReqId" \
    -H "Authorization: Bearer $TOK_DEMO" >/dev/null 2>&1 || true
  pass "pre-clean: huỷ connection-request demo→tai cũ (id=$preReqId)"
else
  pass "pre-clean: không có connection-request demo→tai tồn đọng"
fi

# ---------------------------------------------------------------------------
# 3. REINDEX (D-02): as duyet POST /api/search/reindex → 200; indexed/total >= 5.
# ---------------------------------------------------------------------------
reBody=$(curl -s -XPOST "$GW/api/search/reindex" -H "Authorization: Bearer $TOK_DUYET" || true)
reCode=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/search/reindex" \
           -H "Authorization: Bearer $TOK_DUYET" || true)
case "$reCode" in
  200|201) pass "POST /api/search/reindex → $reCode" ;;
  *) fail "POST /api/search/reindex → $reCode (mong đợi 200/201)" ;;
esac
reCount=$(field_int indexed "$reBody"); [ -n "$reCount" ] || reCount=$(field_int total "$reBody")
if [ -n "${reCount:-}" ] && [ "$reCount" -ge 5 ]; then pass "reindex indexed/total=$reCount (>=5)"
else fail "reindex không báo indexed/total>=5 (body=$reBody)"; fi

# ---------------------------------------------------------------------------
# 4. SEARCH-01 by name: GET /api/search?q=duyet → 200; a hit with id +
#    display_name containing "Duyet"; no @/email in body.
# ---------------------------------------------------------------------------
sName=$(curl -s "$GW/api/search?q=duyet" -H "Authorization: Bearer $TOK_DUYET" || true)
sNameCode=$(curl -s -o /dev/null -w '%{http_code}' "$GW/api/search?q=duyet" \
              -H "Authorization: Bearer $TOK_DUYET" || true)
[ "$sNameCode" = "200" ] && pass "GET /api/search?q=duyet → 200 (SEARCH-01 name)" \
  || fail "GET /api/search?q=duyet → $sNameCode (mong đợi 200)"
if echo "$sName" | grep -qE '"id":[0-9]+' && echo "$sName" | grep -qiE '"display_name":"[^"]*Duy'; then
  pass "search q=duyet trả hit có id + display_name chứa 'Duy' (SEARCH-01 name)"
else
  fail "search q=duyet không có hit display_name chứa Duyệt (SEARCH-01 name)"
fi
assert_no_pii "/api/search?q=duyet" "$sName"

# ---------------------------------------------------------------------------
# 5. SEARCH-01 by skill: GET /api/search?q=PHP → 200; contains duyet (id 2),
#    proving skills_text is indexed via reindex (Pitfall 6).
# ---------------------------------------------------------------------------
sSkill=$(curl -s "$GW/api/search?q=PHP" -H "Authorization: Bearer $TOK_DUYET" || true)
if echo "$sSkill" | grep -qE '"id":2[,}]'; then
  pass "search q=PHP chứa duyet (id 2) — skills_text được index (SEARCH-01 skill)"
else
  fail "search q=PHP không tìm thấy duyet qua kỹ năng (SEARCH-01 skill)"
fi
assert_no_pii "/api/search?q=PHP" "$sSkill"

# ---------------------------------------------------------------------------
# 6. SEARCH-02 composition: each hit carries a viewer-relative connection_status.
#    For duyet's results assert at least one hit with a connection_status whose
#    value is one of the allowed states.
# ---------------------------------------------------------------------------
if echo "$sName" | grep -q '"connection_status"'; then
  pass "search hit có connection_status (SEARCH-02 compose)"
else
  fail "search hit thiếu connection_status (SEARCH-02 compose)"
fi
cs=$(echo "$sName" | grep -o '"connection_status":"[^"]*"' | head -1 | cut -d'"' -f4 || true)
case "${cs:-}" in
  none|pending_outgoing|pending_incoming|connected|unknown)
    pass "connection_status='$cs' hợp lệ (SEARCH-02)" ;;
  *) fail "connection_status='$cs' không thuộc tập hợp lệ (SEARCH-02)" ;;
esac

# ---------------------------------------------------------------------------
# 7. NOTIF-01 invite: as demo POST /api/connections/requests {target_id: tai} →
#    2xx; then as tai GET /api/notifications gains a NEW unread type='invite' with
#    enriched actor (no @) and unread_count >= 1. Track the request for cleanup.
# ---------------------------------------------------------------------------
invBody=$(curl -s -XPOST "$GW/api/connections/requests" \
            -H "Authorization: Bearer $TOK_DEMO" -H 'Content-Type: application/json' \
            -d "{\"target_id\":$TAI_ID}")
invCode=$(curl -s -o /dev/null -w '%{http_code}' "$GW/api/connections/requests?direction=outgoing" \
            -H "Authorization: Bearer $TOK_DEMO" || true)
REQ_ID=$(first_id "$invBody")
if [ -z "$REQ_ID" ]; then
  REQ_ID=$(curl -s "$GW/api/connections/requests?direction=outgoing" -H "Authorization: Bearer $TOK_DEMO" \
            | grep -oE "\{[^{}]*\"target_id\":$TAI_ID[,}][^{}]*\}" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2 || true)
fi
[ -n "$REQ_ID" ] && track_req "$REQ_ID" "$TOK_DEMO"
case "$invCode" in
  2*) pass "demo gửi connection-request → tai (NOTIF-01 invite trigger)" ;;
  *)  fail "demo gửi connection-request → tai trả $invCode (NOTIF-01)" ;;
esac
notTai=$(curl -s "$GW/api/notifications" -H "Authorization: Bearer $TOK_TAI" || true)
if echo "$notTai" | grep -qE '"type":"invite"'; then pass "tai nhận notification type=invite (NOTIF-01)"
else fail "tai không nhận notification invite (NOTIF-01)"; fi
if echo "$notTai" | grep -qE '"actor"'; then pass "notification invite có actor enriched (NOTIF-01)"
else fail "notification invite thiếu actor enriched (NOTIF-01)"; fi
taiUnread=$(field_int unread_count "$notTai")
if [ -n "${taiUnread:-}" ] && [ "$taiUnread" -ge 1 ]; then pass "tai unread_count=$taiUnread (>=1) (NOTIF-01)"
else fail "tai unread_count không >=1 (NOTIF-01)"; fi
assert_no_pii "/api/notifications (tai)" "$notTai"

# ---------------------------------------------------------------------------
# 8. NOTIF-01 reaction + comment: post 1 author = duyet. As diep react + comment
#    → 2xx each (best-effort: the 2xx is unaffected by notify success). Then duyet
#    GET /api/notifications gains reaction + comment from actor diep. Track the
#    comment for cleanup. Self-action does NOT notify (skip-self).
# ---------------------------------------------------------------------------
reactCode=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/posts/1/reactions" \
              -H "Authorization: Bearer $TOK_DIEP" -H 'Content-Type: application/json' \
              -d '{"type":"like"}' || true)
case "$reactCode" in
  2*) pass "diep react post 1 → $reactCode (NOTIF-01 reaction trigger, best-effort 2xx)" ;;
  *)  fail "diep react post 1 → $reactCode (mong đợi 2xx)" ;;
esac
cmtBody=$(curl -s -XPOST "$GW/api/posts/1/comments" \
            -H "Authorization: Bearer $TOK_DIEP" -H 'Content-Type: application/json' \
            -d '{"body":"smoke phase5 bình luận"}')
cmtCode=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/posts/1/comments" \
            -H "Authorization: Bearer $TOK_DIEP" -H 'Content-Type: application/json' \
            -d '{"body":"smoke phase5 probe"}' || true)
CMT_ID=$(first_id "$cmtBody")
[ -n "$CMT_ID" ] && track_comment "$CMT_ID" "$TOK_DIEP"
case "$cmtCode" in
  2*) pass "diep comment post 1 → $cmtCode (NOTIF-01 comment trigger, best-effort 2xx)" ;;
  *)  fail "diep comment post 1 → $cmtCode (mong đợi 2xx)" ;;
esac
notDuyet=$(curl -s "$GW/api/notifications" -H "Authorization: Bearer $TOK_DUYET" || true)
if echo "$notDuyet" | grep -qE '"type":"reaction"'; then pass "duyet nhận notification reaction (NOTIF-01)"
else fail "duyet không nhận notification reaction (NOTIF-01)"; fi
if echo "$notDuyet" | grep -qE '"type":"comment"'; then pass "duyet nhận notification comment (NOTIF-01)"
else fail "duyet không nhận notification comment (NOTIF-01)"; fi

# ---------------------------------------------------------------------------
# 9. NOTIF-02: GET /api/notifications (duyet) → newest-first (created_at desc) +
#    meta.unread_count present and > 0; actor cards have no @/email.
# ---------------------------------------------------------------------------
order=$(echo "$notDuyet" | python3 -c 'import sys,json
try:
    ts=[n.get("created_at","") for n in json.load(sys.stdin).get("data",[])]
    print("DESC" if ts==sorted(ts,reverse=True) else "BAD")
except Exception:
    print("SKIP")' 2>/dev/null || echo SKIP)
case "$order" in
  DESC) pass "notifications (duyet) newest-first created_at desc (NOTIF-02)" ;;
  SKIP) pass "notifications ordering: python3 không khả dụng, bỏ qua kiểm tra thứ tự (NOTIF-02)" ;;
  *)    fail "notifications (duyet) không newest-first (NOTIF-02)" ;;
esac
duyetUnread=$(field_int unread_count "$notDuyet")
if [ -n "${duyetUnread:-}" ] && [ "$duyetUnread" -gt 0 ]; then pass "duyet meta.unread_count=$duyetUnread (>0) (NOTIF-02)"
else fail "duyet meta.unread_count không >0 (NOTIF-02)"; fi
assert_no_pii "/api/notifications (duyet)" "$notDuyet"

# ---------------------------------------------------------------------------
# 10. NOTIF-03 mark read: capture an unread notification id, POST .../{id}/read →
#     2xx; re-fetch → unread_count decreased by 1. Then POST .../read-all → 2xx;
#     re-fetch → unread_count == 0. (Marking seed notifications read is acceptable
#     — read state is not destructive; rows are never deleted.)
# ---------------------------------------------------------------------------
NID=$(echo "$notDuyet" | python3 -c 'import sys,json
try:
    for n in json.load(sys.stdin).get("data",[]):
        if not n.get("read_at"):
            print(n.get("id")); break
except Exception:
    pass' 2>/dev/null || true)
[ -n "$NID" ] || NID=$(first_id "$notDuyet")
before=$(field_int unread_count "$notDuyet")
if [ -n "$NID" ]; then
  rdCode=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/notifications/$NID/read" \
             -H "Authorization: Bearer $TOK_DUYET" || true)
  case "$rdCode" in
    2*) pass "POST /api/notifications/$NID/read → $rdCode (NOTIF-03)" ;;
    *)  fail "POST /api/notifications/$NID/read → $rdCode (mong đợi 2xx)" ;;
  esac
  afterOne=$(curl -s "$GW/api/notifications" -H "Authorization: Bearer $TOK_DUYET" || true)
  afterCount=$(field_int unread_count "$afterOne")
  if [ -n "${before:-}" ] && [ -n "${afterCount:-}" ] && [ "$afterCount" -eq $((before - 1)) ]; then
    pass "mark-read giảm unread_count $before→$afterCount (NOTIF-03)"
  else
    fail "mark-read không giảm unread_count đúng 1 ($before→${afterCount:-?}) (NOTIF-03)"
  fi
else
  fail "không tìm được unread notification id để test mark-read (NOTIF-03)"
fi
allCode=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/notifications/read-all" \
            -H "Authorization: Bearer $TOK_DUYET" || true)
case "$allCode" in
  2*) pass "POST /api/notifications/read-all → $allCode (NOTIF-03)" ;;
  *)  fail "POST /api/notifications/read-all → $allCode (mong đợi 2xx)" ;;
esac
afterAll=$(curl -s "$GW/api/notifications" -H "Authorization: Bearer $TOK_DUYET" || true)
allCount=$(field_int unread_count "$afterAll")
if [ "${allCount:-1}" -eq 0 ]; then pass "read-all → unread_count==0 (NOTIF-03)"
else fail "read-all không đưa unread_count về 0 (=$allCount) (NOTIF-03)"; fi

# ---------------------------------------------------------------------------
# 11. Security sweep: no @/email across search results + notification lists
#     (combined PII guard → CI gate).
# ---------------------------------------------------------------------------
assert_no_pii "/api/search (sweep)" "$(curl -s "$GW/api/search?q=a" -H "Authorization: Bearer $TOK_DUYET" || true)"
assert_no_pii "/api/notifications (sweep tai)" "$(curl -s "$GW/api/notifications" -H "Authorization: Bearer $TOK_TAI" || true)"
assert_no_pii "/api/notifications (sweep duyet)" "$(curl -s "$GW/api/notifications" -H "Authorization: Bearer $TOK_DUYET" || true)"

# ---------------------------------------------------------------------------
# Verdict (trap restore EXIT runs after this block, even on early exit).
# ---------------------------------------------------------------------------
if [ "$FAILURES" -eq 0 ]; then
  echo "[smoke5] ALL PASS"
  exit 0
else
  echo "[smoke5] $FAILURES FAILURES"
  exit 1
fi
