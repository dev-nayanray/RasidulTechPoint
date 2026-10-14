<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Secure native PHP session wrapper.
 *
 * - Configurable lifetime, HttpOnly, Secure, SameSite cookies.
 * - Session IDs are regenerated on privilege change (see regenerate()).
 * - Flash data survives exactly one following request.
 */
final class Session
{
    private const FLASH_KEY = '__flash';
    private const OLD_KEY = '__old';

    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $name = (string) Config::get('session.name', 'rtp_session');
        $lifetime = (int) Config::get('session.lifetime', 7200);
        // Force Secure cookies in production even if SESSION_SECURE is not set.
        $env = (string) Config::get('app.env', 'local');
        $secure = $env === 'production' ? true : (bool) Config::get('session.secure', false);

        $savePath = BASE_PATH . '/storage/sessions';
        if (!is_dir($savePath)) {
            mkdir($savePath, 0770, true);
        }
        session_save_path($savePath);

        session_name($name);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => (bool) Config::get('session.http_only', true),
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        session_start();
        self::$started = true;

        self::sweepOldFlash();
    }

    public static function startTest(array &$fakeSession): void
    {
        // Test-mode session backed by a supplied array reference.
        $_SESSION =& $fakeSession;
        self::$started = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Flash a value for the next request. */
    public static function flash(string $key, mixed $value): void
    {
        $_SESSION[self::FLASH_KEY][$key] = $value;
    }

    /** Read a flash value from the previous request (then it is gone). */
    public static function getFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[self::FLASH_KEY][$key] ?? $default;
        unset($_SESSION[self::FLASH_KEY][$key]);
        return $value;
    }

    /** Persist current input for the next request (repopulation after validation errors). */
    public static function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        $_SESSION[self::OLD_KEY] = array_slice($input, 0, 30);
        $_SESSION[self::FLASH_KEY]['__has_old'] = true;
    }

    public static function old(string $key, mixed $default = ''): mixed
    {
        return $_SESSION[self::OLD_KEY][$key] ?? $default;
    }

    public static function flushOld(): void
    {
        unset($_SESSION[self::OLD_KEY]);
    }

    private static function sweepOldFlash(): void
    {
        // Old input lives for one request: drop it unless this request is a redirect target.
        if (!isset($_SESSION[self::FLASH_KEY]['__has_old'])) {
            unset($_SESSION[self::OLD_KEY]);
        }
        unset($_SESSION[self::FLASH_KEY]['__has_old']);
    }

    /** Regenerate the session ID after authentication or privilege changes. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Server-side invalidation on logout. */
    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $p['path'],
                    'domain' => $p['domain'],
                    'secure' => (bool) Config::get('session.secure', false),
                    'httponly' => (bool) Config::get('session.http_only', true),
                    'samesite' => 'Lax',
                ]);
            }
            session_destroy();
        }
        self::$started = false;
        $_SESSION = [];
    }
}
