<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised by App\Support\BackupSafety when a restore is refused for safety
 * reasons (production target without explicit confirmation, checksum
 * mismatch, etc). Extends HttpException so the CLI wrapper can map it to
 * a non-zero exit code while tests assert on the exception type.
 */
final class RestoreRefusalException extends \RuntimeException
{
    public function __construct(
        public readonly string $target,
        string $message,
    ) {
        parent::__construct($message, 2);
    }
}
