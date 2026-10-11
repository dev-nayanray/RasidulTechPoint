<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * HTTP-significant exception with a status code and safe public message.
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        int $status,
        string $message,
        public readonly bool $report = true,
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->code;
    }
}
