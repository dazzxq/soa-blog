#!/usr/bin/env bash
# Phase 6 smoke test — ProConnect brand + shared navbar (UI-01/UI-04/UI-05 + Integrity).
#
# UI-ONLY phase. Validation = STATIC markup/asset greps over web/*.html +
# web/assets/app.js + web/assets/styles.css (run LOCALLY, no Docker), PLUS an
# optional page-200 curl block gated behind RUN_HTTP=1 for the VPS cutover (Plan 04).
#
# Like smoke-phase5.sh (written in Wave 0, green only AFTER later waves land), some
# assertions here go green only AFTER Plan 02/03 migrate the 8 pages to the shared
# <div id="pronav"> + bump the cache-bust token to ?v=ph6-01. At Wave 0 those page
# asserts may FAIL — that is expected; the suite runs for real at Plan 04 cutover.
# The Task-1 gate is: this file exists, `bash -n` is clean, and it carries the
# static asserts below (pronav / navy / svg / tagline / initTree / vi / no-linkedin /
# cache-bust ph6 token) + a RUN_HTTP-gated page-200 block.
#
# Usage:  bash scripts/smoke-phase6.sh                  # static-only (local, no network)
#         RUN_HTTP=1 BASE=https://soa.duyet.vn bash scripts/smoke-phase6.sh   # + page-200 (VPS)
#
# NON-DESTRUCTIVE / READ-ONLY: greps + curl GETs only. No writes, no auth, no token.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB="$ROOT/web"
APP="$WEB/assets/app.js"
CSS="$WEB/assets/styles.css"
BASE="${BASE:-https://soa.duyet.vn}"
FAILURES=0

pass() { echo "[smoke6] PASS $1"; }
fail() { echo "[smoke6] FAIL $1"; FAILURES=$((FAILURES + 1)); }

# The 8 functional pages restyled in Phase 6 (web/$p.html). index also served at /.
PAGES="index login register feed profile profile-edit connections search"

# ---------------------------------------------------------------------------
# A. STATIC MARKUP — the Wave 0 gate. Greps over web/. No network, no token.
# ---------------------------------------------------------------------------

# --- A0. Shared assets (app.js + styles.css): brand + navbar contract ----------
# UI-01 brand assets: navy token + chain-link SVG logo + Vietnamese tagline.
if grep -q 'window.proNav' "$APP"; then pass "app.js: window.proNav (shared navbar helper)"
else fail "app.js thiếu window.proNav (navbar dùng chung)"; fi
if grep -q '1e3a8a' "$APP"; then pass "app.js: navy #1e3a8a present"
else fail "app.js thiếu navy #1e3a8a"; fi
if grep -q '<svg' "$APP"; then pass "app.js: inline SVG logo present"
else fail "app.js thiếu inline SVG logo (mắt xích)"; fi
if grep -qi 'Kết nối' "$APP"; then pass "app.js: tagline tiếng Việt (Kết nối…)"
else fail "app.js thiếu tagline tiếng Việt"; fi
if grep -q '1e3a8a' "$CSS"; then pass "styles.css: navy #1e3a8a token present"
else fail "styles.css thiếu navy #1e3a8a"; fi

# UI-04 navbar wired + reactive. initTree is mandatory: a navbar injected AFTER
# Alpine init has NO reactivity unless proNav re-scans the subtree via Alpine.initTree.
if grep -q 'connections/requests?direction=incoming' "$APP"; then pass "app.js: invite badge → /api/connections/requests?direction=incoming (D-06)"
else fail "app.js thiếu badge lời mời (connections/requests?direction=incoming)"; fi
if grep -q 'search.html?q=' "$APP"; then pass "app.js: search box → /search.html?q="
else fail "app.js thiếu ô tìm kiếm (search.html?q=)"; fi
if grep -q 'notificationBell' "$APP"; then pass "app.js: chuông thông báo (reuse notificationBell)"
else fail "app.js thiếu chuông thông báo (notificationBell)"; fi
if grep -q 'auth.logout' "$APP"; then pass "app.js: menu hồ sơ đăng xuất (auth.logout xoá token)"
else fail "app.js thiếu auth.logout (đăng xuất xoá token)"; fi
if grep -q 'initTree' "$APP"; then pass "app.js: Alpine.initTree (subtree đã inject có reactivity dù gọi sau init)"
else fail "app.js thiếu initTree — navbar inject sau Alpine init sẽ KHÔNG reactive"; fi

# XSS guard (T-06-01): all user data via x-text, never x-html.
if grep -qi 'x-html' "$APP"; then fail "app.js DÙNG x-html (vi phạm XSS guard T-06-01) — phải x-text"
else pass "app.js không dùng x-html (x-text only, T-06-01)"; fi

# No-LinkedIn legal guard (T-06-04) across shared assets.
for f in "$APP" "$CSS"; do
  if grep -qi 'linkedin' "$f"; then fail "$(basename "$f") chứa 'linkedin' (vi phạm pháp lý T-06-04)"
  else pass "$(basename "$f") không chứa 'linkedin'"; fi
done

# --- A1. Per-page asserts ------------------------------------------------------
for p in $PAGES; do
  f="$WEB/$p.html"
  if [ ! -f "$f" ]; then fail "trang $p.html không tồn tại"; continue; fi

  # UI-01: shared navbar placeholder + navy token on the page (inline tailwind
  # config navy OR a navy/#1e3a8a class).
  if grep -q 'id="pronav"' "$f"; then pass "$p.html: <div id=\"pronav\"> placeholder navbar dùng chung"
  else fail "$p.html thiếu <div id=\"pronav\"> (navbar dùng chung — Plan 02/03)"; fi
  if grep -qE 'navy|1e3a8a' "$f"; then pass "$p.html: navy token (tailwind.config navy / #1e3a8a)"
  else fail "$p.html thiếu navy token (navy / #1e3a8a)"; fi

  # UI-05: lang="vi" + no obvious untranslated English UI labels.
  if grep -q 'lang="vi"' "$f"; then pass "$p.html: lang=\"vi\""
  else fail "$p.html thiếu lang=\"vi\""; fi
  if grep -qiE '>(Login|Logout|Sign in|Sign up|Search|Home|Profile|Settings)<' "$f"; then
    fail "$p.html lộ nhãn tiếng Anh (Login/Logout/Sign in/…)"
  else pass "$p.html không lộ nhãn tiếng Anh"; fi

  # No-LinkedIn legal guard (T-06-04) per page.
  if grep -qi 'linkedin' "$f"; then fail "$p.html chứa 'linkedin' (vi phạm pháp lý T-06-04)"
  else pass "$p.html không chứa 'linkedin'"; fi

  # Integrity: no dead retired blog endpoints; cache-bust bumped to ph6 token.
  if grep -qE '/api/(posts|comments)/?"' "$f"; then
    fail "$p.html tham chiếu endpoint chết /api/posts|comments (đã retire ở Phase 1)"
  else pass "$p.html không tham chiếu endpoint chết /api/posts|comments"; fi
  # NOTE: old token was styles.css?v1778837733 (NO '='); we grep the NEW ph6 token.
  # At Wave 0 these may FAIL until Plan 02/03 replace the token — expected.
  if grep -qE 'app\.js\?v=ph6' "$f"; then pass "$p.html: app.js?v=ph6* cache-bust (Plan 02/03)"
  else fail "$p.html chưa bump app.js?v=ph6* (Plan 02/03 sẽ thay — Wave 0 kỳ vọng FAIL)"; fi
  if grep -qE 'styles\.css\?v=ph6' "$f"; then pass "$p.html: styles.css?v=ph6* cache-bust (Plan 02/03)"
  else fail "$p.html chưa bump styles.css?v=ph6* (Plan 02/03 sẽ thay — Wave 0 kỳ vọng FAIL)"; fi

  # Raw HTML tag balance: exactly one <body and one </body>.
  ob=$(grep -oE '<body' "$f" | wc -l | tr -d ' ')
  cb=$(grep -oE '</body>' "$f" | wc -l | tr -d ' ')
  if [ "$ob" = "1" ] && [ "$cb" = "1" ]; then pass "$p.html: cân bằng <body>/</body> (1==1)"
  else fail "$p.html không cân bằng <body>($ob)/</body>($cb)"; fi
done

# ---------------------------------------------------------------------------
# B. PAGE-200 — VPS cutover (Plan 04). Gated by RUN_HTTP=1 so static-only runs
#    locally with no network. Plan 04: RUN_HTTP=1 BASE=https://soa.duyet.vn ...
# ---------------------------------------------------------------------------
if [ "${RUN_HTTP:-0}" = "1" ]; then
  for p in $PAGES; do
    code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$p.html" || true)
    if [ "$code" = "200" ]; then pass "GET $BASE/$p.html → 200"
    else fail "GET $BASE/$p.html → $code (mong đợi 200)"; fi
  done
  # index also served at /.
  rootCode=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/" || true)
  if [ "$rootCode" = "200" ]; then pass "GET $BASE/ → 200"
  else fail "GET $BASE/ → $rootCode (mong đợi 200)"; fi
else
  echo "[smoke6] (page-200 bỏ qua — đặt RUN_HTTP=1 để chạy trên VPS ở Plan 04)"
fi

# ---------------------------------------------------------------------------
# Verdict.
# ---------------------------------------------------------------------------
if [ "$FAILURES" -eq 0 ]; then
  echo "[smoke6] ALL PASS"
  exit 0
else
  echo "[smoke6] $FAILURES FAILURES"
  exit 1
fi
