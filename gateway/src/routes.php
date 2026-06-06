<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\ProfilesController;
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

        // Profiles (D-07: public, whitelisted to {id,username,display_name})
        $g->get ('/profiles/{id:[0-9]+}', [ProfilesController::class, 'show']);
    });
};
