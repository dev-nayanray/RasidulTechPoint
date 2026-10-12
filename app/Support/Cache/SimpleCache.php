<?php

declare(strict_types=1);

namespace App\Support\Cache;

/**
 * Tiny file-based cache for the public hero-slider endpoint.
 * Stores a JSON blob under storage/cache/<key>.json with an mtime-based
 * TTL. Any code that mutates hero_slides / hero_settings calls forget()
 * to invalidate.
 *
 * Deliberately dependency-free: it never blocks reads on a hit, and a
 * missing or corrupt cache file is silently regenerated on next read.
 */
final class SimpleCache
{
    private const DIR = 'storage/cache';

    private static function path(string $key): string
    {
        $dir = BASE_PATH . '/' . self::DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Whitelist key characters so the cache path is never escaped.
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        return $dir . '/' . $safe . '.json';
    }

    /**
     * Return cached value (already json-decoded) or null on miss.
     * If the cached entry has expired, returns null (regenerate).
     */
    public static function get(string $key, ?int $ttlSeconds = null): ?array
    {
        $path = self::path($key);
        if (!is_file($path)) {
            return null;
        }
        if ($ttlSeconds !== null && (time() - (int) filemtime($path)) > $ttlSeconds) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** Write a value (must be JSON-encodable array). */
    public static function set(string $key, array $data): void
    {
        $path = self::path($key);
        $tmp = $path . '.tmp';
        $bytes = file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        if ($bytes === false) {
            return;
        }
        @rename($tmp, $path);
    }

    /** Invalidate one key. Safe to call when no file exists. */
    public static function forget(string $key): void
    {
        $path = self::path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Wipe every cache entry whose key starts with the given prefix. */
    public static function forgetPrefix(string $prefix): void
    {
        $dir = BASE_PATH . '/' . self::DIR;
        if (!is_dir($dir)) {
            return;
        }
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $prefix);
        foreach ((glob($dir . '/' . $safe . '*') ?: []) as $file) {
            @unlink($file);
        }
    }
}
