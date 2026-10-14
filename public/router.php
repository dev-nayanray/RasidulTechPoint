<?php

declare(strict_types=1);

/**
 * Router script for `php -S 127.0.0.1:8090 -t public public/router.php`.
 * Lets the built-in server deliver real static files and delegates
 * everything else to the front controller.
 *
 * Static assets get no-cache headers so edited JS modules are always
 * re-fetched during development (stale ES modules otherwise poison the
 * browser's module map until a cache clear).
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    // Serve the asset directly with no-cache headers so edited JS modules
    // are always re-fetched during development (return false would let the
    // built-in server send its own header-less response, and stale ES
    // modules then poison the browser's module map until a cache clear).
    $types = [
        'css' => 'text/css', 'js' => 'application/javascript', 'mjs' => 'application/javascript',
        'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'avif' => 'image/avif',
        'gif' => 'image/gif', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'map' => 'application/json', 'txt' => 'text/plain', 'webm' => 'video/webm',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Cache-Control: no-cache, must-revalidate');
    if (isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
    }
    header('Content-Length: ' . (string) filesize($file));
    readfile($file);
    return true;
}

require __DIR__ . '/index.php';
