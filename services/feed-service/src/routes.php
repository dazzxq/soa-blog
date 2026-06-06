<?php
declare(strict_types=1);

use App\Controllers\HealthController;
use Slim\App;

return static function (App $app): void {
    $app->get('/health', [HealthController::class, 'health']);
};
