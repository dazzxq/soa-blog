<?php
declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\SearchController;
use Slim\App;

return static function (App $app): void {
    $app->get('/health', [HealthController::class, 'health']);

    // SEARCH-01 — search + reindex sink. No JWT/X-User-Id middleware: this service
    // has no host port and trusts the gateway on the isolated blog-net (D-07).
    $app->get('/search', [SearchController::class, 'search']);
    $app->post('/index', [SearchController::class, 'upsert']);
};
