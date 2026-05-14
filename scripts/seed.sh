#!/usr/bin/env bash
# Tier-2 rich demo seed via gateway API.
# Use AFTER docker compose up; tier-1 seed already provides alice + bob.
set -euo pipefail

BASE="${BASE:-http://localhost:8000}"
JSON='Content-Type: application/json'

random_word() {
  local words=("Microservices" "Gateway" "Slim" "Docker" "Cloudflare" "PHP" "MariaDB" "JWT" "REST" "SOA")
  echo "${words[RANDOM % ${#words[@]}]}"
}

echo "[seed] creating extra users 3..11"
for i in {3..11}; do
  curl -s -X POST "$BASE/api/auth/register" -H "$JSON" \
    -d "{\"username\":\"user$i\",\"email\":\"user$i@duyet.vn\",\"password\":\"demo123\",\"display_name\":\"User $i\"}" \
    > /dev/null || true
done

echo "[seed] login alice"
TOKEN_ALICE=$(curl -s -X POST "$BASE/api/auth/login" -H "$JSON" \
  -d '{"login":"alice","password":"demo123"}' | jq -r '.data.token')

if [[ -z "$TOKEN_ALICE" || "$TOKEN_ALICE" == "null" ]]; then
  echo "[seed] cannot login alice — aborting" >&2; exit 1
fi

echo "[seed] creating 19 extra posts"
POSTS=()
for i in {1..19}; do
  w=$(random_word)
  pid=$(curl -s -X POST "$BASE/api/posts" -H "$JSON" -H "Authorization: Bearer $TOKEN_ALICE" \
    -d "{\"title\":\"Tìm hiểu về $w (#$i)\",\"content\":\"Đây là bài viết minh hoạ về $w. Nội dung mẫu dùng để hiển thị giao diện danh sách bài viết và demo trang chi tiết.\"}" \
    | jq -r '.data.id')
  POSTS+=("$pid")
done

echo "[seed] adding ~50 comments distributed across posts"
# Log in random users and have them comment
for ((i=0; i<50; i++)); do
  uid=$((RANDOM % 9 + 3))
  tok=$(curl -s -X POST "$BASE/api/auth/login" -H "$JSON" \
    -d "{\"login\":\"user$uid\",\"password\":\"demo123\"}" | jq -r '.data.token' 2>/dev/null || true)
  [[ -z "$tok" || "$tok" == "null" ]] && continue

  postId=${POSTS[$((RANDOM % ${#POSTS[@]}))]}
  curl -s -X POST "$BASE/api/posts/$postId/comments" -H "$JSON" -H "Authorization: Bearer $tok" \
    -d "{\"body\":\"Bình luận số $i từ user$uid.\"}" > /dev/null || true
done

echo "[seed] done. Open the frontend to see rich data."
