---
status: passed
phase: 4-news-feed
verified: 2026-06-07
method: live-production (smoke-phase4.sh ALL PASS x2)
deploy_run: github actions 27074341685 (success)
---
# Phase 4 — Verification (live)
All 4 ROADMAP criteria PASS on soa.duyet.vn: (1) post text+image url, timeline own+connections newest-first (FEED-01/02); (2) reaction one/user changeable + comment (FEED-03/04); (3) repost shows origin (FEED-05); (4) gateway feed composition — each post + author + reaction_count + comment_count + my_reaction, 3-service (connection+feed+profile) union-batch + safe degrade (FEED-06). FEED-01..06 all live. Security: count fan-trap defeated (EXACT asymmetric canary 2/1), no email leak in cards, IDOR owner-only delete + uniform 404, light 404 invariant on missing post, feed-service no JWT, cascade-delete no orphans, non-destructive migration. Review: codex-plan-review APPROVE (3 rounds), codex-impl-review APPROVE (1 round). Regression: Phase 1/2/3 smoke green. Cross-site: 19 vhosts + host MariaDB intact.
