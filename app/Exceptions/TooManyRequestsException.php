<?php

declare(strict_types=1);

namespace App\Exceptions;

final class TooManyRequestsException extends HttpException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        string $message = 'Too many attempts. Please try again later.',
    ) {
        parent::__construct(429, $message, report: false);
    }
}
