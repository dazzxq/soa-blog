# SOA Blog — Microservices + API Gateway Showcase

Đồ án nhóm môn **INT1448 - Phát triển phần mềm hướng dịch vụ** (PTIT, học kỳ 8). Nhóm phụ trách thuyết trình **API Gateway pattern**.

**Live demo:** https://soa.duyet.vn

---

## Kiến trúc

```
                  Cloudflare (DNS + SSL termination)
                          │
                  Nginx VPS :443 (host)
                          │
        ┌─────────────────┼─────────────────┐
        ▼                 ▼                 ▼
   web :8080         API Gateway :8000
   (frontend)              │
                  ┌────────┼────────┐
                  ▼        ▼        ▼
              user-svc  post-svc  comment-svc
                  │        │        │
                  ▼        ▼        ▼
              blog_users blog_posts blog_comments
                          │
                       MariaDB (3 schemas)
```

5 trách nhiệm của Gateway được showcase:

| # | Trách nhiệm | Cách demo |
|---|---|---|
| 1 | **Routing** | `/api/users/*` → user-svc, `/api/posts/*` → post-svc, `/api/comments/*` → comment-svc |
| 2 | **Centralized JWT auth** | Gateway sinh + verify JWT. Service chỉ tin header `X-User-Id`. |
| 3 | **API Composition** ⭐ | `GET /api/posts/{id}/full` gọi đồng thời 3 service rồi gộp post + author + comments + comment authors |
| 4 | **Cross-service invariants** | `POST /api/posts/{id}/comments` kiểm tra post tồn tại; `DELETE /api/posts/{id}` chặn nếu còn comment |
| 5 | **Rate limiting + Logging** | Mỗi IP `RATE_LIMIT_PER_MIN` req/phút, log per-request 1 dòng ra stdout |

---

## Chạy local

```bash
git clone https://github.com/<your-org>/soa-blog.git
cd soa-blog
cp .env.example .env
# Sửa .env: đặt password và JWT_SECRET (>=32 ký tự ngẫu nhiên)

docker compose up -d --build

# Đợi tới khi tất cả container healthy
docker compose ps

# Truy cập:
open http://localhost:8080        # frontend
curl http://localhost:8000/api/health | jq
```

Tài khoản seed sẵn:
- `alice` / `demo123`
- `bob`   / `demo123`

Để có thêm dữ liệu mẫu đẹp khi demo browser:
```bash
./scripts/seed.sh
```

Chạy demo flow đầy đủ cho thuyết trình:
```bash
./scripts/demo.sh
```

---

## Cấu trúc thư mục

```
soa-blog/
├── gateway/                 # API Gateway (Slim 4 + Guzzle + JWT)
│   ├── src/
│   │   ├── Middleware/      # CORS, JWT, RequestId, RateLimit, Logging
│   │   ├── Services/        # UserClient, PostClient, CommentClient
│   │   └── Controllers/     # Auth, Posts, Users, Aggregate, Health
│   └── ...
├── services/
│   ├── user-service/        # Slim 4 + PDO MariaDB
│   ├── post-service/
│   └── comment-service/
├── db/                      # init.sh + schema SQL + seed (volume entrypoint)
├── web/                     # frontend static (HTML + Alpine.js + Tailwind CDN)
├── deploy/                  # VPS nginx + Cloudflare config
├── scripts/                 # demo, seed, deploy, cloudflare-dns
└── .github/workflows/       # CI/CD
```

---

## Deploy lên VPS

CI/CD đã thiết lập sẵn. Sau khi push lên `main`:

1. GitHub Actions chạy `lint` (PHP syntax check).
2. SSH vào VPS, `git pull`, `docker compose up -d --build`.
3. Healthcheck `https://soa.duyet.vn/api/health` 5 lần.

### Secrets cần cấu hình ở GitHub

| Secret | Mô tả |
|---|---|
| `VPS_HOST` | IP hoặc hostname VPS |
| `VPS_USER` | User SSH (recommended: `deploy`) |
| `VPS_DIR`  | Thư mục project trên VPS, vd `/opt/soa-blog` |
| `VPS_SSH_KEY` | Private SSH key có quyền vào VPS |

### One-time setup trên VPS

Xem `deploy/README.md` — tạo Cloudflare origin cert, cài nginx host config.

---

## Tài liệu

- [`PLAN.md`](../PLAN.md) — đặc tả chi tiết kiến trúc, API contract, DB schema, deploy steps.
- [`REPORT.md`](./REPORT.md) — báo cáo cuối kỳ (sẽ viết sau).

## License

MIT.
