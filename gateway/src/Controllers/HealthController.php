<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Json;
use App\Services\ConnectionClient;
use App\Services\FeedClient;
use App\Services\NotificationClient;
use App\Services\ProfileClient;
use App\Services\SearchClient;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthController
{
    public function __construct(
        private ProfileClient $profile,
        private ConnectionClient $connection,
        private FeedClient $feed,
        private SearchClient $search,
        private NotificationClient $notification,
    ) {}

    /**
     * Composition (D-10): ping all 5 backends in parallel. 200 iff all ok.
     *
     * Each `services.<svc>` value carries the downstream /health BODY verbatim
     * (status, db, rid, ts) — NOT a reduced summary — so the stub-echoed `rid`
     * (D-12 receipt proof) is surfaced for the smoke test (ISSUE-5).
     */
    public function check(Request $req, Response $res): Response
    {
        $promises = [
            'profile'      => $this->profile->healthAsync(),
            'connection'   => $this->connection->healthAsync(),
            'feed'         => $this->feed->healthAsync(),
            'search'       => $this->search->healthAsync(),
            'notification' => $this->notification->healthAsync(),
        ];
        $settled = Utils::settle($promises)->wait();

        $services = [];
        $allOk = true;
        foreach ($settled as $name => $r) {
            if ($r['state'] === 'fulfilled' && $r['value']->getStatusCode() === 200) {
                $body = json_decode((string) $r['value']->getBody(), true);
                $services[$name] = is_array($body) ? $body : ['status' => 'ok'];
            } else {
                $allOk = false;
                $reason = $r['state'] === 'rejected'
                    ? $r['reason']->getMessage()
                    : 'http_' . $r['value']->getStatusCode();
                $services[$name] = ['status' => 'down', 'reason' => $reason];
            }
        }

        return Json::raw($res, [
            'status'   => $allOk ? 'ok' : 'degraded',
            'services' => $services,
            'ts'       => gmdate('c'),
        ], $allOk ? 200 : 503);
    }
}
