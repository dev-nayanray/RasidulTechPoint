<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Database\Connection;
use PDO;

/**
 * Typed access to the settings table with a per-request cache.
 *
 * Three kinds of settings are supported:
 *
 *  1. Plaintext typed settings — `set()` + `get()` (legacy API, kept
 *     for back-compat with the existing SettingsController + tests).
 *     Stored in `setting_value` with a `setting_type` ENUM hint
 *     (string / int / bool / json).
 *
 *  2. Categorized settings (Prompt 39) — `setCategory()` + `getInCategory()`
 *     work on the new `category` column. Plaintext only; secrets
 *     must go through `setSecret()`.
 *
 *  3. Encrypted secrets — `setSecret()` + `getSecret()`. The
 *     `encrypted_value` column holds the AES-256-CBC blob; the
 *     `setting_value` column is NULL. `get()` always returns null for
 *     secret keys (so the legacy API never leaks plaintext secrets).
 *
 * Validation registry:
 *  - `define()` registers a category with a list of keys + their
 *    validation rules. `saveFromInput()` validates a form submission
 *    against the registry and applies the typed values.
 *
 * Audit:
 *  - Every `set()` + `setSecret()` call is fire-and-forget audited via
 *    `app_audit('settings.X_updated', ...)`. Secret values are NEVER
 *    included in the audit metadata — only the key name + a
 *    `secret_changed` boolean.
 */
final class SettingService
{
    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /** @var array<string, list<string>> category => ordered list of key names */
    private static array $registry = [];

    public const CATEGORY_CURRENCY = 'currency';
    public const CATEGORY_DATES = 'dates';
    public const CATEGORY_INVOICE = 'invoice';
    public const CATEGORY_INVENTORY = 'inventory';
    public const CATEGORY_WARRANTY = 'warranty';
    public const CATEGORY_DELIVERY = 'delivery';
    public const CATEGORY_EMAIL = 'email';
    public const CATEGORY_PAYMENTS = 'payments';
    public const CATEGORY_INTEGRATIONS = 'integrations';

    /** All recognized category keys (used by the nav + sanity checks). */
    public const CATEGORIES = [
        self::CATEGORY_CURRENCY,
        self::CATEGORY_DATES,
        self::CATEGORY_INVOICE,
        self::CATEGORY_INVENTORY,
        self::CATEGORY_WARRANTY,
        self::CATEGORY_DELIVERY,
        self::CATEGORY_EMAIL,
        self::CATEGORY_PAYMENTS,
        self::CATEGORY_INTEGRATIONS,
    ];

    // ---------------------------------------------------- LEGACY TYPED ACCESS --

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        // Secrets are never exposed through the legacy API.
        if (isset(self::$cache['__secret__' . $key])) {
            return $default;
        }
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, string $value, string $type = 'string', string $group = 'general'): void
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, setting_type, setting_group, category, is_secret, encrypted_value, updated_by)
             VALUES (?, ?, ?, ?, ?, 0, NULL, ?)
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                setting_type = VALUES(setting_type),
                setting_group = VALUES(setting_group),
                category = COALESCE(VALUES(category), category),
                is_secret = 0,
                encrypted_value = NULL,
                updated_by = VALUES(updated_by)'
        );
        $stmt->execute([$key, $value, $type, $group, $group, AuthService::id()]);
        self::$cache = null;

        app_audit('settings.set', 'setting', null, [
            'key' => $key,
            'type' => $type,
            'group' => $group,
        ]);
    }

    // ---------------------------------------------------- SECRETS --

    /**
     * Store a secret. The plaintext is encrypted via Crypto (AES-256-CBC)
     * and only the ciphertext blob is persisted. The setting_value
     * column is set to NULL so the secret never leaks via SELECT *.
     *
     * @throws ValidationException when APP_KEY is not configured
     *   (encryption is unavailable — refusing the write prevents a
     *   silent plaintext fallback).
     */
    public static function setSecret(string $key, string $plaintext, string $category = 'secrets'): void
    {
        if (!Crypto::available()) {
            throw new ValidationException([
                'app_key' => 'APP_KEY is not configured. Set it in .env before storing secrets.',
            ]);
        }
        $blob = Crypto::encrypt($plaintext);
        if ($blob === null) {
            throw new ValidationException([
                $key => 'Failed to encrypt the secret value. Check the openssl extension and APP_KEY.',
            ]);
        }

        $pdo = Connection::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, setting_type, setting_group, category, is_secret, encrypted_value, updated_by)
             VALUES (?, NULL, "string", ?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
                setting_value = NULL,
                setting_type = "string",
                setting_group = VALUES(setting_group),
                category = COALESCE(VALUES(category), category),
                is_secret = 1,
                encrypted_value = VALUES(encrypted_value),
                updated_by = VALUES(updated_by)'
        );
        $stmt->execute([$key, $category, $category, $blob, AuthService::id()]);
        self::$cache = null;

        // Audit: NEVER include the plaintext or the blob.
        app_audit('settings.secret_updated', 'setting', null, [
            'key' => $key,
            'category' => $category,
            'secret_changed' => true,
        ]);
    }

    /**
     * Decrypt and return a secret. Returns null when the secret is
     * not configured or when APP_KEY has changed since the secret
     * was stored (decryption fails — caller must handle gracefully).
     */
    public static function getSecret(string $key): ?string
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT encrypted_value FROM settings WHERE setting_key = ? AND is_secret = 1 LIMIT 1'
        );
        $stmt->execute([$key]);
        $blob = $stmt->fetchColumn();
        if ($blob === false || $blob === null || $blob === '') {
            return null;
        }
        return Crypto::decrypt((string) $blob);
    }

    /**
     * Returns true when a secret is configured (encrypted_value is
     * non-empty). The plaintext is NOT decrypted — safe to use in the
     * admin UI to show "configured" vs "not configured".
     */
    public static function secretIsSet(string $key): bool
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT 1 FROM settings WHERE setting_key = ? AND is_secret = 1 AND encrypted_value IS NOT NULL AND encrypted_value != "" LIMIT 1'
        );
        $stmt->execute([$key]);
        return $stmt->fetchColumn() !== false;
    }

    // ---------------------------------------------------- CATEGORIZED ACCESS --

    /**
     * Set a plaintext setting with a category. Thin wrapper over set()
     * that uses the new category column as the group too (so both
     * legacy and new code agree on the grouping).
     */
    public static function setCategory(string $key, string $value, string $type, string $category): void
    {
        self::set($key, $value, $type, $category);
    }

    /**
     * Return all plaintext settings in a category as key => value
     * (typed). Secrets in the same category are omitted from the
     * returned map — use secretIsSet() / getSecret() to access them.
     *
     * @return array<string,mixed>
     */
    public static function getInCategory(string $category): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT setting_key, setting_value, setting_type FROM settings
             WHERE category = ? AND is_secret = 0'
        );
        $stmt->execute([$category]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['setting_key']] = match ($row['setting_type']) {
                'int' => (int) $row['setting_value'],
                'bool' => in_array($row['setting_value'], ['1', 'true', 'yes'], true),
                'json' => json_decode((string) $row['setting_value'], true) ?? [],
                default => (string) $row['setting_value'],
            };
        }
        return $out;
    }

    /**
     * Return the list of secret keys that are configured in a category
     * (i.e. encrypted_value is non-empty). Plaintext values are NOT
     * included — the caller knows the keys, not the values.
     *
     * @return list<string>
     */
    public static function secretKeysInCategory(string $category): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT setting_key FROM settings
             WHERE category = ? AND is_secret = 1 AND encrypted_value IS NOT NULL AND encrypted_value != ""'
        );
        $stmt->execute([$category]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Return all secret keys in a category (configured or not).
     * Useful for rendering the form (so all expected fields appear).
     *
     * @return list<string>
     */
    public static function allSecretKeysInCategory(string $category): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT setting_key FROM settings WHERE category = ? AND is_secret = 1'
        );
        $stmt->execute([$category]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Delete a secret's stored value (so the admin can "clear" a
     * previously-configured secret by submitting an empty field).
     */
    public static function clearSecret(string $key): void
    {
        $stmt = Connection::pdo()->prepare(
            'UPDATE settings SET encrypted_value = NULL, is_secret = 1, updated_by = ?
             WHERE setting_key = ? AND is_secret = 1'
        );
        $stmt->execute([AuthService::id(), $key]);
        self::$cache = null;

        app_audit('settings.secret_cleared', 'setting', null, ['key' => $key]);
    }

    // ---------------------------------------------------- VALIDATION REGISTRY --

    /**
     * Register a category with its validation rules. Idempotent —
     * calling twice overwrites.
     *
     * @param array<string, array{type:string, default?:mixed, secret?:bool, rules?:string}> $keys
     *   e.g. ['currency_code' => ['type' => 'string', 'rules' => 'required|max:3']]
     */
    public static function define(string $category, array $keys): void
    {
        self::$registry[$category] = array_keys($keys);
        // The actual rules are stored in the controller — the registry
        // is just the list of recognized keys per category.
    }

    /** @return list<string> */
    public static function definedKeys(string $category): array
    {
        return self::$registry[$category] ?? [];
    }

    // ---------------------------------------------------- CACHE --

    /** @return array<string,mixed> */
    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        $rows = Connection::pdo()->query('SELECT setting_key, setting_value, setting_type, is_secret FROM settings')
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ((int) $row['is_secret'] === 1) {
                // Mark as secret so get() returns the default rather
                // than leaking a (NULL) value.
                self::$cache['__secret__' . $row['setting_key']] = true;
                continue;
            }
            self::$cache[$row['setting_key']] = match ($row['setting_type']) {
                'int' => (int) $row['setting_value'],
                'bool' => in_array($row['setting_value'], ['1', 'true', 'yes'], true),
                'json' => json_decode((string) $row['setting_value'], true) ?? [],
                default => $row['setting_value'],
            };
        }
    }

    /** Flush the per-request cache. Test helper. */
    public static function flushCache(): void
    {
        self::$cache = null;
    }
}
