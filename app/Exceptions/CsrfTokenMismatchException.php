<?php

declare(strict_types=1);

namespace App\Exceptions;

final class CsrfTokenMismatchException extends HttpException
{
    public function __construct(string $message = 'Security token expired. Please go back and try again.')
    {
        parent::__construct(419, $message, report: false);
    }
}
