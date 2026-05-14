<?php
declare(strict_types=1);

use App\Controllers\AggregateController;
use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\PostsController;
use App\Controllers\UsersController;
use App\Middleware\JwtAuthMiddleware;
use Slim\App;

return static function (App $app): void {
    $container = $app->getContainer();
    $jwtMw     = $container?->get(JwtAuthMiddleware::class);

    // Group callback cannot be static — Slim rebinds $this to the container
    $app->group('/api', function ($g) use ($jwtMw) {
        $g->get ('/health', [HealthController::class, 'check']);

        // Auth
        $g->post('/auth/register', [AuthController::class, 'register']);
        $g->post('/auth/login',    [AuthController::class, 'login']);
        $g->get ('/me',            [AuthController::class, 'me'])->add($jwtMw);

        // Users
        $g->get ('/users/{id:[0-9]+}', [UsersController::class, 'show']);

        // Posts
        $g->get   ('/posts',                          [PostsController::class, 'index']);
        $g->get   ('/posts/{id:[0-9]+}',              [PostsController::class, 'show']);
        $g->get   ('/posts/{id:[0-9]+}/full',         [AggregateController::class, 'postFull']);
        $g->post  ('/posts',                          [PostsController::class, 'create']) ->add($jwtMw);
        $g->patch ('/posts/{id:[0-9]+}',              [PostsController::class, 'update']) ->add($jwtMw);
        $g->delete('/posts/{id:[0-9]+}',              [PostsController::class, 'delete']) ->add($jwtMw);

        // Comments
        $g->get   ('/posts/{id:[0-9]+}/comments',     [PostsController::class, 'comments']);
        $g->post  ('/posts/{id:[0-9]+}/comments',     [PostsController::class, 'createComment'])->add($jwtMw);
        $g->delete('/comments/{id:[0-9]+}',           [PostsController::class, 'deleteComment'])->add($jwtMw);
    });
};
