<?php

declare(strict_types=1);

/**
 * Restore from a backup created by bin/backup.php.
 *
 *   php bin/restore.php --list                       list available backups
 *   php bin/restore.php --from=<stamp>               print what WOULD happen, do not write
 *   php bin/restore.php --from=<stamp> --confirm     execute the restore
 *   php bin/restore.php --from=<stamp> --confirm --target=<db>
 *       restore into a specific database name (must NOT be the configured
 *       production database unless --i-know-this-is-production is set)
 *
 * Safety:
 *   1. The restore target MUST be different from the configured production
 *      database (Config::database.database), OR --i-know-this-is-production
 *      must be present. This prevents an accidental `mysql rasidul_tech_point`
 *      in a CI/dev shell from destroying production data.
 *   2. The restore target MUST already exist (we never CREATE DATABASE —
 *      that requires privileges the least-priv DB user should not have).
 *   3. Without --confirm, the script runs in DRY-RUN mode: it prints every
 *      action it WOULD take and exits without touching the target database.
 *   4. The script NEVER drops the configured production database. The
 *      target's existing data is overwritten by the SQL dump (which is
 *      written to first DROP-and-recreate every table), but only when
 *      --confirm + (different target OR --i-know-this-is-production).
 *   5. Schema-hash check: the manifest's schema_hash is compared to the
 *      target database's current schema hash. A mismatch produces a warning
 *      (not a refusal) — the restore still works, but the operator should
 *      investigate (often a migration was applied between backup and restore).
 *
 * Restore is a destructive operation. ALWAYS test in a non-production
 * environment first. The shared project rules forbid running destructive
 * commands against production without explicit confirmation — this script
 * encodes that rule in code.
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Support\Config;
use App\Support\Env;

$opts = parseArgs($argv);

$backupDir = $opts['dir'] ?? Env::get('BACKUP_DIR', BASE_PATH . '/storage/backups');
if (!is_dir($backupDir)) {
    fwrite(STDERR, "ERROR: backup directory '{$backupDir}' does not exist." . PHP_EOL);
    exit(2);
}

if (isset($opts['list']) || !isset($opts['from'])) {
    printList($backupDir);
    exit(0);
}

$stamp = (string) $opts['from'];
$entry = findManifestEntry($backupDir, $stamp);
if ($entry === null) {
    fwrite(STDERR, "ERROR: no backup with stamp '{$stamp}' in manifest." . PHP_EOL);
    exit(2);
}

$dbFile = $backupDir . '/' . $entry['db_file'];
if (!is_file($dbFile)) {
    fwrite(STDERR, "ERROR: backup file '{$dbFile}' is missing — retention rotation may have removed it." . PHP_EOL);
    exit(2);
}

// SAFETY: verify the on-disk SHA-256 matches the manifest (bit-rot /
// tampering detection). Centralized in BackupSafety so tests share the
// exact same rule.
try {
    \App\Support\BackupSafety::assertChecksumMatches($dbFile, (string) $entry['db_sha256']);
} catch (\App\Exceptions\RestoreRefusalException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$confirm = isset($opts['confirm']);
$target = $opts['target'] ?? (string) Config::get('database.database', 'rasidul_tech_point');
$prodConfiguredDb = (string) Config::get('database.database', 'rasidul_tech_point');
$appEnv = (string) Config::get('app.env', 'local');
$iKnowThisIsProduction = isset($opts['i-know-this-is-production']);

// SAFETY 1: refuse production target without explicit confirmation.
// The logic is centralized in App\Support\BackupSafety so tests and the
// CLI share the exact same rule.
$isProductionTarget = ($target === $prodConfiguredDb) && $appEnv === 'production';
try {
    \App\Support\BackupSafety::assertRestoreTargetAllowed(
        $target, $prodConfiguredDb, $appEnv, $iKnowThisIsProduction,
    );
} catch (\App\Exceptions\RestoreRefusalException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
}

// SAFETY 2: target DB must already exist (we never CREATE DATABASE).
$dbHost = (string) Config::get('database.host', '127.0.0.1');
$dbPort = (int) Config::get('database.port', 3306);
$dbUser = (string) Config::get('database.username', 'root');
$dbPass = (string) Config::get('database.password', '');
if (!targetDatabaseExists($dbHost, $dbPort, $target, $dbUser, $dbPass)) {
    fwrite(STDERR, "ERROR: target database '{$target}' does not exist. Create it first:" . PHP_EOL);
    fwrite(STDERR, "  mysql -u root -e \"CREATE DATABASE {$target} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\"" . PHP_EOL);
    exit(2);
}

// Schema-hash warning (does not block the restore).
$currentHash = computeSchemaHash($dbHost, $dbPort, $target, $dbUser, $dbPass);
if ($entry['schema_hash'] !== 'unknown' && $currentHash !== $entry['schema_hash']) {
    fwrite(STDERR, "WARNING: schema hash mismatch (backup={$entry['schema_hash']}, target={$currentHash})." . PHP_EOL);
    fwrite(STDERR, "  Restore will proceed — but a migration was likely applied between the backup and now." . PHP_EOL);
    fwrite(STDERR, "  After restore, run `php bin/migrate.php` to re-apply any missing migrations." . PHP_EOL);
}

// Print the plan.
printHeader($entry, $target, $currentHash, $isProductionTarget, $iKnowThisIsProduction);

if (!$confirm) {
    print(PHP_EOL . "DRY RUN. To execute, re-run with --confirm." . PHP_EOL);
    exit(0);
}

// EXECUTE.
$mysqlBin = Env::get('MYSQL_BIN', '/tmp/mamba/envs/mysql-env/bin/mysql');
if (!is_executable($mysqlBin)) {
    $mysqlBin = trim((string) shell_exec('command -v mysql') ?: '');
    if ($mysqlBin === '') {
        fwrite(STDERR, "ERROR: mysql client not found. Set MYSQL_BIN." . PHP_EOL);
        exit(2);
    }
}

print(PHP_EOL . "Restoring database..." . PHP_EOL);
// If the dump is GPG-encrypted, decrypt to a temp stream first.
$dumpStream = $dbFile;
$tmpPlain = null;
if (str_ends_with($dbFile, '.gpg')) {
    $gpgBin = trim((string) shell_exec('command -v gpg') ?: '');
    if ($gpgBin === '') {
        fwrite(STDERR, "ERROR: backup is GPG-encrypted but gpg is not available." . PHP_EOL);
        exit(2);
    }
    $tmpPlain = tempnam(sys_get_temp_dir(), 'rtp-restore-');
    $cmd = sprintf('%s --decrypt --output %s %s 2>/tmp/gpg-decrypt.err',
        escapeshellarg($gpgBin), escapeshellarg($tmpPlain), escapeshellarg($dbFile));
    exec($cmd, $out, $exit);
    if ($exit !== 0) {
        $err = file_get_contents('/tmp/gpg-decrypt.err') ?: '(no stderr)';
        fwrite(STDERR, "ERROR: gpg decrypt failed: {$err}" . PHP_EOL);
        unlink($tmpPlain);
        exit(1);
    }
    $dumpStream = $tmpPlain;
}

$pipe = str_ends_with($dumpStream, '.gz') || (!str_ends_with($dumpStream, '.gpg') && isGzFile($dumpStream))
    ? "gzip -dc " . escapeshellarg($dumpStream) . " | "
    : "cat " . escapeshellarg($dumpStream) . " | ";

// Use MYSQL_PWD env var to avoid the "Using a password on the command
// line is insecure" warning. mysql prints that warning to stderr and
// exit code stays 0 — but we want to suppress it so genuine errors
// don't drown in noise.
$env = array_merge(getenv() ?: [], ['MYSQL_PWD' => $dbPass]);
$cmd = sprintf(
    '%s %s --host=%s --port=%d --user=%s %s 2>/tmp/mysql-restore.err',
    $pipe,
    escapeshellarg($mysqlBin),
    escapeshellarg($dbHost),
    $dbPort,
    escapeshellarg($dbUser),
    escapeshellarg($target),
);
$descriptors = [
    ['pipe', 'r'],   // stdin (we don't use it)
    ['pipe', 'w'],   // stdout
    ['file', '/tmp/mysql-restore.err', 'a'],
];
$proc = proc_open($cmd, $descriptors, $pipes, null, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "ERROR: could not spawn restore pipeline." . PHP_EOL);
    exit(1);
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$exit = proc_close($proc);
if ($tmpPlain !== null) {
    unlink($tmpPlain);
}

// A successful restore returns exit 0 from the mysql client. gzip may
// return non-zero (SIGPIPE / "broken pipe") when mysql closes the
// stream after a syntax error — we re-check by counting tables in the
// target DB rather than trusting the pipe's exit code.
$tablesAfter = countTablesInTarget($dbHost, $dbPort, $target, $dbUser, $dbPass);
if ($tablesAfter === 0) {
    $err = file_get_contents('/tmp/mysql-restore.err') ?: '(no stderr)';
    fwrite(STDERR, "ERROR: restore produced 0 tables in target '{$target}' (mysql exit {$exit}). stderr: {$err}" . PHP_EOL);
    exit(1);
}

print("  ✓ database restored into '{$target}' ({$tablesAfter} tables)" . PHP_EOL);

// Optionally restore the files archive.
$filesArchive = $entry['files_archive'] ?? null;
$restoreFiles = isset($opts['files']);
if ($filesArchive !== null && $restoreFiles) {
    $archivePath = $backupDir . '/' . $filesArchive;
    if (is_file($archivePath)) {
        $tarBin = Env::get('TAR_BIN', '/usr/bin/tar');
        print("Restoring files archive..." . PHP_EOL);
        $cmd = sprintf('%s xzf %s -C %s 2>/tmp/tar-restore.err',
            escapeshellarg($tarBin), escapeshellarg($archivePath), escapeshellarg(BASE_PATH));
        exec($cmd, $out, $exit);
        if ($exit !== 0) {
            $err = file_get_contents('/tmp/tar-restore.err') ?: '(no stderr)';
            fwrite(STDERR, "WARNING: file restore failed: {$err}" . PHP_EOL);
        } else {
            print("  ✓ files restored to " . BASE_PATH . "/storage + /public/uploads" . PHP_EOL);
        }
    }
} elseif ($filesArchive !== null) {
    print("  (files archive exists but was not restored — re-run with --files to restore)" . PHP_EOL);
}

print(PHP_EOL . "Restore complete." . PHP_EOL);
if ($isProductionTarget) {
    print("WARNING: you restored INTO THE PRODUCTION DATABASE. Verify schema with `php bin/migrate.php status`." . PHP_EOL);
} else {
    print("Target is non-production. To make it active, point DB_DATABASE at '{$target}' in .env." . PHP_EOL);
}
exit(0);

// ===================================================== helpers =================

function parseArgs(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--list')) {
            $opts['list'] = true;
            continue;
        }
        if (str_starts_with($arg, '--confirm')) {
            $opts['confirm'] = true;
            continue;
        }
        if (str_starts_with($arg, '--files')) {
            $opts['files'] = true;
            continue;
        }
        if (str_starts_with($arg, '--i-know-this-is-production')) {
            $opts['i-know-this-is-production'] = true;
            continue;
        }
        if (str_starts_with($arg, '--from=')) {
            $opts['from'] = substr($arg, strlen('--from='));
            continue;
        }
        if (str_starts_with($arg, '--target=')) {
            $opts['target'] = substr($arg, strlen('--target='));
            continue;
        }
        if (str_starts_with($arg, '--dir=')) {
            $opts['dir'] = substr($arg, strlen('--dir='));
            continue;
        }
    }
    return $opts;
}

function findManifestEntry(string $dir, string $stamp): ?array
{
    $manifest = $dir . '/manifest.json';
    if (!is_file($manifest)) return null;
    $list = json_decode((string) file_get_contents($manifest), true) ?: [];
    foreach ($list as $e) {
        if (($e['stamp'] ?? null) === $stamp) return $e;
        if (($e['db_file'] ?? '') === $stamp) return $e;
    }
    return null;
}

function printList(string $dir): void
{
    $manifest = $dir . '/manifest.json';
    if (!is_file($manifest)) {
        print("No backups yet." . PHP_EOL);
        return;
    }
    $list = json_decode((string) file_get_contents($manifest), true) ?: [];
    if ($list === []) {
        print("Manifest is empty." . PHP_EOL);
        return;
    }
    $list = array_reverse($list);
    printf("%-22s  %-10s  %-36s  %-10s  %s\n", 'Timestamp', 'Env', 'DB file (=--from=)', 'Size', 'Files archive');
    foreach ($list as $e) {
        printf("%-22s  %-10s  %-36s  %-10s  %s\n",
            $e['timestamp'],
            $e['env'] ?? '?',
            $e['db_file'] ?? '?',
            formatBytes((int) $e['db_size']),
            $e['files_archive'] ?? '—',
        );
    }
    print(PHP_EOL . "Restore with: php bin/restore.php --from=<stamp> --confirm --target=<db>" . PHP_EOL);
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KiB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MiB';
    return round($bytes / 1073741824, 2) . ' GiB';
}

function isGzFile(string $file): bool
{
    $f = fopen($file, 'rb');
    if (!$f) return false;
    $magic = fread($f, 2);
    fclose($f);
    return $magic === "\x1f\x8b";
}

function targetDatabaseExists(string $host, int $port, string $target, string $user, string $pass): bool
{
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$target]);
        return $stmt->fetchColumn() !== false;
    } catch (\Throwable) {
        return false;
    }
}

function countTablesInTarget(string $host, int $port, string $target, string $user, string $pass): int
{
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $target);
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $pdo->quote($target))->fetchColumn();
    } catch (\Throwable) {
        return 0;
    }
}

function computeSchemaHash(string $host, int $port, string $db, string $user, string $pass): string
{
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db);
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);
        $sum = '';
        foreach ($tables as $t) {
            $create = $pdo->query("SHOW CREATE TABLE " . $pdo->quote($t))->fetch(PDO::FETCH_ASSOC);
            $sum .= $create['Create Table'] ?? '';
        }
        return substr(sha1($sum), 0, 16);
    } catch (\Throwable) {
        return 'unknown';
    }
}

function printHeader(array $entry, string $target, string $currentHash, bool $isProd, bool $iKnow): void
{
    print("═══════════════════════════════════════════════════════════════" . PHP_EOL);
    print("  RESTORE PLAN" . PHP_EOL);
    print("═══════════════════════════════════════════════════════════════" . PHP_EOL);
    printf("  Backup timestamp : %s\n", $entry['timestamp']);
    printf("  Backup env       : %s\n", $entry['env'] ?? '?');
    printf("  Backup DB        : %s\n", $entry['database'] ?? '?');
    printf("  DB dump file     : %s\n", $entry['db_file']);
    printf("  DB dump size     : %s\n", formatBytes((int) $entry['db_size']));
    printf("  DB dump sha256   : %s\n", $entry['db_sha256']);
    printf("  Schema hash      : %s (target current: %s)\n", $entry['schema_hash'], $currentHash);
    printf("  Files archive    : %s\n", $entry['files_archive'] ?? '—');
    printf("  Restore target   : %s\n", $target);
    printf("  Production flag  : %s\n", $isProd ? 'YES (production target)' : 'no');
    if ($isProd) {
        printf("  --i-know-this-is-production: %s\n", $iKnow ? 'YES' : 'NO (would refuse)');
    }
    print("═══════════════════════════════════════════════════════════════" . PHP_EOL);
}
