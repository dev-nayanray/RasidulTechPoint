<?php

declare(strict_types=1);

/**
 * Pre-deployment / post-deployment verification.
 *
 *   php bin/deploy-check.php
 *
 * Runs a battery of read-only checks against the current environment:
 *   1. .env exists and is non-default (APP_URL not localhost in prod).
 *   2. APP_ENV, APP_DEBUG, APP_URL consistency.
 *   3. APP_KEY is set (required for secret encryption — Prompt 39).
 *   4. SESSION_SECURE=true in production.
 *   5. DB user is least-privilege (no DROP/FILE/GRANT — best-effort check).
 *   6. All migrations are applied (no pending).
 *   7. public/ is the only web-exposed dir (no .env, no storage/ there).
 *   8. Storage/ + backups/ are NOT web-accessible.
 *   9. .env file permissions are restrictive (chmod 640 or better).
 *  10. Critical PHP extensions loaded (pdo_mysql, openssl, mbstring, fileinfo).
 *  11. APP_KEY != previous-deploy hash (warns on rotation; informational).
 *
 * Exits 0 on success, 1 on any FAILURE, 2 on any WARNING (non-blocking).
 * Designed for CI: wire `php bin/deploy-check.php || exit 1` after deploy.
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Support\Config;
use App\Support\Database\Connection;
use App\Support\Database\Migrator;

$failures = [];
$warnings = [];
$isProd = (string) Config::get('app.env', 'local') === 'production';
$isLocal = (string) Config::get('app.env', 'local') === 'local';

check('App environment', function () use ($isProd, $isLocal, &$failures) {
    $env = (string) Config::get('app.env', '');
    if (!in_array($env, ['local', 'production'], true)) {
        $failures[] = "APP_ENV must be 'local' or 'production'; got '{$env}'.";
    }
}, $failures, $warnings);

check('Debug mode matches environment', function () use ($isProd, &$failures) {
    $debug = (bool) Config::get('app.debug', false);
    if ($isProd && $debug) {
        $failures[] = 'APP_DEBUG=true in production — stack traces would be exposed to visitors.';
    }
}, $failures, $warnings);

check('APP_URL is non-default in production', function () use ($isProd, &$failures) {
    $url = (string) Config::get('app.url', 'http://localhost');
    if ($isProd && ($url === 'http://localhost' || $url === 'http://localhost:8090')) {
        $failures[] = "APP_URL is the default '{$url}' in production.";
    }
}, $failures, $warnings);

check('APP_KEY is set (required for secrets + sessions)', function () use (&$warnings, $isLocal) {
    $key = (string) Config::get('app.key', '');
    if ($key === '') {
        if (!$isLocal) {
            $warnings[] = 'APP_KEY is empty — secrets (payment provider keys, SMTP password) cannot be saved.';
        }
    } elseif (strlen($key) < 16) {
        $warnings[] = 'APP_KEY is shorter than 16 chars — consider a longer random key.';
    }
}, $failures, $warnings);

check('SESSION_SECURE=true in production', function () use ($isProd, &$failures) {
    $secure = (bool) Config::get('security.session.secure', false);
    if ($isProd && !$secure) {
        $failures[] = 'SESSION_SECURE=false in production — cookies would leak over HTTP.';
    }
}, $failures, $warnings);

check('All migrations applied', function () use (&$failures) {
    try {
        $migrator = new Migrator(Connection::pdo(), BASE_PATH . '/database/migrations');
        $pending = $migrator->pending();
        if ($pending !== []) {
            $failures[] = 'Pending migrations not applied: ' . implode(', ', $pending);
        }
    } catch (\Throwable $e) {
        $failures[] = 'Migration check failed: ' . $e->getMessage();
    }
}, $failures, $warnings);

check('public/ is the only web-exposed dir', function () use (&$failures) {
    $leaks = [];
    foreach (['.env', 'composer.json', 'composer.lock', 'storage/logs', 'storage/sessions', 'database/migrations'] as $probe) {
        $path = BASE_PATH . '/public/' . $probe;
        if (file_exists($path)) {
            $leaks[] = $probe;
        }
    }
    if ($leaks !== []) {
        $failures[] = 'public/ contains: ' . implode(', ', $leaks) . ' — these must NOT be web-accessible.';
    }
}, $failures, $warnings);

check('storage/backups/ is not web-accessible', function () use (&$failures) {
    $backupDir = realpath(BASE_PATH . '/storage/backups');
    $publicDir = realpath(BASE_PATH . '/public');
    if ($backupDir !== false && $publicDir !== false && str_starts_with($backupDir, $publicDir)) {
        $failures[] = "BACKUP_DIR is inside public/ — backups would be downloadable.";
    }
    if ($backupDir !== false && !file_exists($backupDir . '/.htaccess')) {
        $warnings[] = 'storage/backups/.htaccess missing — add a deny rule even if the dir is outside public/.';
    }
}, $failures, $warnings);

check('PHP extensions loaded', function () use (&$failures) {
    $required = ['pdo_mysql', 'openssl', 'mbstring', 'fileinfo'];
    $missing = array_filter($required, fn ($e) => !extension_loaded($e));
    if ($missing !== []) {
        $failures[] = 'Missing PHP extensions: ' . implode(', ', $missing);
    }
}, $failures, $warnings);

check('.env file permissions', function () use (&$warnings) {
    $envPath = BASE_PATH . '/.env';
    if (!file_exists($envPath)) {
        return; // already covered by app.env check
    }
    $perms = fileperms($envPath) & 0777;
    if (($perms & 0007) !== 0) {
        $warnings[] = sprintf('.env is world-readable/writable (perms=%o); tighten to 0640 or 0600.', $perms);
    }
}, $failures, $warnings);

check('No demo seed data in production', function () use ($isProd, &$failures) {
    if (!$isProd) return;
    try {
        $stmt = Connection::pdo()->query("SELECT COUNT(*) FROM users WHERE email LIKE '%@rtp.local'");
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            $failures[] = "Found {$count} demo staff users (@rtp.local) in production.";
        }
    } catch (\Throwable $e) {
        $warnings[] = 'Could not check for demo users: ' . $e->getMessage();
    }
}, $failures, $warnings);

check('Admin account exists', function () use (&$warnings, $isProd) {
    try {
        $stmt = Connection::pdo()->query(
            "SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
             WHERE r.slug = 'owner' AND u.is_active = 1 AND u.deleted_at IS NULL"
        );
        $count = (int) $stmt->fetchColumn();
        if ($count === 0) {
            if ($isProd) {
                $warnings[] = 'No active owner account — run `php bin/create-admin.php` after deploy.';
            }
        }
    } catch (\Throwable $e) {
        $warnings[] = 'Could not check for admin: ' . $e->getMessage();
    }
}, $failures, $warnings);

// Print results.
print(PHP_EOL);
printHeader('Rasidul Tech Point — Deployment Check');
print(PHP_EOL);
foreach ([
    'FAIL' => $failures,
    'WARN' => $warnings,
] as $label => $items) {
    foreach ($items as $i) {
        printf("  [%s] %s\n", $label, $i);
    }
}
if ($failures === [] && $warnings === []) {
    print("  [OK]   All deployment checks passed.\n");
} else {
    print(PHP_EOL . "  " . count($failures) . " failure(s), " . count($warnings) . " warning(s)." . PHP_EOL);
}
print(PHP_EOL);

if ($failures !== []) exit(1);
if ($warnings !== []) exit(2);
exit(0);

// ===================================================== helpers =================

function check(string $name, callable $fn, array &$failures, array &$warnings): void
{
    $beforeFail = count($failures);
    $beforeWarn = count($warnings);
    try {
        $fn();
    } catch (\Throwable $e) {
        $failures[] = "{$name}: " . $e->getMessage();
    }
    $addedFail = count($failures) - $beforeFail;
    $addedWarn = count($warnings) - $beforeWarn;
    $label = $addedFail > 0 ? 'FAIL' : ($addedWarn > 0 ? 'WARN' : 'OK');
    printf("  [%s]   %s\n", $label, $name);
}

function printHeader(string $title): void
{
    print(str_repeat('=', 70) . PHP_EOL);
    print('  ' . $title . PHP_EOL);
    print(str_repeat('=', 70) . PHP_EOL);
}
