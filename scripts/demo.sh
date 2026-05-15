#!/usr/bin/env bash
# Demo flow for the presentation. Runs against gateway directly.
#
# Override the base URL by exporting BASE before invoking:
#   BASE=https://soa.duyet.vn ./scripts/demo.sh
#
# Seed accounts (đã tồn tại sẵn — không cần register):
#   demo, duyet, long, diep, tai   — tất cả pass "demo@123**"
set -euo pipefail

BASE="${BASE:-http://localhost:8000}"
JSON='Content-Type: application/json'
PASS='demo@123**'

echo "==[1] Health composition (gateway → 3 services)"
curl -s "$BASE/api/health" | jq

echo
echo "==[2] Login 2 thành viên nhóm (Duyệt + Long)"
TOKEN_DUYET=$(curl -s -X POST "$BASE/api/auth/login" -H "$JSON" \
  -d "{\"login\":\"duyet\",\"password\":\"$PASS\"}" | jq -r '.data.token')
TOKEN_LONG=$(curl  -s -X POST "$BASE/api/auth/login" -H "$JSON" \
  -d "{\"login\":\"long\",\"password\":\"$PASS\"}"  | jq -r '.data.token')
echo "  Duyệt token (20 ký tự đầu): ${TOKEN_DUYET:0:20}…"
echo "  Long  token (20 ký tự đầu): ${TOKEN_LONG:0:20}…"

echo
echo "==[3] Duyệt tạo 1 bài viết qua gateway → post-service"
POST_ID=$(curl -s -X POST "$BASE/api/posts" -H "$JSON" -H "Authorization: Bearer $TOKEN_DUYET" \
  -d '{"title":"Bài viết demo từ script","content":"Bài viết được tạo qua API gateway — route đến post-service. Showcase microservices + Gateway pattern."}' \
  | jq -r '.data.id')
echo "  Đã tạo post id=$POST_ID"

echo
echo "==[4] Long bình luận (gateway composition: verify post tồn tại trước)"
curl -s -X POST "$BASE/api/posts/$POST_ID/comments" -H "$JSON" -H "Authorization: Bearer $TOKEN_LONG" \
  -d '{"body":"Bài viết hay quá! Mong chờ những nội dung tiếp theo."}' | jq '.data'

echo
echo "==[5] ⭐ Aggregation: GET /api/posts/$POST_ID/full (gateway gọi 3 service rồi gộp)"
curl -s "$BASE/api/posts/$POST_ID/full" | jq '.data | {
  id,
  title,
  author:        .author.display_name,
  comment_count: (.comments | length),
  commenters:    [.comments[].author.display_name]
}'

echo
echo "==[6] Orphan-prevention: bình luận cho bài KHÔNG tồn tại → expect 404 POST_NOT_FOUND"
HTTP=$(curl -s -o /tmp/demo-r6.json -w "%{http_code}" -X POST "$BASE/api/posts/9999999/comments" \
  -H "$JSON" -H "Authorization: Bearer $TOKEN_LONG" -d '{"body":"orphan attempt"}')
echo "  HTTP $HTTP"
jq '.error // .' /tmp/demo-r6.json

echo
echo "==[7] Data-integrity: Duyệt thử xoá bài có comment → expect 409 POST_HAS_COMMENTS"
HTTP=$(curl -s -o /tmp/demo-r7.json -w "%{http_code}" -X DELETE "$BASE/api/posts/$POST_ID" \
  -H "Authorization: Bearer $TOKEN_DUYET")
echo "  HTTP $HTTP"
jq '.error // .' /tmp/demo-r7.json

echo
echo "==[8] Direct backend services không bind ra host (bị docker network cô lập)"
echo "  Thử 'curl 127.0.0.1:8001' từ host sẽ fail — bind internal-only."

echo
echo "==[9] Rate-limit header (gateway tự thêm vào mỗi response)"
curl -sI "$BASE/api/posts" | grep -i ratelimit || echo "  (không có header — rate limit disabled?)"

echo
echo "==[done] Demo flow hoàn tất."
