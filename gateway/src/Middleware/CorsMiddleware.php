<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

final class CorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        // Preflight: respond directly with CORS headers, no downstream call.
        if ($request->getMethod() === 'OPTIONS') {
            $factory = new \Slim\Psr7\Factory\ResponseFactory();
            $response = $factory->createResponse(204);
        } else {
            $response = $handler->handle($request);
        }

        $origin = $request->getHeaderLine('Origin') ?: '*';

        return $response
            ->withHeader('Access-Control-Allow-Origin',      $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Methods',     'GET, POST, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers',     'Content-Type, Authorization, X-Requested-With')
            ->withHeader('Access-Control-Max-Age',           '600')
            ->withHeader('Vary',                             'Origin');
    }
}
