<?php

declare(strict_types=1);

/**
 * Database seeder.
 *
 *   php bin/seed.php          roles, permissions, business profile, locations, settings
 *   php bin/seed.php --demo   additionally seed LOCAL-ONLY demo data
 *                             (refuses to run outside APP_ENV=local)
 *
 * This command never creates administrator accounts — use bin/create-admin.php.
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Database\Seeder;
use App\Database\Seeders\BusinessProfileSeeder;
use App\Database\Seeders\DemoCatalogSeeder;
use App\Database\Seeders\DemoDataSeeder;
use App\Database\Seeders\RolePermissionSeeder;
use App\Support\Database\Connection;

$withDemo = in_array('--demo', $argv, true);

try {
    $classes = [
        RolePermissionSeeder::class,
        BusinessProfileSeeder::class,
    ];
    if ($withDemo) {
        $classes[] = DemoDataSeeder::class;
        $classes[] = DemoCatalogSeeder::class;
    }

    $seeder = new Seeder(Connection::pdo(), $classes);
    $count = $seeder->run(fn (string $line) => print($line . PHP_EOL));
    print("Done: {$count} seeder(s) executed." . PHP_EOL);
    if ($withDemo) {
        print('Demo staff logins: manager@rtp.local / cashier@rtp.local / inventory@rtp.local / technician@rtp.local' . PHP_EOL);
        print('Demo staff password: demo-password-123 (LOCAL ONLY)' . PHP_EOL);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'SEED FAILED: ' . $e->getMessage() . PHP_EOL);
    \App\Support\Logger::error('seed', $e->getMessage());
    exit(1);
}
