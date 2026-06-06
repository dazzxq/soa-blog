---
status: passed
phase: 5-t-m-ki-m-th-ng-b-o
verified: 2026-06-07
method: live-production (smoke-phase5.sh ALL PASS x2)
deploy_run: github actions 27075967102 (success)
---
# Phase 5 — Verification (live)
All 4 ROADMAP criteria PASS on soa.duyet.vn: (1) search by name/title/skill → cards (avatar/name/headline) + relationship status + quick-connect (SEARCH-01/02; gateway composes search-service LIKE results with connection_status via Utils::settle, email-allowlisted); (2) notifications on new invite/reaction/comment (NOTIF-01, gateway-coordinated best-effort — main action never blocked); (3) polling near-real-time unread badge + mark-read/read-all, no WebSocket (NOTIF-02/03); (4) gateway composes search + centrally coordinates notifications. SEARCH-01/02 + NOTIF-01/02/03 all live. Security: LIKE parameterized + wildcard-escaped (no SQLi), notifications recipient-scoped (IDOR-safe) + X-User-Id 401 guards, no email leak in search/notification cards, new services no JWT/no host port, best-effort notify isolated (try/catch swallow), non-destructive migration. Review: codex-plan-review APPROVE (2 rounds), codex-impl-review APPROVE (2 rounds). Regression: Phase 1-4 smoke green (isolation; all-5-at-once hits intentional per-IP rate limit). Cross-site: 19 vhosts + host MariaDB intact. Note: gateway correctly excludes the viewer from their own search results.
