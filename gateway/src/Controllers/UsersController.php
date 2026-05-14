<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Json;
use App\Services\UserClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UsersController
{
    public function __construct(private UserClient $users) {}

    public function show(Request $req, Response $res, array $args): Response
    {
        $upstream = $this->users->get((int) $args['id']);
        return Json::raw($res, $this->decode($upstream), $upstream->getStatusCode());
    }

    private function decode(\Psr\Http\Message\ResponseInterface $r): array
    {
        $decoded = json_decode((string) $r->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
