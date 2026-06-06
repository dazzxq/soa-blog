# Plan 03-05 — Live Cutover Summary

**Status:** complete · orchestrator-run (codex-impl-review → push → CI deploy → verify).

## What happened
1. codex-impl-review (branch vs main): APPROVE after 3 rounds — 3 real bugs fixed: anonymous /full leaked connection_status "none" instead of null (Phase 2 regression); ProfileClient::allUsers + ConnectionsController::enrich didn't catch GuzzleException (suggestions/lists would 500 on profile-service outage instead of degrading).
2. Independent backup → /root/proconnect-backups/pre-phase3-*.sql.
3. Deploy: merged phase-3-connection → main, push → GitHub Actions run 27072769346 SUCCESS (idempotent non-destructive db/03-migrate-phase3.sql applied; 8 containers healthy). No new secret/container/grant.
4. Post-deploy smoke reconciliation: smoke-phase2 had 2 now-obsolete assertions (Phase 3 made connection real → /full no longer force-degrades; own-profile status is "self" not "none") — updated the test to match the codex-approved behavior. Also fixed two smoke-phase3 harness bugs (grep-extraction abort under pipefail + accepted-edge cleanup used wrong id → non-idempotent / polluted demo graph).

## Live verification (soa.duyet.vn)
- smoke-phase3.sh: ALL PASS, run twice (idempotent). Covers CONN-01..07, the gateway invariant (self→400, missing→404 PROFILE_NOT_FOUND, dup/connected→409 REQUEST_EXISTS/ALREADY_CONNECTED), accept→connected, lists, suggestions, ownership, email/PII guard.
- **D-05 payoff LIVE:** duyet (token) viewing long → /api/profiles/3/full connection_status:"connected" — the Phase 2 composition endpoint lit up with real Phase 3 data, ZERO AggregateController change.
- Regression: smoke-phase1 + smoke-phase2 ALL PASS.
- Demo graph = clean seed only (duyet↔long accepted, demo→duyet pending).
- 8 containers healthy; 19 other VPS vhosts intact; nginx -t OK; migration non-destructive.

## Cross-service invariant (CONN-04 centerpiece) — live
Gateway checks profile-service existence (404) + connection-service edge (409) + 503-on-incomplete-info before writing to connection-service. Mirrors the brownfield comment-requires-post pattern.
