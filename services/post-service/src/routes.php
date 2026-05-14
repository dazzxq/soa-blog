<?php
declare(strict_types=1);

use App\Controllers\PostController;
use Slim\App;

return static function (App $app): void {
    $app->get('/health', [PostController::class, 'health']);

    $app->get   ('/posts',                [PostController::class, 'index']);
    $app->post  ('/posts',                [PostController::class, 'create']);
    $app->get   ('/posts/{id:[0-9]+}',    [PostController::class, 'show']);
    $app->patch ('/posts/{id:[0-9]+}',    [PostController::class, 'update']);
    $app->delete('/posts/{id:[0-9]+}',    [PostController::class, 'delete']);
};
