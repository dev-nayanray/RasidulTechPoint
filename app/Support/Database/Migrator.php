<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Support\Config;

/**
 * Versioned migration runner.
 *
 * - Tracks applied versions in a `migrations` table.
 * - Runs pending migrations in filename order.
 * - A migration is only recorded after it finishes without error.
 * - DDL in MySQL/MariaDB is auto-committing, so a failed migration may
 *   leave partial DDL behind; `--status` reports such cases and the
 *   operator must repair manually (documented in README).
 */
final class Migrator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $migrationsDir,
    ) {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                version VARCHAR(190) NOT NULL PRIMARY KEY,
                batch INT NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<string,string> version => filename */
    public function available(): array
    {
        $files = [];
        foreach (scandir($this->migrationsDir) ?: [] as $file) {
            if (preg_match('/^(\d{3}_\w+)\.php$/', $file, $m)) {
                $files[$m[1]] = $file;
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /** @return array<int,array{version:string,batch:int,applied_at:string}> */
    public function applied(): array
    {
        return $this->pdo->query(
            'SELECT version, batch, applied_at FROM migrations ORDER BY version'
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string> */
    public function pending(): array
    {
        $applied = array_column($this->applied(), 'version');
        return array_keys(array_diff_key($this->available(), array_flip($applied)));
    }

    /**
     * @param callable(string): void $output
     * @return int number of migrations applied
     */
    public function migrate(callable $output): int
    {
        $pending = $this->pending();
        if ($pending === []) {
            $output('Nothing to migrate.');
            return 0;
        }

        $batch = (int) $this->pdo->query('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn() + 1;
        $count = 0;

        foreach ($pending as $version) {
            $migration = require $this->migrationsDir . '/' . $version . '.php';
            $up = is_array($migration)
                ? ($migration['up'] ?? null)
                : $migration;
            if (!is_callable($up)) {
                throw new \RuntimeException("Migration {$version} must return a callable or ['up' => callable].");
            }

            $output('Migrating: ' . $version);
            $up($this->pdo);

            $stmt = $this->pdo->prepare('INSERT INTO migrations (version, batch) VALUES (?, ?)');
            $stmt->execute([$version, $batch]);
            $count++;
            $output('Migrated:  ' . $version);
        }

        return $count;
    }

    /**
     * Rollback the most recent batch. Only safe for migrations that define a
     * `down`; destructive schema changes are documented as non-reversible.
     *
     * @param callable(string): void $output
     */
    public function rollbackLastBatch(callable $output): int
    {
        $rows = $this->pdo->query('SELECT version FROM migrations ORDER BY batch DESC, version DESC')->fetchAll(\PDO::FETCH_COLUMN);
        if ($rows === []) {
            $output('Nothing to roll back.');
            return 0;
        }
        $latestBatchStmt = $this->pdo->query('SELECT MAX(batch) FROM migrations');
        $latestBatch = (int) $latestBatchStmt->fetchColumn();

        $inBatch = $this->pdo->prepare('SELECT version FROM migrations WHERE batch = ? ORDER BY version DESC');
        $inBatch->execute([$latestBatch]);
        $versions = $inBatch->fetchAll(\PDO::FETCH_COLUMN);

        $count = 0;
        foreach ($versions as $version) {
            $path = $this->migrationsDir . '/' . $version . '.php';
            $migration = is_file($path) ? require $path : null;
            if (!is_array($migration) || !isset($migration['down']) || !is_callable($migration['down'])) {
                $output("Skipping {$version}: no reversible down() defined (destructive or structural).");
                continue;
            }
            $output('Rolling back: ' . $version);
            ($migration['down'])($this->pdo);
            $stmt = $this->pdo->prepare('DELETE FROM migrations WHERE version = ?');
            $stmt->execute([$version]);
            $count++;
        }
        return $count;
    }
}
