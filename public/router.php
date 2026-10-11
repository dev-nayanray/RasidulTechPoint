<?php

declare(strict_types=1);

/**
 * Router script for `php -S 127.0.0.1:8090 -t public public/router.php`.
 * Lets the built-in server deliver real static files and delegates
 * everything else to the front controller.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false; // serve the static asset as-is
}

require __DIR__ . '/index.php';
