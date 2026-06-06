---
status: passed
phase: 3-k-t-n-i-social-graph
verified: 2026-06-07
method: live-production (VPS smoke-phase3.sh ALL PASS x2 idempotent)
deploy_run: github actions 27072769346 (success)
---

# Phase 3 — Verification (live)

All 4 ROADMAP success criteria PASS on soa.duyet.vn:
1. Send/accept/reject invite; after accept both connected — smoke CONN-01/02 PASS.
2. Connections + pending incoming/outgoing lists (enriched, no email) — CONN-03/04 PASS.
3. Relationship status on profile (none/pending_outgoing/pending_incoming/connected/self) + suggestions excluding self+edged — CONN-05/06 PASS.
4. (Gateway demonstrated) cross-service invariant: missing user→404, dup/already-connected→409, self→400, 503-on-incomplete-info, profile-service checked before connection-service write — CONN-07 PASS.

Requirements CONN-01..07 all live. D-05 payoff: /api/profiles/{id}/full connection_status now real ("connected"/"self"/"none") with zero gateway rework. Security: IDOR-safe (X-User-Id scoped, uniform 404), no email leak in cards, connection-service no JWT, uq_pair prevents reverse-invite dup, non-destructive migration. Review gates: codex-plan-review APPROVE (2 rounds), codex-impl-review APPROVE (3 rounds). Regression: Phase 1+2 smoke green. Cross-site: 19 other vhosts + host MariaDB intact.
