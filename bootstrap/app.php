<?php

declare(strict_types=1);

/**
 * Application bootstrap: autoloading, environment, configuration, error handling.
 * Shared by the web front controller and all CLI commands.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require BASE_PATH . '/vendor/autoload.php';

App\Support\Env::load(BASE_PATH . '/.env');
App\Support\Config::load(BASE_PATH . '/config');

// All stored timestamps are UTC; presentation converts to app timezone.
date_default_timezone_set('UTC');

$isDebug = (bool) App\Support\Config::get('app.debug', false);
$isProd = App\Support\Config::get('app.env') === 'production';

ini_set('display_errors', $isDebug && !$isProd ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');
error_reporting(E_ALL);

// Convert PHP warnings/notices into exceptions in development so nothing is silenced.
if ($isDebug && !$isProd) {
    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
}

// Fail fast in production when required configuration is missing.
if ($isProd) {
    foreach (['app.url'] as $key) {
        if (App\Support\Config::get($key) === 'http://localhost') {
            throw new RuntimeException('APP_URL must be configured for production.');
        }
    }
}
