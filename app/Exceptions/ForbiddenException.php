<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'You do not have permission to perform this action.')
    {
        parent::__construct(403, $message);
    }
}
