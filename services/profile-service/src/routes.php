<?php
declare(strict_types=1);

use App\Controllers\ProfileController;
use App\Controllers\UserController;
use Slim\App;

return static function (App $app): void {
    $app->get('/health', [UserController::class, 'health']);

    $app->get   ('/users',                 [UserController::class, 'index']);
    $app->post  ('/users',                 [UserController::class, 'create']);
    $app->post  ('/users/verify-credentials', [UserController::class, 'verifyCredentials']);
    $app->get   ('/users/{id:[0-9]+}',     [UserController::class, 'show']);
    $app->patch ('/users/{id:[0-9]+}',     [UserController::class, 'update']);

    // Phase 2 — full assembly (intra-service; gateway composes across services)
    $app->get   ('/users/{id:[0-9]+}/full',                       [UserController::class,    'full']);

    // Experience (owner-scoped by X-User-Id at the controller)
    $app->post  ('/users/{id:[0-9]+}/experience',                 [ProfileController::class, 'addExperience']);
    $app->patch ('/users/{id:[0-9]+}/experience/{eid:[0-9]+}',    [ProfileController::class, 'updateExperience']);
    $app->delete('/users/{id:[0-9]+}/experience/{eid:[0-9]+}',    [ProfileController::class, 'deleteExperience']);

    // Education
    $app->post  ('/users/{id:[0-9]+}/education',                  [ProfileController::class, 'addEducation']);
    $app->patch ('/users/{id:[0-9]+}/education/{eid:[0-9]+}',     [ProfileController::class, 'updateEducation']);
    $app->delete('/users/{id:[0-9]+}/education/{eid:[0-9]+}',     [ProfileController::class, 'deleteEducation']);

    // Skills (add / remove only)
    $app->post  ('/users/{id:[0-9]+}/skills',                     [ProfileController::class, 'addSkill']);
    $app->delete('/users/{id:[0-9]+}/skills/{sid:[0-9]+}',        [ProfileController::class, 'deleteSkill']);
};
