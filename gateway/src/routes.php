<?php
declare(strict_types=1);

use App\Controllers\AggregateController;
use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\ProfilesController;
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
    });
};
