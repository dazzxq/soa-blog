<?php
declare(strict_types=1);

namespace App;

/**
 * Throw this from controllers to return a structured error response.
 */
final class DomainError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}
