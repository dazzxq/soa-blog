# Phase 2: Hồ sơ nghề nghiệp - Discussion Log

> **Audit trail only.** Decisions captured in 02-CONTEXT.md.

**Date:** 2026-06-06
**Phase:** 2-Hồ sơ nghề nghiệp
**Mode:** User-delegated. User selected all 4 gray areas then said: "nhiều thứ phết nhỉ, tôi cũng ko hiểu lắm đâu, nên cứ suggest nhé sao cho fit với nhu cầu của tôi về bài toán microservices để demo cho project môn học." → Claude provided recommended defaults optimised for a simple, clear API-Gateway showcase; user to object before planning if any don't fit.

## Areas + Claude recommendations (selected = the recommendation)

### Composition endpoint + connection-status
- Gateway does genuine parallel composition (`GET /api/profiles/{id}/full`), fan-out profile-service + connection-service via settle, degrade on partial failure (D-01..D-04).
- connection-service still a stub → call it anyway, degrade to `connection_status:"none"` now; lights up automatically in Phase 3 (D-03). Chosen over "defer field" / "fake stub" because it builds the composition+degrade demo now with zero Phase-3 gateway rework.

### Data model
- Extend `users` (cover_url/headline/location/about); new tables experience/education/skills in proconnect_profile; skills = simple list, no endorsements; images = URL strings (D-05..D-09). Chosen for simplicity within one bounded context.

### Editing model
- Granular `/api/profiles/me/*` CRUD, owner-only via X-User-Id, profile-service scopes writes by header (D-10..D-12). Chosen `/me` over `/{id}` to avoid IDOR.

### UI scope
- Functional profile view + edit pages, minimal styling (Alpine+Tailwind CDN); branding/3-column deferred to Phase 6 (D-13..D-14).

## Deferred
- Real connection status (Phase 3), endorsements, branding (Phase 6), binary upload (out-of-scope).
