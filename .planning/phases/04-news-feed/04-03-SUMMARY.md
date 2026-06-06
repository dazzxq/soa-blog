---
phase: 04-news-feed
plan: 03
subsystem: gateway
tags: [gateway, composition, api-gateway, degrade, enrich, union-batch]
requires:
  - "feed-service posts/reactions/comments/repost + ?ids= batch + timeline — Plan 04-02"
  - "ConnectionClient::listAccepted + ProfileClient::batch — Phase 3"
  - "AggregateController settle/degrade + ConnectionsController enrich/allowlist patterns — Phase 2/3"
  - "OptionalJwtMiddleware (optMw) + JwtAuthMiddleware (jwtMw) — Phase 2/3"
provides:
  - "GET /api/feed — 3-service composition (connection -> feed -> profile) newest-first, degrade-safe"
  - "GET /api/posts/{id} — single-post composition (optional-auth, union author batch)"
  - "POST /api/posts, /repost, DELETE, reactions, comments — authenticated passthrough (JWT->X-User-Id)"
  - "GET /api/posts/{id}/comments — enriched comment authors (email allowlist)"
  - "FeedClient typed feed-service surface (timeline/getPosts(Async)/getPost/createPost/.../deleteComment)"
affects:
  - "Plan 04-04 (feed UI consumes /api/feed + posts/reactions/comments/repost)"
  - "Plan 04-05 (smoke-phase4.sh asserts the composition/degrade/email-free/404 against the deployed stack)"
tech-stack:
  added: []
  patterns:
    - "UNION-BATCH: resolve repost originals (Utils::settle, no N+1) THEN ONE ProfileClient::batch over {post authors ∪ original authors}"
    - "email allowlist via array_intersect_key on every author card (post/original/comment); never emit email (Pitfall 2)"
    - "degrade-safe composition: connection/reposts/profiles failures -> meta.degraded; feed-service is the only hard dep"
    - "thin authenticated passthrough mapping JWT me() -> X-User-Id (body user_id never trusted)"
    - "assemble in place (preserve feed-service newest-first order, never re-sort by a map — Pitfall 6)"
key-files:
  created:
    - gateway/src/Controllers/FeedController.php
  modified:
    - gateway/src/Services/FeedClient.php
    - gateway/src/routes.php
    - gateway/public/index.php
decisions:
  - "feed() uses the UNION-BATCH shape: STEP 3 resolves originals first, then STEP 4 issues ONE profile batch over the union of post-authors ∪ original-authors. The fan-out is SEQUENTIAL by construction (the union needs the originals' authors) — ≤2 round trips after the timeline spine, NOT a parallel profile/originals settle. The single batch carries the exact `// FEED-UNION-BATCH` marker so the verifier can grep+awk-assert exactly one ->batch( in feed()."
  - "Every author card (post author, repost-original author, comment author) is allowlisted to {id,username,display_name,avatar_url} via array_intersect_key. /users?ids= SELECTs email, so the gateway MUST allowlist (T-04-13); the FeedController source contains no 'email' literal."
  - "feed-service is the only hard dependency: connection-service down -> own-posts + meta.degraded:['connections']; a failed originals batch -> degraded:['reposts'] + original:null; a failed profile batch -> degraded:['profiles'] + author:null. No partial failure yields a 500."
  - "Mutations + single-post resolution reuse the proven thin Json::raw passthrough (me() -> FeedClient -> X-User-Id). The missing-post 404, reaction-type validation, and owner-scope all live in feed-service (Plan 02) — the gateway is a thin authenticated passthrough, no duplicated invariant."
  - "showPost is the single-row variant of the same union batch (optional-auth viewer from getAttribute('user_id'), 0=anon); listComments enriches comment authors through one profile batch with the same allowlist + degrade."
metrics:
  duration: 3min
  tasks: 3
  files: 4
  completed: 2026-06-06
---

# Phase 4 Plan 3: Gateway Feed Composition Summary

GET /api/feed composes three services into one newest-first timeline — connection-service (author universe = self ∪ accepted connections) -> feed-service (posts + precomputed counts + viewer-relative my_reaction) -> ONE union ProfileClient::batch over {post authors ∪ resolved repost-original authors} — with repost originals batch-resolved (no N+1), every author card email-allowlisted, and connection/reposts/profiles failures degrading to meta.degraded instead of a 500; plus single-post composition, enriched comment listing, and the authenticated posts/reactions/comments/repost passthrough mapping JWT -> X-User-Id.

## What Was Built

- **FeedClient (extended)** — typed feed-service surface: `timeline`, `getPosts`/`getPostsAsync` (?ids= batch), `getPost`, `listComments`, and the mutations `createPost`/`deletePost`/`repost`/`react`/`unreact`/`addComment`/`deleteComment`. Mutations inject the gateway-trusted `X-User-Id` header (mirrors ConnectionClient); `getPostsAsync` exists for the STEP 3 originals settle idiom.
- **FeedController** — `feed()` 5-step UNION-BATCH composition, `showPost()` (single-row composition, optional-auth), `listComments()` (enriched authors), and seven thin passthrough mutation/read methods. Private `me()`/`decode()`/`allowlist()` helpers cloned from ConnectionsController.
- **routes + DI** — `/api/feed` and the `/api/posts*` + `/api/comments/{id}` route block (suffixed `/posts/{id}/repost|reactions|comments` before the bare `/posts/{id}`; jwtMw on feed+mutations, optMw on single-post GET, public comment list) + the FeedController DI factory.

## The feed() composition (UNION-BATCH)

1. **STEP 1 author universe** — `$authorIds=[$me]`; ConnectionClient::listAccepted append each `user_id`; non-200/GuzzleException -> degrade `connections` (own-posts only).
2. **STEP 2 posts+counts (HARD dep)** — FeedClient::timeline; non-200 -> `Json::raw` passthrough (fail the feed — feed-service is the only hard dependency).
3. **STEP 3 originals (no N+1)** — collect unique `repost_of` ids; `Utils::settle([getPostsAsync($ids)])->wait()`; 200 -> `id->original` map; else degrade `reposts`.
4. **STEP 4 ONE union batch** — `$authorUnion = unique(post author_ids ∪ original author_ids)`; the single `// FEED-UNION-BATCH` ProfileClient::batch; 200 -> `id->allowlisted card` map (serves BOTH post + original authors); else degrade `profiles`.
5. **STEP 5 assemble in place** — preserve newest-first order; attach `author` + (`original` with its allowlisted author | `null` for a deleted original); emit `Json::list` with `meta.degraded` when non-empty.

## Deviations from Plan

None — plan executed exactly as written. The slightly convoluted STEP 5 `else` branch was simplified to a clean `elseif ($rid > 0) { $p['original'] = null; }` during Task 2 (cosmetic, same behavior; not a functional deviation).

## Verification

- `php -l` clean for all four files (FeedClient, FeedController, routes.php, public/index.php).
- grep/awk asserts all pass: `listAccepted` call, `Utils::settle` (originals), the `// FEED-UNION-BATCH` marker present exactly once, an awk range scoped to `function feed(` counts EXACTLY one `->batch(` (no second profile batch in the feed() path), `array_intersect_key` allowlist with `display_name`, NO `'email'` literal, `repost_of`/`original` handling, `degraded`, `getAttribute('user_id')` optional-auth, and routes + DI registration.
- HttpClient.php untouched -> X-Request-Id downstream forwarding (RequestIdMiddleware static set + lazy per-request clients) intact.
- **Runtime checks deferred to VPS/CI (Plan 05):** Docker is not installed locally, so the live 3-service composition, precomputed counts, viewer-relative my_reaction, degrade behavior, email-free bodies, missing-post 404 invariants, owner-scope, and newest-first ordering are asserted by `smoke-phase4.sh` against the deployed stack.

## Known Stubs

None — all author cards are wired to real ProfileClient::batch data; no hardcoded empty/placeholder values flow to output.

## Self-Check: PASSED

- Files: FeedController.php, FeedClient.php, routes.php, public/index.php, 04-03-SUMMARY.md — all FOUND.
- Commits: 808941b, c44e248, 019745f — all FOUND.
