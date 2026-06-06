# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-06-06)

**Core value:** API Gateway điều phối một hệ microservices đủ phong phú (profile, connection/graph, feed, search) thể hiện rõ và thuyết phục các trách nhiệm cốt lõi của Gateway pattern.
**Current focus:** Phase 1 — Nền tảng & Gateway

## Current Position

Phase: 1 of 6 (Nền tảng & Gateway)
Plan: 0 of TBD in current phase
Status: Ready to plan
Last activity: 2026-06-06 — Roadmap created, 36/36 v1 requirements mapped across 6 phases

Progress: [░░░░░░░░░░] 0%

## Performance Metrics

**Velocity:**
- Total plans completed: 0
- Average duration: —
- Total execution time: 0 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| - | - | - | - |

**Recent Trend:**
- Last 5 plans: —
- Trend: —

*Updated after each plan completion*

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- [Roadmap]: Brownfield — `user-service` tiến hoá thành `profile-service`; thêm connection/feed/search/notification-service, mỗi bounded context 1 service.
- [Roadmap]: Giữ stack ≤ 9 container (ngân sách 2GB RAM); 1 MariaDB nhiều schema; notification polling (không WebSocket).
- [Roadmap]: UI tính năng (UI-03) fold vào Phase 2; chrome thương hiệu (UI-01/02/04) gom vào Phase 6 sau khi mọi tính năng tồn tại để navbar/badge nối đúng.
- [Roadmap]: Mỗi phase tính năng thêm 1 endpoint composition hoặc invariant tại gateway để pattern luôn nổi bật.

### Pending Todos

[From .planning/todos/pending/ — ideas captured during sessions]

None yet.

### Blockers/Concerns

[Issues that affect future work]

- Ngân sách RAM cứng (2GB chia sẻ, ≤ 9 container): theo dõi số container ở mỗi phase thêm service mới — Phase 1, 3, 4, 5 đều thêm service.
- Chưa có test framework (chỉ `php -l` ở CI): cân nhắc thêm PHPUnit trước refactor lớn ở Phase 1.
- Tailwind Play CDN không production-grade cho UI phong phú hơn: cân nhắc build step ở Phase 6.

## Session Continuity

Last session: 2026-06-06
Stopped at: ROADMAP.md + STATE.md created, REQUIREMENTS.md traceability updated
Resume file: None
