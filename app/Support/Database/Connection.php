<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Support\Config;

/**
 * Lazily-created shared PDO connection.
 *
 * - utf8mb4 charset, transaction-safe SQL mode.
 * - Emulated prepares DISABLED (real server-side prepares).
 * - Session time zone forced to UTC so DATETIME columns always store UTC.
 * - Exceptions enabled for every error.
 */
final class Connection
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::build(
                (string) Config::get('database.host'),
                (int) Config::get('database.port'),
                (string) Config::get('database.database'),
                (string) Config::get('database.username'),
                (string) Config::get('database.password'),
            );
        }
        return self::$pdo;
    }

    public static function build(string $host, int $port, string $database, string $username, string $password): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database
        );

        try {
            $pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            $pdo->exec("SET SESSION time_zone = '+00:00'");
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            // Detect the most common deployment fault early and tell the
            // operator exactly what to do — otherwise the user only sees a
            // generic 500 page and the admin is left guessing.
            if (str_contains($msg, 'could not find driver')) {
                throw new \RuntimeException(
                    'Database connection failed: the pdo_mysql extension is not loaded in PHP. '
                    . 'Install/enable pdo_mysql (e.g. `apt install php-mysql` '
                    . 'or add `extension=pdo_mysql` to php.ini) '
                    . 'then restart the web server or PHP-FPM.',
                    0,
                    $e
                );
            }
            throw new \RuntimeException(
                'Database connection failed: ' . $msg
                    . ' — check DB_* values in your .env file.',
                0,
                $e
            );
        }

        return $pdo;
    }

    /** Replace the shared connection (tests use a separate database). */
    public static function swap(\PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * Run a callback inside a transaction; rolls back on any throwable.
     * Reentrant: nested calls use SAVEPOINT/RELEASE so a service can call
     * another transactional service safely (partial rollbacks stay isolated).
     */
    private static int $transactionLevel = 0;

    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        $level = self::$transactionLevel;

        if ($level === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT nested_' . $level);
        }
        self::$transactionLevel++;

        try {
            $result = $work($pdo);
            if ($level === 0) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT nested_' . $level);
            }
            self::$transactionLevel--;
            return $result;
        } catch (\Throwable $e) {
            self::$transactionLevel--;
            if ($level === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT nested_' . $level);
                } catch (\PDOException) {
                    // connection-level failure; outer rollback will handle it
                }
            }
            throw $e;
        }
    }
}
