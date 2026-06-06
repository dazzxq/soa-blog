# Phase 1: Nền tảng & Gateway - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-06
**Phase:** 1-Nền tảng & Gateway
**Areas discussed:** Service topology + container budget, Migration user→profile, Service stub shape, Gateway/DB wiring conventions

---

## Service topology + container budget

### Số phận post-service + comment-service
| Option | Description | Selected |
|--------|-------------|----------|
| Retire ngay, feed-service thành stub mới | Xoá post+comment, feed stub mới, giữ code cũ tham khảo → 8 container | ✓ |
| Đổi tên post→feed tại chỗ, gộp comment | post git mv thành feed, comment gộp vào, giữ data cũ → 8 container | |
| Giữ post+comment riêng, hoãn notification | 9 container sát nút, trùng lặp khái niệm | |

### Scaffold bao nhiêu service mới
| Option | Description | Selected |
|--------|-------------|----------|
| Cả 4 stub ngay | connection+feed+search+notification stub /health | ✓ |
| Hoãn notification, scaffold 3 | notification dựng ở Phase 5 | |
| Chỉ profile + 1-2 core | tối thiểu hoá Phase 1 | |

### Hiểu "deploy additive / site không gãy"
| Option | Description | Selected |
|--------|-------------|----------|
| Deploy xanh + /api/health OK là đủ | chấp nhận endpoint blog cũ chết | ✓ |
| Phải giữ tương thích ngược URL cũ | alias /api/users, /api/posts | |

**User's choice:** Retire post/comment + scaffold cả 4 stub + additive=health-ok.
**Notes:** User sau đó xác nhận "thoải mái restructure, không cần bảo vệ SOA cũ".

---

## Migration user→profile

### Cơ chế rename
| Option | Description | Selected |
|--------|-------------|----------|
| git mv đổi tên tại chỗ | đổi directory/container/client/env, giữ logic | ✓ |
| Dựng profile-service mới song song | copy code, cutover sau (tạm 2 container) | |

### Xử lý DB live (blog_users + 5 demo account)
| Option | Description | Selected |
|--------|-------------|----------|
| Wipe & reseed từ db/99-seed.sql | drop schema cũ, tạo proconnect_profile, reseed | ✓ |
| Migrate giữ data: RENAME schema | migration script giữ data | |
| Giữ nguyên tên schema blog_users | rủi ro thấp, tên không khớp brand | |

### Route surface gateway Phase 1
| Option | Description | Selected |
|--------|-------------|----------|
| Auth + /api/profiles/{id} cơ bản | giữ auth, đổi users→profiles | ✓ |
| Chỉ auth, không route profile công khai | tối thiểu | |
| Giữ nguyên /api/users/{id} cũ | ít thay đổi nhất | |

**User's choice:** git mv + wipe&reseed + auth & /api/profiles/{id}.
**Notes:** Flag cho planner: mariadb_data volume chỉ init khi mới → cần drop volume/migration chủ động.

---

## Service stub shape

### Tầng stub lộ ra
| Option | Description | Selected |
|--------|-------------|----------|
| /health có check DB, chưa route nghiệp vụ | Slim app tối thiểu, đúng pattern | ✓ |
| /health nông, không động DB | nhẹ nhất | |
| /health + 1 route placeholder | demo routing end-to-end | |

### Provision DB cho stub
| Option | Description | Selected |
|--------|-------------|----------|
| Tạo DB + user riêng, chưa tạo bảng | schema rỗng + scoped user | ✓ |
| Tạo luôn cả bảng | schema đầy đủ sớm | |
| Chưa provision DB cho stub | DB tạo ở phase sau | |

### Gateway /api/health fan-out
| Option | Description | Selected |
|--------|-------------|----------|
| Fan-out đủ 5 service | báo trạng thái từng cái | ✓ |
| Chỉ gồm service đã build | tránh phụ thuộc stub | |

**User's choice:** /health+DB + DB-user-no-tables + fan-out đủ 5.

---

## Gateway/DB wiring conventions

### Cách wiring gateway tới stub
| Option | Description | Selected |
|--------|-------------|----------|
| Typed client + controller cho từng service | đúng pattern UserClient/PostClient | ✓ |
| Profile typed, 4 stub proxy generic | ít boilerplate, 2 pattern tạm | |
| Tất cả proxy generic | ít code, làm mờ pattern | |

### Forward X-Request-Id downstream
| Option | Description | Selected |
|--------|-------------|----------|
| Bật ngay | tracing end-to-end rẻ | ✓ |
| Hoãn, giữ hiện trạng | request-id chỉ ở gateway | |

### Quy ước đặt tên DB
| Option | Description | Selected |
|--------|-------------|----------|
| proconnect_<svc> + <svc>_svc | brand-aligned, prefix chung | ✓ |
| <svc>_db + <svc>_svc | ngắn gọn, không prefix | |
| Claude tự quyết | | |

**User's choice:** typed client + X-Request-Id bật ngay + proconnect_<svc>.

## Claude's Discretion
- Route path prefix cụ thể cho từng service; cấu trúc thư mục stub (clone user-service); chi tiết healthcheck; cách wipe volume VPS an toàn.

## Deferred Ideas
- Business logic connection/feed/search/notification (Phase 3/4/5); profile đầy đủ (Phase 2); notification real-time/WebSocket (out of scope); object storage; Redis cache; PHPUnit (cân nhắc ở researcher).
