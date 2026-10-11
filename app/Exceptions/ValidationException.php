<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ValidationException extends HttpException
{
    /**
     * @param array<string,string> $errors field => first error message
     */
    public function __construct(
        public readonly array $errors,
        string $message = 'The given data was invalid.',
    ) {
        parent::__construct(422, $message, report: false);
    }
}
