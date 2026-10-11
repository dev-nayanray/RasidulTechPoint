<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database\Connection;

/**
 * Typed access to the settings table with a per-request cache.
 */
final class SettingService
{
    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, string $value, string $type = 'string', string $group = 'general'): void
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, setting_type, setting_group, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        );
        $stmt->execute([$key, $value, $type, $group, AuthService::id()]);
        self::$cache = null;
    }

    /** @return array<string,mixed> */
    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        $rows = Connection::pdo()->query('SELECT setting_key, setting_value, setting_type FROM settings')
            ->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            self::$cache[$row['setting_key']] = match ($row['setting_type']) {
                'int' => (int) $row['setting_value'],
                'bool' => in_array($row['setting_value'], ['1', 'true', 'yes'], true),
                default => $row['setting_value'],
            };
        }
    }
}
