<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Json;
use App\Services\CommentClient;
use App\Services\PostClient;
use App\Services\UserClient;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthController
{
    public function __construct(
        private UserClient $users,
        private PostClient $posts,
        private CommentClient $comments,
    ) {}

    /**
     * Composition: ping all 3 backends in parallel.
     */
    public function check(Request $req, Response $res): Response
    {
        $promises = [
            'user'    => $this->users->healthAsync(),
            'post'    => $this->posts->healthAsync(),
            'comment' => $this->comments->healthAsync(),
        ];
        $settled = Utils::settle($promises)->wait();

        $services = [];
        $allOk = true;
        foreach ($settled as $name => $r) {
            if ($r['state'] === 'fulfilled' && $r['value']->getStatusCode() === 200) {
                $body = json_decode((string) $r['value']->getBody(), true) ?: [];
                $services[$name] = ['status' => 'ok', 'db' => $body['db'] ?? 'unknown'];
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
