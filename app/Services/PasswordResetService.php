<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Config;
use App\Support\Database\Connection;

/**
 * Expiring, single-use password reset tokens.
 *
 * - The emailed link carries a random token; only its SHA-256 hash is stored.
 * - Tokens expire (default 60 minutes) and are invalidated on use.
 * - The request endpoint NEVER reveals whether the email exists.
 */
final class PasswordResetService
{
    private const TTL_MINUTES = 60;

    /**
     * Create a reset token for the account (if any). Always behaves the
     * same from the caller's perspective.
     *
     * @return array{token:?string,user:?array} token is null when no account matched
     */
    public function createToken(string $email): array
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, name, email FROM users WHERE email = ? AND deleted_at IS NULL AND is_active = 1'
        );
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($user === false) {
            return ['token' => null, 'user' => null];
        }

        // Invalidate previous outstanding tokens for this user.
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL')
            ->execute([$user['id']]);

        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ' . self::TTL_MINUTES . ' MINUTE)'
        );
        $stmt->execute([$user['id'], hash('sha256', $token)]);

        return ['token' => $token, 'user' => $user];
    }

    /**
     * Validate a token and set the new password.
     *
     * @throws ValidationException for invalid/expired/used tokens
     */
    public function resetPassword(string $token, string $newPassword): array
    {
        $hash = hash('sha256', $token);

        return Connection::transaction(function ($pdo) use ($hash, $newPassword) {
            $stmt = $pdo->prepare(
                'SELECT prt.id, prt.user_id, u.email
                 FROM password_reset_tokens prt
                 JOIN users u ON u.id = prt.user_id
                 WHERE prt.token_hash = ?
                   AND prt.used_at IS NULL
                   AND prt.expires_at > UTC_TIMESTAMP()
                   AND u.deleted_at IS NULL
                     FOR UPDATE'
            );
            $stmt->execute([$hash]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row === false) {
                throw new ValidationException(['token' => 'This password reset link is invalid or has expired.']);
            }

            $update = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), $row['user_id']]);

            $consume = $pdo->prepare('UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?');
            $consume->execute([$row['id']]);

            return ['email' => $row['email']];
        });
    }
}
