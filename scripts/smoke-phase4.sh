#!/usr/bin/env bash
# Phase 4 smoke test — news feed (FEED-01..06 + composition + EXACT-count canary).
#
# Covers: create post + repost (FEED-01/05), timeline = self + connections,
# newest-first (FEED-02), reaction set/change/remove + count + my_reaction
# (FEED-03), comment add/list/delete + count (FEED-04), repost shows origin
# (FEED-05), feed item composition shape (FEED-06), the ASYMMETRIC fan-trap
# EXACT-count guard on seed post 1 (Pitfall 3), the 404 invariant on missing
# posts, ownership (only the owner deletes), and the email/PII-leak guard on
# every card-bearing body.
#
# Runs against an ALREADY-RUNNING stack (VPS or local `docker compose up -d`).
# The feed gateway routes go live in Plan 03; this harness is authored now in
# Wave 0 and its assertions run against the deployed stack in Plan 05 (live
# cutover) — Docker is not available locally (04-RESEARCH §Environment).
# Bash + curl only — no test framework (consistent with smoke-phase3.sh).
#
# Usage:  bash scripts/smoke-phase4.sh
#         GW=http://127.0.0.1:8000 bash scripts/smoke-phase4.sh
#
# Note: the seeded demo password `demo@123**` is a throwaway value for the 5
# seeded demo accounts only (db/04-migrate-phase4.sql / db/99-seed.sql) — not a
# real secret.
#
# NON-DESTRUCTIVE: every post/reaction/comment this script CREATES it deletes in
# cleanup (a `trap restore EXIT` registered BEFORE anything is created). It NEVER
# deletes the demo-seed fixtures — posts 1/2/3, the seed reactions, long's seed
# comment — so repeated runs leave the demo feed unchanged.
#
# DEGRADE (documented, asserted indirectly): when connection-service is down the
# gateway degrades the timeline to OWN posts only + `meta.degraded:["connections"]`
# (feed-service is the only hard dependency of the feed composition). That is a
# fault-injection path NOT exercised locally; the own-posts path is covered by the
# create -> feed assertion (step 4).
set -euo pipefail

GW="${GW:-http://127.0.0.1:8000}"
PW='demo@123**'
FAILURES=0

pass() { echo "[smoke4] PASS $1"; }
fail() { echo "[smoke4] FAIL $1"; FAILURES=$((FAILURES + 1)); }

# ---------------------------------------------------------------------------
# 0. Non-destructive cleanup scaffolding. Any post/comment/repost the script
#    creates is recorded with the token able to delete it. The trap is
#    registered BEFORE the first write so an early failure under `set -e` still
#    reverts. The demo-seed fixtures (posts 1/2/3 etc.) are NEVER touched.
# ---------------------------------------------------------------------------
CLEANUP_POSTS=()       # parallel arrays: post id[i] deleted with token tok[i]
CLEANUP_POST_TOKS=()
CLEANUP_COMMENTS=()    # parallel arrays: comment id[i] deleted with token tok[i]
CLEANUP_COMMENT_TOKS=()
TOK_DUYET=""
TOK_LONG=""
TOK_DIEP=""

track_post()    { CLEANUP_POSTS+=("$1");    CLEANUP_POST_TOKS+=("$2"); }
track_comment() { CLEANUP_COMMENTS+=("$1"); CLEANUP_COMMENT_TOKS+=("$2"); }

restore() {
  local i id tok
  # Delete created comments first (in case a post delete cascades or 409s).
  for i in "${!CLEANUP_COMMENTS[@]}"; do
    id="${CLEANUP_COMMENTS[$i]}"; tok="${CLEANUP_COMMENT_TOKS[$i]}"
    [ -n "$id" ] && [ -n "$tok" ] || continue
    curl -s -o /dev/null -XDELETE "$GW/api/comments/$id" \
      -H "Authorization: Bearer $tok" >/dev/null 2>&1 || true
  done
  for i in "${!CLEANUP_POSTS[@]}"; do
    id="${CLEANUP_POSTS[$i]}"; tok="${CLEANUP_POST_TOKS[$i]}"
    [ -n "$id" ] && [ -n "$tok" ] || continue
    curl -s -o /dev/null -XDELETE "$GW/api/posts/$id" \
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

# First "id":N from a body (created-resource id).
first_id() { echo "$1" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2; }

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

RAND="$RANDOM"

# ---------------------------------------------------------------------------
# 2. FEED-01 create: duyet posts → 200/201 + id. Track for cleanup.
# ---------------------------------------------------------------------------
createBody=$(curl -s -XPOST "$GW/api/posts" \
              -H "Authorization: Bearer $TOK_DUYET" -H 'Content-Type: application/json' \
              -d "{\"content\":\"smoke bài viết X-$RAND\"}")
createCode=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/posts" \
              -H "Authorization: Bearer $TOK_DUYET" -H 'Content-Type: application/json' \
              -d "{\"content\":\"smoke probe X-$RAND\"}" || true)
POST_ID=$(first_id "$createBody")
[ -n "$POST_ID" ] && track_post "$POST_ID" "$TOK_DUYET"
# the probe post (createCode) is also created — clean it up too
PROBE_ID=$(curl -s "$GW/api/feed?limit=50" -H "Authorization: Bearer $TOK_DUYET" \
            | grep -o "\"id\":[0-9]*" | head -1 | cut -d: -f2 || true)
case "$createCode" in
  200|201) pass "duyet POST /api/posts → $createCode (FEED-01)" ;;
  *) fail "duyet POST /api/posts → $createCode (mong đợi 200/201)" ;;
esac
if [ -n "$POST_ID" ]; then pass "create trả post id=$POST_ID (FEED-01)"; else fail "create không trả post id (FEED-01)"; fi

# ---------------------------------------------------------------------------
# 3. FEED-01 with image: duyet posts with image_url → 201; body has image_url.
# ---------------------------------------------------------------------------
imgBody=$(curl -s -XPOST "$GW/api/posts" \
            -H "Authorization: Bearer $TOK_DUYET" -H 'Content-Type: application/json' \
            -d "{\"content\":\"smoke ảnh X-$RAND\",\"image_url\":\"https://example.com/a.png\"}")
IMG_ID=$(first_id "$imgBody")
[ -n "$IMG_ID" ] && track_post "$IMG_ID" "$TOK_DUYET"
if echo "$imgBody" | grep -q '"image_url"'; then pass "post có image_url trong body (FEED-01)"
else fail "post image_url không xuất hiện trong body (FEED-01)"; fi

# ---------------------------------------------------------------------------
# 4. FEED-02 timeline self+connections + newest-first: duyet GET /api/feed.
#    Must contain the just-created POST_ID AND long's seed post 2 (duyet↔long
#    connected). POST_ID (newest) must appear BEFORE seed post 2 (older) in body.
# ---------------------------------------------------------------------------
feedDuyet=$(curl -s "$GW/api/feed?limit=20" -H "Authorization: Bearer $TOK_DUYET" || true)
if echo "$feedDuyet" | grep -q "\"id\":$POST_ID"; then pass "feed (duyet) chứa post vừa tạo (FEED-02 self)"
else fail "feed (duyet) không chứa post vừa tạo $POST_ID (FEED-02 self)"; fi
if echo "$feedDuyet" | grep -q 'Vừa hoàn thành'; then pass "feed (duyet) chứa post của long (FEED-02 connections)"
else fail "feed (duyet) không chứa post của long (FEED-02 connections)"; fi
# newest-first: byte offset of the new post id must be earlier than seed post 2.
posNew=$(echo "$feedDuyet" | grep -bo "\"id\":$POST_ID" | head -1 | cut -d: -f1 || true)
posOld=$(echo "$feedDuyet" | grep -bo '"id":2,' | head -1 | cut -d: -f1 || true)
if [ -n "$posNew" ] && [ -n "$posOld" ] && [ "$posNew" -lt "$posOld" ]; then
  pass "feed (duyet) newest-first (post mới trước seed post 2) (FEED-02)"
else
  fail "feed (duyet) không newest-first (posNew=$posNew posOld=$posOld) (FEED-02)"
fi

# ---------------------------------------------------------------------------
# 5. FEED-03 reaction set/change/remove + count + my_reaction.
#    Operate on POST_ID (a script-created post) so the demo seed is untouched.
# ---------------------------------------------------------------------------
curl -s -o /dev/null -XPOST "$GW/api/posts/$POST_ID/reactions" \
  -H "Authorization: Bearer $TOK_DUYET" -H 'Content-Type: application/json' -d '{"type":"like"}' || true
f=$(curl -s "$GW/api/feed?limit=50" -H "Authorization: Bearer $TOK_DUYET" || true)
item=$(echo "$f" | grep -oE "\{[^{]*\"id\":$POST_ID[,}].*?\"my_reaction\":[^,}]*" | head -1 || true)
if echo "$f" | grep -qE "\"reaction_count\":1"; then pass "reaction set → reaction_count 1 (FEED-03)"
else fail "reaction set không cho reaction_count 1 (FEED-03)"; fi
if echo "$f" | grep -qE "\"my_reaction\":\"like\""; then pass "reaction set → my_reaction like (FEED-03)"
else fail "reaction set không cho my_reaction like (FEED-03)"; fi
# change like→love is an UPSERT, not a 2nd row → count still 1, my_reaction love.
curl -s -o /dev/null -XPOST "$GW/api/posts/$POST_ID/reactions" \
  -H "Authorization: Bearer $TOK_DUYET" -H 'Content-Type: application/json' -d '{"type":"love"}' || true
f=$(curl -s "$GW/api/feed?limit=50" -H "Authorization: Bearer $TOK_DUYET" || true)
if echo "$f" | grep -qE "\"my_reaction\":\"love\""; then pass "reaction change → my_reaction love, không thêm row (FEED-03)"
else fail "reaction change không cập nhật my_reaction love (FEED-03)"; fi
# remove → count 0, my_reaction null.
curl -s -o /dev/null -XDELETE "$GW/api/posts/$POST_ID/reactions" -H "Authorization: Bearer $TOK_DUYET" || true
f=$(curl -s "$GW/api/feed?limit=50" -H "Authorization: Bearer $TOK_DUYET" || true)
if echo "$f" | grep -qE "\"my_reaction\":null"; then pass "reaction remove → my_reaction null (FEED-03)"
else fail "reaction remove không cho my_reaction null (FEED-03)"; fi

# ---------------------------------------------------------------------------
# 6. FEED-04 comment add/list/delete + count.
# ---------------------------------------------------------------------------
cBody=$(curl -s -XPOST "$GW/api/posts/$POST_ID/comments" \
          -H "Authorization: Bearer $TOK_DUYET" -H 'Content-Type: application/json' \
          -d '{"body":"smoke bình luận"}')
cCode=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/posts/$POST_ID/comments" \
          -H "Authorization: Bearer $TOK_DUYET" -H 'Content-Type: application/json' \
          -d '{"body":"smoke bình luận 2"}' || true)
CID=$(first_id "$cBody")
[ -n "$CID" ] && track_comment "$CID" "$TOK_DUYET"
case "$cCode" in
  200|201) pass "duyet POST comment → $cCode (FEED-04)" ;;
  *) fail "duyet POST comment → $cCode (mong đợi 200/201)" ;;
esac
listBody=$(curl -s "$GW/api/posts/$POST_ID/comments" || true)
if echo "$listBody" | grep -q 'smoke bình luận'; then pass "comment list chứa bình luận vừa tạo (FEED-04)"
else fail "comment list không chứa bình luận vừa tạo (FEED-04)"; fi
if echo "$listBody" | grep -q '"display_name"'; then pass "comment author card có display_name (FEED-04)"
else fail "comment author card thiếu display_name (FEED-04)"; fi
assert_no_pii "/api/posts/$POST_ID/comments" "$listBody"
# DELETE comment (owner duyet) → gone.
curl -s -o /dev/null -XDELETE "$GW/api/comments/$CID" -H "Authorization: Bearer $TOK_DUYET" || true
afterDel=$(curl -s "$GW/api/posts/$POST_ID/comments" || true)
if echo "$afterDel" | grep -q "\"id\":$CID,"; then fail "comment $CID vẫn còn sau delete (FEED-04)"
else pass "comment $CID biến mất sau owner delete (FEED-04)"; fi

# ---------------------------------------------------------------------------
# 7. FEED-05 repost shows origin: long reposts POST_ID → 201 + id; long's feed
#    item has repost_of == POST_ID and an `original` with author + content.
# ---------------------------------------------------------------------------
repBody=$(curl -s -XPOST "$GW/api/posts/$POST_ID/repost" -H "Authorization: Bearer $TOK_LONG")
repCode=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/posts/$POST_ID/repost" \
            -H "Authorization: Bearer $TOK_LONG" || true)
REPOST_ID=$(first_id "$repBody")
[ -n "$REPOST_ID" ] && track_post "$REPOST_ID" "$TOK_LONG"
case "$repCode" in
  200|201|409) pass "long repost POST_ID → $repCode (FEED-05)" ;;
  *) fail "long repost POST_ID → $repCode (mong đợi 200/201)" ;;
esac
feedLong=$(curl -s "$GW/api/feed?limit=50" -H "Authorization: Bearer $TOK_LONG" || true)
if echo "$feedLong" | grep -qE "\"repost_of\":$POST_ID"; then pass "long feed repost item repost_of=$POST_ID (FEED-05)"
else fail "long feed repost item thiếu repost_of=$POST_ID (FEED-05)"; fi
if echo "$feedLong" | grep -qE '"original"'; then pass "repost item có original (author+content) (FEED-05)"
else fail "repost item thiếu original (FEED-05)"; fi

# ---------------------------------------------------------------------------
# 8. FEED-06 composition shape: every feed item carries author/reaction_count/
#    comment_count/my_reaction/repost_of; whole feed body has no @/email.
# ---------------------------------------------------------------------------
feedShape=$(curl -s "$GW/api/feed?limit=20" -H "Authorization: Bearer $TOK_DUYET" || true)
for key in '"author"' '"display_name"' '"reaction_count"' '"comment_count"' '"my_reaction"' '"repost_of"'; do
  if echo "$feedShape" | grep -q "$key"; then pass "feed item có $key (FEED-06)"
  else fail "feed item thiếu $key (FEED-06)"; fi
done
assert_no_pii "/api/feed (duyet)" "$feedShape"

# ---------------------------------------------------------------------------
# 9. FEED-06 EXACT-count correctness (ASYMMETRIC fan-trap guard, Pitfall 3).
#    Seed post 1 = 2 reactions (long+diep) + 1 comment (long). Isolate the
#    "id":1 object slice from the feed and assert reaction_count==2 AND
#    comment_count==1 EXACTLY. A double-JOIN count query multiplies to
#    comment_count==2 (2 reactions × 1 comment) or otherwise inflates, so this
#    EXACT 2-AND-1 assert (NOT >=1) fails on it. A symmetric 1/1 post could NOT
#    catch the bug — that is why the seed is asymmetric.
# ---------------------------------------------------------------------------
feedFan=$(curl -s "$GW/api/feed?limit=50" -H "Authorization: Bearer $TOK_DUYET" || true)
# Slice the JSON object that starts at the seed post 1 id, up to the next "id":.
post1=$(echo "$feedFan" | grep -oE "\"id\":1,[^]]*?\"comment_count\":[0-9]+" | head -1 || true)
if [ -z "$post1" ]; then
  # Fallback: tolerant awk slice from the first "id":1, to the next "id":
  post1=$(echo "$feedFan" | awk 'BEGIN{RS="\"id\":"} /^1,/{print; exit}' || true)
fi
if echo "$post1" | grep -qE 'reaction_count":2' && echo "$post1" | grep -qE 'comment_count":1'; then
  pass "fan-trap: seed post1 reaction_count=2 AND comment_count=1 (Pitfall 3 EXACT)"
else
  fail "fan-trap: post1 expected react=2 comment=1, slice=[$post1]"
fi

# ---------------------------------------------------------------------------
# 10. Invariant 404: reaction/comment/repost on a missing post 999999 → 404.
# ---------------------------------------------------------------------------
for ep in "reactions:-d {\"type\":\"like\"}" "comments:-d {\"body\":\"x\"}" "repost:"; do
  path="${ep%%:*}"
  code=$(curl -s -o /dev/null -w '%{http_code}' -XPOST "$GW/api/posts/999999/$path" \
          -H "Authorization: Bearer $TOK_DUYET" -H 'Content-Type: application/json' \
          -d '{"type":"like","body":"x"}' || true)
  if [ "$code" = "404" ]; then pass "POST /api/posts/999999/$path → 404 (invariant)"
  else fail "POST /api/posts/999999/$path → $code (mong đợi 404)"; fi
done

# ---------------------------------------------------------------------------
# 11. Ownership: diep(4) tries to delete duyet's POST_ID → 403/404; diep tries
#     to delete a duyet comment → 403/404 (only the owner deletes).
# ---------------------------------------------------------------------------
ownPost=$(curl -s -o /dev/null -w '%{http_code}' -XDELETE "$GW/api/posts/$POST_ID" \
            -H "Authorization: Bearer $TOK_DIEP" || true)
case "$ownPost" in
  403|404) pass "diep DELETE duyet's post → $ownPost (ownership enforced)" ;;
  *) fail "diep DELETE duyet's post → $ownPost (mong đợi 403/404)" ;;
esac
# Create a duyet comment, then have diep try to delete it.
ocBody=$(curl -s -XPOST "$GW/api/posts/$POST_ID/comments" \
          -H "Authorization: Bearer $TOK_DUYET" -H 'Content-Type: application/json' \
          -d '{"body":"smoke owner-scope"}')
OCID=$(first_id "$ocBody")
[ -n "$OCID" ] && track_comment "$OCID" "$TOK_DUYET"
if [ -n "$OCID" ]; then
  ownCmt=$(curl -s -o /dev/null -w '%{http_code}' -XDELETE "$GW/api/comments/$OCID" \
            -H "Authorization: Bearer $TOK_DIEP" || true)
  case "$ownCmt" in
    403|404) pass "diep DELETE duyet's comment → $ownCmt (ownership enforced)" ;;
    *) fail "diep DELETE duyet's comment → $ownCmt (mong đợi 403/404)" ;;
  esac
else
  fail "không tạo được comment để test owner-scope"
fi

# ---------------------------------------------------------------------------
# 12. Security sweep: no @/email across /api/feed, /api/posts/{id},
#     /api/posts/{id}/comments (combined PII guard → CI gate).
# ---------------------------------------------------------------------------
assert_no_pii "/api/posts/$POST_ID" "$(curl -s "$GW/api/posts/$POST_ID" || true)"
assert_no_pii "/api/feed (sweep)" "$(curl -s "$GW/api/feed?limit=50" -H "Authorization: Bearer $TOK_DUYET" || true)"
assert_no_pii "/api/posts/$POST_ID/comments (sweep)" "$(curl -s "$GW/api/posts/$POST_ID/comments" || true)"

# ---------------------------------------------------------------------------
# Verdict (trap restore EXIT runs after this block, even on early exit).
# ---------------------------------------------------------------------------
if [ "$FAILURES" -eq 0 ]; then
  echo "[smoke4] ALL PASS"
  exit 0
else
  echo "[smoke4] $FAILURES FAILURES"
  exit 1
fi
