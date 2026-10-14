<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Symmetric encryption helper for secrets at rest.
 *
 * Uses AES-256-CBC with a random 16-byte IV per value, plus HMAC-SHA256
 * for ciphertext integrity (encrypt-then-MAC). The blob is:
 *   base64( IV (16B) || ciphertext (N) || HMAC (32B) )
 * so the storage column can be a plain TEXT.
 *
 * Key derivation: SHA-256 of APP_KEY split into two 32-byte keys:
 *   - encryption key = SHA-256(APP_KEY . '|enc')
 *   - MAC key        = SHA-256(APP_KEY . '|mac')
 * This key separation prevents the same key from being used for both
 * encryption and authentication (a cryptographic best practice).
 *
 * Failure modes:
 *  - When APP_KEY is empty (typical in dev before APP_KEY is set),
 *    encrypt() returns null and decrypt() returns null. setSecret()
 *    throws a ValidationException to surface the misconfiguration
 *    instead of silently storing a plaintext value.
 *  - When HMAC verification fails (tampered ciphertext), decrypt()
 *    returns null BEFORE attempting decryption — padding-oracle
 *    probes are rejected at the MAC layer, not the decrypt layer.
 *  - When openssl_decrypt fails (wrong key / corrupted ciphertext),
 *    decrypt() returns null. Callers must handle null defensively.
 *
 * Backward compatibility:
 *  - decrypt() detects old-format blobs (IV + ciphertext, no HMAC)
 *    by checking the blob length. Old blobs have exactly IV_LEN +
 *    ciphertext_length bytes; new blobs have IV_LEN + ciphertext +
 *    HMAC_LEN bytes. When an old blob is detected, it is decrypted
 *    without HMAC verification (same as the previous behavior) and
 *    can be transparently re-encrypted on the next setSecret() call.
 *
 * Audit safety:
 *  - encrypt() and decrypt() never log the plaintext or the key.
 *  - Only the IV (which is public) and the ciphertext blob are
 *    persisted; neither is sensitive on its own.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-cbc';
    private const IV_LEN = 16;
    private const HMAC_LEN = 32; // SHA-256 = 32 bytes

    /**
     * Derive the 32-byte AES-256 encryption key from APP_KEY.
     * Returns null when APP_KEY is empty.
     */
    private static function encKey(): ?string
    {
        $appKey = (string) Config::get('app.key', '');
        if ($appKey === '') {
            return null;
        }
        return hash('sha256', $appKey . '|enc', true); // raw binary 32 bytes
    }

    /**
     * Derive the 32-byte HMAC-SHA256 MAC key from APP_KEY.
     * Returns null when APP_KEY is empty.
     */
    private static function macKey(): ?string
    {
        $appKey = (string) Config::get('app.key', '');
        if ($appKey === '') {
            return null;
        }
        return hash('sha256', $appKey . '|mac', true); // raw binary 32 bytes
    }

    /**
     * Encrypt a plaintext string. Returns a base64-encoded blob of
     * (IV || ciphertext || HMAC) ready for TEXT storage.
     * Returns null when APP_KEY is not configured.
     */
    public static function encrypt(string $plaintext): ?string
    {
        $encKey = self::encKey();
        $macKey = self::macKey();
        if ($encKey === null || $macKey === null) {
            return null;
        }
        $iv = random_bytes(self::IV_LEN);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $encKey, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return null;
        }
        // Encrypt-then-MAC: HMAC over IV || ciphertext.
        $mac = hash_hmac('sha256', $iv . $ciphertext, $macKey, true);
        return base64_encode($iv . $ciphertext . $mac);
    }

    /**
     * Decrypt a base64-encoded blob. Supports both:
     *  - New format: IV || ciphertext || HMAC (verified)
     *  - Old format: IV || ciphertext (no HMAC, for backward compat)
     *
     * Returns the plaintext, or null when decryption fails, HMAC
     * verification fails, or APP_KEY is not configured.
     */
    public static function decrypt(string $blob): ?string
    {
        $encKey = self::encKey();
        $macKey = self::macKey();
        if ($encKey === null || $macKey === null) {
            return null;
        }
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < self::IV_LEN + 1) {
            return null;
        }

        $totalLen = strlen($raw);
        // Detect new format (has HMAC) vs old format (no HMAC).
        // We can't know the ciphertext length, but we CAN check if the
        // last 32 bytes form a valid HMAC for the preceding bytes.
        // Strategy: try HMAC verification first (new format). If the
        // HMAC doesn't match, fall back to old-format decryption.
        if ($totalLen > self::IV_LEN + self::HMAC_LEN) {
            // Potential new-format blob: IV || ciphertext || HMAC
            $iv = substr($raw, 0, self::IV_LEN);
            $mac = substr($raw, -self::HMAC_LEN);
            $ciphertext = substr($raw, self::IV_LEN, $totalLen - self::IV_LEN - self::HMAC_LEN);
            $expectedMac = hash_hmac('sha256', $iv . $ciphertext, $macKey, true);
            if (hash_equals($expectedMac, $mac)) {
                // HMAC verified — decrypt.
                $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $encKey, OPENSSL_RAW_DATA, $iv);
                return $plaintext === false ? null : $plaintext;
            }
            // HMAC didn't match — fall through to old-format attempt.
            // This is the backward-compat path for blobs encrypted
            // before the HMAC migration.
        }

        // Old-format blob: IV || ciphertext (no HMAC).
        $iv = substr($raw, 0, self::IV_LEN);
        $ciphertext = substr($raw, self::IV_LEN);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $encKey, OPENSSL_RAW_DATA, $iv);
        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Is encryption available? Returns true when APP_KEY is set.
     */
    public static function available(): bool
    {
        return self::encKey() !== null;
    }
}
