# Phase 4: News Feed - Research

**Researched:** 2026-06-07
**Domain:** API Gateway read-heavy multi-service composition (the showcase centerpiece) + build-out of the real feed-service (posts/reactions/comments/reposts CRUD, raw-PDO, owner-scoped) + gateway timeline composition (connection ∪ self → feed → profile enrichment, parallel fan-out + degrade + newest-first) + idempotent live-DB migration + minimal Alpine/Tailwind UI
**Confidence:** HIGH — every claim below is verified against actual source files read this session (feed-service stub, gateway clients/controllers/routes/DI, connection-service ConnectionController, profile-service UserController, db schema/migration/init, deploy.sh, smoke-phase3.sh, web pages + app.js) plus the brownfield posts/comments code recovered verbatim from git `2f6ecf8` (PostsController gateway + post-service PostController + comment-service CommentController).

## Summary

Phase 4 is the project's richest Gateway demo: a single `GET /api/feed` request fans out to THREE services in sequence-then-parallel (connection-service → who are my connections; feed-service → their recent posts WITH counts; profile-service → author enrichment), composes one newest-first timeline, and degrades safely. This is the read-heavy API Composition story the grading rubric rewards, and every mechanic it needs already ships in the repo: the `Utils::settle` + `meta.degraded` pattern (`AggregateController::profileFull`), the batch `?ids=` enrichment (`ProfilesController::enrich` / git-history `PostsController::fetchAuthors`), the X-User-Id owner-scoped raw-PDO CRUD doctrine (`connection-service ConnectionController`, git-history `post-service PostController` + `comment-service CommentController`), and the idempotent live-volume migration wired into `deploy.sh` (`db/03-migrate-phase3.sql` + step after `7b`).

The feed-service is currently a `/health`-only stub whose scaffold (Slim 4, manual wiring in `public/index.php`, raw-PDO `Db.php` pointed at `proconnect_feed`, `App\Json`/`App\DomainError`/`App\JsonErrorHandler`) is byte-for-byte identical to connection-service and the other services. Building it out means: (1) clone the git-history `post-service/PostController` for posts CRUD (drop the `title`/`slug` machinery — D-01 has no title), (2) clone `comment-service/CommentController` for comments, (3) add reactions as a new upsert path (`INSERT ... ON DUPLICATE KEY UPDATE type`, DELETE to remove, `UNIQUE(post_id,user_id)`), and (4) write the ONE efficient timeline query `GET /posts?authors=&viewer=&limit=` that returns each post with `reaction_count`, `comment_count`, and the viewer's `my_reaction` via correlated subqueries / a LEFT JOIN — computed inside feed-service's own DB so the gateway never does N+1 counting.

The gateway side adds a `FeedController` (clone the shape of `ConnectionsController`) and extends the already-stubbed `FeedClient` (currently health-only). The headline `GET /api/feed` composition is: resolve the author universe from `ConnectionClient::listAccepted($me)` + self → call `FeedClient::timeline(authorIds, viewer, limit)` → batch-enrich authors via `ProfileClient::batch()` (allowlist out email) → resolve repost originals (batch the `repost_of` ids in the SAME feed-service call universe to avoid N+1) → settle + degrade + emit newest-first. The DB migration clones `db/03-migrate-phase3.sql` exactly (idempotent `CREATE TABLE IF NOT EXISTS` + guarded demo seed, plain `.sql`, wired BLOCKING into `deploy.sh` after the phase-3 step). The `proconnect_feed` DB + `feed_svc` grant already exist from Phase 1.

**Primary recommendation:** Build feed-service as a `PostController` (posts + reactions + the timeline query) + `CommentController` cloned from the git-history post/comment services and the connection-service PDO doctrine, over three tables (`posts`, `reactions`, `comments`) in `proconnect_feed`. Reactions = `INSERT ... ON DUPLICATE KEY UPDATE` on `UNIQUE(post_id,user_id)`; repost = a `posts` row with `repost_of` set. The timeline endpoint returns posts with counts + my_reaction computed in ONE query (subqueries or LEFT JOIN GROUP BY). Build the gateway `FeedController` cloning `ConnectionsController` (invariant pre-checks via `FeedClient::getPost` 404, enrichment via `ProfileClient::batch` with email allowlist, settle + degrade). Resolve repost originals by batching `repost_of` ids through feed-service in the same composition (no N+1). Migrate the live DB with `db/04-migrate-phase4.sql` (clone `03`) wired into `deploy.sh` after the phase-3 step. Smoke via `scripts/smoke-phase4.sh` cloned from `smoke-phase3.sh`.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**A. Data model (feed-service, `proconnect_feed`, raw-PDO, logical FK)**
- **D-01:** `posts(id, author_id BIGINT, content TEXT, image_url VARCHAR(512) NULL, repost_of BIGINT UNSIGNED NULL, created_at, INDEX(author_id,created_at), INDEX(created_at))`.
- **D-02:** `reactions(id, post_id, user_id, type ENUM('like','love','haha','wow','sad','angry') NOT NULL DEFAULT 'like', created_at, UNIQUE(post_id,user_id))` — ONE reaction/person/post; change = upsert (`INSERT ... ON DUPLICATE KEY UPDATE type`), remove = DELETE.
- **D-03:** `comments(id, post_id, author_id BIGINT, body TEXT, created_at, INDEX(post_id,created_at))`.
- **D-04:** Repost = `posts.repost_of` points to the original post id (NULL if normal). Repost shows the original (author + content). Repost-of-repost → points straight to the original (no nesting — keep simple). Image = URL string (no upload).

**B. Endpoints (gateway, owner-scoped via X-User-Id)**
- **D-05:** `POST /api/posts {content, image_url?}`; `POST /api/posts/{id}/repost`; `DELETE /api/posts/{id}` (owner); `POST /api/posts/{id}/reactions {type}` (upsert) + `DELETE /api/posts/{id}/reactions` (remove own); `GET /api/posts/{id}/comments` + `POST /api/posts/{id}/comments {body}` + `DELETE /api/comments/{id}` (owner); `GET /api/posts/{id}` (1 post, composed); `GET /api/feed` (timeline composition).
- Light invariant (reuse Phase 3 pattern): comment/react/repost to nonexistent post → 404 (gateway or feed-service checks).

**C. The Feed Composition — showcase centerpiece (FEED-06, D-05)**
- **D-06:** `GET /api/feed` = gateway composition, parallel fan-out (Utils::settle) + degrade:
  1. connection-service `listAccepted(viewer)` → author ids; author set = [viewer] + connections.
  2. feed-service `GET /posts?authors=<ids>&viewer=<id>&limit=N` → newest posts by those authors, EACH with `reaction_count`, `comment_count`, `my_reaction` (feed-service COUNTs in its own DB — cheap), + `repost_of`.
  3. gateway batch-enrich authors (profile-service `?ids=` → id/username/display_name/avatar_url, NO email) + resolve repost originals (original author + content).
  4. settle + `meta.degraded` if a part fails; sort newest-first.
  - This is the read-heavy API Composition joining ≥3 services (connection + feed + profile). Presentation story: "1 request → gateway asks in parallel connection (who are my friends) + feed (posts + counts) + profile (authors) → merges 1 timeline → degrades if a part fails."

**D. Service / infra**
- **D-07:** feed-service trusts X-User-Id (NO JWT, no host port), raw-PDO, Json/DomainError Vietnamese. Writes scoped by X-User-Id; owner-only delete (post/comment); reaction/comment scoped to caller. Clone profile/connection service doctrine (uniform 404, scoped SELECT existence, 23000 dedupe for reactions).
- **D-08:** Idempotent NON-destructive `db/04-migrate-phase4.sql` (`CREATE TABLE IF NOT EXISTS` posts/reactions/comments + guarded demo seed: a few posts by demo accounts, a few reactions/comments, 1 repost) wired BLOCKING into `deploy.sh` after the phase-3 migrate; sync fresh-volume schema. `proconnect_feed` + `feed_svc` already exist (Phase 1).
- **D-09:** content length cap (e.g. ≤5000), image_url ≤512; validation in Vietnamese.

**E. UI**
- **D-10:** `web/feed.html` — compose box (text + image URL) + timeline (post with author/image/reaction count/comment count/my reaction/repost source) + react button (pick emotion), comment (expand), repost, delete (own posts). Nav link from index. Alpine+Tailwind CDN, Vietnamese, minimal (NO branding/3-column — Phase 6). x-text (no x-html).

### Claude's Discretion
- The specific reaction emotion set; timeline limit/pagination; comment display depth; feed-service internal route shapes; counting method (inline COUNT vs subquery).

### Deferred Ideas (OUT OF SCOPE)
- Search + notifications (Phase 5); branding/3-column (Phase 6); binary image upload; nested repost chains; feed pagination beyond a simple limit; notification on reaction/comment (Phase 5).
</user_constraints>

## Project Constraints (from CLAUDE.md)

These have the same authority as locked decisions. The planner must not propose anything contradicting them.

- **Stack locked:** PHP 8.2 + Slim 4 + Guzzle + firebase/php-jwt v7 + MariaDB 10.11 + Docker Compose. Frontend HTML + Alpine.js + Tailwind CDN, **no heavy build step**. Do NOT add new heavy dependencies. [CITED: CLAUDE.md Constraints]
- **2GB RAM shared VPS, ≤ ~9-10 containers.** Phase 4 adds NO new container — feed-service already exists in `docker-compose.yml` (built, deployed, health-only). [VERIFIED: docker-compose.yml lines 56-62 feed-service + gateway FEED_SERVICE_URL line 101 + depends_on line 111]
- **All UI + content + error messages in Vietnamese with diacritics.** [CITED: CLAUDE.md]
- **API Gateway pattern must not be blurred** — core grading criterion. `GET /api/feed` IS the showcase; keep it legible. [CITED: CLAUDE.md Core Value + PROJECT.md "feed read-heavy composition is the richest Gateway demo"]
- **Keep SIMPLE** — the team must understand and present the code. Favour existing patterns over cleverness. [CITED: CONTEXT.md + CLAUDE.md]
- **MANDATORY review workflow (CLAUDE.md):** after plan approval, run `/codex-plan-review` and start coding only after Codex APPROVES the plan. Before any commit, run `/codex-impl-review` and commit only after Codex APPROVES. Fix valid issues and re-review. The planner MUST bake these two gates into the phase plan as explicit steps. [CITED: ~/.claude/CLAUDE.md + project CLAUDE.md]
- **Deploy flow:** local git → review → push public GitHub `main` → GitHub Actions → VPS `git pull --ff-only` → `scripts/deploy.sh` → smoke `/api/health`. Docker NOT available locally — runtime verification is on the VPS. [CITED: CLAUDE.md + MEMORY]

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| FEED-01 | Đăng bài (text + optional image URL) | feed-service `PostController::create` cloned from git-history post-service (drop title/slug; add `image_url`). Gateway `POST /api/posts` → `FeedClient::createPost($me, body)` with X-User-Id (mirror git-history `PostsController::create`). Validation D-09 (content ≤5000, image_url ≤512). |
| FEED-02 | Timeline: own + connections' posts, newest-first | THE composition (D-06). `FeedController::feed`: `ConnectionClient::listAccepted($me)` → author ids ∪ [me] → `FeedClient::timeline(authorIds, $me, limit)` → enrich authors + resolve reposts → settle + degrade + newest-first. See Pattern 1. |
| FEED-03 | Reactions (set / change / remove, one per person per post) | feed-service `reactions` table `UNIQUE(post_id,user_id)`; `react()` = `INSERT ... ON DUPLICATE KEY UPDATE type` (upsert, D-02); `unreact()` = scoped DELETE. Gateway `POST/DELETE /api/posts/{id}/reactions`. 404 invariant if post missing. See Pattern 4. |
| FEED-04 | Comments (add / list / delete own) | feed-service `CommentController` cloned from git-history comment-service (comments table D-03). Gateway `GET/POST /api/posts/{id}/comments`, `DELETE /api/comments/{id}` (owner). 404 invariant on POST to missing post (mirror git-history `createComment`). See Pattern 5. |
| FEED-05 | Repost (share, shows the original) | Repost = a `posts` row with `repost_of` set (D-04). Gateway `POST /api/posts/{id}/repost` verifies original exists (404 invariant) → `FeedClient::repost($me, $originalId)` → feed-service inserts a post with `repost_of` (collapse repost-of-repost to the root original). Gateway resolves originals in the feed composition by batching `repost_of` ids. See Pattern 6. |
| FEED-06 | Gateway feed composition (post + author + reaction_count + comment_count + viewer's reaction), parallel multi-service, degrade | THE centerpiece (D-06). `Utils::settle` parallel fan-out across connection + feed + profile; `meta.degraded` on partial failure; counts/my_reaction computed in feed-service's own DB (no gateway N+1). See Pattern 1. |

## Standard Stack

No new dependencies. feed-service's `composer.json` is already dependency-minimal and identical to the other services (`slim/slim ^4.12`, `slim/psr7 ^1.6`, `monolog/monolog ^3.0`, `ext-mbstring`, `ext-pdo` — NO ORM, NO Guzzle, NO JWT lib). [VERIFIED: feed-service has committed `composer.json` + `composer.lock`; stub mirrors connection-service exactly]

### Core (verified from committed lockfiles + STACK.md — NOT training data)
| Library | Version (locked) | Purpose | Why Standard |
|---------|------------------|---------|--------------|
| `slim/slim` | 4.15.1 | HTTP framework, gateway + feed-service | [VERIFIED: STACK.md] Already the framework. |
| `slim/psr7` | 1.8.0 | PSR-7 messages | [VERIFIED: STACK.md] |
| `php-di/php-di` | 7.1.1 | DI container, **gateway only** | [VERIFIED: gateway/public/index.php uses `DI\Container`; feed-service `public/index.php` wires MANUALLY — no container, AppFactory + addBodyParsingMiddleware only] |
| `guzzlehttp/guzzle` | 7.10.0 | Gateway → service HTTP | [VERIFIED: gateway/src/Services/HttpClient.php] |
| `guzzlehttp/promises` | 2.3.0 | `Utils::settle` parallel composition | [VERIFIED: AggregateController.php uses `use GuzzleHttp\Promise\Utils;`] |
| `firebase/php-jwt` | v7.0.5 | JWT HS256, **gateway only** | [VERIFIED: STACK.md; feed-service has NO JWT dep — D-07 trusts X-User-Id] |
| `ext-pdo` / `ext-pdo_mysql` | bundled (php:8.2-fpm-alpine) | Raw PDO in feed-service | [VERIFIED: services/feed-service/src/Db.php is a real PDO singleton, EMULATE_PREPARES=false, points at proconnect_feed] |

### Frontend (CDN, no build)
| Asset | Source | Purpose |
|-------|--------|---------|
| Tailwind | `https://cdn.tailwindcss.com` (Play CDN) | Styling | [VERIFIED: web/connections.html line 7] |
| Alpine.js 3.x | `https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js` | Reactivity | [VERIFIED: web/connections.html line 10] |
| `web/assets/app.js` | local classic script | `window.api` (get/post/patch/delete, Bearer auto-attach, returns `{data, meta}`), `window.auth`, `window.navbar`, `window.formatDate`, `window.loadFull` | [VERIFIED: read in full — `api.post(p, body)` JSON-encodes; 204 returns `{data:null}`; non-2xx throws `err` with `.status`/`.code`] |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| New gateway `FeedController` | Add methods to an existing controller | RECOMMENDED: a dedicated `FeedController` (mirrors how the brownfield kept posts/comments in `PostsController` and composition in `AggregateController`, and how Phase 3 used `ConnectionsController`). Keeps the showcase composition legible. [VERIFIED: existing controllers are domain-scoped] |
| Counts via correlated subqueries in the timeline SELECT | LEFT JOIN + GROUP BY | EITHER works (Claude's discretion D, "inline COUNT vs subquery"). RECOMMENDED: **correlated subqueries** for `reaction_count`/`comment_count` + a scalar subquery (or LEFT JOIN on a single row) for `my_reaction`. Subqueries keep the query readable for presentation and avoid GROUP BY interaction bugs when joining two child tables (a naive double LEFT JOIN multiplies counts — a real footgun). See Pattern 3 + Pitfall 4. [VERIFIED: post/comment services already do `SELECT COUNT(*)` patterns] |
| Resolving repost originals via a 2nd feed-service round-trip | Include originals in the SAME timeline query universe | RECOMMENDED: collect all `repost_of` ids from the timeline rows, then ONE batch `FeedClient::getPosts(ids)` (a `?ids=` batch on feed-service, mirroring post-service's `?ids=` cap-100 batch) to fetch originals + their authors fold into the SAME `ProfileClient::batch` id set. One extra batch call, NOT N+1. See Pattern 6. [VERIFIED: git-history post-service `GET /posts?ids=` batch exists] |
| `Utils::settle` for the feed fan-out | synchronous sequential calls | The author-universe step (connection) MUST resolve before the feed query (you need the ids), so it is sequential by nature; but feed + the subsequent profile-batch + repost-originals-batch can be expressed via `settle` for the genuine parallel-fan-out slide. RECOMMENDED: connection (sync, or its own settle) → THEN `settle([feed, ...])`. Honest about ordering; see Pattern 1 note. [VERIFIED: AggregateController settle pattern] |

**Installation:** none. `composer install` already run; lockfiles committed; Dockerfiles copy `composer.json composer.lock`. feed-service already has a committed `composer.lock`. [VERIFIED: feed-service tree includes composer.lock + Dockerfile]

**Version verification:** Versions taken from `.planning/codebase/STACK.md` (which read the committed lockfiles). The stack is pinned and reproducible by design — do NOT bump versions in Phase 4. No registry lookup needed.

## Architecture Patterns

### Project structure touched in Phase 4
```
db/
├── 01-schema-feed.sql              # NEW (fresh-volume): CREATE TABLE posts/reactions/comments
│                                   #   (structure-only, mirrors 01-schema-connection.sql; DDL VERBATIM == migration)
├── 04-migrate-phase4.sql           # NEW: idempotent live-volume migration + demo seed (clone db/03-migrate-phase3.sql)
└── 99-seed.sql                     # OPTIONAL: mirror the demo seed for fresh volumes (INSERT IGNORE) — like phase 3

services/feed-service/src/
├── routes.php                      # ADD the internal route set (currently /health only)
├── Controllers/PostController.php  # NEW: posts CRUD + reactions + the timeline query (clone git-history post-service PostController, drop title/slug)
├── Controllers/CommentController.php # NEW: comments CRUD (clone git-history comment-service CommentController)
└── (Db.php / Json.php / DomainError.php / JsonErrorHandler.php / HealthController.php already present — reuse as-is)

gateway/src/
├── routes.php                      # ADD /api/posts* + /api/feed + /api/comments/{id} (mutations JWT; reads public or JWT-aware)
├── public/index.php                # ADD DI: FeedController(FeedClient, ProfileClient, ConnectionClient)
├── Controllers/FeedController.php  # NEW: the /api/feed composition + posts/reactions/comments/repost passthrough + invariants
└── Services/FeedClient.php         # EXTEND: createPost/deletePost/repost/react/unreact/listComments/addComment/deleteComment/getPost/getPosts(batch)/timeline + *Async where composed

web/
├── feed.html                       # NEW (D-10): compose + timeline + react/comment/repost/delete
├── index.html                      # EXTEND: nav link to feed
└── assets/app.js                   # OPTIONAL: small feed helpers (api/auth already suffice)

scripts/
├── deploy.sh                       # EXTEND: apply db/04-migrate-phase4.sql right AFTER the phase-3 migrate step (currently lines 142-144)
└── smoke-phase4.sh                 # NEW: post→own feed; react set/change/remove+dedupe; comment add/list/delete; repost shows origin; /api/feed composition (author+counts+my_reaction, degrade, newest-first, NO email); connection's post in timeline; owner-only delete; 404 invariants
```

### Pattern 1: The feed composition (THE centerpiece — D-06/FEED-02/FEED-06)
**What:** ONE `GET /api/feed` request resolves the author universe (connection ∪ self), fetches their posts WITH counts from feed-service, enriches authors + repost originals from profile-service, degrades safely, sorts newest-first.
**Source:** `gateway/src/Controllers/AggregateController.php` (settle + degrade + decode), `gateway/src/Controllers/ConnectionsController.php` (`enrich()` + email allowlist + `me()`), `gateway/src/Services/ConnectionClient::listAccepted()` (returns `[{user_id, status}]`).

```php
// gateway/src/Controllers/FeedController.php — NEW. Composes connection + feed + profile.
public function feed(Request $req, Response $res): Response
{
    $me  = $this->me($req);                          // JWT-set attribute (mirror ConnectionsController::me)
    $lim = min(50, max(1, (int)($req->getQueryParams()['limit'] ?? 20)));

    $degraded = [];

    // STEP 1 (author universe) — connection-service listAccepted($me). Degrade-safe:
    // if connection-service is down, show OWN posts only (D-06 degrade story).
    $authorIds = [$me];
    try {
        $cr = $this->connections->listAccepted($me);
        if ($cr->getStatusCode() === 200) {
            foreach ((array)($this->decode($cr)['data'] ?? []) as $row) {
                $uid = (int)($row['user_id'] ?? 0);
                if ($uid > 0) { $authorIds[] = $uid; }
            }
        } else {
            $degraded[] = 'connections';             // own posts only
        }
    } catch (GuzzleException $e) {
        $degraded[] = 'connections';
    }
    $authorIds = array_values(array_unique($authorIds));

    // STEP 2 (the posts + counts) — feed-service computes reaction_count/comment_count/my_reaction
    // in its OWN DB in ONE query (no gateway N+1). This is the hard dependency (the spine).
    $fr = $this->feed->timeline($authorIds, $me, $lim);
    if ($fr->getStatusCode() !== 200) {
        return Json::raw($res, $this->decode($fr), $fr->getStatusCode());
    }
    $posts = (array)($this->decode($fr)['data'] ?? []);

    // STEP 3 (repost originals) — collect repost_of ids → ONE batch feed-service call (NO N+1).
    $originalIds = array_values(array_unique(array_filter(array_map(
        static fn(array $p) => (int)($p['repost_of'] ?? 0), $posts
    ), static fn(int $i) => $i > 0)));
    $originals = [];
    if ($originalIds !== []) {
        $or = $this->feed->getPosts($originalIds);   // GET feed-service /posts?ids=
        if ($or->getStatusCode() === 200) {
            foreach ((array)($this->decode($or)['data'] ?? []) as $o) {
                $originals[(int)$o['id']] = $o;
            }
        } else { $degraded[] = 'reposts'; }
    }

    // STEP 4 (author enrichment) — batch BOTH post authors AND original-post authors in ONE ?ids= call.
    $authorIdSet = [];
    foreach ($posts as $p)      { $authorIdSet[(int)$p['author_id']] = true; }
    foreach ($originals as $o)  { $authorIdSet[(int)$o['author_id']] = true; }
    $profiles = $this->fetchProfiles(array_keys($authorIdSet), $degraded);  // email-allowlisted (Pitfall 2)

    // STEP 5 (assemble) — attach author, original (with its author), counts already present.
    $out = array_map(function (array $p) use ($profiles, $originals): array {
        $p['author'] = $profiles[(int)$p['author_id']] ?? null;
        $rid = (int)($p['repost_of'] ?? 0);
        if ($rid > 0 && isset($originals[$rid])) {
            $orig = $originals[$rid];
            $orig['author'] = $profiles[(int)$orig['author_id']] ?? null;
            $p['original'] = $orig;
        } else {
            $p['original'] = null;
        }
        return $p;
    }, $posts);

    // feed-service already ORDER BY created_at DESC; gateway trusts that order (newest-first).
    $meta = $degraded !== [] ? ['degraded' => true, 'parts' => array_values(array_unique($degraded))] : [];
    return Json::list($res, $out, $meta);
}
```
**Parallelism honesty (for the slides):** Step 1 (author universe) MUST resolve before Step 2 (you need the ids to query). Steps 2/3/4 are sequential dependencies too (you need posts before you know which originals/authors to fetch). The genuine "parallel fan-out" claim is weaker than `/full` — but the COMPOSITION-across-3-services + degrade story is intact and richer. If the team wants a visible `Utils::settle`, the honest place is to settle the feed call with a concurrent connection-service health/secondary call, OR settle the original-posts batch with the author batch (both depend only on Step 2's output). RECOMMENDED for the slide: after Step 2, run Step 3 (repost originals) and a first author-batch (post authors only) inside ONE `Utils::settle`, then a small follow-up batch for any original-authors not already fetched. Flag the exact shape at `/codex-plan-review`. [VERIFIED: AggregateController settle pattern; ConnectionClient::listAccepted shape read in source]
**CRITICAL — `listAccepted` shape:** `connection-service ConnectionController::listAccepted` returns `Json::list` of `[{user_id:int, status:'accepted'}]` (the OTHER party's id). The gateway `ConnectionClient::listAccepted($user)` calls `GET /connections?user=` and returns the raw ResponseInterface; `$this->decode($cr)['data']` is the array of rows. Extract `user_id`. [VERIFIED: ConnectionController::listAccepted SELECT `IF(requester_id=:u1, addressee_id, requester_id) AS user_id` + ConnectionClient::listAccepted]

### Pattern 2: feed-service internal route set (build out the stub)
**What:** feed-service mirrors the "dumb service" shape — trusts `X-User-Id`, raw-PDO, Vietnamese envelope. Currently `routes.php` is `/health` only.
**Source:** `services/feed-service/src/routes.php` (stub), `services/connection-service/src/routes.php` (the shape to mirror — literal paths before bare, numeric constraints), git-history post/comment service routes.

Recommended internal routes (Claude's discretion on exact shapes — D + the timeline contract is the only hard one):
```php
// services/feed-service/src/routes.php — extend (clone connection-service ordering discipline)
$app->get   ('/health',                      [HealthController::class, 'health']);          // keep

// D-06 timeline — the EXACT contract the gateway composition calls. PRIORITY.
$app->get   ('/posts',                       [PostController::class, 'index']);             // ?authors=&viewer=&limit=  OR  ?ids= (batch for repost originals)
$app->post  ('/posts',                       [PostController::class, 'create']);            // {content,image_url?} — author = X-User-Id
$app->post  ('/posts/{id:[0-9]+}/repost',    [PostController::class, 'repost']);            // X-User-Id; body/none — inserts a repost_of row
$app->get   ('/posts/{id:[0-9]+}',           [PostController::class, 'show']);              // single post + counts (?viewer=)
$app->delete('/posts/{id:[0-9]+}',           [PostController::class, 'delete']);            // owner only (X-User-Id == author_id)

$app->put   ('/posts/{id:[0-9]+}/reactions', [PostController::class, 'react']);             // upsert {type}; OR POST — pick one, gateway maps
$app->delete('/posts/{id:[0-9]+}/reactions', [PostController::class, 'unreact']);           // remove caller's reaction

$app->get   ('/posts/{id:[0-9]+}/comments',  [CommentController::class, 'index']);          // list (public-ish)
$app->post  ('/posts/{id:[0-9]+}/comments',  [CommentController::class, 'create']);         // {body} — author = X-User-Id
$app->delete('/comments/{id:[0-9]+}',        [CommentController::class, 'delete']);         // owner only
```
**Bootstrap:** `services/feed-service/public/index.php` is identical to connection-service's (manual wiring, no DI, `App\JsonErrorHandler` installed via `setDefaultErrorHandler`). No bootstrap change needed beyond the new controller classes (PSR-4 `App\` autoloaded). [VERIFIED: feed-service public/index.php read in full — AppFactory + addBodyParsingMiddleware + JsonErrorHandler, no container]
**ROUTE-ORDERING:** register literal `/posts` before parameterized; keep `{id:[0-9]+}` numeric constraints so `/posts/{id}/comments` vs `/comments/{id}` never collide. Mirror connection-service's documented ordering discipline. [VERIFIED: connection-service routes.php ordering comments]

### Pattern 3: The ONE efficient timeline query (no N+1 inside feed-service — D-06)
**What:** `GET /posts?authors=&viewer=&limit=` returns each post with `reaction_count`, `comment_count`, `my_reaction` computed in ONE query. The whole point of D-06 is that feed-service counts cheaply in its own DB so the gateway never fans out per-post.
**Source:** git-history `post-service PostController::index` (the `?ids=`/`?author_id=` + `LIMIT :lim` `bindValue PARAM_INT` pattern); git-history `comment-service CommentController::count` (`SELECT COUNT(*)`).

```php
// feed-service PostController::index — timeline mode (?authors=&viewer=&limit=)
public function index(Request $req, Response $res): Response
{
    $q = $req->getQueryParams();

    // Batch mode (?ids=) — for the gateway's repost-original resolution (mirror post-service cap 100).
    if (($q['ids'] ?? '') !== '') {
        return $this->batchByIds($req, $res);   // SELECT ... WHERE id IN (...) WITH the same count columns
    }

    // Timeline mode (?authors=&viewer=&limit=).
    $authors = array_values(array_unique(array_filter(array_map(
        static fn($s) => (int) trim($s), explode(',', (string)($q['authors'] ?? ''))
    ), static fn($i) => $i > 0)));
    if ($authors === []) { return Json::list($res, [], ['total' => 0]); }
    if (count($authors) > 100) { throw new DomainError(400, 'TOO_MANY_IDS', 'Tối đa 100 tác giả mỗi lượt.'); }

    $viewer = (int) ($q['viewer'] ?? 0);
    $limit  = min(50, max(1, (int) ($q['limit'] ?? 20)));

    $pdo = Db::pdo();
    $place = implode(',', array_fill(0, count($authors), '?'));
    // Correlated subqueries keep counts correct (a double LEFT JOIN on reactions+comments
    // would multiply rows — Pitfall 4). my_reaction via a scalar subquery scoped to :viewer.
    $sql =
        "SELECT p.id, p.author_id, p.content, p.image_url, p.repost_of, p.created_at,
                (SELECT COUNT(*) FROM reactions r WHERE r.post_id = p.id)                       AS reaction_count,
                (SELECT COUNT(*) FROM comments  c WHERE c.post_id = p.id)                       AS comment_count,
                (SELECT r2.type  FROM reactions r2 WHERE r2.post_id = p.id AND r2.user_id = ?)  AS my_reaction
           FROM posts p
          WHERE p.author_id IN ($place)
          ORDER BY p.created_at DESC, p.id DESC
          LIMIT ?";
    $stmt = $pdo->prepare($sql);
    // Native prepared statements: positional binding. Order: viewer (scalar subquery), authors..., limit.
    $i = 1;
    $stmt->bindValue($i++, $viewer, PDO::PARAM_INT);
    foreach ($authors as $a) { $stmt->bindValue($i++, $a, PDO::PARAM_INT); }
    $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = array_map(static function (array $r): array {
        $r['reaction_count'] = (int) $r['reaction_count'];
        $r['comment_count']  = (int) $r['comment_count'];
        $r['repost_of']      = $r['repost_of'] !== null ? (int) $r['repost_of'] : null;
        // my_reaction stays string|null (the viewer's reaction type, or null if none)
        return $r;
    }, $stmt->fetchAll());

    return Json::list($res, $rows, ['total' => count($rows)]);
}
```
**CRITICAL — placeholder ordering with EMULATE_PREPARES=false:** native prepared statements bind positionally here (the `my_reaction` subquery `?` comes FIRST in the SELECT list, so bind `:viewer` first, then the `IN (...)` authors, then `LIMIT`). Mixing named + positional in one statement is NOT allowed; use all-positional for the dynamic `IN` list (this is exactly how git-history post-service did its `?ids=` batch). [VERIFIED: post-service `?ids=` uses `array_fill(0, count($ids), '?')` positional placeholders; Db.php sets EMULATE_PREPARES=false]
**`my_reaction` for anonymous/no-viewer:** when `viewer=0`, the scalar subquery `WHERE user_id = 0` simply returns NULL (no user 0) — correct. The single-post `show()` for `GET /api/posts/{id}` reuses the SAME count columns with `?viewer=`. [VERIFIED: pattern is deterministic]

### Pattern 4: Reactions upsert + remove (D-02/D-03/FEED-03)
**What:** ONE reaction per (post,user). Set or change = `INSERT ... ON DUPLICATE KEY UPDATE type`. Remove = scoped DELETE. `UNIQUE(post_id,user_id)` is the upsert key AND the dedupe backstop.
**Source:** connection-service `ConnectionController::create` (the 23000 catch pattern); MariaDB `ON DUPLICATE KEY UPDATE` is standard.

```php
// feed-service PostController::react — PUT /posts/{id}/reactions {type}. Upsert (D-02).
public function react(Request $req, Response $res, array $args): Response
{
    $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
    $postId = (int) $args['id'];
    if ($caller <= 0) { throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu thông tin người dùng (X-User-Id).'); }

    $type = trim((string) ((array)($req->getParsedBody() ?? []))['type'] ?? 'like');
    $allowed = ['like','love','haha','wow','sad','angry'];        // D-02 enum (Claude's discretion on the set)
    if (!in_array($type, $allowed, true)) {
        throw new DomainError(400, 'VALIDATION_FAILED', 'Loại cảm xúc không hợp lệ.');
    }

    // Existence invariant (light): react to a nonexistent post → 404. Scoped SELECT.
    if ($this->findPost($postId) === null) {
        throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
    }

    // Upsert: one reaction per (post,user); changing the type updates in place.
    $stmt = Db::pdo()->prepare(
        'INSERT INTO reactions (post_id, user_id, type) VALUES (:p, :u, :t)
         ON DUPLICATE KEY UPDATE type = VALUES(type)'
    );
    $stmt->execute([':p' => $postId, ':u' => $caller, ':t' => $type]);
    return Json::ok($res, ['post_id' => $postId, 'type' => $type]);
}

// DELETE /posts/{id}/reactions — remove caller's own reaction (scoped).
public function unreact(Request $req, Response $res, array $args): Response
{
    $caller = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
    $postId = (int) $args['id'];
    $stmt = Db::pdo()->prepare('DELETE FROM reactions WHERE post_id = :p AND user_id = :u');
    $stmt->execute([':p' => $postId, ':u' => $caller]);
    // Idempotent remove: rowCount 0 is fine (nothing to remove) — return 200/204 either way.
    return Json::ok($res, ['post_id' => $postId, 'removed' => $stmt->rowCount() > 0]);
}
```
**Why `ON DUPLICATE KEY UPDATE` not a SELECT-then-INSERT:** the `UNIQUE(post_id,user_id)` makes the upsert atomic — no race, no 23000 to catch for the normal change-reaction path. The 23000 catch is only relevant if you chose plain INSERT (don't — use the upsert). [VERIFIED: D-02 specifies upsert; UNIQUE key supports ON DUPLICATE KEY UPDATE — standard MariaDB]
**Reaction breakdown (optional, Claude's discretion):** if the UI wants per-type counts ("3 like, 1 love"), add a `GET /posts/{id}/reactions` returning `SELECT type, COUNT(*) GROUP BY type`. NOT required by D-10 (which only needs a total `reaction_count` + `my_reaction`). Keep minimal unless the team wants it. [VERIFIED: D-10 lists "số reaction" (count) + "reaction của tôi" only]

### Pattern 5: Comments (clone git-history comment-service — D-03/FEED-04)
**What:** comments table `(id, post_id, author_id, body, created_at)`; list by post, create (author = X-User-Id, post-exists invariant), delete (owner only).
**Source:** git-history `comment-service CommentController` (read verbatim this session) — `index` (`?post_id=` + pagination), `create` (X-User-Id + body validation ≤5000), `delete` (owner check → 403, find → 404).

The git-history `CommentController` is a near drop-in. Changes for feed-service: comments live in the SAME `proconnect_feed` DB as posts (database-per-service: feed owns posts+reactions+comments), so the post-existence invariant can be a LOCAL scoped SELECT inside feed-service (`SELECT 1 FROM posts WHERE id=:p`) — cheaper and stronger than the gateway round-trip the brownfield used (post and comment were separate services there). RECOMMENDED: enforce the 404 invariant INSIDE feed-service `create` (local SELECT) AND optionally at the gateway — defense in depth, and the local check is essentially free. [VERIFIED: git-history comment-service create + connection-service local-existence doctrine; feed owns both tables so no cross-service hop needed]

```php
// feed-service CommentController::create — body validation + LOCAL post-existence invariant.
$postId = (int) $args['id'];
if ($this->postExists($postId) === false) {            // SELECT 1 FROM posts WHERE id=:p LIMIT 1
    throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
}
$body = trim((string) ((array)($req->getParsedBody() ?? []))['body'] ?? '');
if ($body === '' || mb_strlen($body) > 5000) {
    throw new DomainError(400, 'VALIDATION_FAILED', 'Nội dung bình luận phải từ 1-5000 ký tự.');
}
// INSERT (author_id = X-User-Id), return the row (clone git-history create).
```
**Comment author enrichment (D-10):** the comment list shows author name/avatar. Gateway `GET /api/posts/{id}/comments` → `FeedClient::listComments($id)` → batch-enrich comment authors via `ProfileClient::batch` (email allowlist), mirroring git-history `PostsController::comments` + `fetchAuthors`. [VERIFIED: git-history PostsController::comments enrich pattern]

### Pattern 6: Repost (a posts row with repost_of — D-04/FEED-05)
**What:** repost = create a new `posts` row whose `repost_of` points to the original. The gateway verifies the original exists (404 invariant) before the write; in the feed composition, originals are batch-resolved (no N+1).
**Source:** git-history `PostsController::createComment` (the existence-check-before-write invariant) + post-service `create`.

```php
// feed-service PostController::repost — POST /posts/{id}/repost. Collapse repost-of-repost to root.
public function repost(Request $req, Response $res, array $args): Response
{
    $caller   = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
    $targetId = (int) $args['id'];
    if ($caller <= 0) { throw new DomainError(401, 'UNAUTHORIZED', 'Thiếu X-User-Id.'); }

    $target = $this->findPost($targetId);
    if ($target === null) { throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.'); }

    // D-04: repost-of-repost points straight to the ORIGINAL (no nesting).
    $rootId = $target['repost_of'] !== null ? (int) $target['repost_of'] : $targetId;

    $stmt = Db::pdo()->prepare(
        'INSERT INTO posts (author_id, content, image_url, repost_of) VALUES (:a, :c, NULL, :r)'
    );
    // A repost carries no new content of its own (D-04) — content empty/null; original shown via repost_of.
    $stmt->execute([':a' => $caller, ':c' => '', ':r' => $rootId]);
    return Json::ok($res, $this->findPost((int) Db::pdo()->lastInsertId()), 201);
}
```
**Gateway `POST /api/posts/{id}/repost`:** verify the original exists (the feed-service `repost()` already 404s, so the gateway can be a thin passthrough mapping JWT→X-User-Id, mirroring `ConnectionsController::accept` passthrough). The composition resolves the original's content+author via the Step-3 batch (Pattern 1). [VERIFIED: feed-service owns the post table → local existence check sufficient; gateway passthrough mirrors ConnectionsController]
**Note — `content NOT NULL` vs empty:** D-01 has `content TEXT` (no NULL stated). A repost has no own content. RECOMMENDED: store `''` (empty string) for a repost's content and let the UI render the original. If the planner prefers `content` nullable for reposts, that is a one-word DDL change — flag at plan review. [VERIFIED: D-01 `content TEXT`; D-04 repost shows the original]

### Pattern 7: Extending FeedClient + registering FeedController + DI
**Source:** `gateway/src/Services/FeedClient.php` (currently health-only — read in full), `gateway/src/Services/ConnectionClient.php` (the X-User-Id-injection-on-mutation pattern), `gateway/public/index.php` (DI), `gateway/src/routes.php` (group `/api`, per-route `->add($jwtMw)`).

```php
// gateway/src/Services/FeedClient.php — additions (mirror ConnectionClient mutation header injection).
public function timeline(array $authorIds, int $viewer, int $limit): ResponseInterface {
    return $this->http->request('GET', '/posts', ['query' => [
        'authors' => implode(',', $authorIds), 'viewer' => $viewer, 'limit' => $limit,
    ]]);
}
public function getPost(int $id, int $viewer = 0): ResponseInterface {
    return $this->http->request('GET', '/posts/' . $id, ['query' => ['viewer' => $viewer]]);
}
public function getPosts(array $ids): ResponseInterface {                   // batch repost originals (?ids=)
    return $this->http->request('GET', '/posts', ['query' => ['ids' => implode(',', $ids)]]);
}
public function createPost(int $author, array $body): ResponseInterface {
    return $this->http->request('POST', '/posts', [
        'json' => $body, 'headers' => ['Content-Type'=>'application/json', 'X-User-Id'=>(string)$author],
    ]);
}
public function deletePost(int $caller, int $id): ResponseInterface { /* DELETE /posts/{id} + X-User-Id */ }
public function repost(int $caller, int $id): ResponseInterface { /* POST /posts/{id}/repost + X-User-Id */ }
public function react(int $caller, int $id, string $type): ResponseInterface { /* PUT /posts/{id}/reactions {type} + X-User-Id */ }
public function unreact(int $caller, int $id): ResponseInterface { /* DELETE /posts/{id}/reactions + X-User-Id */ }
public function listComments(int $id): ResponseInterface { /* GET /posts/{id}/comments */ }
public function addComment(int $caller, int $id, string $body): ResponseInterface { /* POST /posts/{id}/comments {body} + X-User-Id */ }
public function deleteComment(int $caller, int $id): ResponseInterface { /* DELETE /comments/{id} + X-User-Id */ }
```
```php
// gateway/public/index.php — FeedClient already registered (line: $container->set(FeedClient::class, fn() => new FeedClient());)
// ADD FeedController DI (mirror ConnectionsController factory):
$container->set(FeedController::class, fn(Container $c) => new FeedController(
    $c->get(FeedClient::class),
    $c->get(ProfileClient::class),
    $c->get(ConnectionClient::class),
));
```
```php
// gateway/src/routes.php — inside $app->group('/api', ...). Mutations require JWT.
// Reads (/feed, /posts/{id}, comments-list) — JWT REQUIRED for /feed (me-relative) + my_reaction;
// /posts/{id} and comment list MAY be public or optional-auth (Claude's discretion; /feed must be JWT).
$g->get   ('/feed',                              [FeedController::class, 'feed'])->add($jwtMw);
$g->post  ('/posts',                             [FeedController::class, 'createPost'])->add($jwtMw);
$g->post  ('/posts/{id:[0-9]+}/repost',          [FeedController::class, 'repost'])->add($jwtMw);
$g->delete('/posts/{id:[0-9]+}',                 [FeedController::class, 'deletePost'])->add($jwtMw);
$g->post  ('/posts/{id:[0-9]+}/reactions',       [FeedController::class, 'react'])->add($jwtMw);
$g->delete('/posts/{id:[0-9]+}/reactions',       [FeedController::class, 'unreact'])->add($jwtMw);
$g->get   ('/posts/{id:[0-9]+}/comments',        [FeedController::class, 'listComments']);            // public-ish read
$g->post  ('/posts/{id:[0-9]+}/comments',        [FeedController::class, 'addComment'])->add($jwtMw);
$g->delete('/comments/{id:[0-9]+}',              [FeedController::class, 'deleteComment'])->add($jwtMw);
$g->get   ('/posts/{id:[0-9]+}',                 [FeedController::class, 'showPost'])->add($optMw);   // composed single post (optional-auth for my_reaction)
```
**ROUTE-ORDERING (verified safe):** `/posts` (literal collection), `/posts/{id:[0-9]+}`, `/posts/{id}/comments`, `/posts/{id}/reactions`, `/posts/{id}/repost` all disambiguate via the numeric `{id}` constraint + distinct literal suffixes. `/comments/{id}` is a separate top-level path. Mirror the Phase 3 ordering discipline exactly. [VERIFIED: gateway routes.php uses `{id:[0-9]+}` throughout; FastRoute matches by first-fit with constraints]
**`/api/feed` auth:** MUST be JWT-required (it is "my" timeline + needs `my_reaction` for the viewer). Use the existing `$jwtMw` (not optional) — there is no anonymous feed. `/api/posts/{id}` single-post view can use `$optMw` (OptionalJwtMiddleware, already wired) so `my_reaction` is viewer-relative when logged in, null when anonymous — mirrors the `/full` auth-aware pattern. [VERIFIED: OptionalJwtMiddleware exists + wired in index.php; routes.php uses `$optMw` on /profiles/{id}/full]

### Pattern 8: Owner-scoped delete + the light 404 invariant (D-07)
**What:** delete post/comment only by the author; react/comment/repost to a nonexistent post → 404.
**Source:** git-history post-service `delete` (find → 404, author mismatch → 403) + connection-service scoped-mutation doctrine.

feed-service `PostController::delete` clones git-history exactly: `find($id)` → null → 404 `POST_NOT_FOUND`; `X-User-Id !== author_id` → 403 `FORBIDDEN`; else `DELETE`. Same for `CommentController::delete`. The gateway passes through (mirror `ConnectionsController::remove`). [VERIFIED: git-history post-service PostController::delete + comment-service delete read verbatim]
**Cascade on post delete (Claude's discretion):** deleting a post leaves orphan reactions/comments in feed-service's OWN DB. Since it is one DB, RECOMMENDED: feed-service `delete` also `DELETE FROM reactions WHERE post_id=:id` + `DELETE FROM comments WHERE post_id=:id` in the same handler (cheap, single DB, no cross-service invariant like the brownfield's `POST_HAS_COMMENTS` 409). The brownfield's 409-block-if-comments-exist invariant is NOT wanted here (different product: a feed post should be deletable). Flag the choice at plan review. [VERIFIED: feed owns reactions+comments tables; brownfield 409 invariant was cross-service and not applicable]

### Anti-Patterns to Avoid
- **Gateway counting reactions/comments per post (N+1)** — D-06 explicitly puts counts INSIDE feed-service's timeline query. The gateway must NEVER loop posts calling a count endpoint. [VERIFIED: D-06]
- **Double LEFT JOIN on reactions + comments then GROUP BY** — multiplies counts (a post with 3 reactions and 2 comments reports 6 of each). Use correlated subqueries (Pattern 3) or separate the joins. [VERIFIED: classic SQL fan-trap; Pitfall 4]
- **Passing `/users?ids=` batch bodies through verbatim into feed cards** — leaks `email` (the SELECT includes it). Allowlist to `{id,username,display_name,avatar_url}` before emitting. [VERIFIED: UserController::index SELECT line 146 includes email]
- **Trusting `author_id`/`user_id` from the request body in feed-service** — IDOR. Scope by `X-User-Id` only (D-07), never the body. [CITED: D-07 + connection/post service doctrine]
- **Resolving repost originals with one feed-service call per repost** — N+1. Batch all `repost_of` ids in ONE `?ids=` call (Pattern 1 Step 3). [VERIFIED: post-service ?ids= batch precedent]
- **Adding the feed tables only to a fresh-volume schema file** — the live VPS volume already exists; the schema file silently no-ops there. Ship the idempotent `db/04-migrate-phase4.sql` wired into deploy.sh (Pitfall 1). [VERIFIED: Phase 2/3 precedent + db/00-init.sh initdb-glob behavior]
- **Mixing named + positional placeholders in the timeline IN-list query** — not allowed with native prepared statements. Use all-positional for the dynamic IN list (Pattern 3). [VERIFIED: post-service ?ids= uses positional]
- **`x-html` in feed.html** — D-10 says x-text only (XSS-safe for user content). [CITED: D-10]

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Posts/comments CRUD | bespoke controllers | Clone git-history post-service `PostController` + comment-service `CommentController` (git 2f6ecf8) | Proven, owner-scoped, validated; drop title/slug. [VERIFIED: read verbatim] |
| Reaction set/change | SELECT-then-INSERT/UPDATE race | `INSERT ... ON DUPLICATE KEY UPDATE type` on `UNIQUE(post_id,user_id)` | Atomic upsert, no race, D-02 mandate. [VERIFIED: standard MariaDB] |
| Per-post counts | gateway N+1 loop | correlated subqueries in feed-service timeline query | D-06: count cheaply in feed's own DB. [VERIFIED: post/comment COUNT precedent] |
| Author/repost enrichment | N+1 per-row fetch | `ProfileClient::batch()` (`?ids=`, cap 100) + batch `FeedClient::getPosts(ids)` for originals | `fetchAuthors`/`enrich` precedent; batch already exists. [VERIFIED: ProfileClient::batch + post-service ?ids=] |
| Parallel fan-out (if used) | `curl_multi`/threads | `GuzzleHttp\Promise\Utils::settle` | Already the pattern in AggregateController. [VERIFIED] |
| Owner-scoped writes / IDOR safety | role checks on body fields | `X-User-Id` header scope + find→404 / author-mismatch→403 | post/comment/connection service doctrine. [VERIFIED] |
| Error envelope | ad-hoc JSON | `App\DomainError` + `App\JsonErrorHandler` (VN) | Already present in feed-service. [VERIFIED] |
| Idempotent live migration | manual SQL on VPS | `db/04-migrate-phase4.sql` + deploy.sh step (clone 03) | Phase 2/3 solved this exact pitfall. [VERIFIED] |
| Request correlation | manual headers | `HttpClient::create` auto-forwards X-Request-Id | feed-service /health already echoes `rid` proving it works. [VERIFIED: HealthController echoes X-Request-Id] |
| Degrade on partial failure | fail the whole feed | catch + `meta.degraded` (own-posts-only if connection down) | AggregateController/ConnectionsController degrade precedent. [VERIFIED] |

**Key insight:** Phase 4 introduces ZERO genuinely-new infrastructure. The CRUD is the git-history post/comment services re-pointed at `proconnect_feed`; reactions are a standard upsert; the composition is `AggregateController` + `ConnectionsController` re-shaped for a 3-service read join; the migration is `03-migrate-phase3.sql` re-cloned; enrichment is `ProfileClient::batch` re-used. The risk is reusing the patterns correctly — especially the single efficient count query (avoid the JOIN fan-trap), the email allowlist, and the no-N+1 repost-original batch.

## Runtime State Inventory

Brownfield with a LIVE deployed stack (Phases 1-3 live on soa.duyet.vn). feed-service is currently a `/health`-only stub.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | **LIVE DB `proconnect_feed` on VPS is EMPTY** — the schema has NO `posts`/`reactions`/`comments` tables (Phase 1 created the empty `proconnect_feed` schema + `feed_svc` user/grant only). Both fresh-volume and live-volume lack the tables. | **Data migration**: idempotent `CREATE TABLE IF NOT EXISTS posts/reactions/comments` against the live volume via deploy.sh (NOT just a code edit) + a small idempotent demo seed (D-08: a few posts/reactions/comments + 1 repost). Mirrors Phase 3's `db/03-migrate-phase3.sql`. |
| Live service config | feed-service container is deployed and serving `/health` only. `FEED_SERVICE_URL: http://feed-service:80` already injected into gateway; gateway `depends_on: feed-service service_healthy` already set; `FeedClient` already registered in DI; `HealthController` already fans out to FeedClient. No docker-compose / DI bootstrap change needed beyond new routes/controller. | **None** for infra — the container exists and is wired (compose lines 56-62, 101, 111 verified). New routes ship via image rebuild on deploy. No new container/port/env. |
| OS-registered state | None — no Task Scheduler / cron / launchd referencing feed. Deploy is GitHub Actions → `scripts/deploy.sh`. | None. |
| Secrets / env vars | `FEED_SVC_DB_PASS` already in `.env`/compose (line 15, 62); `feed_svc`@'%' already exists with `GRANT ALL ON proconnect_feed.*` (verified in migrate-phase1.sql.tmpl lines 56-66). Schema-wide grant covers the new tables automatically. | **None** — no new secret, user, or grant (D-08 confirmed). |
| Build artifacts | feed-service `vendor/` committed/built; no new composer deps → no dependency rebuild. Image rebuilds on deploy (`docker compose build --pull`) so new PHP files ship. `web/` is bind-mounted; deploy.sh restarts web only when `web/` changed (`$WEB_TOUCHED`). | New `.php` ships via image rebuild (automatic). New/changed `web/*.html` ships via bind mount + conditional restart (automatic). No manual step. |

**The canonical question — after every repo file is updated, what runtime state still has the old shape?** Answer: the **live `proconnect_feed` schema** has no `posts`/`reactions`/`comments` tables. A repo-only schema-file edit deploys green but every feed endpoint 500s on `SELECT ... FROM posts`. The idempotent live migration wired into deploy.sh is the ONLY fix — exactly the Phase 2/3 pattern. NOTHING ELSE carries old state (the grant is schema-wide; the container/URL/depends_on/FeedClient/HealthController are already correct).

## Common Pitfalls

### Pitfall 1: feed tables invisible on the live volume (THE big one)
**What goes wrong:** You add `posts`/`reactions`/`comments` to a schema file, deploy, and every feed endpoint 500s with "Table 'posts' doesn't exist" because the live volume's init scripts already ran.
**Why:** MariaDB's initdb glob runs `db/*.sql` + `*.sh` alphabetically ONLY on a fresh volume; the live VPS volume already exists → silently skipped.
**How to avoid:**
1. Ship `db/04-migrate-phase4.sql` — idempotent `CREATE TABLE IF NOT EXISTS` for the three tables + the idempotent guarded demo seed. Clone `db/03-migrate-phase3.sql` header/structure EXACTLY (it documents this same pitfall verbatim).
2. ALSO add a fresh-volume schema file `db/01-schema-feed.sql` (structure-only, DDL VERBATIM identical to the migration) so a from-scratch `docker compose up` converges — mirroring how `db/01-schema-connection.sql` mirrors `db/03-migrate-phase3.sql`. Must `USE proconnect_feed;` at the top.
3. Wire into `deploy.sh` IMMEDIATELY after the phase-3 step (currently lines 142-144), applied to the running container:
   ```bash
   echo "[deploy] applying db/04-migrate-phase4.sql (additive)"
   docker compose exec -T mariadb mysql -uroot -p"$DB_ROOT_PASSWORD" proconnect_feed < db/04-migrate-phase4.sql
   echo "[deploy] phase-4 additive migration applied"
   ```
   Plain `.sql`, NO envsubst (no secret placeholders — `feed_svc` already has `GRANT ALL ON proconnect_feed.*`). Place BEFORE the "FULL-TOPOLOGY UP" step (deploy.sh line ~147) so feed-service boots against existing tables. [VERIFIED: deploy.sh phase-3 step lines 142-144 + the full-topology-up at 147; migrate-phase1 feed grant lines 56-66]
**Warning signs:** local lint green, VPS deploy green, but `/api/feed` and `/api/posts` 500.
**Confidence:** HIGH — directly mirrors the documented Phase 2/3 resolution.

### Pitfall 2: email leak in feed cards / comment cards
**What goes wrong:** `/api/feed`, `/api/posts/{id}`, or `/api/posts/{id}/comments` include `email` because `ProfileClient::batch()` → `/users?ids=` SELECTs email and the gateway passes it through.
**Why:** `UserController::index` (the `?ids=` batch) SELECT is `SELECT id, username, email, display_name, avatar_url, created_at` — it INCLUDES email. The brownfield/Phase 3 always allowlisted.
**How to avoid:** allowlist enrichment to `{id, username, display_name, avatar_url}` before emitting (the EXACT `array_intersect_key(..., array_flip([...]))` pattern from `ConnectionsController::enrich`). Applies to post authors, repost-original authors, AND comment authors. Smoke MUST assert no `@`/`email` in any feed/post/comment body. [VERIFIED: UserController::index SELECT includes email line 146; ConnectionsController::enrich allowlist]

### Pitfall 3: the count fan-trap (double JOIN multiplies counts)
**What goes wrong:** joining `posts LEFT JOIN reactions LEFT JOIN comments` then `COUNT(*) GROUP BY p.id` reports `reactions×comments` for both counts (a post with 3 reactions and 2 comments shows 6/6).
**Why:** a row-multiplying cartesian product across two child tables.
**How to avoid:** correlated scalar subqueries per count (Pattern 3), or `COUNT(DISTINCT r.id)`/`COUNT(DISTINCT c.id)` if you insist on JOINs. Subqueries are clearer for presentation. Smoke MUST assert exact counts (post with 2 reactions + 3 comments → reaction_count=2, comment_count=3). [VERIFIED: classic SQL fan-trap; D-06 needs correct counts]

### Pitfall 4: `my_reaction` wrong viewer or wrong shape
**What goes wrong:** `my_reaction` returns another user's reaction, or a boolean instead of the type string, or 500s when `viewer=0`.
**Why:** the scalar subquery must be scoped `WHERE r2.post_id=p.id AND r2.user_id=:viewer`; with `viewer=0` it returns NULL (correct — no reaction). Returning the type string (e.g. `"love"`) lets the UI highlight the chosen emotion.
**How to avoid:** scope the subquery by viewer; coerce to `string|null` (NOT int). Smoke: viewer who reacted "love" sees `my_reaction:"love"`; a different viewer sees `null`. [VERIFIED: Pattern 3 query; D-10 "reaction của tôi"]

### Pitfall 5: repost original N+1 or broken when original deleted
**What goes wrong:** the composition fetches each repost's original in a loop (N+1), or crashes when the original was deleted.
**Why:** missing batch; missing null-guard.
**How to avoid:** batch ALL `repost_of` ids in ONE `FeedClient::getPosts(ids)` (Pattern 1 Step 3); if an original id is absent from the batch result, set `original:null` and let the UI show "bài viết gốc đã bị xoá". Smoke: repost shows original author+content; deleted-original repost degrades to null, not 500. [VERIFIED: post-service ?ids= batch; null-safe map in Pattern 1]

### Pitfall 6: `/api/feed` newest-first ordering lost in composition
**What goes wrong:** the gateway re-keys posts by author or id and loses the `created_at DESC` order from feed-service.
**Why:** building an associative map and emitting `array_values` of it, or sorting by author batch order.
**How to avoid:** feed-service `ORDER BY created_at DESC, id DESC`; the gateway preserves array order (map over `$posts` in place, never re-sort by a map). Smoke: assert the timeline is monotonically non-increasing by `created_at`. [VERIFIED: Pattern 1 maps in place; post-service ORDER BY precedent]

### Pitfall 7: connection-service down should degrade to own-posts, not fail
**What goes wrong:** `/api/feed` 500s when connection-service is unavailable instead of showing the viewer's own posts.
**Why:** not catching the connection-service failure in Step 1.
**How to avoid:** Step 1 try/catch → on failure, `authorIds=[$me]` + `meta.degraded:['connections']` (Pattern 1). The feed-service call (Step 2) is the only HARD dependency. Smoke (if fault-injection available on VPS) or document: connection down → own posts + `meta.degraded`. [VERIFIED: degrade precedent; D-06 degrade story]

## Code Examples

### Idempotent live migration (db/04-migrate-phase4.sql) — clone of db/03-migrate-phase3.sql
```sql
-- Phase 4 live-volume ADDITIVE migration (D-01..D-04, D-08) — news feed.
-- WHY: db/*.sql run ONLY on a fresh volume; the live proconnect_feed schema
-- exists but is EMPTY. This adds posts/reactions/comments + demo seed to the
-- running DB. Plain .sql (no envsubst/secrets — feed_svc already has GRANT ALL
-- on this schema). NON-DESTRUCTIVE + idempotent. Re-runs are no-ops.
USE proconnect_feed;

CREATE TABLE IF NOT EXISTS posts (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  author_id BIGINT UNSIGNED NOT NULL,
  content TEXT NOT NULL,
  image_url VARCHAR(512) NULL,
  repost_of BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_posts_author_created (author_id, created_at),
  INDEX idx_posts_created (created_at),
  INDEX idx_posts_repost (repost_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reactions (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  type ENUM('like','love','haha','wow','sad','angry') NOT NULL DEFAULT 'like',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reaction_post_user (post_id, user_id),
  INDEX idx_reaction_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_comment_post_created (post_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent demo seed (D-08): a few posts by demo accounts (ids 1=demo,2=duyet,3=long),
-- a couple reactions/comments, and 1 repost. Explicit ids + WHERE NOT EXISTS = no-op on re-run.
INSERT INTO posts (id, author_id, content, image_url, repost_of)
SELECT 1, 2, 'Chào mừng đến với ProConnect! Đây là bài viết demo đầu tiên.', NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM posts WHERE id = 1);
INSERT INTO posts (id, author_id, content, image_url, repost_of)
SELECT 2, 3, 'Vừa hoàn thành một dự án microservices thú vị.', NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM posts WHERE id = 2);
-- repost: demo(1) reposts duyet's post (id 1).
INSERT INTO posts (id, author_id, content, image_url, repost_of)
SELECT 3, 1, '', NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM posts WHERE id = 3);

INSERT INTO reactions (id, post_id, user_id, type)
SELECT 1, 1, 3, 'love' WHERE NOT EXISTS (SELECT 1 FROM reactions WHERE id = 1);
INSERT INTO reactions (id, post_id, user_id, type)
SELECT 2, 2, 2, 'like' WHERE NOT EXISTS (SELECT 1 FROM reactions WHERE id = 2);

INSERT INTO comments (id, post_id, author_id, body)
SELECT 1, 1, 3, 'Chúc mừng bạn!' WHERE NOT EXISTS (SELECT 1 FROM comments WHERE id = 1);
```
[NOTE: `CREATE TABLE IF NOT EXISTS` + guarded INSERT mirrors the verified `db/03-migrate-phase3.sql` exactly. Demo account ids 1/2/3 = demo/duyet/long match the seeded users (smoke-phase3 logs in `duyet`(2)/`long`(3)/`diep`(4)). Confirm exact seeded ids against `db/99-seed.sql` / migrate-phase1 at plan time.]

### Repost original resolution (gateway — batch, no N+1)
See Pattern 1 Step 3 — collect `repost_of` ids → ONE `FeedClient::getPosts($ids)` → map by id → fold original authors into the SAME `ProfileClient::batch` id set. [VERIFIED: post-service ?ids= batch + ConnectionsController::enrich batch pattern]

### Frontend: feed.html data loaders (reuse app.js api/auth)
```js
// web/feed.html — Alpine component, reuses window.api (Bearer auto-attach) + window.auth.
function feedPage() {
  return {
    posts: [], loading: true, error: '', busy: false, draft: { content: '', image_url: '' },
    async load() {
      try {
        const r = await api.get('/feed?limit=20');     // {data:[...], meta?:{degraded}}
        this.posts = r.data; this.degraded = r.meta && r.meta.degraded;
      } catch (e) { this.error = e.message; } finally { this.loading = false; }
    },
    async submit() {
      if (this.busy || !this.draft.content.trim()) return;
      this.busy = true;
      try { await api.post('/posts', { content: this.draft.content, image_url: this.draft.image_url || undefined });
            this.draft = { content: '', image_url: '' }; await this.load(); }
      finally { this.busy = false; }
    },
    react(id, type)   { return this._act(() => api.post('/posts/' + id + '/reactions', { type })); },
    unreact(id)       { return this._act(() => api.delete('/posts/' + id + '/reactions')); },
    repost(id)        { return this._act(() => api.post('/posts/' + id + '/repost')); },
    removePost(id)    { return this._act(() => api.delete('/posts/' + id)); },
    addComment(id, b) { return this._act(() => api.post('/posts/' + id + '/comments', { body: b })); },
    async _act(fn) { if (this.busy) return; this.busy = true; try { await fn(); await this.load(); } finally { this.busy = false; } },
  };
}
```
[VERIFIED: app.js `api.get/post/delete` with Bearer auto-attach + `{data, meta}` envelope; connections.html `connectionsPage()`/`_act(busy)` pattern read in full. Use `x-text` (D-10), never `x-html`, for `content`/author names/comment bodies.]

## State of the Art

| Old Approach (Phase 3) | Current Approach (Phase 4) | When Changed | Impact |
|------------------------|----------------------------|--------------|--------|
| connection-service built out (graph CRUD); feed-service still `/health` stub | feed-service built out (posts/reactions/comments/repost + timeline query) | Phase 4 | The last "real" domain service before search/notifications (Phase 5). |
| 2-service composition (`/full`: profile + connection) | 3-service read-heavy composition (`/api/feed`: connection + feed + profile) | Phase 4 | The richest Gateway demo — the grading centerpiece. |
| `db/03-migrate-phase3.sql` (connections) | `db/04-migrate-phase4.sql` (posts/reactions/comments) wired after the phase-3 step | Phase 4 | Same idempotent live-migration pattern, one DB further. |
| `smoke-phase3.sh` (graph invariants) | `smoke-phase4.sh` (feed composition + CRUD + counts + degrade) | Phase 4 | Regression: phase1/2/3 smoke must still pass. |

**Deprecated/outdated:** none. The stack is pinned; nothing in Phases 1-3 is replaced, only extended. NOTE: the brownfield post/comment SERVICES (`post-service`, `comment-service`) were RETIRED at the Phase 1 cutover (`--remove-orphans` in deploy.sh drops them) — their CODE in git 2f6ecf8 is the REFERENCE for feed-service, but you are NOT reviving those containers. feed-service is the single home for the new post/reaction/comment domain. [VERIFIED: deploy.sh "drops the retired post/comment containers"; ARCHITECTURE.md lists only profile/connection/feed/search/notification services]

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The seeded demo user ids are 1=demo, 2=duyet, 3=long, 4=diep, 5=tai (smoke-phase3 logs in duyet=2/long=3/diep=4) | Code Examples (seed) | LOW — confirm against `db/99-seed.sql` / `migrate-phase1.sql.tmpl` at plan time; the seed INSERTs use explicit ids so a mismatch is a one-line fix. Smoke uses login-by-username (not hardcoded id) so it is resilient. |
| A2 | `proconnect_feed` is EMPTY on the live VPS (Phase 1 created schema+grant only, no business tables) | Runtime State Inventory | LOW — mirrors the verified Phase 3 state for `proconnect_connection`; confirm with a `SHOW TABLES IN proconnect_feed` on the VPS before relying on it. If tables somehow exist, `CREATE TABLE IF NOT EXISTS` is still a safe no-op. |
| A3 | MariaDB 10.11 `INSERT ... ON DUPLICATE KEY UPDATE` works with the `UNIQUE(post_id,user_id)` key for reaction upsert | Pattern 4 | LOW — ON DUPLICATE KEY UPDATE is core MariaDB (since 5.x); deploy target is 10.11. Standard. |
| A4 | `/api/feed` should be JWT-required (no anonymous feed) and `my_reaction` is viewer-relative | Pattern 7 | LOW/PRODUCT — D-06 says "viewer" and timeline = own + connections (inherently authenticated). If the team wants a public feed, that's a product change; flag at `/codex-plan-review`. |
| A5 | Deleting a post should cascade-delete its reactions/comments in feed-service (no brownfield-style 409-block) | Pattern 8 | LOW/PRODUCT — different product than the blog (a feed post should be deletable). One DB → cheap cascade. Confirm at plan review. |
| A6 | A repost stores `content=''` (empty) and shows the original via `repost_of` | Pattern 6 / Code Examples | LOW — D-04 says repost shows the original; the repost row's own content is unused. If planner prefers nullable `content` for reposts, one-word DDL change. |
| A7 | The "genuine parallel fan-out" slide is satisfied by composing-across-3-services + degrade, even though Steps 1→2→3 are data-dependent (sequential) | Pattern 1 note | LOW/PRESENTATION — the composition story is strong; the literal `Utils::settle` can wrap Step 3 + author-batch for a visible parallel call. Decide the exact shape at plan review. |

## Open Questions (RESOLVED — Q1 showPost scope, Q2 POST verb, Q3 seed ids 1=demo,2=duyet,3=long — all decided in Plans 02/03/01)

1. **Single-post `GET /api/posts/{id}` composition scope.**
   - What we know: D-05 lists `GET /api/posts/{id}` (1 post, composed). It should return the post + author + counts + my_reaction, and (if a repost) the original.
   - What's unclear: whether it also embeds the comment list (like the brownfield `/full`) or just counts.
   - Recommendation: return post + author + counts + my_reaction + original (mirror a single feed row); fetch comments separately via `GET /api/posts/{id}/comments`. Keeps the single-post endpoint cheap and the comment list paginated independently. Confirm at plan review.

2. **Reaction route verb: `POST` vs `PUT` for upsert.**
   - What we know: D-05 says `POST /api/posts/{id}/reactions {type}` (upsert) + `DELETE` (remove).
   - What's unclear: feed-service internal verb (Pattern 2 used PUT; D-05 gateway uses POST).
   - Recommendation: gateway exposes `POST` (per D-05); the gateway maps it to whatever internal verb feed-service uses (PUT or POST). Pick POST internally too for consistency with the gateway. Trivial; Claude's discretion (D).

3. **Demo seed exact user ids.**
   - What we know: the seed needs real user ids that exist in `proconnect_profile`.
   - What's unclear: the exact seeded ids (A1).
   - Recommendation: read `db/99-seed.sql` / the migrate-phase1 seed at plan time and use matching ids; keep the seed tiny (2-3 posts, 2 reactions, 1 comment, 1 repost).

## Environment Availability

| Dependency | Required By | Available (local) | Version | Fallback |
|------------|------------|-------------------|---------|----------|
| Docker / docker compose | Runtime + smoke tests | ✗ (Docker NOT available locally) | — | Verify statically locally (`php -l`, code review); runtime verification on VPS after deploy (canonical method, Phase 1-3 precedent). |
| PHP CLI (`php -l` lint) | CI lint gate | likely ✓ on CI | 8.2 | CI runs `php -l` over all `*.php` (deploy.yml). |
| MariaDB 10.11 | Migration + queries | ✗ locally (in container on VPS) | 10.11 | Apply/verify migration on VPS via `docker compose exec mariadb mysql`. |
| curl + bash | smoke-phase4.sh | ✓ | — | — |

**Missing dependencies with no fallback:** none — Docker-local absence handled by the established VPS-runtime verification (Phase 1-3 precedent).
**Missing dependencies with fallback:** Docker (→ verify on VPS); MariaDB (→ on VPS).

## Validation Architecture

`workflow.nyquist_validation` not explicitly false → section included. Phases 1-3 established bash/curl smoke validation with NO PHPUnit; Phase 4 stays consistent (CLAUDE.md: keep simple, no new heavy deps).

### Test Framework
| Property | Value |
|----------|-------|
| Framework | bash + curl smoke test (no PHP test runner — consistent with Phase 1-3) [VERIFIED: smoke-phase3.sh structure] |
| Config file | none — `scripts/smoke-phase3.sh` is the template |
| Quick run command | `bash scripts/smoke-phase4.sh` (against the running stack: VPS, or local if Docker available) |
| Full suite command | `bash scripts/smoke-phase1.sh && bash scripts/smoke-phase2.sh && bash scripts/smoke-phase3.sh && bash scripts/smoke-phase4.sh` (no regression) |
| Static gate (local, always) | `php -l` over changed `*.php` (CI lint job mirrors this) |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command / Assertion | File Exists? |
|--------|----------|-----------|-------------------------------|--------------|
| FEED-01 | post (text + optional image URL) | smoke | login → `POST /api/posts {content,image_url}` → 201; appears in own `/api/feed` | ❌ Wave 0 (smoke-phase4.sh) |
| FEED-02 | timeline own + connections, newest-first | smoke | duyet's `/api/feed` includes a post by long (seed accepted duyet↔long); assert `created_at` non-increasing | ❌ Wave 0 |
| FEED-03 | reaction set/change/remove + dedupe | smoke | `POST /api/posts/{id}/reactions {type:like}` → reaction_count 1, my_reaction "like"; change to "love" → still 1, my_reaction "love"; `DELETE` → 0, my_reaction null | ❌ Wave 0 |
| FEED-04 | comment add/list/delete | smoke | `POST .../comments {body}` → in list + comment_count++; `GET .../comments` shows author (no email); `DELETE /api/comments/{id}` (owner) → gone | ❌ Wave 0 |
| FEED-05 | repost shows origin | smoke | `POST /api/posts/{id}/repost` → new post with `original.author` + `original.content`; deleted-original repost → `original:null` not 500 | ❌ Wave 0 |
| FEED-06 | feed composition: author+counts+my_reaction, degrade, newest-first, NO email | smoke | `/api/feed` each item has `author`,`reaction_count`,`comment_count`,`my_reaction`,`repost_of`; body has NO `@`/`email`; (if injectable) connection down → own posts + `meta.degraded` | ❌ Wave 0 |
| D-07 | owner-only delete | smoke | user A cannot delete user B's post/comment → 403/404 | ❌ Wave 0 |
| Invariant | 404 on react/comment/repost to missing post | smoke | `POST /api/posts/999999/reactions` / `.../comments` / `.../repost` → 404 | ❌ Wave 0 |
| Security | no email leak anywhere | smoke | feed + single-post + comment-list bodies MUST NOT contain `@`/`email` | ❌ Wave 0 |
| Regression | Phases 1-3 still green | smoke | `bash scripts/smoke-phase{1,2,3}.sh` pass | ✅ exist |

### Sampling Rate
- **Per task commit:** `php -l` on changed files + `/codex-impl-review` (mandatory CLAUDE.md gate before commit).
- **Per wave merge / phase gate:** full smoke suite (phase1-4) green on the VPS-deployed stack.
- **Phase gate:** full smoke green before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `scripts/smoke-phase4.sh` — covers FEED-01..06 + email-leak + owner-scope + 404-invariant + count-correctness + degrade + newest-first (clone `smoke-phase3.sh`: `pass`/`fail`/`FAILURES`, `GW` env, login-by-username, NON-DESTRUCTIVE `trap restore EXIT` cleanup of created posts/reactions/comments, never delete the demo seed).
- [ ] Confirm `db/04-migrate-phase4.sql` applied before any feed smoke runs (deploy step ordering — Pitfall 1).
- [ ] No framework install needed (bash/curl present).

## Security Domain

`security_enforcement` not explicitly false → included. Demo project, internal-network trust model — keep proportionate.

### Applicable ASVS Categories
| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | JWT HS256 at gateway (`firebase/php-jwt`), unchanged. `/api/feed` + mutations require `$jwtMw`; single-post `$optMw`. [VERIFIED] |
| V3 Session Management | partial | Stateless JWT, 24h TTL. No change. |
| V4 Access Control | **yes (key)** | Owner-only delete (post/comment) scoped by `X-User-Id`; react/comment scoped to caller; never trust body `author_id`/`user_id` (D-07). [VERIFIED precedent: post/comment/connection services] |
| V5 Input Validation | yes | feed-service validates content ≤5000, image_url ≤512 (URL format), reaction type ∈ enum, body ≤5000 (D-09). Mirror existing validation style. [VERIFIED] |
| V6 Cryptography | no new | No new crypto. No new secrets. |
| V7 Data protection | yes | Feed/comment cards MUST NOT leak `email` (Pitfall 2). Allowlist `{id,username,display_name,avatar_url}`. [VERIFIED: UserController::index SELECTs email] |

### Known Threat Patterns for PHP/Slim/MariaDB + Gateway
| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| IDOR (delete/react on another user's behalf) | Elevation/Tampering | Scope ALL writes by `X-User-Id`; delete checks `author_id == X-User-Id` → 403; reaction/comment keyed to caller. [VERIFIED] |
| SQL injection (esp. dynamic IN-list) | Tampering | PDO prepared statements, `EMULATE_PREPARES=false`, positional placeholders for the IN list (never interpolate ids). [VERIFIED: Db.php + post-service ?ids= positional] |
| PII leak on feed/comment cards | Information disclosure | Allowlist out email before emitting (Pitfall 2). [VERIFIED] |
| Stored XSS via post content / comment body / image_url | Tampering/Info disclosure | UI uses `x-text` not `x-html` (D-10); image_url validated as URL; content stored as-is, rendered as text. [CITED: D-10] |
| Reaction race (double reaction per user) | Tampering | `UNIQUE(post_id,user_id)` + `ON DUPLICATE KEY UPDATE` upsert — at most one row per (post,user). [VERIFIED: D-02] |
| Feed 500 on a degradable dependency | DoS/availability | Degrade via try/catch → `meta.degraded`, own-posts-only when connection down; feed-service is the only hard dep (Pitfall 7). [VERIFIED] |
| Trusting X-User-Id from outside | Spoofing | Network isolation (feed-service has no host port) + only gateway sets `X-User-Id`. Unchanged trust model. [VERIFIED: ARCHITECTURE.md + docker-compose feed-service no ports] |

## Sources

### Primary (HIGH confidence — read directly this session)
- `git show 2f6ecf8:gateway/src/Controllers/PostsController.php` — posts CRUD + `?ids=` batch enrichment (`fetchAuthors`) + `createComment` 404-invariant + `delete` 503-on-incomplete — the strongest reference for feed CRUD + composition.
- `git show 2f6ecf8:services/post-service/src/Controllers/PostController.php` — posts table CRUD, `?ids=` batch (cap 100, `TOO_MANY_IDS`), `?author_id=` + `LIMIT :lim` `bindValue PARAM_INT`, owner delete/403.
- `git show 2f6ecf8:services/comment-service/src/Controllers/CommentController.php` — comments CRUD, `?post_id=` list, `COUNT(*)`, body ≤5000, owner delete.
- `services/feed-service/{src/routes.php, src/Db.php, public/index.php, src/Controllers/HealthController.php, src/Json.php, src/DomainError.php, src/JsonErrorHandler.php}` — the stub to build out (mirrors connection-service exactly).
- `services/connection-service/src/Controllers/ConnectionController.php` — raw-PDO doctrine: X-User-Id scope, uniform 404, scoped SELECT existence, 23000→409, `listAccepted` shape, named-placeholder-twice for native prepared statements.
- `services/connection-service/src/routes.php` — route-ordering discipline (literal before bare, numeric constraints).
- `gateway/src/Controllers/{AggregateController, ConnectionsController}.php` — `Utils::settle` + `meta.degraded`, `enrich()` + email allowlist + `me()` + `decode()`.
- `gateway/src/Services/{FeedClient, ConnectionClient, ProfileClient, HttpClient}.php` — FeedClient stub; ConnectionClient `listAccepted`/mutation header injection; ProfileClient `batch` (`?ids=`)/`allUsers`; HttpClient timeouts + auto X-Request-Id.
- `gateway/src/routes.php` + `gateway/public/index.php` — route group + per-route `->add($jwtMw)`/`$optMw`; DI factories (FeedClient + FeedController wiring point).
- `services/profile-service/src/Controllers/UserController.php` — `?ids=` batch SELECT INCLUDES email (cap 100); the public projection excludes email.
- `db/{03-migrate-phase3.sql, 01-schema-connection.sql, migrate-phase1.sql.tmpl}` — idempotent live-migration pattern + feed grant (lines 45/56/66).
- `scripts/deploy.sh` — phase-2/3 migration steps (lines 126-144) + full-topology-up (147) — the wiring point for phase-4.
- `scripts/smoke-phase3.sh` — smoke harness template (pass/fail, NON-DESTRUCTIVE trap, login-by-username, PII-leak guard).
- `web/{connections.html, assets/app.js}` — Alpine component + `_act(busy)` pattern + `api`/`auth` helpers + `{data, meta}` envelope.
- `docker-compose.yml` — feed-service built/wired (lines 56-62, 101, 111), no host port.
- `CLAUDE.md` (project) + `~/.claude/CLAUDE.md` (review-workflow mandate); `.planning/phases/0{2,3}-*/0{2,3}-RESEARCH.md`; `.planning/codebase/ARCHITECTURE.md`.

### Secondary (MEDIUM)
- `.planning/codebase/STACK.md` (via Phase 2/3 research) — version pins from committed lockfiles.

### Tertiary (LOW / to confirm at impl)
- Live `proconnect_feed` is empty (A2) — confirm `SHOW TABLES IN proconnect_feed` on VPS.
- Exact seeded demo user ids (A1) — confirm against `db/99-seed.sql` / migrate-phase1 seed.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new deps; feed-service stub identical to other services; versions from committed lockfiles.
- Architecture / composition: HIGH — settle/degrade/enrich recovered from live gateway source; CRUD from git-history post/comment services read verbatim; `listAccepted` shape verified.
- Timeline query (counts + my_reaction): HIGH for the pattern (correlated subqueries + positional IN-list mirror post-service); MEDIUM only on the exact SQL phrasing (confirm against MariaDB on VPS during impl).
- Live migration: HIGH — directly mirrors the shipped Phase 2/3 solution; feed grant verified.
- Pitfalls: HIGH — each grounded in a specific verified file (initdb-glob, email-leak SELECT, fan-trap, ?ids= cap).

**Research date:** 2026-06-07
**Valid until:** ~2026-07-07 (stable; the only volatile item is the live `proconnect_feed` schema state, which Phase 4 itself changes — re-verify the VPS schema if implementation is delayed).

## RESEARCH COMPLETE

**Phase:** 04 - News Feed
**Confidence:** HIGH

### Key Findings
- **The feed composition is a 3-service read join, not a parallel-everything fan-out.** `GET /api/feed`: connection-service `listAccepted` (author universe ∪ self) → feed-service `GET /posts?authors=&viewer=&limit=` (posts WITH counts+my_reaction computed in feed's OWN DB — no gateway N+1) → batch-enrich authors + batch-resolve repost originals via `ProfileClient::batch` / `FeedClient::getPosts(?ids=)` → settle + degrade (own-posts-only if connection down) + preserve feed-service's newest-first order. Steps are data-dependent (sequential); the composition+degrade story carries the showcase. (D-06/FEED-02/FEED-06)
- **feed-service is built by cloning git-history code:** post-service `PostController` (drop title/slug, add `image_url`+`repost_of`) for posts; comment-service `CommentController` for comments; connection-service PDO doctrine for X-User-Id scoping + uniform 404. Reactions are NEW: `INSERT ... ON DUPLICATE KEY UPDATE type` on `UNIQUE(post_id,user_id)` (D-02 upsert), scoped DELETE to remove.
- **The ONE timeline query** returns `reaction_count`/`comment_count` via correlated subqueries and `my_reaction` via a viewer-scoped scalar subquery — avoiding the double-JOIN fan-trap (Pitfall 3). Positional placeholders for the dynamic author IN-list (native prepared statements; mirrors post-service `?ids=`).
- **Infra is already wired:** feed-service container built/deployed/health-only, `FEED_SERVICE_URL` + `depends_on` + `FeedClient` DI + HealthController fan-out all present. Phase 4 adds routes/controllers/migration only — NO new container/secret/grant. `proconnect_feed` + `feed_svc` grant exist from Phase 1; the live DB is EMPTY (needs the migration).
- **THE critical risk is the live migration** (Pitfall 1): `db/*.sql` runs only on a fresh volume; ship idempotent `db/04-migrate-phase4.sql` (clone `03`) wired into `deploy.sh` after the phase-3 step (lines 142-144), before full-topology-up. Plus the email allowlist on every card (Pitfall 2) and correct counts (Pitfall 3).

### File Created
`/Users/theduyet/Documents/Code/SOA/soa-blog/.planning/phases/04-news-feed/04-RESEARCH.md`

### Confidence Assessment
| Area | Level | Reason |
|------|-------|--------|
| Standard Stack | HIGH | No new deps; feed-service stub identical to other services; pinned lockfiles. |
| Architecture / composition | HIGH | settle/degrade/enrich from live source; CRUD from git-history read verbatim. |
| Timeline query | HIGH (pattern) / MEDIUM (exact SQL) | Subqueries + positional IN-list mirror post-service; confirm phrasing on VPS MariaDB. |
| Live migration | HIGH | Mirrors shipped Phase 2/3 solution; feed grant verified. |
| Pitfalls | HIGH | Each grounded in a specific verified file. |

### Open Questions
1. Single-post `GET /api/posts/{id}` scope — include comments or just counts? (Recommend: counts + original; comments via separate endpoint.)
2. Reaction internal verb POST vs PUT (gateway is POST per D-05; trivial).
3. Exact demo seed user ids — confirm against `db/99-seed.sql` at plan time (A1).

### Ready for Planning
Research complete. Planner can create PLAN.md files. Remember the mandatory CLAUDE.md gates: `/codex-plan-review` before coding, `/codex-impl-review` before commit.
