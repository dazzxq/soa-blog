<?php
declare(strict_types=1);

use App\Controllers\ConnectionController;
use App\Controllers\HealthController;
use Slim\App;

return static function (App $app): void {
    $app->get('/health', [HealthController::class, 'health']);

    // D-05 — the EXACT path ConnectionClient::statusForAsync already calls.
    // Literal /connections/* paths are registered BEFORE the bare GET /connections
    // so FastRoute matches the literals first; the {id}/{userId} numeric
    // constraints keep accept/delete/by-user unambiguous (Pattern 2/6).
    $app->get   ('/connections/status',                  [ConnectionController::class, 'status']);       // ?viewer=&target=
    $app->get   ('/connections/pending',                 [ConnectionController::class, 'listPending']);  // ?user=&direction=
    $app->get   ('/connections/suggestions',             [ConnectionController::class, 'suggestions']);  // ?user=&candidates=&limit=

    $app->post  ('/connections',                         [ConnectionController::class, 'create']);       // {addressee_id} — requester = X-User-Id
    $app->post  ('/connections/{id:[0-9]+}/accept',      [ConnectionController::class, 'accept']);       // addressee only
    $app->delete('/connections/{id:[0-9]+}',             [ConnectionController::class, 'deleteRequest']);// reject (addressee) OR cancel (requester)
    $app->delete('/connections/by-user/{userId:[0-9]+}', [ConnectionController::class, 'removeEdge']);   // remove accepted, either side

    $app->get   ('/connections',                         [ConnectionController::class, 'listAccepted']); // ?user= → accepted edges
};
