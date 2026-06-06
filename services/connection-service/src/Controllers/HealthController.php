<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthController
{
    /**
     * GET /health — liveness + DB ping.
     *
     * Echoes the inbound X-Request-Id back as `rid` (D-12 receipt proof):
     * lets the smoke test verify the gateway-forwarded request id actually
     * reached this downstream service.
     */
    public function health(Request $req, Response $res): Response
    {
        $dbOk = Db::ping();
        return Json::raw($res, [
            'status' => $dbOk ? 'ok' : 'degraded',
            'db'     => $dbOk ? 'ok' : 'down',
            'rid'    => $req->getHeaderLine('X-Request-Id'),
            'ts'     => gmdate('c'),
        ], $dbOk ? 200 : 503);
    }
}
