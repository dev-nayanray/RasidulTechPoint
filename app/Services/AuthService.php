<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TooManyRequestsException;
use App\Support\Config;
use App\Support\Database\Connection;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Session;

/**
 * Database-backed authentication and permission-based authorization.
 *
 * - password_hash()/password_verify() only.
 * - Session ID regenerated on every successful login.
 * - Login throttling via the login_attempts table (per email+IP window).
 * - Generic error messages (never reveals whether the email exists).
 */
final class AuthService
{
    private static ?array $user = null;
    private static ?array $permissions = null;

    /** @var int|null user id restored from session during boot() */
    private static ?int $sessionUserId = null;

    public static function boot(): void
    {
        $id = Session::get('user_id');
        if (is_int($id)) {
            self::$sessionUserId = $id;
            self::refreshUser();
        }
    }

    public static function refreshUser(): void
    {
        $id = self::$sessionUserId;
        if ($id === null) {
            return;
        }
        $stmt = Connection::pdo()->prepare(
            'SELECT u.id, u.name, u.email, u.phone, u.is_active, u.last_login_at
             FROM users u
             WHERE u.id = ? AND u.deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($user === false || (int) $user['is_active'] !== 1) {
            // Deactivated or deleted mid-session: invalidate immediately.
            self::logout();
            return;
        }

        $user['id'] = (int) $user['id'];
        $user['roles'] = self::rolesFor($user['id']);
        self::$user = $user;
        self::$permissions = self::permissionsFor($user['id']);
    }

    /**
     * Attempt a login. Returns the user array on success.
     *
     * @throws TooManyRequestsException when the throttle window is exceeded
     */
    public static function attempt(string $email, string $password, Request $request): ?array
    {
        self::assertNotThrottled($email, $request->ip());

        $stmt = Connection::pdo()->prepare(
            'SELECT id, name, email, password, is_active FROM users
             WHERE email = ? AND deleted_at IS NULL'
        );
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        $ok = $user !== false
            && (int) $user['is_active'] === 1
            && password_verify($password, $user['password']);

        self::recordAttempt(strtolower(trim($email)), $request->ip(), $ok);

        if (!$ok) {
            // Burn equivalent time when the user row is missing.
            if ($user === false) {
                password_verify($password, password_hash('timing-equalizer', PASSWORD_DEFAULT));
            }
            Logger::security('Login failed', [
                'ip' => $request->ip(),
                'correlation_id' => $request->correlationId(),
            ]);
            return null;
        }

        // Privilege change → new session id, same session data.
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        self::$sessionUserId = (int) $user['id'];
        self::refreshUser();

        $stmt = Connection::pdo()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([$user['id']]);

        return self::$user;
    }

    public static function logout(): void
    {
        self::$user = null;
        self::$permissions = null;
        self::$sessionUserId = null;
        Session::destroy();
    }

    /**
     * Undo an authentication within the current request WITHOUT destroying
     * the session — used when a successful attempt must be rolled back
     * (e.g. customer credentials posted to the management console login).
     * Flash data and the CSRF token survive so the form can re-render with
     * an error, unlike logout() which kills the whole session.
     */
    public static function deauthenticate(): void
    {
        Session::regenerate();
        Session::forget('user_id');
        self::flushState();
    }

    public static function check(): bool
    {
        return self::$user !== null;
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function id(): ?int
    {
        return self::$user['id'] ?? null;
    }

    public static function hasRole(string ...$slugs): bool
    {
        if (self::$user === null) {
            return false;
        }
        foreach ($slugs as $slug) {
            if (in_array($slug, self::$user['roles'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Permission-based authorization. 'owner' role short-circuits to true
     * for convenience, but permission rows remain the source of truth.
     */
    public static function can(string $permission): bool
    {
        if (self::$user === null) {
            return false;
        }
        if (in_array('owner', self::$user['roles'], true)) {
            return true;
        }
        return in_array($permission, self::$permissions ?? [], true);
    }

    /** @return list<string> role slugs */
    public static function rolesFor(int $userId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?'
        );
        $stmt->execute([$userId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<string> permission names */
    public static function permissionsFor(int $userId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT DISTINCT p.name
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?'
        );
        $stmt->execute([$userId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Whether this user id belongs to a staff member (management console
     * user). Mirrors the can('dashboard.view') split used to route logins:
     * 'owner' short-circuits, otherwise the permission rows decide.
     */
    public static function isStaff(int $userId): bool
    {
        if (in_array('owner', self::rolesFor($userId), true)) {
            return true;
        }
        return in_array('dashboard.view', self::permissionsFor($userId), true);
    }

    /**
     * Reset the shared state (used by tests between scenarios).
     */
    public static function flushState(): void
    {
        self::$user = null;
        self::$permissions = null;
        self::$sessionUserId = null;
    }

    private static function assertNotThrottled(string $email, string $ip): void
    {
        $max = (int) Config::get('security.login_throttle.max_attempts', 5);
        $decay = (int) Config::get('security.login_throttle.decay', 900);

        $stmt = Connection::pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE attempted_at > UTC_TIMESTAMP() - INTERVAL ' . (int) $decay . ' SECOND
               AND was_successful = 0
               AND (email = ? OR ip = ?)'
        );
        $stmt->execute([$email, $ip]);

        if ((int) $stmt->fetchColumn() >= $max) {
            throw new TooManyRequestsException(
                $decay,
                "Too many failed login attempts. Please try again in " . (int) ceil($decay / 60) . " minutes."
            );
        }
    }

    private static function recordAttempt(string $email, string $ip, bool $success): void
    {
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO login_attempts (email, ip, was_successful) VALUES (?, ?, ?)'
        );
        $stmt->execute([$email, $ip, $success ? 1 : 0]);
    }
}
