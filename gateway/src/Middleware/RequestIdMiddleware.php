<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use App\Services\HttpClient;
use Ramsey\Uuid\Uuid;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $rid = $request->getHeaderLine('X-Request-Id');
        if ($rid === '') {
            $rid = Uuid::uuid4()->toString();
        }
        // D-12: stash the request-scoped id so service clients (constructed
        // lazily later in route handlers) forward it downstream as X-Request-Id.
        HttpClient::setRequestId($rid);
        $request = $request->withAttribute('request_id', $rid);
        $response = $handler->handle($request);
        return $response->withHeader('X-Request-Id', $rid);
    }
}
