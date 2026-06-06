<?php
declare(strict_types=1);

use App\Controllers\AggregateController;
use App\Controllers\AuthController;
use App\Controllers\ConnectionsController;
use App\Controllers\FeedController;
use App\Controllers\HealthController;
use App\Controllers\ProfilesController;
use App\Controllers\SearchController;
use App\Middleware\JwtAuthMiddleware;
use App\Middleware\OptionalJwtMiddleware;
use Slim\App;

return static function (App $app): void {
    $container = $app->getContainer();
    $jwtMw     = $container?->get(JwtAuthMiddleware::class);
    $optMw     = $container?->get(OptionalJwtMiddleware::class);

    // Group callback cannot be static — Slim rebinds $this to the container
    $app->group('/api', function ($g) use ($jwtMw, $optMw) {
        $g->get ('/health', [HealthController::class, 'check']);

        // Auth
        $g->post('/auth/register', [AuthController::class, 'register']);
        $g->post('/auth/login',    [AuthController::class, 'login']);
        $g->get ('/me',            [AuthController::class, 'me'])->add($jwtMw);

        // FLAGSHIP composition — public + auth-aware (optional JWT).
        // Numeric {id} constraint keeps the literal `me` segment from colliding.
        $g->get ('/profiles/{id:[0-9]+}/full', [AggregateController::class, 'profileFull'])->add($optMw);

        // Owner-only CRUD via /me — JWT REQUIRED (maps JWT user_id -> X-User-Id)
        $g->patch ('/profiles/me',                          [ProfilesController::class, 'updateBasic'])->add($jwtMw);
        $g->post  ('/profiles/me/experience',               [ProfilesController::class, 'addExperience'])->add($jwtMw);
        $g->patch ('/profiles/me/experience/{eid:[0-9]+}',  [ProfilesController::class, 'updateExperience'])->add($jwtMw);
        $g->delete('/profiles/me/experience/{eid:[0-9]+}',  [ProfilesController::class, 'deleteExperience'])->add($jwtMw);
        $g->post  ('/profiles/me/education',                [ProfilesController::class, 'addEducation'])->add($jwtMw);
        $g->patch ('/profiles/me/education/{eid:[0-9]+}',   [ProfilesController::class, 'updateEducation'])->add($jwtMw);
        $g->delete('/profiles/me/education/{eid:[0-9]+}',   [ProfilesController::class, 'deleteEducation'])->add($jwtMw);
        $g->post  ('/profiles/me/skills',                   [ProfilesController::class, 'addSkill'])->add($jwtMw);
        $g->delete('/profiles/me/skills/{sid:[0-9]+}',      [ProfilesController::class, 'deleteSkill'])->add($jwtMw);

        // Profiles (D-07: public, whitelisted to {id,username,display_name})
        $g->get ('/profiles/{id:[0-9]+}', [ProfilesController::class, 'show']);

        // Connections / social graph (D-04, all me-relative, JWT REQUIRED).
        // Literal segments (`requests`/`suggestions`/`status`) + numeric {id}/{userId}
        // constraints keep FastRoute unambiguous (Pattern 6): DELETE /connections/{userId}
        // can never match the literal `requests`, so DELETE /connections/requests/{id} is safe.
        $g->post  ('/connections/requests',                    [ConnectionsController::class, 'sendRequest'])->add($jwtMw);
        $g->post  ('/connections/requests/{id:[0-9]+}/accept', [ConnectionsController::class, 'accept'])->add($jwtMw);
        $g->post  ('/connections/requests/{id:[0-9]+}/reject', [ConnectionsController::class, 'reject'])->add($jwtMw);
        $g->delete('/connections/requests/{id:[0-9]+}',        [ConnectionsController::class, 'cancel'])->add($jwtMw);
        $g->get   ('/connections/requests',                    [ConnectionsController::class, 'listPending'])->add($jwtMw);
        $g->get   ('/connections/suggestions',                 [ConnectionsController::class, 'suggestions'])->add($jwtMw);
        $g->get   ('/connections/status/{userId:[0-9]+}',      [ConnectionsController::class, 'statusVsMe'])->add($jwtMw);
        $g->delete('/connections/{userId:[0-9]+}',             [ConnectionsController::class, 'remove'])->add($jwtMw);
        $g->get   ('/connections',                             [ConnectionsController::class, 'listConnections'])->add($jwtMw);

        // News feed + posts/reactions/comments/repost (FEED-06 / D-06).
        // /feed + all mutations require JWT (no anonymous feed; my_reaction is
        // viewer-relative). The SUFFIXED /posts/{id}/repost|reactions|comments are
        // registered BEFORE the bare /posts/{id} so FastRoute matches the literal
        // suffixes first (mirrors the connection/feed-service route ordering).
        $g->get   ('/feed',                        [FeedController::class, 'feed'])->add($jwtMw);
        $g->post  ('/posts',                       [FeedController::class, 'createPost'])->add($jwtMw);
        $g->post  ('/posts/{id:[0-9]+}/repost',    [FeedController::class, 'repost'])->add($jwtMw);
        $g->post  ('/posts/{id:[0-9]+}/reactions', [FeedController::class, 'react'])->add($jwtMw);
        $g->delete('/posts/{id:[0-9]+}/reactions', [FeedController::class, 'unreact'])->add($jwtMw);
        $g->get   ('/posts/{id:[0-9]+}/comments',  [FeedController::class, 'listComments']);
        $g->post  ('/posts/{id:[0-9]+}/comments',  [FeedController::class, 'addComment'])->add($jwtMw);
        $g->delete('/posts/{id:[0-9]+}',           [FeedController::class, 'deletePost'])->add($jwtMw);
        $g->get   ('/posts/{id:[0-9]+}',           [FeedController::class, 'showPost'])->add($optMw);
        $g->delete('/comments/{id:[0-9]+}',        [FeedController::class, 'deleteComment'])->add($jwtMw);

        // Search (SEARCH-01/02, JWT REQUIRED). search() composes each hit with the
        // viewer's connection_status; reindex() rebuilds the index from profile-service.
        $g->get ('/search',         [SearchController::class, 'search'])->add($jwtMw);
        $g->post('/search/reindex', [SearchController::class, 'reindex'])->add($jwtMw);
    });
};
