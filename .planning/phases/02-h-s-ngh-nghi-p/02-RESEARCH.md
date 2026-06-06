# Phase 2: Hồ sơ nghề nghiệp - Research

**Researched:** 2026-06-06
**Domain:** API Gateway composition (genuine parallel fan-out + degrade) + profile-service CRUD data model + live-DB migration + minimal Alpine/Tailwind UI
**Confidence:** HIGH (all findings verified against actual source files + git history in this repo; PHP/Slim/Guzzle versions confirmed from committed lockfile)

## Summary

Phase 2 is brownfield work on a live, shipped Phase 1 stack (8 containers on `soa.duyet.vn`). Everything you need already has a working precedent in the codebase: the exact composition+degrade pattern lives in git history (`2f6ecf8:gateway/src/Controllers/AggregateController.php`, using `GuzzleHttp\Promise\Utils::settle`), the raw-PDO service pattern is in `services/profile-service/src/Controllers/UserController.php` + `Db.php`, the typed-async client pattern is in `ProfileClient.php`, and the live-DB migration strategy is fully solved by the Phase-1 `db/migrate-phase1.sql.tmpl` + `scripts/deploy.sh` envsubst wiring. The job is to re-introduce and extend these patterns, not invent new ones.

The single highest-value, highest-risk item is the **live-DB migration**: `db/00-init.sh` + `db/01-schema-profile.sql` run ONLY on a fresh MariaDB volume. The VPS volume already exists (`proconnect_profile.users` has 5 demo rows), so adding columns + 3 tables there requires an idempotent migration applied against the running container — exactly mirroring how Phase 1 solved the identical pitfall with a `.sql.tmpl` (note: the `.tmpl` extension is load-bearing — it keeps the file out of MariaDB's initdb glob). Phase 2's migration needs NO envsubst placeholders (no new DB users/secrets), so it can be a plain idempotent `.sql` file — but it must still be applied to the live volume via a deploy step, because the schema file alone will not run there.

**Primary recommendation:** Build `GET /api/profiles/{id}/full` as a new `AggregateController::profileFull` cloned from the git-history `AggregateController` (settle + meta.degraded), where the gateway fans out in parallel to (a) profile-service's NEW aggregate endpoint `GET /users/{id}/full` (basic+experience+education+skills, assembled inside the service) and (b) connection-service `GET /connections/status?viewer=&target=` (which 404s as a stub → degrades to `connection_status: "none"`). Do CRUD as granular `/api/profiles/me/*` gateway routes that map JWT→X-User-Id onto profile-service `/users/{id}/*` mirror routes scoped by header. Migrate the live DB with an idempotent `db/02-migrate-phase2.sql` wired into `deploy.sh`. UI = two new pages reusing `web/assets/app.js`.

## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** Gateway does GENUINE composition (not passthrough). `GET /api/profiles/{id}/full` is the flagship. Parallel Guzzle `*Async` + `settle` to ≥2 sources merged into ONE response: profile-service (basic + experience + education + skills) and connection-service (connection_status).
- **D-02:** Degrade safely (success criterion #4): if one source fails/times out/not ready, return the rest + `meta.degraded: true` instead of failing the whole request.
- **D-03:** connection-service is still a STUB in Phase 2 → call it anyway; it degrades → `connection_status: "none"` + `meta.degraded`. Phase 3 lights the endpoint up with ZERO gateway rework. No deferred fields, no fake stub.
- **D-04:** `/full` is public-readable but auth-aware: JWT optional. With token → include viewer-relative `connection_status`. Without token → `connection_status: null`. Basic profile always public (PROF-07/PROF-06).
- **D-05:** Extend `users` with `cover_url VARCHAR(512) NULL`, `headline VARCHAR(160) NULL`, `location VARCHAR(128) NULL`, `about TEXT NULL`. Keep in `users` (no 1-1 split).
- **D-06:** New table `experience` (`id, user_id, company VARCHAR(160), title VARCHAR(160), start_date DATE, end_date DATE NULL, description TEXT NULL`). `end_date NULL` = "hiện tại".
- **D-07:** New table `education` (`id, user_id, school VARCHAR(160), degree VARCHAR(160) NULL, field VARCHAR(160) NULL, start_year SMALLINT NULL, end_year SMALLINT NULL`).
- **D-08:** New table `skills` (`id, user_id, name VARCHAR(80)`, UNIQUE(user_id, name)). Simple list, NO endorsements.
- **D-09:** Avatar + cover = URL string (no binary upload). User pastes URL.
- **D-10:** Granular RESTful `/me` endpoints at gateway (avoid IDOR): `PATCH /api/profiles/me` (basic); `POST|PATCH|DELETE /api/profiles/me/experience[/{id}]`; `POST|PATCH|DELETE /api/profiles/me/education[/{id}]`; `POST|DELETE /api/profiles/me/skills[/{id}]` (skills add/remove only, no edit).
- **D-11:** Gateway verifies JWT, sets `X-User-Id`; profile-service trusts header and scopes ALL writes by `X-User-Id` (never accepts `user_id` from body).
- **D-12:** profile-service mirrors routes internally under `/users/{id}/experience|education|skills`. Services "dumb"; gateway maps `/me`→id. Keep Json/DomainError Vietnamese pattern.
- **D-13:** UI = functional, minimal style (Alpine.js + Tailwind CDN like Phase 1; NO navy branding / 3-column layout — Phase 6). Two screens: `profile.html` (view via `/full`), `profile-edit.html` (edit). Vietnamese with diacritics.
- **D-14:** Minimal link from `index.html` → own profile after login.

### Claude's Discretion
- Specific internal route names, JSON section format, display field order, concrete validation (length, URL format), secondary DB indexes — Claude chooses sensibly at plan/implement.
- profile.html / profile-edit.html organisation (1 file 2 modes vs 2 files) — Claude chooses.

### Deferred Ideas (OUT OF SCOPE)
- Real `connection_status` business logic (invite/connected) — Phase 3 (connection-service).
- Skill endorsements, "People you may know" — Phase 3+.
- ProConnect branding (navy #1e3a8a, logo, 3-column) — Phase 6.
- Binary image upload / object storage — out of scope v1.

## Project Constraints (from CLAUDE.md)

These have the same authority as locked decisions. The planner must not propose anything contradicting them.

- **Stack locked:** PHP 8.2 + Slim 4 + Guzzle + firebase/php-jwt v7 + MariaDB 10.11 + Docker Compose. Frontend HTML + Alpine.js + Tailwind CDN, **no heavy build step**. Do NOT add new heavy dependencies. [CITED: CLAUDE.md Constraints]
- **2GB RAM shared VPS, ≤ ~9-10 containers.** Phase 2 adds NO new container (extends existing profile-service + gateway). [CITED: CLAUDE.md]
- **All UI + content + error messages in Vietnamese with diacritics.** [CITED: CLAUDE.md]
- **API Gateway pattern must not be blurred** — it is the core grading criterion. The `/full` composition IS the showcase; keep it legible. [CITED: CLAUDE.md Core Value]
- **Keep SIMPLE** — the team must be able to understand and present the code. Favour the existing patterns over cleverness. [CITED: CONTEXT.md + CLAUDE.md]
- **MANDATORY review workflow (CLAUDE.md):** after plan approval, run `/codex-plan-review` and start coding only after Codex APPROVES the plan. Before any commit, run `/codex-impl-review` and commit only after Codex APPROVES. Fix valid issues and re-review. The planner MUST bake these two gates into the phase plan as explicit steps. [CITED: ~/.claude/CLAUDE.md + project CLAUDE.md]
- **Deploy flow:** local git → review → push public GitHub `main` → GitHub Actions → VPS `git pull --ff-only` → `scripts/deploy.sh` → smoke `/api/health`. Docker NOT available locally — runtime verification is on the VPS. [CITED: CLAUDE.md + MEMORY]

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| PROF-02 | Profile: avatar, cover, headline, location, about — editable | D-05 column additions in `db/02-migrate-phase2.sql`; extend `UserController::update` to accept the 4 new fields; gateway `PATCH /api/profiles/me` → `PATCH /users/{id}` with X-User-Id. Pattern already exists for `display_name`/`avatar_url` in `UserController::update` (lines 165-207). |
| PROF-03 | Experience CRUD (company, title, dates, description) | New `experience` table; new `ExperienceController` or methods on `UserController` in profile-service; mirror routes `/users/{id}/experience[/{eid}]`; gateway `/api/profiles/me/experience[/{id}]`. |
| PROF-04 | Education CRUD (school, field, dates) | New `education` table + same controller/route pattern as experience. |
| PROF-05 | Skills add/remove | New `skills` table (UNIQUE user_id+name); POST/DELETE only; `409` on duplicate (mirror `USER_EXISTS` pattern). |
| PROF-06 | Public profile viewing of others | `/api/profiles/{id}/full` is public (no JWT required); `connection_status` becomes viewer-relative only when a token is present (D-04). |
| PROF-07 | Gateway composition endpoint (basic + exp + edu + skills + connection_status) in ONE request | `AggregateController::profileFull` — settle over profile-full + connection-status; `meta.degraded` on partial failure. This is THE flagship. |
| UI-03 | Profile page with cover + avatar + experience/education/skills sections | `profile.html` consumes `/api/profiles/{id}/full`; `profile-edit.html` consumes the `/me/*` CRUD endpoints. Reuse `web/assets/app.js` `api`/`auth` helpers. |

## Standard Stack

No new dependencies. Everything is already installed and version-locked.

### Core (verified from committed lockfiles — NOT training data)
| Library | Version (locked) | Purpose | Why Standard |
|---------|------------------|---------|--------------|
| `slim/slim` | 4.15.1 | HTTP framework, gateway + all services | [VERIFIED: STACK.md / composer.lock] Already the framework; routing via FastRoute. |
| `slim/psr7` | 1.8.0 | PSR-7 messages | [VERIFIED: STACK.md] |
| `php-di/php-di` | 7.1.1 | DI container, **gateway only** | [VERIFIED: STACK.md] Services wire manually (no container) — see `services/profile-service/public/index.php`. |
| `guzzlehttp/guzzle` | 7.10.0 | Gateway → service HTTP | [VERIFIED: composer.lock] `HttpClient::create` factory already set up. |
| `guzzlehttp/promises` | ^2.3 (2.3.0) | `Utils::settle` for parallel composition | [VERIFIED: gateway/composer.lock line confirmed] This is what powers the degrade pattern. |
| `firebase/php-jwt` | v7.0.5 | JWT HS256, gateway only | [VERIFIED: STACK.md] |
| `ext-pdo` / `ext-pdo_mysql` | bundled (php:8.2-fpm-alpine) | Raw PDO data access in services | [VERIFIED: Db.php uses real prepared statements] |

### Frontend (CDN, no build)
| Asset | Source | Purpose |
|-------|--------|---------|
| Tailwind | `https://cdn.tailwindcss.com` (Play CDN) | Styling | [VERIFIED: web/index.html line 7] |
| Alpine.js 3.x | `https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js` | Reactivity | [VERIFIED: web/index.html line 10] |
| `web/assets/app.js` | local classic script | `window.api`, `window.auth`, `window.formatDate`, `window.navbar` helpers | [VERIFIED: read in full] |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| profile-service assembles `/users/{id}/full` then gateway composes that + connection | Gateway makes 4 separate parallel calls to profile-service (basic, exp, edu, skills) + connection | The 4-call variant makes a noisier composition diagram but "looks like more fan-out". REJECTED for keep-simple AND because it couples gateway to profile-service internals. RECOMMENDED: profile-service owns its own intra-service assembly (one DB round-trip set, one `/full`), gateway composes ACROSS services (profile + connection). This is the architecturally correct boundary (each service owns its bounded context; gateway composes across contexts) and still showcases genuine cross-service fan-out + degrade. [VERIFIED: ARCHITECTURE.md "gateway-centric composition" + bounded-context principle] |
| New `AggregateController` | Add `profileFull` method to existing `ProfilesController` | Either works. RECOMMENDED: re-introduce a dedicated `AggregateController` (matches the retired pattern + ARCHITECTURE.md names it the canonical composition home). Keeps composition legible for presentation. |
| `Utils::settle` | `Utils::unwrap` / `Promise::all` | `unwrap` THROWS on first rejection — defeats degrade. `settle` returns per-promise `{state, value/reason}` so each part degrades independently. MUST use `settle`. [VERIFIED: git-history AggregateController] |

**Installation:** none. `composer install` already run; lockfiles committed; Dockerfiles copy `composer.json composer.lock`.

**Version verification:** Versions taken from `.planning/codebase/STACK.md` (which read the committed lockfiles) and confirmed `guzzlehttp/promises ^2.3` directly in `gateway/composer.lock`. No registry lookup needed — the stack is pinned and reproducible by design; do NOT bump versions in Phase 2.

## Architecture Patterns

### Project structure touched in Phase 2
```
db/
├── 01-schema-profile.sql          # EXTEND: add 4 columns + 3 tables (for fresh-volume init)
├── 02-migrate-phase2.sql          # NEW: idempotent live-volume migration (the critical one)
└── 99-seed.sql / 02-seed-phase2.sql  # EXTEND or NEW: sample exp/edu/skills for 5 demo accounts

services/profile-service/src/
├── routes.php                     # ADD mirror routes /users/{id}/full|experience|education|skills
├── Controllers/UserController.php # EXTEND update() w/ 4 fields; ADD full(); 
└── Controllers/ProfileController.php  # NEW (optional): experience/education/skills CRUD methods

gateway/src/
├── routes.php                     # ADD /profiles/{id}/full (public+optional-auth) + /profiles/me/* (JWT)
├── public/index.php               # ADD DI: AggregateController + ConnectionClient already wired; extend ProfileClient methods
├── Controllers/AggregateController.php  # NEW (re-introduced): profileFull composition + degrade
├── Controllers/ProfilesController.php   # EXTEND: CRUD passthrough for /me/* 
├── Services/ProfileClient.php     # ADD getFull/experience/education/skills + *Async variants
├── Services/ConnectionClient.php  # ADD statusFor(viewerId,targetId) + statusForAsync
└── Middleware/OptionalJwtMiddleware.php  # NEW: optional-auth variant for /full (see Pattern 4)

web/
├── profile.html                   # NEW: view via /full
├── profile-edit.html              # NEW: edit via /me/*
├── index.html                     # EXTEND: link to own profile after login
└── assets/app.js                  # EXTEND: profile loaders (optional)

scripts/
├── deploy.sh                      # EXTEND: apply db/02-migrate-phase2.sql after Phase-1 migration step
└── smoke-phase2.sh                # NEW: composition + CRUD + owner-scope + public-view smoke
```

### Pattern 1: Composition + degrade (THE flagship — re-introduce from git history)
**What:** Gateway calls ≥2 services in parallel, merges into one response, degrades non-core parts instead of failing.
**When to use:** `GET /api/profiles/{id}/full`.
**Canonical source:** `git show 2f6ecf8:gateway/src/Controllers/AggregateController.php` (the retired `postFull`). Phase 2 clones its skeleton. The exact, verified shape:

```php
// Source: VERIFIED — git 2f6ecf8:gateway/src/Controllers/AggregateController.php (adapted)
use GuzzleHttp\Promise\Utils;

public function profileFull(Request $req, Response $res, array $args): Response
{
    $targetId = (int) $args['id'];
    $viewerId = (int) ($req->getAttribute('user_id') ?? 0); // 0 when no/invalid token (D-04)

    // Step 1 — profile-full is the CORE/spine. 404 => hard 404 immediately.
    $profileRes = $this->profiles->getFull($targetId);
    if ($profileRes->getStatusCode() === 404) {
        throw new DomainError(404, 'PROFILE_NOT_FOUND', 'Không tìm thấy hồ sơ.');
    }
    if ($profileRes->getStatusCode() !== 200) {
        return Json::raw($res, $this->decode($profileRes), $profileRes->getStatusCode());
    }
    $profile = (array) ($this->decode($profileRes)['data'] ?? []);

    // Step 2 — parallel fan-out across services. settle = no throw on rejection.
    $settled = Utils::settle([
        'connection' => $this->connections->statusForAsync($viewerId, $targetId),
    ])->wait();

    $degraded = [];

    // connection_status: viewer-relative when logged in (D-04). Stub 404 -> degrade.
    $connectionStatus = $viewerId > 0 ? 'none' : null; // default
    $c = $settled['connection'];
    if ($c['state'] === 'fulfilled' && $c['value']->getStatusCode() === 200) {
        $connectionStatus = (string) ($this->decode($c['value'])['data']['status'] ?? 'none');
    } else {
        // Phase 2: connection-service is a stub (only /health) so this ALWAYS
        // degrades. That is the POINT (D-03) — proves degrade works now; Phase 3
        // lights it up with no gateway change.
        $degraded[] = 'connection';
    }

    $profile['connection_status'] = $connectionStatus;

    $body = ['data' => $profile];
    if ($degraded !== []) {
        $body['meta'] = ['degraded' => true, 'parts' => $degraded];
    }
    $res->getBody()->write(json_encode($body, JSON_UNESCAPED_UNICODE));
    return $res->withHeader('Content-Type', 'application/json; charset=utf-8');
}
```

**Note on showcasing parallelism:** with only one async call in the settle array the "parallel" story is thin. To keep the flagship demonstrably a parallel fan-out (criterion #4 + presentation value), the planner has two legitimate options:
- **(A) Keep profile-full synchronous (Step 1) as the spine, connection async.** Simplest; honest (the spine must resolve first anyway). The "parallel" claim is weaker but the degrade demo is intact.
- **(B) Make BOTH profile-full and connection async in the settle array, then treat profile-full as the hard dependency after settle** (404 on the profile promise → 404). This is closer to the git-history pattern (it settled author+comments in parallel) and gives a genuine 2-way parallel fan-out for the slides. RECOMMENDED for showcase value, still simple.

[VERIFIED: pattern from git history; degrade contract from ARCHITECTURE.md "Degraded-mode contract"]

### Pattern 2: Registering a new gateway controller + route + DI
**What:** Wire `AggregateController` into the Slim app.
**Source:** `gateway/public/index.php` (verified) — DI is explicit closures, not autowiring.

```php
// Source: VERIFIED — gateway/public/index.php pattern (lines 37-54)
// In public/index.php, after the existing $container->set(...) calls:
$container->set(AggregateController::class, fn(Container $c) => new AggregateController(
    $c->get(ProfileClient::class),
    $c->get(ConnectionClient::class),
));
// ProfilesController already wired (line 54); if you add CRUD passthrough that
// also needs ConnectionClient or nothing extra, adjust its factory similarly.
```
```php
// Source: VERIFIED — gateway/src/routes.php pattern (lines 15-25)
// Inside the $app->group('/api', ...) closure:
$g->get('/profiles/{id:[0-9]+}/full', [AggregateController::class, 'profileFull'])
    ->add($optionalJwtMw);   // optional auth (Pattern 4)

// CRUD — JWT REQUIRED (mutations), mapped to X-User-Id (D-10/D-11):
$g->patch ('/profiles/me',                  [ProfilesController::class, 'updateBasic'])->add($jwtMw);
$g->post  ('/profiles/me/experience',       [ProfilesController::class, 'addExperience'])->add($jwtMw);
$g->patch ('/profiles/me/experience/{eid:[0-9]+}', [ProfilesController::class, 'updateExperience'])->add($jwtMw);
$g->delete('/profiles/me/experience/{eid:[0-9]+}', [ProfilesController::class, 'deleteExperience'])->add($jwtMw);
// ...education + skills analogous. Skills = POST + DELETE only (D-10).
```
**CRITICAL route-ordering gotcha:** `/profiles/me` must be registered so it does NOT collide with `/profiles/{id:[0-9]+}`. Because the existing show route is constrained `{id:[0-9]+}`, the literal segment `me` will never match the numeric regex — so `/profiles/me` is safe. Keep the `[0-9]+` constraint on all `{id}` profile routes. [VERIFIED: existing route uses `{id:[0-9]+}` in routes.php line 24]

### Pattern 3: Adding *Async client methods for fan-out
**Source:** `gateway/src/Services/ProfileClient.php` (verified — already has sync+async pairs).

```php
// Source: VERIFIED — ProfileClient.php getAsync pattern (lines 30-37)
// ProfileClient additions:
public function getFull(int $id): ResponseInterface {
    return $this->http->request('GET', '/users/' . $id . '/full');
}
public function getFullAsync(int $id): PromiseInterface {
    return $this->http->requestAsync('GET', '/users/' . $id . '/full');
}
// Mutating calls inject X-User-Id (mirror the existing update() at lines 76-85):
public function addExperience(int $userId, array $body): ResponseInterface {
    return $this->http->request('POST', '/users/' . $userId . '/experience', [
        'json' => $body,
        'headers' => ['Content-Type' => 'application/json', 'X-User-Id' => (string) $userId],
    ]);
}
```
```php
// ConnectionClient additions (currently health-only, lines 20-27):
public function statusForAsync(int $viewerId, int $targetId): PromiseInterface {
    // Phase 2: connection-service only exposes /health → this path 404s → degrade.
    // Phase 3 implements GET /connections/status; gateway code DOES NOT CHANGE (D-03).
    return $this->http->requestAsync('GET', '/connections/status', [
        'query' => ['viewer' => $viewerId, 'target' => $targetId],
    ]);
}
```
`X-Request-Id` is forwarded automatically by `HttpClient::create` (reads the static set by `RequestIdMiddleware`) — no extra work. [VERIFIED: HttpClient.php lines 41-54 + RequestIdMiddleware.php]

### Pattern 4: Optional-auth (auth-aware public route) — D-04
**The gap:** the existing `JwtAuthMiddleware` is REQUIRED-auth: it throws `401 UNAUTHORIZED` when the `Authorization` header is missing or invalid (lines 21-23, 26-29). It cannot be reused for `/full`, which must serve anonymous viewers.
**Recommended solution:** a NEW small `OptionalJwtMiddleware` that mirrors `JwtAuthMiddleware` but:
- If no `Authorization` header → set `user_id = 0` (or leave unset) and `$handler->handle()` (no throw).
- If a Bearer token IS present and VALID → set `user_id` attribute as today.
- If a token is present but INVALID/expired → choice: either treat as anonymous (set `user_id=0`) OR `401`. RECOMMEND treat-as-anonymous for `/full` (a stale token should still let you view a public profile), but document the choice. The controller reads `(int)($req->getAttribute('user_id') ?? 0)` and branches: `0` ⇒ `connection_status: null`; `>0` ⇒ viewer-relative status.

```php
// NEW gateway/src/Middleware/OptionalJwtMiddleware.php (clone of JwtAuthMiddleware)
public function process(Request $request, Handler $handler): Response {
    $auth = $request->getHeaderLine('Authorization');
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        try {
            $payload = JWT::decode($m[1], new Key($this->secret, 'HS256'));
            $uid = isset($payload->sub) ? (int) $payload->sub : 0;
            if ($uid > 0) {
                $request = $request->withAttribute('user_id', $uid);
                if (isset($payload->username)) {
                    $request = $request->withAttribute('username', (string) $payload->username);
                }
            }
        } catch (\Throwable) { /* invalid token => treat as anonymous */ }
    }
    return $handler->handle($request);
}
```
Wire it in `public/index.php` as a singleton (`fn() => new OptionalJwtMiddleware($jwtSecret)`) exactly like `JwtAuthMiddleware` (line 55), and `->add()` it only on `/profiles/{id}/full`. [VERIFIED: JwtAuthMiddleware.php read in full; DI pattern from index.php line 55]

### Pattern 5: profile-service intra-service assembly of `/users/{id}/full`
**What:** profile-service assembles basic + experience + education + skills in ONE endpoint (its own bounded context), so the gateway composes ACROSS services, not across profile-service internals.
**Source:** raw-PDO pattern from `UserController.php` + `Db.php` (verified — prepared statements, `FETCH_ASSOC`).
```php
// profile-service: GET /users/{id}/full
public function full(Request $req, Response $res, array $args): Response {
    $id = (int) $args['id'];
    $user = $this->find($id);                      // existing private find() — extend its SELECT
    if ($user === null) throw new DomainError(404, 'USER_NOT_FOUND', 'Không tìm thấy người dùng.');
    $pdo = Db::pdo();
    $exp = $pdo->prepare('SELECT id,company,title,start_date,end_date,description FROM experience WHERE user_id=:u ORDER BY start_date DESC');
    $exp->execute([':u' => $id]);
    $edu = $pdo->prepare('SELECT id,school,degree,field,start_year,end_year FROM education WHERE user_id=:u ORDER BY start_year DESC');
    $edu->execute([':u' => $id]);
    $sk  = $pdo->prepare('SELECT id,name FROM skills WHERE user_id=:u ORDER BY name');
    $sk->execute([':u' => $id]);
    $user['experience'] = $exp->fetchAll();
    $user['education']  = $edu->fetchAll();
    $user['skills']     = $sk->fetchAll();
    return Json::ok($res, $user);   // full profile is OK to expose here (internal); gateway decides public trimming
}
```
**IMPORTANT — `find()` currently selects `email`** (UserController.php line 212). The gateway `/full` is PUBLIC, so the assembled body must NOT leak `email`. Two safe options: (A) profile-service `full()` SELECTs a public column set (no `email`, no `password_hash`); or (B) gateway `AggregateController` trims to an allowlist before emitting (mirroring the existing `ProfilesController::show` allowlist at lines 31-36). RECOMMEND (A) plus (B) defense-in-depth: profile-service `/full` returns no `email`/`password_hash`, AND gateway keeps an allowlist of profile fields. [VERIFIED: ProfilesController.php allowlist pattern lines 31-36; find() leaks email line 212]

### Pattern 6: Owner-scoped writes (D-11) — already proven
profile-service trusts `X-User-Id` and enforces ownership. The exact precedent is `UserController::update` (lines 168-172):
```php
$callerId = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
if ($callerId === 0 || $callerId !== $id) {
    throw new DomainError(403, 'FORBIDDEN', 'Bạn chỉ có thể chỉnh sửa hồ sơ của chính mình.');
}
```
For experience/education/skills the rule is: the `user_id` of the row being mutated MUST equal `X-User-Id`. For POST: insert with `user_id = X-User-Id` (never from body). For PATCH/DELETE by `{eid}`: `WHERE id=:eid AND user_id=:caller` (so a mismatched owner affects 0 rows → return 404/403). Gateway maps `/me` → the JWT `user_id` and forwards it as `X-User-Id` and as the path id (`/users/{X-User-Id}/...`). [VERIFIED: UserController::update lines 165-207]

### Anti-Patterns to Avoid
- **Using `Utils::unwrap` or `Promise::all` for composition** — they throw on rejection and kill the degrade story. Use `settle`. [VERIFIED]
- **Passing the profile-service `/full` body through verbatim on the public route** — leaks `email`/`password_hash`. Allowlist or select-public-columns. [VERIFIED: find() leaks email]
- **Trusting `user_id` from the request body for writes** — IDOR. Always scope by `X-User-Id` (D-11). [CITED: CONTEXT D-11]
- **Adding columns/tables only to `db/01-schema-profile.sql`** — that file runs ONLY on a fresh volume; the LIVE VPS volume will not pick it up. You MUST also ship an idempotent live migration applied via deploy. [VERIFIED: db/00-init.sh comment lines 1-15 + migrate-phase1.sql.tmpl header]
- **Naming the live migration `*.sql.tmpl` if it has no envsubst placeholders** — the `.tmpl` trick exists ONLY because Phase 1's file carried `${...}` secrets and had to be kept out of the initdb glob. Phase 2's migration has no secrets, so a plain `db/02-migrate-phase2.sql` is fine — BUT do not let it auto-run with surprising side effects on fresh init; keep it idempotent (`ADD COLUMN IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`). See Pitfall 1 for the naming decision. [VERIFIED: migrate-phase1.sql.tmpl header lines 18-25]

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Parallel HTTP fan-out | `curl_multi` / manual threads | `GuzzleHttp\Promise\Utils::settle([...])->wait()` | Already installed (2.3.0), already the proven pattern. [VERIFIED] |
| Per-promise success/failure handling | try/catch around each await | `settle` returns `{state:'fulfilled'|'rejected', value|reason}` | Built for exactly this degrade case. [VERIFIED] |
| JWT verify/sign | custom HMAC | `firebase/php-jwt` (gateway only) | Already used by AuthController + JwtAuthMiddleware. [VERIFIED] |
| Optional auth | re-parse token in controller | `OptionalJwtMiddleware` (one small clone of JwtAuthMiddleware) | Keeps controllers clean; matches the per-route `->add()` middleware pattern. [VERIFIED] |
| SQL injection safety | string interpolation | PDO prepared statements (`ATTR_EMULATE_PREPARES=false`) | Db.php already enforces real prepared statements; reuse it. [VERIFIED: Db.php lines 28-32] |
| Error envelope | ad-hoc JSON | `DomainError` + `JsonErrorHandler` (`{error:{code,message}}` VN) | Already mirrored in gateway + profile-service. [VERIFIED] |
| Request correlation | manual header plumbing | `HttpClient::create` auto-forwards `X-Request-Id` | Already wired via RequestIdMiddleware static. [VERIFIED] |

**Key insight:** Phase 2 introduces ZERO genuinely-new infrastructure. Every primitive (settle, typed async clients, X-User-Id ownership, error envelope, idempotent live migration, smoke-test gating) already shipped in Phase 1. The risk is in *reusing the patterns correctly*, not in choosing technology.

## Runtime State Inventory

This phase is brownfield with a LIVE deployed stack — runtime state matters.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | **LIVE DB `proconnect_profile.users` on VPS** has 5 demo rows + real schema (no `cover_url/headline/location/about`, no experience/education/skills tables). Local schema file does NOT reflect what's running. | **Data migration** (idempotent `ALTER TABLE`/`CREATE TABLE` against the live volume) — NOT just a code edit. Plus optional seed of sample exp/edu/skills for the 5 demo rows. |
| Live service config | connection-service on VPS is a STUB exposing only `/health` (verified `services/connection-service/src/routes.php` = health only). The `/connections/status` call WILL 404. | None — this is intentional (D-03). The 404 IS the degrade trigger. No config change. Document in smoke test that `meta.degraded` is EXPECTED in Phase 2. |
| OS-registered state | None — no Task Scheduler / cron / launchd referencing profile fields. Deploy is GitHub Actions → `scripts/deploy.sh`. | None. |
| Secrets/env vars | No new secrets. `PROFILE_SERVICE_URL` + `CONNECTION_SERVICE_URL` already injected into gateway (docker-compose.yml lines 99-100). No new `*_SVC_DB_PASS` (reuse `profile_svc`; new tables live in the same `proconnect_profile` schema the existing grant already covers — `GRANT ALL ON proconnect_profile.*`). | None — verified the existing grant is schema-wide so new tables are covered automatically. |
| Build artifacts | profile-service `vendor/` is committed/built; no new composer deps means no rebuild needed for dependencies. Gateway image rebuilds on deploy (`docker compose build --pull`) so new PHP files ship. `web/` is bind-mounted — `deploy.sh` restarts the web container only when `web/` changed (lines 43-51, 136-139). | New `.php` files ship via image rebuild (automatic). New `web/*.html` ship via bind mount + conditional restart (automatic, already handled). No manual step. |

**The canonical question — after every repo file is updated, what runtime state still has the old shape?** Answer: the **live MariaDB volume**. Its `users` table lacks the 4 new columns and the 3 new tables. A repo-only schema edit will deploy green but the endpoints will 500 on `SELECT cover_url ...` / `SELECT ... FROM experience`. The idempotent live migration (Pitfall 1) is the ONLY thing that fixes this, and it must be wired into `deploy.sh`.

## Common Pitfalls

### Pitfall 1: Schema changes invisible on the live volume (THE big one)
**What goes wrong:** You add columns + tables to `db/01-schema-profile.sql`, deploy, and the live endpoints 500 with "Unknown column 'cover_url'" / "Table 'experience' doesn't exist".
**Why it happens:** MariaDB's official image runs `/docker-entrypoint-initdb.d/*` (the `db/*.sql` + `00-init.sh`) ONLY on a FRESH volume. The VPS volume already exists, so those files are silently skipped. This is the EXACT pitfall Phase 1 hit and solved. [VERIFIED: db/00-init.sh lines 1-15; migrate-phase1.sql.tmpl header]
**How to avoid:**
1. Write `db/02-migrate-phase2.sql` — idempotent:
   - `ALTER TABLE users ADD COLUMN IF NOT EXISTS cover_url VARCHAR(512) NULL`, ... (4 columns). MariaDB 10.11 supports `ADD COLUMN IF NOT EXISTS`. [CITED: MariaDB 10.11 supports `IF NOT EXISTS` on `ALTER TABLE ADD COLUMN` — confirm during impl with `SELECT VERSION()`; ASSUMED stable since MariaDB 10.0.2]
   - `CREATE TABLE IF NOT EXISTS experience (...)`, `education`, `skills` (with `UNIQUE(user_id,name)`).
2. ALSO update `db/01-schema-profile.sql` (and the Phase-1 cutover `migrate-phase1.sql.tmpl`'s users definition, if you want fresh+migrated environments to converge) so a from-scratch `docker compose up` on a fresh volume produces the SAME final schema. Keep the two in sync.
3. Wire into `scripts/deploy.sh` AFTER the existing Phase-1 migration step (after line 124, before/with full-topology up at line 130), applied to the running container:
   ```bash
   echo "[deploy] applying db/02-migrate-phase2.sql"
   docker compose exec -T mariadb mysql -uroot -p"$DB_ROOT_PASSWORD" proconnect_profile < db/02-migrate-phase2.sql
   ```
   No envsubst needed (no placeholders) → so a plain `.sql` is correct, NOT `.sql.tmpl`. (The `.tmpl` extension in Phase 1 existed solely to keep secret placeholders out of the initdb glob.)
**Warning signs:** local fresh-volume build works; VPS deploy is green but `/api/profiles/2/full` returns 500.
**Confidence:** HIGH — directly mirrors the documented Phase-1 resolution.

### Pitfall 2: `email` leak on the public `/full` route
**What goes wrong:** profile-service `find()` selects `email`; if `/full` passes it through, the public composition endpoint leaks every user's email.
**Why:** `UserController::find()` SELECT includes `email` (line 212). Phase 1 explicitly guarded `/api/profiles/{id}` with an allowlist (ProfilesController lines 31-36) for this reason.
**How to avoid:** profile-service `/users/{id}/full` SELECTs only public columns (no `email`, no `password_hash`); AND gateway keeps an allowlist. Defense in depth. [VERIFIED]
**Warning signs:** smoke test should assert `/api/profiles/2/full` body does NOT contain `@` / `email`.

### Pitfall 3: `settle` mis-read as throwing / wrong state key
**What goes wrong:** treating a rejected promise as an exception, or reading `$settled['x']->getStatusCode()` directly (it's `$settled['x']['value']->getStatusCode()`).
**Why:** `settle` returns an array `['state'=>'fulfilled'|'rejected', 'value'=>Response | 'reason'=>Throwable]`. A network failure → `state='rejected'` (no `value`). A 404/500 → `state='fulfilled'` with a `value` whose status is 404/500.
**How to avoid:** check `state === 'fulfilled' && value->getStatusCode() === 200` before reading `data`; everything else → degrade. (This is exactly the git-history code.) [VERIFIED]

### Pitfall 4: Optional-auth route accidentally requiring auth
**What goes wrong:** attaching the existing `JwtAuthMiddleware` to `/full` → anonymous viewers get 401, breaking PROF-06/D-04.
**How to avoid:** use the NEW `OptionalJwtMiddleware` (Pattern 4) on `/full`; keep `JwtAuthMiddleware` on the `/me/*` mutations. [VERIFIED: JwtAuthMiddleware throws on missing header]
**Warning signs:** smoke test must hit `/full` with NO token and expect 200.

### Pitfall 5: Route collision `/profiles/me` vs `/profiles/{id}`
**What goes wrong:** if a `{id}` route were unconstrained, `me` could match it.
**How to avoid:** all `{id}` profile routes keep the `:[0-9]+` constraint (already the case) so the literal `me` segment routes to the CRUD handlers. Register `/profiles/me*` routes regardless of order — the numeric constraint disambiguates. [VERIFIED: routes.php line 24 uses `{id:[0-9]+}`]

### Pitfall 6: Date handling for experience/education
**What goes wrong:** `start_date DATE`, `end_date DATE NULL` (`NULL`="hiện tại") — sending `""` instead of `NULL`, or `YYYY` instead of `YYYY-MM-DD`, causes SQL errors or silent bad data.
**How to avoid:** profile-service validates: `start_date` required `YYYY-MM-DD`; `end_date` either valid date or explicit null; `start_year`/`end_year` `SMALLINT` (validate 1900–2100). Mirror the existing validation style in `UserController` (lines 40-54, 182-197). Return `400 VALIDATION_FAILED` with VN message. [VERIFIED: validation pattern exists]

## Code Examples

### Idempotent live migration (db/02-migrate-phase2.sql)
```sql
-- Source: VERIFIED pattern from db/migrate-phase1.sql.tmpl (idempotent, IF NOT EXISTS)
USE proconnect_profile;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS cover_url VARCHAR(512) NULL AFTER avatar_url,
  ADD COLUMN IF NOT EXISTS headline  VARCHAR(160) NULL,
  ADD COLUMN IF NOT EXISTS location  VARCHAR(128) NULL,
  ADD COLUMN IF NOT EXISTS about     TEXT NULL;

CREATE TABLE IF NOT EXISTS experience (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  company VARCHAR(160) NOT NULL,
  title   VARCHAR(160) NOT NULL,
  start_date DATE NOT NULL,
  end_date   DATE NULL,
  description TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_exp_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS education (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  school VARCHAR(160) NOT NULL,
  degree VARCHAR(160) NULL,
  field  VARCHAR(160) NULL,
  start_year SMALLINT NULL,
  end_year   SMALLINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_edu_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS skills (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_skill_user_name (user_id, name),
  INDEX idx_skill_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
[NOTE: `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` is MariaDB-specific — VERIFIED supported MariaDB ≥10.0.2; the deploy targets MariaDB 10.11 so this is safe. Confirm `SELECT VERSION()` during impl.]

### Skills duplicate handling (profile-service)
```php
// POST /users/{id}/skills — scope by X-User-Id; 409 on duplicate (UNIQUE).
$name = trim((string)($b['name'] ?? ''));
if ($name === '' || mb_strlen($name) > 80)
    throw new DomainError(400, 'VALIDATION_FAILED', 'Tên kỹ năng từ 1-80 ký tự.');
try {
    $stmt = Db::pdo()->prepare('INSERT INTO skills (user_id, name) VALUES (:u, :n)');
    $stmt->execute([':u' => $callerId, ':n' => $name]);
} catch (\PDOException $e) {
    if ($e->getCode() === '23000')  // duplicate key
        throw new DomainError(409, 'SKILL_EXISTS', 'Kỹ năng đã tồn tại.');
    throw $e;
}
```
[VERIFIED: USER_EXISTS 409 precedent in UserController create lines 57-61; PDO 23000 is the standard SQLSTATE for integrity violation]

### Frontend: consume /full (profile.html) using existing app.js helpers
```js
// Source: VERIFIED — web/assets/app.js api wrapper (lines 64-69)
// /api/profiles/{id}/full is public; api.get already attaches Bearer if present,
// which makes connection_status viewer-relative when logged in (D-04).
async function loadFull(id) {
  const r = await api.get('/profiles/' + id + '/full'); // {data:{...}, meta?:{degraded}}
  return { profile: r.data, degraded: r.meta && r.meta.degraded };
}
```

## State of the Art

| Old Approach (Phase 1 / blog) | Current Approach (Phase 2) | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `AggregateController::postFull` (post+author+comments) | RETIRED in commit `79661cf`; re-introduce as `profileFull` (profile+connection) | Phase 1 retire / Phase 2 re-add | The settle+degrade pattern is recovered verbatim from git `2f6ecf8`. |
| `/api/profiles/{id}` basic only (allowlist {id,username,display_name}) | + `/api/profiles/{id}/full` composition | Phase 2 | The basic route stays (public, trimmed); `/full` is the new flagship. |
| profile = `users` table, 6 columns | + 4 columns + 3 child tables | Phase 2 | Live-DB migration required (Pitfall 1). |
| Required-auth only (`JwtAuthMiddleware`) | + optional-auth (`OptionalJwtMiddleware`) for public+aware routes | Phase 2 | New middleware; small clone. |

**Deprecated/outdated:** none. The stack is pinned; nothing in Phase 1 is being replaced, only extended.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | MariaDB 10.11 supports `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` and the deployed instance is 10.11 | Pitfall 1 / Code Examples | LOW — `IF NOT EXISTS` on ADD COLUMN is MariaDB-supported since 10.0.2; compose pins `mariadb:10.11` per ARCHITECTURE/CLAUDE.md. Mitigation: `SELECT VERSION()` on VPS before relying on it; fallback = guard with `information_schema.columns` check or accept that a re-run errors (then make the deploy step tolerant). |
| A2 | Phase 3 will implement `GET /connections/status?viewer=&target=` returning `{data:{status}}` | Pattern 1/3 | LOW — the exact internal route/param names are Claude's discretion (CONTEXT). As long as Phase 2 picks names and ConnectionClient encodes them, Phase 3 just implements the same contract. If Phase 3 picks different names, only ConnectionClient changes, not AggregateController. |
| A3 | The live `profile_svc` grant (`GRANT ALL ON proconnect_profile.*`) covers new tables automatically | Runtime State Inventory | LOW — schema-wide grant verified in `db/00-init.sh` line 41 + migrate-phase1.sql.tmpl line 64; `.*` covers tables created later. |
| A4 | New `.php` files ship to VPS via `docker compose build` (gateway + profile-service images rebuild on deploy) and need no manual step | Runtime State Inventory | LOW — `deploy.sh` line 55 runs `docker compose build --pull`; services build from `./services/...` context. Verify Dockerfile COPYs `src/` (it does — STACK.md). |
| A5 | Treating an invalid/expired token as anonymous on `/full` (vs 401) is acceptable | Pattern 4 | LOW/PRODUCT — this is a UX choice; flag to user/Codex at plan review. Either behavior satisfies D-04 (which only specifies token-present-and-valid vs absent). |

## Open Questions (RESOLVED)

All three resolved during planning; each is implemented in a Phase 2 plan (see inline markers).


1. **Showcase: one async call vs two in the settle array (Pattern 1 note A vs B).**
   - What we know: D-01 says "parallel ... ≥2 sources". With profile-full as a synchronous spine and only connection async, the settle array has one entry — technically still composition+degrade, but a weaker "parallel" story.
   - What's unclear: whether the team wants the slide to show a genuine 2-way parallel fan-out.
   - Recommendation: Option B (both profile-full and connection in the settle array, profile-full treated as hard dep after settle). Slightly more code, much better presentation, still simple. Confirm at `/codex-plan-review`. **RESOLVED: implemented in Plan 03 Task 2** — AggregateController::profileFull puts BOTH getFullAsync + statusForAsync in the Utils::settle array (genuine 2-way parallel fan-out), profile treated as hard dep after settle.

2. **Seed sample data for the 5 demo accounts (CONTEXT "Specific Ideas").**
   - What we know: CONTEXT suggests seeding sample exp/edu/skills so the demo has content.
   - What's unclear: whether to extend `db/99-seed.sql` (fresh-volume only) or ship a separate idempotent `db/02-seed-phase2.sql` applied on the live volume like the migration.
   - Recommendation: ship a SEPARATE idempotent seed (`INSERT ... WHERE NOT EXISTS` or `INSERT IGNORE` keyed on a deterministic id) applied via the same deploy step, so the LIVE demo gets content. Pure `99-seed.sql` edits won't appear on the existing volume (same Pitfall-1 reason). Keep it small (a few rows for `duyet`/`demo`). **RESOLVED: implemented in Plan 01 Task 1** — idempotent demo seed (INSERT ... WHERE NOT EXISTS / guarded UPDATE on deterministic ids) lives inside db/02-migrate-phase2.sql, applied to the live volume via the same deploy step; db/99-seed.sql mirrors it (INSERT IGNORE) for fresh volumes.

3. **Should `/api/profiles/{id}/full` for a logged-in viewer also support `/api/profiles/me/full` convenience?**
   - What we know: D-10 uses `/me` for writes; reads use `/{id}/full`.
   - Recommendation: not required. The UI resolves own id via `/api/me` then calls `/{id}/full`. Skip `/me/full` to keep surface minimal unless trivial. **RESOLVED: implemented in Plan 04** — profile.html/profile-edit.html resolve own id via `/api/me` then call `/{id}/full`; no `/me/full` route was added (surface kept minimal).

## Environment Availability

| Dependency | Required By | Available (local) | Version | Fallback |
|------------|------------|-------------------|---------|----------|
| Docker / docker compose | Runtime + smoke tests | ✗ (Docker NOT available locally) | — | Verify statically locally (`php -l`, code review); runtime verification on VPS after deploy (this is the canonical method per Phase 1 VALIDATION). |
| PHP CLI (`php -l` lint) | CI lint gate | likely ✓ on CI | 8.2 | CI runs `php -l` over all `*.php` (deploy.yml). |
| MariaDB 10.11 | Migration + queries | ✗ locally (in container on VPS) | 10.11 | Apply/verify migration on VPS via `docker compose exec mariadb mysql`. |
| envsubst (gettext-base) | Phase-1 migration only | n/a | — | Phase 2 migration needs NO envsubst (plain `.sql`). |
| curl + bash | smoke-phase2.sh | ✓ | — | — |

**Missing dependencies with no fallback:** none — Docker-local absence is handled by the established VPS-runtime verification method (Phase 1 precedent).
**Missing dependencies with fallback:** Docker (→ verify on VPS); MariaDB (→ on VPS).

## Validation Architecture

`workflow.nyquist_validation = true` (config.json) → section included. Phase 1 established smoke-test (bash/curl) validation with NO PHPUnit; Phase 2 stays consistent (CLAUDE.md: keep simple, no new heavy deps).

### Test Framework
| Property | Value |
|----------|-------|
| Framework | bash + curl smoke test (no PHP test runner — consistent with Phase 1) [VERIFIED: STACK.md "None detected", smoke-phase1.sh] |
| Config file | none — `scripts/smoke-phase1.sh` is the template |
| Quick run command | `bash scripts/smoke-phase2.sh` (against running stack: VPS, or local if Docker available) |
| Full suite command | `bash scripts/smoke-phase1.sh && bash scripts/smoke-phase2.sh` (Phase 2 must not regress Phase 1) |
| Static gate (local, always) | `php -l` over changed `*.php` (CI lint job mirrors this) |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command / Assertion | File Exists? |
|--------|----------|-----------|-------------------------------|--------------|
| PROF-07 | `/full` returns composed sections + degrades | smoke | `curl $GW/api/profiles/2/full` body has `experience`,`education`,`skills`,`connection_status` AND `meta.degraded:true` (connection stub) | ❌ Wave 0 (smoke-phase2.sh) |
| PROF-06 | public view w/o token | smoke | `curl` (no Authorization) `$GW/api/profiles/2/full` → HTTP 200, `connection_status:null` | ❌ Wave 0 |
| PROF-04 (D-04) | auth-aware status | smoke | with valid token → `connection_status:"none"` (not null) | ❌ Wave 0 |
| PROF-02 | edit basic | smoke | login → `PATCH /api/profiles/me {headline,location,about,cover_url}` → 200 → appears in `/full` | ❌ Wave 0 |
| PROF-03 | experience CRUD round-trip | smoke | `POST /api/profiles/me/experience` → id → appears in `/full` → `DELETE .../experience/{id}` → gone from `/full` | ❌ Wave 0 |
| PROF-04 | education CRUD round-trip | smoke | same as PROF-03 for education | ❌ Wave 0 |
| PROF-05 | skills add/remove + dedupe | smoke | `POST /api/profiles/me/skills {name}` → in `/full`; POST same again → 409; `DELETE` → gone | ❌ Wave 0 |
| PROF-02..05 | owner-scoping (no IDOR) | smoke | login as user A, attempt to mutate via `/me` then verify a DIFFERENT user's `/full` unchanged; attempt direct profile-service-style id is not exposed at gateway (only `/me`) | ❌ Wave 0 |
| Security | no email leak on `/full` | smoke | `/full` body MUST NOT contain `@`/`email` | ❌ Wave 0 |
| Regression | Phase 1 still green | smoke | `bash scripts/smoke-phase1.sh` passes | ✅ exists |

### Sampling Rate
- **Per task commit:** `php -l` on changed files + `/codex-impl-review` (mandatory CLAUDE.md gate before commit).
- **Per wave merge / phase gate:** `bash scripts/smoke-phase1.sh && bash scripts/smoke-phase2.sh` green on the VPS-deployed stack (or local if Docker available).
- **Phase gate:** full smoke green before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `scripts/smoke-phase2.sh` — covers PROF-02..07 + email-leak + owner-scope + degrade assertions (clone `smoke-phase1.sh` structure: `pass`/`fail`/`FAILURES` + `GW` env).
- [ ] Confirm `db/02-migrate-phase2.sql` applied before any `/full` smoke runs (deploy step ordering — Pitfall 1).
- [ ] No framework install needed (bash/curl present).

## Security Domain

`security_enforcement` not explicitly false → included. Demo project, internal-network trust model — keep proportionate.

### Applicable ASVS Categories
| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | JWT HS256 at gateway (`firebase/php-jwt`), unchanged. Optional-auth variant for `/full`. [VERIFIED] |
| V3 Session Management | partial | Stateless JWT, 24h TTL (AuthController). No change. |
| V4 Access Control | **yes (key)** | Owner-only writes scoped by `X-User-Id` (D-11); `/me` routes prevent IDOR. profile-service `WHERE id=:eid AND user_id=:caller`. [VERIFIED precedent: UserController::update] |
| V5 Input Validation | yes | profile-service validates lengths, URL (`FILTER_VALIDATE_URL`), date formats, SMALLINT ranges. Mirror existing `UserController` validation. [VERIFIED] |
| V6 Cryptography | no new | No new crypto. Passwords already bcrypt; no new secrets. |
| V7 Data protection | yes | PUBLIC `/full` MUST NOT leak `email`/`password_hash` (Pitfall 2). Allowlist + select-public-columns. [VERIFIED] |

### Known Threat Patterns for PHP/Slim/MariaDB + Gateway
| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| IDOR (edit another user's profile via id) | Elevation/Tampering | `/me` routes only at gateway; profile-service scopes ALL writes by `X-User-Id`, never body `user_id` (D-11). [VERIFIED pattern] |
| SQL injection | Tampering | PDO prepared statements, `EMULATE_PREPARES=false`. Never interpolate. [VERIFIED: Db.php] |
| PII leak on public route | Information disclosure | Allowlist + public-column SELECT on `/full` (Pitfall 2). [VERIFIED] |
| Auth bypass on writes | Elevation | `JwtAuthMiddleware` (required) on every `/me/*` mutation; only `/full` uses optional auth. [VERIFIED] |
| Trusting X-User-Id from outside | Spoofing | Network isolation (services have no host port) + only gateway sets `X-User-Id`. Unchanged trust model. [VERIFIED: ARCHITECTURE.md] |
| Stub 404 mishandled as hard error | DoS / availability | Degrade via `settle` → `meta.degraded`, never 500 the whole `/full` (D-02/D-03). [VERIFIED] |

## Sources

### Primary (HIGH confidence — read directly this session)
- `git show 2f6ecf8:gateway/src/Controllers/AggregateController.php` + `PostsController.php` — canonical settle+degrade+enrich pattern (the spec for `profileFull`).
- `gateway/src/Services/{HttpClient,ProfileClient,ConnectionClient}.php`, `gateway/src/routes.php`, `gateway/public/index.php`, `gateway/src/Middleware/{JwtAuthMiddleware,RequestIdMiddleware}.php`, `gateway/src/Controllers/{ProfilesController,AuthController}.php`, `gateway/src/JsonErrorHandler.php`.
- `services/profile-service/src/{Controllers/UserController,routes,Db,Json,DomainError,JsonErrorHandler}.php`, `services/profile-service/public/index.php`.
- `services/connection-service/src/routes.php` (confirmed stub = `/health` only → `/connections/status` 404s → degrade).
- `db/{01-schema-profile.sql,99-seed.sql,migrate-phase1.sql.tmpl,00-init.sh}`, `scripts/{deploy.sh,smoke-phase1.sh}`.
- `web/{index.html,login.html,assets/app.js}`.
- `docker-compose.yml` (PROFILE/CONNECTION_SERVICE_URL injected, lines 99-100).
- `.planning/{REQUIREMENTS.md, codebase/ARCHITECTURE.md}`, both phase CONTEXT files, `01-VERIFICATION.md`, `.planning/config.json`.
- `gateway/composer.lock` — `guzzlehttp/promises ^2.3` confirmed.
- `CLAUDE.md` (project) + `~/.claude/CLAUDE.md` (review-workflow mandate).

### Secondary (MEDIUM)
- `.planning/codebase/STACK.md` — version pins (it read the lockfiles).

### Tertiary (LOW / to confirm at impl)
- MariaDB `ALTER TABLE ADD COLUMN IF NOT EXISTS` support on the deployed instance (A1) — confirm with `SELECT VERSION()`.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new deps; versions from committed lockfile.
- Architecture / composition pattern: HIGH — recovered verbatim from git history; every integration point read in source.
- Live migration strategy: HIGH — directly mirrors the documented, shipped Phase-1 solution.
- Pitfalls: HIGH — each is grounded in a specific verified file (init-glob, email leak, required-auth middleware).
- MariaDB DDL idempotency syntax (A1): MEDIUM — feature is standard but version should be confirmed on VPS.

**Research date:** 2026-06-06
**Valid until:** ~2026-07-06 (stable; the only volatile item is the live DB schema state, which Phase 2 itself changes — re-verify the VPS schema if Phase 2 implementation is delayed or other phases touch profile-service first).

## RESEARCH COMPLETE

**Phase:** 02 - Hồ sơ nghề nghiệp

### Key Findings
- The exact composition+degrade pattern (`GuzzleHttp\Promise\Utils::settle`) is recoverable verbatim from git `2f6ecf8:gateway/src/Controllers/AggregateController.php` — Phase 2 clones it as `profileFull(profile-full + connection-status)`. Connection-service is a `/health`-only stub, so `/connections/status` 404s and degrades to `connection_status:"none"` automatically (D-03), proving the degrade path now with zero Phase-3 gateway rework.
- THE critical risk is the live-DB migration: `db/*.sql` runs only on a fresh volume; the VPS volume already exists. Ship an idempotent `db/02-migrate-phase2.sql` (`ADD COLUMN IF NOT EXISTS` / `CREATE TABLE IF NOT EXISTS`) wired into `scripts/deploy.sh` after the Phase-1 step — and it needs NO envsubst (plain `.sql`, not `.sql.tmpl`).
- Auth-aware `/full` (D-04) requires a NEW `OptionalJwtMiddleware` because the existing `JwtAuthMiddleware` throws 401 on missing token. CRUD `/me/*` keep the required `JwtAuthMiddleware`.
- Owner-scoping, raw-PDO CRUD, error envelope, X-Request-Id forwarding, and the email-allowlist are all already proven in `UserController`/`ProfilesController` — reuse, don't reinvent. Public `/full` MUST allowlist out `email` (find() currently selects it).
- profile-service should assemble `/users/{id}/full` internally (its bounded context); the gateway composes ACROSS services (profile + connection) — the architecturally correct showcase boundary.

### File Created
`/Users/theduyet/Documents/Code/SOA/soa-blog/.planning/phases/02-h-s-ngh-nghi-p/02-RESEARCH.md`

### Confidence Assessment
| Area | Level | Reason |
|------|-------|--------|
| Standard Stack | HIGH | No new deps; versions from committed lockfile |
| Architecture / composition | HIGH | Recovered from git history; all integration points read in source |
| Live migration | HIGH | Mirrors shipped Phase-1 solution |
| Pitfalls | HIGH | Each grounded in a specific verified file |
| MariaDB DDL idempotency syntax | MEDIUM | Confirm `SELECT VERSION()` on VPS |

### Open Questions (RESOLVED)
1. Showcase: one async call vs two in the settle array (Option B — both async — for a genuine parallel fan-out slide). RESOLVED: implemented in Plan 03 Task 2 (Utils::settle over getFullAsync + statusForAsync).
2. Seed sample exp/edu/skills for demo accounts via a separate idempotent live seed (99-seed.sql won't reach the existing volume). RESOLVED: implemented in Plan 01 Task 1 (idempotent seed inside db/02-migrate-phase2.sql).
3. Optional `/me/full` convenience — skipped. RESOLVED: implemented in Plan 04 (resolve id via /api/me then /{id}/full; no /me/full route added).

### Ready for Planning
Research complete. Planner can create PLAN.md files. Remember the mandatory CLAUDE.md gates: `/codex-plan-review` before coding, `/codex-impl-review` before commit.
