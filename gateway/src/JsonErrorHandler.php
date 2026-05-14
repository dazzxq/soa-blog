<?php
declare(strict_types=1);

namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Handlers\ErrorHandler;
use Slim\Exception\HttpException;
use Throwable;

/**
 * Convert any uncaught exception into the project's
 * {error:{code,message}} envelope.
 */
final class JsonErrorHandler extends ErrorHandler
{
    protected function respond(): ResponseInterface
    {
        $exception = $this->exception;
        $status = 500;
        $code = 'INTERNAL_ERROR';
        $message = 'Đã có lỗi xảy ra phía máy chủ.';

        if ($exception instanceof HttpException) {
            $status = $exception->getCode();
            $message = $exception->getMessage();
            $code = match ($status) {
                400 => 'BAD_REQUEST',
                401 => 'UNAUTHORIZED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED',
                409 => 'CONFLICT',
                default => 'HTTP_ERROR',
            };
        } elseif ($exception instanceof DomainError) {
            $status = $exception->status;
            $code = $exception->errorCode;
            $message = $exception->getMessage();
        }

        $body = json_encode([
            'error' => ['code' => $code, 'message' => $message],
        ], JSON_UNESCAPED_UNICODE);

        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write($body);

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
