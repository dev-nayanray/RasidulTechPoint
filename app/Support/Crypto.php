<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Symmetric encryption helper for secrets at rest.
 *
 * Uses AES-256-CBC with a random 16-byte IV per value. The IV is
 * prepended to the ciphertext and base64-encoded as a single blob
 * so the storage column can be a plain TEXT.
 *
 * Key derivation: SHA-256 of APP_KEY (the same env var loaded into
 * Config::app.key). The hash output is 32 bytes — exactly the AES-256
 * key length.
 *
 * Failure modes:
 *  - When APP_KEY is empty (typical in dev before APP_KEY is set),
 *    encrypt() returns null and decrypt() returns null. setSecret()
 *    throws a ValidationException to surface the misconfiguration
 *    instead of silently storing a plaintext value.
 *  - When openssl_decrypt fails (wrong key / corrupted ciphertext),
 *    decrypt() returns null. Callers must handle null defensively.
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

    /**
     * Derive the 32-byte AES-256 key from APP_KEY. Returns null when
     * APP_KEY is empty (the app is not configured for secrets).
     */
    private static function key(): ?string
    {
        $appKey = (string) Config::get('app.key', '');
        if ($appKey === '') {
            return null;
        }
        return hash('sha256', $appKey, true); // raw binary 32 bytes
    }

    /**
     * Encrypt a plaintext string. Returns a base64-encoded blob of
     * (IV || ciphertext) ready for TEXT storage. Returns null when
     * APP_KEY is not configured.
     */
    public static function encrypt(string $plaintext): ?string
    {
        $key = self::key();
        if ($key === null) {
            return null;
        }
        $iv = random_bytes(self::IV_LEN);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return null;
        }
        return base64_encode($iv . $ciphertext);
    }

    /**
     * Decrypt a base64-encoded blob of (IV || ciphertext). Returns the
     * plaintext, or null when decryption fails (wrong key, corrupted
     * blob) or when APP_KEY is not configured.
     */
    public static function decrypt(string $blob): ?string
    {
        $key = self::key();
        if ($key === null) {
            return null;
        }
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < self::IV_LEN + 1) {
            return null;
        }
        $iv = substr($raw, 0, self::IV_LEN);
        $ciphertext = substr($raw, self::IV_LEN);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            return null;
        }
        return $plaintext;
    }

    /**
     * Is encryption available? Returns true when APP_KEY is set.
     */
    public static function available(): bool
    {
        return self::key() !== null;
    }
}
