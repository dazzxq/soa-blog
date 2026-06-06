<?php
declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\NotificationController;
use Slim\App;

return static function (App $app): void {
    $app->get('/health', [HealthController::class, 'health']);

    $app->post('/notifications',                  [NotificationController::class, 'create']);
    $app->get('/notifications',                   [NotificationController::class, 'index']);
    // Literal /read-all MUST be registered BEFORE the {id}/read param route.
    $app->post('/notifications/read-all',         [NotificationController::class, 'markAllRead']);
    $app->post('/notifications/{id:[0-9]+}/read', [NotificationController::class, 'markRead']);
};
