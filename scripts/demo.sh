#!/usr/bin/env bash
# Demo flow for the presentation. Runs against gateway directly.
#
# Override the base URL by exporting BASE before invoking:
#   BASE=https://soa.duyet.vn ./scripts/demo.sh
set -euo pipefail

BASE="${BASE:-http://localhost:8000}"
JSON='Content-Type: application/json'

echo "==[1] Health composition (gateway → 3 services)"
curl -s "$BASE/api/health" | jq

echo
echo "==[2] Register Alice + Bob (idempotent — ignore 409 if already exist)"
curl -s -X POST "$BASE/api/auth/register" -H "$JSON" -d '{"username":"alice","email":"alice@duyet.vn","password":"demo123","display_name":"Alice Nguyễn"}' | jq '.data // .error'
curl -s -X POST "$BASE/api/auth/register" -H "$JSON" -d '{"username":"bob","email":"bob@duyet.vn","password":"demo123","display_name":"Bob Trần"}'     | jq '.data // .error'

echo
echo "==[3] Alice login → get JWT"
TOKEN_ALICE=$(curl -s -X POST "$BASE/api/auth/login" -H "$JSON" -d '{"login":"alice","password":"demo123"}' | jq -r '.data.token')
TOKEN_BOB=$(curl   -s -X POST "$BASE/api/auth/login" -H "$JSON" -d '{"login":"bob","password":"demo123"}'   | jq -r '.data.token')
echo "  Alice token (first 20): ${TOKEN_ALICE:0:20}…"
echo "  Bob   token (first 20): ${TOKEN_BOB:0:20}…"

echo
echo "==[4] Alice creates a post"
POST_ID=$(curl -s -X POST "$BASE/api/posts" -H "$JSON" -H "Authorization: Bearer $TOKEN_ALICE" \
  -d '{"title":"Bài viết demo từ script","content":"Đây là bài viết được tạo qua API gateway, đi qua post-service. Showcase microservices."}' \
  | jq -r '.data.id')
echo "  Created post id=$POST_ID"

echo
echo "==[5] Bob comments on the post (gateway composition: verifies post exists first)"
curl -s -X POST "$BASE/api/posts/$POST_ID/comments" -H "$JSON" -H "Authorization: Bearer $TOKEN_BOB" \
  -d '{"body":"Bình luận hay quá! Mong chờ nội dung tiếp theo."}' | jq '.data'

echo
echo "==[6] ⭐ Aggregation: GET /api/posts/$POST_ID/full"
curl -s "$BASE/api/posts/$POST_ID/full" | jq '.data | {id, title, author: .author.username, comments: (.comments | length)}'

echo
echo "==[7] Orphan-prevention: comment for a non-existent post → expect 404 POST_NOT_FOUND"
curl -s -w "\n  HTTP %{http_code}\n" -X POST "$BASE/api/posts/9999999/comments" \
  -H "$JSON" -H "Authorization: Bearer $TOKEN_BOB" -d '{"body":"orphan attempt"}' | jq '.error // .'

echo
echo "==[8] Data-integrity: Alice tries to delete her post (has comments) → expect 409 POST_HAS_COMMENTS"
curl -s -w "\n  HTTP %{http_code}\n" -X DELETE "$BASE/api/posts/$POST_ID" \
  -H "Authorization: Bearer $TOKEN_ALICE" | jq '.error // .'

echo
echo "==[9] Direct backend access is blocked (services don't bind to host)"
echo "  curl 127.0.0.1:8001 from host would fail — bind is internal-only."

echo
echo "==[10] Rate-limit headers (look for X-RateLimit-*)"
curl -sI "$BASE/api/posts" | grep -i ratelimit || echo "  (no header — rate limit disabled?)"

echo
echo "==[done] Demo flow completed."
