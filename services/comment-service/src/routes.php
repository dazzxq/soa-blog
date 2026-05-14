<?php
declare(strict_types=1);

use App\Controllers\CommentController;
use Slim\App;

return static function (App $app): void {
    $app->get('/health', [CommentController::class, 'health']);

    $app->get   ('/comments',                    [CommentController::class, 'index']);
    $app->get   ('/comments/count',              [CommentController::class, 'count']);
    $app->post  ('/comments',                    [CommentController::class, 'create']);
    $app->delete('/comments/{id:[0-9]+}',        [CommentController::class, 'delete']);
};
