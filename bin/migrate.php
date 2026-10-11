<?php

declare(strict_types=1);

/**
 * Migration runner.
 *
 *   php bin/migrate.php            apply pending migrations
 *   php bin/migrate.php status     show applied/pending migrations
 *   php bin/migrate.php rollback   roll back the last batch (reversible migrations only)
 *
 * Note: MySQL/MariaDB DDL auto-commits, so a migration that fails halfway
 * may leave partial changes; the failed version is NOT recorded and must
 * be repaired manually before re-running (see README).
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Support\Database\Connection;
use App\Support\Database\Migrator;

$command = $argv[1] ?? 'migrate';

try {
    $migrator = new Migrator(Connection::pdo(), BASE_PATH . '/database/migrations');
    $out = fn (string $line) => print($line . PHP_EOL);

    switch ($command) {
        case 'status':
            $out('Applied:');
            foreach ($migrator->applied() as $row) {
                $out("  {$row['version']}  (batch {$row['batch']}, {$row['applied_at']})");
            }
            $pending = $migrator->pending();
            $out($pending === [] ? 'No pending migrations.' : 'Pending:');
            foreach ($pending as $version) {
                $out("  {$version}");
            }
            break;

        case 'rollback':
            $count = $migrator->rollbackLastBatch($out);
            $out("Rolled back {$count} migration(s).");
            break;

        case 'migrate':
        default:
            $count = $migrator->migrate($out);
            $out($count > 0 ? "Done: {$count} migration(s) applied." : 'Database is up to date.');
            break;
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'MIGRATION FAILED: ' . $e->getMessage() . PHP_EOL);
    \App\Support\Logger::error('migrate', $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
    exit(1);
}
