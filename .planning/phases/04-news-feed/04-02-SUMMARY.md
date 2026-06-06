---
phase: 04-news-feed
plan: 02
subsystem: feed-service
tags: [feed-service, raw-pdo, timeline-query, reactions, comments]
requires:
  - "proconnect_feed schema (posts/reactions/comments) — Plan 04-01 migration"
  - "feed-service Db/Json/DomainError helpers + HealthController stub — Phase 1"
provides:
  - "feed-service posts CRUD (create/show/index/delete)"
  - "reactions upsert + remove (INSERT ON DUPLICATE KEY UPDATE)"
  - "the ONE timeline query (reaction_count/comment_count/my_reaction via correlated subqueries)"
  - "?ids= batch resolution for gateway repost-original lookup"
  - "comments CRUD with local post-existence invariant"
  - "repost (D-04 root collapse)"
  - "feed-service internal route set"
affects:
  - "Plan 04-03 (gateway feed composition calls these endpoints)"
  - "Plan 04-05 (smoke-phase4.sh asserts counts/my_reaction/404 against deployed stack)"
tech-stack:
  added: []
  patterns:
    - "correlated scalar subqueries for per-row counts (no double-JOIN fan-trap)"
    - "positional ? placeholders for dynamic IN-list + scalars; named for fixed-arity (never mixed)"
    - "INSERT ... ON DUPLICATE KEY UPDATE upsert backed by UNIQUE(post_id,user_id)"
    - "X-User-Id-from-header identity; uniform 404; owner-only delete"
key-files:
  created:
    - services/feed-service/src/Controllers/PostController.php
    - services/feed-service/src/Controllers/CommentController.php
  modified:
    - services/feed-service/src/routes.php
decisions:
  - "Timeline + batch + single-row find() share one SELECT column list (selectColumns helper), differing only in the viewer placeholder (? positional vs :viewer named) — one source of truth for the count subqueries."
  - "Comment post id taken from the route path {id} (not body/query) — cannot be spoofed independently of the URL; index() also reads it from route per the new /posts/{id}/comments shape."
  - "Reaction validation defaults empty type to 'like' before the enum allowlist check."
metrics:
  duration: 3min
  tasks: 2
  files: 3
  completed: 2026-06-06
---

# Phase 4 Plan 02: feed-service Posts + Comments Domain Summary

Built feed-service from a /health stub into the real domain service: posts CRUD, reactions upsert/remove, comments CRUD, repost, and the single-query timeline that computes reaction_count/comment_count/my_reaction per row via correlated scalar subqueries (no gateway N+1, no double-JOIN fan-trap). All writes scoped by the gateway-trusted X-User-Id header; uniform 404; owner-only delete with reactions->comments->posts cascade.

## What Was Built

### Task 1 — PostController.php (commit f6f484f)
- `index()` dual-mode: `?ids=` batch (cap 100 -> `TOO_MANY_IDS`) for gateway repost-original resolution, else the timeline (`?authors=&viewer=&limit=`).
- THE TIMELINE QUERY verbatim from the plan: `posts` filtered by a parameterized `author_id IN (?,?,...)`, each row carrying three correlated subqueries — `(SELECT COUNT(*) FROM reactions ...)`, `(SELECT COUNT(*) FROM comments ...)`, `(SELECT type FROM reactions ... AND user_id = ?)`. Positional bind order: viewer first, authors next, LIMIT last; all `PDO::PARAM_INT`, native prepared (`EMULATE_PREPARES=false`).
- `react()` upsert: `INSERT INTO reactions ... ON DUPLICATE KEY UPDATE type = VALUES(type)` (D-02); `unreact()` idempotent scoped DELETE.
- `repost()` collapses to the root original (D-04). `delete()` owner-only + cascade (reactions -> comments -> posts).
- Type coercion: reaction_count/comment_count -> int, repost_of -> int|null, my_reaction left string|null (Pitfall 4 / T-04-12).

### Task 2 — CommentController.php + routes.php (commit b352eb9)
- CommentController `index`/`create`/`delete` cloned from brownfield comment-service, re-homed into proconnect_feed. Post id from the route path; LOCAL post-existence invariant via `SELECT 1 FROM posts WHERE id` (T-04-11, no cross-service hop). Author from X-User-Id; body 1-5000; owner-only delete.
- routes.php: full feed route set with suffixed `/posts/{id}/(repost|reactions|comments)` registered BEFORE the bare `/posts/{id}` GET/DELETE; numeric `{id:[0-9]+}` constraints; `/health` kept.

## Threat Model Coverage
- T-04-06 (IDOR delete): owner check `author_id == X-User-Id` -> 403; caller from header only; uniform 404 — mitigated.
- T-04-07 (body tampering): grep-verified no `$b['author'/'user_id'/...]` — identity always from header.
- T-04-08 (SQLi IN-list): native prepared, positional placeholders, no string-interpolated ids.
- T-04-09 (count fan-trap): correlated subqueries; grep asserts no `LEFT JOIN reactions/comments`.
- T-04-10 (double reaction): UNIQUE(post_id,user_id) + ON DUPLICATE KEY UPDATE upsert.
- T-04-11 (orphan): react/repost via findPost() null guard, comment via postExists() — 404.
- T-04-12 (wrong viewer): my_reaction scoped `user_id = :viewer`; viewer=0 -> NULL; string|null.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Reaction `type` parsing precedence**
- **Found during:** Task 1
- **Issue:** Initial `react()` wrote `trim((string) ((array)$body)['type'] ?? '')` — the `?? ''` bound outside `trim()` and the array access on a possibly-missing key would emit a warning under strict types.
- **Fix:** Extracted `$b = (array)($req->getParsedBody() ?? [])` then `$type = trim((string)($b['type'] ?? ''))`, matching the brownfield doctrine.
- **Files modified:** services/feed-service/src/Controllers/PostController.php
- **Commit:** f6f484f

**2. [Rule 3 - Blocking] Fan-trap negative-assertion tripped by explanatory comment**
- **Found during:** Task 1 verification
- **Issue:** The plan's gate asserts `! grep -qE "LEFT JOIN reactions|LEFT JOIN comments"`. The class docstring literally contained `LEFT JOIN reactions LEFT JOIN comments` as the anti-pattern it warns against, so the static gate failed on the comment despite zero JOINs in the actual SQL.
- **Fix:** Reworded the docstring to describe the cross-product anti-pattern without the literal token. Gate then passed verbatim. No SQL change.
- **Files modified:** services/feed-service/src/Controllers/PostController.php
- **Commit:** f6f484f

## Runtime / Docker Verification
Docker is NOT installed locally — no container/mysql run was possible. Verification was static: `php -l` clean on both controllers + routes.php, plus grep assertions (upsert SQL, correlated subqueries, X-User-Id header source, cascade deletes, no fan-trap JOIN, no JWT refs, no body-sourced identity, route registration/ordering). Runtime behavior (exact counts, viewer-relative my_reaction, 404 invariants, owner-scope, route collision) is **deferred to VPS/CI in Plan 04-05** via smoke-phase4.sh against the deployed stack.

## Self-Check: PASSED
- FOUND: services/feed-service/src/Controllers/PostController.php
- FOUND: services/feed-service/src/Controllers/CommentController.php
- FOUND: services/feed-service/src/routes.php (modified)
- FOUND commit: f6f484f
- FOUND commit: b352eb9
