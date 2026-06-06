# Phase 3: Kết nối / Social Graph - Context

**Gathered:** 2026-06-07
**Status:** Ready for planning
**Note:** User-delegated decisions ("cứ suggest sao cho fit demo microservices, keep simple"). Claude-recommended defaults optimised to showcase the **cross-service invariant** responsibility of the API Gateway, simply.

<domain>
## Phase Boundary
Build the social graph: send/accept/reject connection invites, view connections + pending invites (incoming/outgoing), "People you may know" suggestions, and show correct relationship status on profiles — with the **gateway enforcing cross-service graph invariants** (the phase's grading centerpiece). Builds the real `connection-service` (currently a /health-only stub).
Requirements: CONN-01..07. Depends on Phase 2.

**OUT of scope:** feed (Phase 4), search/notifications (Phase 5), branding/3-column (Phase 6), mutual-friend ranking for suggestions.
</domain>

<decisions>
## Implementation Decisions

### A. The Gateway invariant — showcase centerpiece (CONN-04)
- **D-01:** On `POST /api/connections/requests {target_id}`, the gateway enforces a cross-service invariant BEFORE any write (mirrors the classic "comment requires existing post" pattern from the brownfield, recoverable via git history):
  1. reject self-invite → `400`.
  2. call `profile-service GET /users/{target_id}` → if absent `404 PROFILE_NOT_FOUND`.
  3. call `connection-service` to check an existing edge in EITHER direction → if accepted `409 ALREADY_CONNECTED`; if pending `409 REQUEST_EXISTS`.
  4. only then write the pending edge to connection-service.
  This is the explicit demonstration of gateway-orchestrated invariants across two services — call it out in the presentation.

### B. Data model (connection-service, own DB `proconnect_connection`, raw-PDO, logical FK)
- **D-02:** Single table `connections(id, requester_id BIGINT, addressee_id BIGINT, status ENUM('pending','accepted') NOT NULL DEFAULT 'pending', created_at, updated_at, UNIQUE(requester_id, addressee_id), INDEX(addressee_id), INDEX(status))`. Direction is preserved (requester vs addressee) so we can show "đã gửi" vs "chờ phản hồi".
- **D-03:** Reject an invite = DELETE the pending row (re-invitable later). Remove a connection = DELETE the accepted row. No soft-delete/rejected status (keep simple).

### C. Endpoints (gateway `/api/connections/*`, owner-scoped via X-User-Id; connection-service mirrors internally)
- **D-04:**
  - `POST /api/connections/requests` `{target_id}` — send invite (runs the D-01 invariant).
  - `POST /api/connections/requests/{id}/accept` — addressee accepts → `accepted`.
  - `POST /api/connections/requests/{id}/reject` — addressee rejects → delete.
  - `DELETE /api/connections/requests/{id}` — requester cancels own outgoing pending.
  - `DELETE /api/connections/{userId}` — remove an accepted connection (either side).
  - `GET /api/connections` — my accepted connections (gateway-enriched with profile basics).
  - `GET /api/connections/requests?direction=incoming|outgoing` — my pending invites (enriched).
  - `GET /api/connections/suggestions` — People you may know (composition).
  - `GET /api/connections/status/{userId}` — relationship status vs me (also reused by the internal status call).
- **D-05:** **connection-service MUST implement internal `GET /connections/status?viewer=&target=`** — this is the EXACT path the existing `gateway/src/Services/ConnectionClient::statusForAsync()` already calls. Implementing it makes the Phase 2 `/api/profiles/{id}/full` composition's `connection_status` light up with ZERO gateway rework (the planned D-03 payoff). Status values: `"none" | "pending_outgoing" | "pending_incoming" | "connected" | "self"`.

### D. Enrichment & suggestions (more gateway composition)
- **D-06:** Connection/invite lists = gateway composition: connection-service returns user_ids + status; gateway batch-fetches profile basics (display_name, headline, avatar_url) from profile-service to build cards; degrade-safe (meta.degraded if profile batch partly fails).
- **D-07:** "People you may know" (CONN-06): SIMPLE heuristic — candidates = users I have NO edge with (not connected, no pending), excluding myself, capped (e.g. 10); connection-service computes the exclusion set, gateway enriches with profile basics. No mutual-friend ranking (keep simple, still demonstrates composition).

### E. Migration / infra (non-destructive, like Phase 2)
- **D-08:** Idempotent `db/03-migrate-phase3.sql` (`CREATE TABLE IF NOT EXISTS connections` in proconnect_connection) wired BLOCKING into `scripts/deploy.sh` after the phase-2 migrate step; sync the fresh-volume connection schema file. Small idempotent demo seed (e.g. duyet↔long accepted; demo→duyet pending) so the demo graph has content. NON-destructive (no DROP). No new container/secret/grant (connection_svc already owns proconnect_connection from Phase 1).
- **D-09:** connection-service trusts `X-User-Id` (no JWT lib), raw-PDO + Json/DomainError Vietnamese envelope. Gateway verifies JWT → X-User-Id; ownership enforced (accept/reject only by addressee; cancel only by requester) — checked at gateway and/or scoped in connection-service queries.

### F. UI (CONN UI, minimal)
- **D-10:** `web/connections.html`: my connections list + incoming/outgoing pending (accept/reject/cancel) + "People you may know" (connect button). `web/profile.html` gains a relationship-status badge + context action (Kết nối / Huỷ lời mời / Chấp nhận / Đã kết nối). Alpine+Tailwind CDN, Vietnamese, minimal (NO branding/3-column — Phase 6).

### Claude's Discretion
- Exact suggestion cap/ordering, pagination, internal route shapes, status-enum storage details, which side "removes" a connection, demo-seed specifics.
</decisions>

<canonical_refs>
## Canonical References
- `.planning/phases/01-n-n-t-ng-gateway/01-CONTEXT.md` (typed clients, X-User-Id trust, proconnect_* naming, deploy/migration pattern).
- `.planning/phases/02-h-s-ngh-nghi-p/02-CONTEXT.md` + 02-RESEARCH.md (composition+settle+degrade+enrichment pattern; idempotent live-migration wired into deploy.sh; owner-scoping; smoke pattern).
- `.planning/codebase/ARCHITECTURE.md` — the cross-service invariant pattern ("comment requires existing post", "delete blocked while comments exist") to mirror for the graph invariant.
- `.planning/PROJECT.md` (connection-service = classic graph use case; keep simple), REQUIREMENTS.md (CONN-01..07), ROADMAP.md §Phase 3.
- Code: `gateway/src/Services/ConnectionClient.php` (statusForAsync → `GET /connections/status?viewer=&target=` — match it), `gateway/src/Services/ProfileClient.php` (batch profile fetch for enrichment), `gateway/src/Controllers/ProfilesController.php` (composition pattern), `gateway/src/routes.php` + `public/index.php` (route/DI), `services/connection-service/*` (stub to build out), `db/02-migrate-phase2.sql` (idempotent-migration pattern), `scripts/deploy.sh` (wire migration), `scripts/smoke-phase2.sh` (smoke pattern), `web/profile.html` + `web/assets/app.js` (UI pattern).
</canonical_refs>

<code_context>
## Existing Code Insights
- **connection-service** stub: has Db.php/Json/DomainError/JsonErrorHandler/Controllers/Models skeleton + /health only — build CRUD + status here.
- **ConnectionClient** (gateway): health/healthAsync/statusForAsync exist; add request/accept/reject/cancel/list/suggestions methods + `*Async` for enrichment.
- **ProfileClient**: reuse for existence check (D-01 step 2) + batch enrichment (D-06).
- **Composition + Utils::settle + meta.degraded** (Phase 2 ProfilesController) — reuse for enriched lists + suggestions.
- **Idempotent migration + deploy.sh wiring** (Phase 2) — clone for db/03-migrate-phase3.sql.
- proconnect_connection DB + connection_svc user already provisioned (Phase 1).
</code_context>

<specifics>
## Specific Ideas
- Phase 3 makes the Phase 2 `/full` `connection_status` go LIVE (presentation: "watch the profile's connection badge light up now that connection-service is real — same gateway endpoint, zero rework").
- 5 demo accounts: seed a small graph (duyet↔long connected; demo→duyet pending) for demo content.
- Mandatory: /codex-plan-review before code, /codex-impl-review before commit (CLAUDE.md).
</specifics>

<deferred>
## Deferred Ideas
- Feed (Phase 4), search + notifications (Phase 5), ProConnect branding/3-column (Phase 6).
- Notification on new invite — Phase 5 (notification-service). Phase 3 just creates the edges.
- Mutual-friend / smart suggestion ranking — out of scope (simple exclusion list).
*No scope creep — within phase boundary.*
</deferred>

---
*Phase: 03-k-t-n-i-social-graph*
*Context gathered: 2026-06-07 (Claude-recommended defaults, user-delegated)*
