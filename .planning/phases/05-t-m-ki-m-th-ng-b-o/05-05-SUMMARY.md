---
phase: 05-t-m-ki-m-th-ng-b-o
plan: 05
subsystem: gateway-notifications
tags: [gateway, notifications, best-effort, api-composition, D-05]
requires:
  - notification-service NotificationController (Plan 03): POST /notifications, GET /notifications, /read-all, /{id}/read
  - NotificationClient health singleton (Plan 01)
  - ConnectionClient::createRequest, FeedClient::getPost/react/addComment, ProfileClient::batch
provides:
  - gateway NotificationsController (list+unread_count+actor allowlist enrich, mark-read/all)
  - NotificationClient.create/list/markRead/markAllRead
  - best-effort notify hooks (invite/reaction/comment) wired into the gateway as central coordinator
affects:
  - gateway/src/Controllers/ConnectionsController.php (sendRequest now fires invite notify)
  - gateway/src/Controllers/FeedController.php (react/addComment now notify post author)
tech-stack:
  added: []
  patterns:
    - best-effort side-effect AFTER 2xx write inside swallowing try/catch GuzzleException (D-05)
    - recipient resolved authoritatively via getPost author_id, NOT the upstream actor row (Pitfall 2)
    - actor enrichment email-dropping allowlist {id,username,display_name,avatar_url}
    - literal route before parameterized (/read-all before /{id}/read)
key-files:
  created:
    - gateway/src/Controllers/NotificationsController.php
  modified:
    - gateway/src/Services/NotificationClient.php
    - gateway/src/Controllers/ConnectionsController.php
    - gateway/src/Controllers/FeedController.php
    - gateway/src/routes.php
    - gateway/public/index.php
decisions:
  - "notify fires ONLY on upstream 200/201; any non-2xx skips notify entirely (the main action passes through unchanged)"
  - "reaction/comment recipient = getPost(postId).author_id (authoritative), not the react/comment response which carries the actor — prevents self-notify-as-other and wrong-recipient (T-05-17)"
  - "ref_id: invite => created request id; reaction/comment => postId"
metrics:
  duration: ~4min
  tasks: 3
  files: 6
  completed: 2026-06-06
---

# Phase 5 Plan 5: Gateway Notifications + Best-Effort Coordination Summary

Wired the gateway as the central notification coordinator (NOTIF-01/02/03): a typed `NotificationClient` (create/list/markRead/markAllRead, X-User-Id on reads/marks), a recipient-scoped `NotificationsController` (newest-first list + passthrough `unread_count` + email-free actor enrichment + degrade-safe; mark-read/read-all passthroughs), and best-effort `notify()` hooks injected into the existing `ConnectionsController::sendRequest` (invite → addressee) and `FeedController::react`/`addComment` (reaction/comment → post author via `getPost`) — every notify firing only after the 2xx write, wrapped in a swallowing `try/catch GuzzleException` so a notification failure can never alter the main action (D-05).

## What Was Built

### Task 1 — NotificationClient + NotificationsController (c5cbd1e)
- `NotificationClient`: `create(recipient,actor,type,refId)` POST /notifications (gateway-trusted body), `list/markRead/markAllRead` inject `X-User-Id` (recipient scope). Health methods retained.
- `NotificationsController`:
  - `index`: scopes to `me()`→list; passes service `meta.unread_count` straight through; collects unique positive `actor_id`s → ONE `ProfileClient::batch`; actor card built via `array_intersect_key(..., array_flip(['id','username','display_name','avatar_url']))` (no email, T-05-19); profile-service non-200 or `GuzzleException` → `meta.degraded=true, parts=['profiles']`, actor:null (never a 500). On notification-service non-200 → raw passthrough.
  - `markRead`/`markAllRead`: thin `me()`-scoped passthroughs.

### Task 2 — Best-effort notify hooks (030a0e2)
- `ConnectionsController`: ctor now `(ProfileClient, ConnectionClient, NotificationClient)`. After `createRequest`, on 200/201 it reads the created request id and calls `notifyBestEffort(target, me, 'invite', refId)`. Helper skips self/invalid then `create()` inside `try/catch GuzzleException` (swallow). The return uses the captured `$createdCode` — the main response is byte-identical to before.
- `FeedController`: ctor now `(FeedClient, ProfileClient, ConnectionClient, NotificationClient)`. `react`/`addComment` capture `$code`, and on 2xx call `notifyPostAuthor(postId, me, 'reaction'|'comment')`. Helper resolves the recipient via `getPost($postId, 0).author_id` (authoritative — NOT the upstream response which carries the actor, Pitfall 2 / T-05-17), skips self, `create()` with `ref_id=postId`, all inside one swallowing `try/catch GuzzleException`. Non-2xx upstream → no notify, untouched passthrough.

### Task 3 — Routes + DI (21fc513)
- `routes.php`: `GET /notifications`, `POST /notifications/read-all` (registered BEFORE) , `POST /notifications/{id:[0-9]+}/read` — all `->add($jwtMw)`.
- `public/index.php`: `NotificationsController` factory `(NotificationClient, ProfileClient)`; `NotificationClient::class` added as the 3rd arg to the ConnectionsController factory and 4th arg to the FeedController factory (matching the new ctors). `NotificationClient` was already a registered singleton.

## Threat Model Coverage
- **T-05-16 (self-inflicted DoS):** notify + getPost run AFTER the 2xx write inside swallowing try/catch — a notify/getPost failure never changes the main action. Verified: `$createdCode`/`$code` captured before notify; return uses the captured code.
- **T-05-17 (wrong recipient):** reaction/comment recipient = `getPost.author_id`, skip-self (`authorId === actor`). Verified by grep.
- **T-05-18 (IDOR/info disclosure on list):** list scoped by `me()`→X-User-Id; notification-service enforces the WHERE.
- **T-05-19 (email leak in actor enrich):** allowlist via `array_intersect_key`; no `'email'` string literal in the controller (only prose).
- **T-05-20 (notify type tampering):** type is a gateway-controlled literal ('invite'/'reaction'/'comment'); notification-service re-validates the ENUM.

## Deviations from Plan
None — plan executed exactly as written.

## Verification
- `php -l` clean on all 6 changed/new files (PHP 8.5 local).
- grep/awk asserts passed: `notifyBestEffort`, `notifyPostAuthor`, `getPost($postId, 0)`, `recipient === $actor`, `authorId === $actor`, `unread_count`, actor allowlist (no `'email'` literal), `create`/`X-User-Id` in client, routes (`/notifications`, `/notifications/read-all` before `/{id}/read`), DI (NotificationClient in both factories + NotificationsController factory).
- X-Request-Id propagation intact (no middleware/HttpClient changes; all notify calls reuse the lazy per-request Guzzle clients).
- **Docker/runtime checks deferred to VPS/CI (Plan 07):** Docker not installed locally. Runtime smoke (smoke-phase5.sh) will exercise invite→notify, react/comment→post-author notify, best-effort-2xx-unchanged, list+unread+mark-read, and the no-PII guard.

## Known Stubs
None.

## Self-Check: PASSED
- FOUND: gateway/src/Controllers/NotificationsController.php
- FOUND: gateway/src/Services/NotificationClient.php (modified)
- FOUND: gateway/src/Controllers/ConnectionsController.php (modified)
- FOUND: gateway/src/Controllers/FeedController.php (modified)
- FOUND: gateway/src/routes.php (modified)
- FOUND: gateway/public/index.php (modified)
- FOUND commit: c5cbd1e (Task 1)
- FOUND commit: 030a0e2 (Task 2)
- FOUND commit: 21fc513 (Task 3)
