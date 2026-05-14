<?php
declare(strict_types=1);

use App\Controllers\UserController;
use Slim\App;

return static function (App $app): void {
    $app->get('/health', [UserController::class, 'health']);

    $app->get   ('/users',                 [UserController::class, 'index']);
    $app->post  ('/users',                 [UserController::class, 'create']);
    $app->post  ('/users/verify-credentials', [UserController::class, 'verifyCredentials']);
    $app->get   ('/users/{id:[0-9]+}',     [UserController::class, 'show']);
    $app->patch ('/users/{id:[0-9]+}',     [UserController::class, 'update']);
};
