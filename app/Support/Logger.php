<?php

declare(strict_types=1);

namespace App\Support;

/**
 * File logger for application and security events.
 * Logs go to storage/logs/ which lives outside the public directory.
 */
final class Logger
{
    public static function log(string $channel, string $level, string $message, array $context = []): void
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        // Redact obvious secret-looking context keys defensively.
        foreach (array_keys($context) as $key) {
            if (preg_match('/password|token|secret|authorization/i', (string) $key)) {
                $context[$key] = '[redacted]';
            }
        }

        $line = sprintf(
            "[%s] %s.%s: %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $channel,
            strtoupper($level),
            $message,
            $context !== [] ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );

        @file_put_contents($dir . '/' . gmdate('Y-m-d') . '-' . $channel . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $channel, string $message, array $context = []): void
    {
        self::log($channel, 'info', $message, $context);
    }

    public static function error(string $channel, string $message, array $context = []): void
    {
        self::log($channel, 'error', $message, $context);
    }

    public static function security(string $message, array $context = []): void
    {
        self::log('security', 'warning', $message, $context);
    }
}
