<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Restore safety helper — the only place that decides whether a restore
 * into a given target DB is allowed. Centralized so bin/restore.php and
 * the test suite share the exact same rule.
 *
 * Rule:
 *   The restore target MUST differ from the configured production DB,
 *   OR the caller must pass --i-know-this-is-production. Refusing to
 *   overwrite the live production database without explicit confirmation
 *   is the shared project rule for destructive operations.
 *
 * Encoded as an exception so the CLI wrapper can print the message and
 * exit non-zero, and tests can assert on the exception type.
 */
final class BackupSafety
{
    /**
     * @param string $target The DB name the operator wants to restore INTO.
     * @param string $prodConfiguredDb The DB name configured in .env (Config::database.database).
     * @param string $appEnv The current APP_ENV (local | production).
     * @param bool $iKnowThisIsProduction Whether the operator passed --i-know-this-is-production.
     * @throws RestoreRefusalException When the target is the configured production DB AND
     *   the operator did not explicitly opt in.
     */
    public static function assertRestoreTargetAllowed(
        string $target,
        string $prodConfiguredDb,
        string $appEnv,
        bool $iKnowThisIsProduction,
    ): void {
        $isProductionTarget = ($target === $prodConfiguredDb) && $appEnv === 'production';
        if ($isProductionTarget && !$iKnowThisIsProduction) {
            throw new \App\Exceptions\RestoreRefusalException(
                $target,
                "REFUSING: restore target '{$target}' is the configured production database. "
                . 'If this is really what you intend, re-run with '
                . "--target={$target} --confirm --i-know-this-is-production. "
                . 'Otherwise restore into a non-production database: '
                . "--target=rasidul_tech_point_restore --confirm.",
            );
        }
    }

    /**
     * Verify the backup file's on-disk SHA-256 matches the manifest.
     * Detects bit-rot or tampering BEFORE the restore pipeline opens
     * the file.
     *
     * @throws \App\Exceptions\RestoreRefusalException When the checksum does not match.
     */
    public static function assertChecksumMatches(string $file, string $expectedSha): void
    {
        $actual = hash_file('sha256', $file);
        if ($actual !== $expectedSha) {
            throw new \App\Exceptions\RestoreRefusalException(
                $file,
                "Checksum mismatch for '{$file}'. expected={$expectedSha} actual={$actual}. Refusing to restore a corrupt backup.",
            );
        }
    }
}
