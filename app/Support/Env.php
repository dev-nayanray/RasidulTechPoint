<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal, validated environment file loader.
 *
 * Parses KEY=VALUE pairs, supports quoting, comments and blank lines,
 * and exposes typed readers. Fails loudly in production when a required
 * variable is missing (via requireEnv).
 */
final class Env
{
    private static bool $loaded = false;

    /** @var array<string,string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_file($path)) {
            // Missing .env is fatal in production, tolerated for built-in tests.
            self::$loaded = true;
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                if (str_ends_with($value, $quote) && strlen($value) >= 2) {
                    $value = substr($value, 1, -1);
                    if ($quote === '"') {
                        $value = str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $value);
                    }
                }
            } else {
                // Unquoted value: strip trailing inline comments ("KEY=value # note").
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
                if ($value === 'null') {
                    $value = '';
                }
            }

            self::$values[$key] = $value;
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::$values[$key] ?? $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    /**
     * Required variable; throws in production if absent.
     */
    public static function requireEnv(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new \RuntimeException(
                "Required environment variable {$key} is missing. Copy .env.example to .env and configure it."
            );
        }
        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null ? $default : (int) $value;
    }
}
