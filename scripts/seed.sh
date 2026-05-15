#!/usr/bin/env bash
# Tier-2 rich demo seed via gateway API.
# Use AFTER docker compose up. Tier-1 seed (db/99-seed.sql) đã tạo sẵn:
#   demo, duyet, long, diep, tai  — pass "demo@123**"
# Script này tạo thêm ~20 post và ~50 comment do 4 thành viên nhóm + tài khoản demo
# rải đều, để trang nhìn đầy đặn khi thuyết trình.
set -euo pipefail

BASE="${BASE:-http://localhost:8000}"
JSON='Content-Type: application/json'
PASS='demo@123**'
AUTHORS=(demo duyet long diep tai)

random_topic() {
  local topics=("Microservices" "API Gateway" "Database-per-service" "JWT" "Docker Compose"
                "Cloudflare" "Slim Framework" "API Composition" "Circuit Breaker" "REST")
  echo "${topics[RANDOM % ${#topics[@]}]}"
}

random_intro() {
  local lines=(
    "Hôm nay nhóm mình muốn chia sẻ một vài kinh nghiệm về"
    "Trong quá trình làm đồ án, nhóm rút ra được một số điểm thú vị về"
    "Bài viết này tổng hợp lại các kiến thức quan trọng về"
    "Một trong những thử thách lớn nhất khi triển khai project là"
  )
  echo "${lines[RANDOM % ${#lines[@]}]}"
}

# ---------- Login all team members ----------
declare -A TOKEN
echo "[seed] đăng nhập 5 tài khoản: ${AUTHORS[*]}"
for u in "${AUTHORS[@]}"; do
  t=$(curl -s -X POST "$BASE/api/auth/login" -H "$JSON" \
    -d "{\"login\":\"$u\",\"password\":\"$PASS\"}" | jq -r '.data.token')
  if [[ -z "$t" || "$t" == "null" ]]; then
    echo "[seed] FATAL: không đăng nhập được tài khoản '$u'" >&2
    exit 1
  fi
  TOKEN[$u]="$t"
done

# ---------- Create posts ----------
POSTS=()
echo "[seed] tạo 20 bài viết, tác giả luân phiên các thành viên"
for i in {1..20}; do
  author=${AUTHORS[$((RANDOM % ${#AUTHORS[@]}))]}
  topic=$(random_topic)
  intro=$(random_intro)
  pid=$(curl -s -X POST "$BASE/api/posts" -H "$JSON" -H "Authorization: Bearer ${TOKEN[$author]}" \
    -d "{\"title\":\"Tìm hiểu về $topic (#$i)\",\"content\":\"$intro $topic. Đây là bài viết minh hoạ để hiển thị giao diện danh sách. Nhóm hi vọng các bạn sẽ thấy hữu ích!\"}" \
    | jq -r '.data.id') || pid=
  [[ -n "$pid" && "$pid" != "null" ]] && POSTS+=("$pid")
done
echo "  → đã tạo ${#POSTS[@]} bài"

# ---------- Comments ----------
echo "[seed] thêm ~50 bình luận, tác giả random"
SAMPLES=(
  "Bài viết hay quá, cảm ơn nhóm đã chia sẻ!"
  "Mình thấy phần này giải thích rất rõ ràng."
  "Có ai có ví dụ thực tế cho phần này không?"
  "Đọc xong mới thấy microservices không đơn giản như mình tưởng."
  "Khi nào nên dùng pattern này thay cho monolith vậy nhóm?"
  "Mình áp dụng vào project của mình thì work luôn."
)
for ((i=0; i<50; i++)); do
  author=${AUTHORS[$((RANDOM % ${#AUTHORS[@]}))]}
  postId=${POSTS[$((RANDOM % ${#POSTS[@]}))]}
  body=${SAMPLES[$((RANDOM % ${#SAMPLES[@]}))]}
  curl -s -X POST "$BASE/api/posts/$postId/comments" -H "$JSON" -H "Authorization: Bearer ${TOKEN[$author]}" \
    -d "{\"body\":\"$body\"}" > /dev/null || true
done

echo "[seed] xong. Mở frontend để xem dữ liệu phong phú."
