<?php
declare(strict_types=1);

use App\Controllers\CommentController;
use App\Controllers\HealthController;
use App\Controllers\PostController;
use Slim\App;

/**
 * feed-service internal route set (reached only by the gateway over blog-net).
 *
 * Ordering discipline (mirrors connection-service): the literal `/posts`
 * collection comes first, and the SUFFIXED `/posts/{id}/...` routes are
 * registered BEFORE the bare `/posts/{id}` GET/DELETE so FastRoute matches the
 * more specific literals (reactions/comments/repost) first. Numeric
 * `{id:[0-9]+}` constraints keep `/posts/{id}/comments` and `/comments/{id}`
 * from ever colliding.
 */
return static function (App $app): void {
    $app->get('/health', [HealthController::class, 'health']);                       // keep

    $app->get('/posts',  [PostController::class, 'index']);                          // ?authors=&viewer=&limit= OR ?ids=
    $app->post('/posts', [PostController::class, 'create']);                         // {content,image_url?} author=X-User-Id

    // Suffixed literals BEFORE the bare /posts/{id} routes.
    $app->post('/posts/{id:[0-9]+}/repost',    [PostController::class, 'repost']);
    $app->post('/posts/{id:[0-9]+}/reactions', [PostController::class, 'react']);    // upsert {type}
    $app->delete('/posts/{id:[0-9]+}/reactions', [PostController::class, 'unreact']); // remove caller's
    $app->get('/posts/{id:[0-9]+}/comments',  [CommentController::class, 'index']);
    $app->post('/posts/{id:[0-9]+}/comments', [CommentController::class, 'create']);

    $app->get('/posts/{id:[0-9]+}',    [PostController::class, 'show']);             // single post + counts (?viewer=)
    $app->delete('/posts/{id:[0-9]+}', [PostController::class, 'delete']);           // owner only (cascade)

    $app->delete('/comments/{id:[0-9]+}', [CommentController::class, 'delete']);     // owner only
};
