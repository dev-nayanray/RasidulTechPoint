<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dot-notation configuration repository backed by the config/ directory.
 */
final class Config
{
    /** @var array<string,array<string,mixed>> */
    private static array $items = [];

    public static function load(string $configDir): void
    {
        foreach (glob($configDir . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $value = require $file;
            if (is_array($value)) {
                self::$items[$key] = $value;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &self::$items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $ref[$segment] = $value;
                break;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return self::$items;
    }
}
