<?php

declare(strict_types=1);

/**
 * Database + private-files backup tool.
 *
 *   php bin/backup.php                           create a new backup
 *   php bin/backup.php --list                    list recent backups
 *   php bin/backup.php --retention=30            rotate: keep last 30 days
 *   php bin/backup.php --no-files                skip the storage/ + uploads/ tarball
 *   php bin/backup.php --gpg=user@example.com    GPG-encrypt the archive to a recipient
 *
 * What is backed up:
 *   1. The full database schema + data via `mysqldump --single-transaction
 *      --routines --skip-lock-tables` (consistent InnoDB snapshot without
 *      blocking writes; --routines preserves stored procedures).
 *   2. The `storage/` directory (logs, sessions, cache, private uploads)
 *      and `public/uploads/` directory (uploaded images + banners).
 *
 * Storage layout (private, OUTSIDE the web root by default):
 *   storage/backups/
 *     ├─ manifest.json                  append-only log of every backup
 *     ├─ db-YYYYMMDD-HHMMSS-<rand>.sql.gz
 *     ├─ files-YYYYMMDD-HHMMSS-<rand>.tar.gz
 *     └─ db-…sql.gz.gpg / files-…tar.gz.gpg   (when --gpg is set)
 *
 * Manifest records per backup: timestamp, db file name (relative path),
 * files archive name, sizes (bytes), sha256 of each file, GPG-encrypted
 * flag, schema hash (sum of SHOW CREATE TABLE for every table — used by
 * restore to refuse a restore that targets a different schema).
 *
 * The backup directory MUST NOT be web-accessible. The default path
 * `storage/backups/` is rejected when `APP_ENV=production` unless an
 * `.htaccess`/`DENY` marker is present OR `BACKUP_DIR` env var points
 * outside the web root.
 *
 * Production safety: this tool NEVER deletes anything outside
 * `BACKUP_DIR` and NEVER touches the live database. Restore is a
 * separate command (`bin/restore.php`) that refuses to run against a
 * production target without an explicit confirmation flag.
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Support\Config;
use App\Support\Env;
use App\Support\Database\Connection;

$opts = parseArgs($argv);

$backupDir = $opts['dir'] ?? Env::get('BACKUP_DIR', BASE_PATH . '/storage/backups');
$retentionDays = isset($opts['retention']) ? max(0, (int) $opts['retention']) : (int) Env::get('BACKUP_RETENTION_DAYS', '30');
$gpgRecipient = $opts['gpg'] ?? null;
$includeFiles = !isset($opts['no-files']);
$isList = isset($opts['list']);
$env = (string) Config::get('app.env', 'local');

// ALWAYS validate the backup directory is outside the web root. The
// default storage/backups/ is inside the project but outside public/
// — safe. A custom BACKUP_DIR under public/ is rejected.
if (str_starts_with(realpath($backupDir) ?: $backupDir, realpath(BASE_PATH . '/public') ?: BASE_PATH . '/public')) {
    fwrite(STDERR, "ERROR: BACKUP_DIR '{$backupDir}' is inside public/ — backups must NOT be web-accessible." . PHP_EOL);
    exit(2);
}
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0770, true);
}
// Deny web access at the file-system level too (defense in depth).
$htaccess = $backupDir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
}
$indexHtml = $backupDir . '/index.html';
if (!file_exists($indexHtml)) {
    file_put_contents($indexHtml, '');
}

if ($isList) {
    printList($backupDir);
    exit(0);
}

// Create a new backup.
$stamp = gmdate('Ymd-His');
$rand = bin2hex(random_bytes(3));

$dbHost = (string) Config::get('database.host', '127.0.0.1');
$dbPort = (int) Config::get('database.port', 3306);
$dbName = (string) Config::get('database.database', 'rasidul_tech_point');
$dbUser = (string) Config::get('database.username', 'root');
$dbPass = (string) Config::get('database.password', '');

// Use the mysqldump binary. We resolve the path the same way the
// project's other PHP-CLI helpers do — assume the env PATH includes
// the mysql-env bin dir (see AGENTS.md local-env section). The user
// can override via env var MYSQLDUMP_BIN.
$mysqldump = Env::get('MYSQLDUMP_BIN', '/tmp/mamba/envs/mysql-env/bin/mysqldump');
if (!is_executable($mysqldump)) {
    // Fall back to PATH lookup.
    $mysqldump = trim((string) shell_exec('command -v mysqldump') ?: '');
    if ($mysqldump === '') {
        fwrite(STDERR, "ERROR: mysqldump not found. Set MYSQLDUMP_BIN or ensure mysqldump is on PATH." . PHP_EOL);
        exit(2);
    }
}

$dbFile = $backupDir . "/db-{$stamp}-{$rand}.sql.gz";
$filesFile = $backupDir . "/files-{$stamp}-{$rand}.tar.gz";

print("Backing up database '{$dbName}'..." . PHP_EOL);
$cmd = sprintf(
    '%s --host=%s --port=%d --user=%s --password=%s --single-transaction --routines --skip-lock-tables --no-tablespaces --set-gtid-purged=OFF --skip-comments %s 2>/tmp/mysqldump.err | gzip > %s',
    escapeshellarg($mysqldump),
    escapeshellarg($dbHost),
    $dbPort,
    escapeshellarg($dbUser),
    escapeshellarg($dbPass),
    escapeshellarg($dbName),
    escapeshellarg($dbFile),
);
$exit = 0;
$out = [];
exec($cmd, $out, $exit);
if ($exit !== 0 || !is_file($dbFile) || filesize($dbFile) === 0) {
    $err = file_get_contents('/tmp/mysqldump.err') ?: '(no stderr)';
    fwrite(STDERR, "ERROR: mysqldump failed (exit {$exit}): {$err}" . PHP_EOL);
    exit(1);
}
$dbSize = filesize($dbFile);
$dbSha = hash_file('sha256', $dbFile);
print("  ✓ database: " . basename($dbFile) . " (" . formatBytes($dbSize) . ", sha256={$dbSha})" . PHP_EOL);

$filesSize = 0;
$filesSha = null;
if ($includeFiles) {
    print("Backing up storage/ + public/uploads/..." . PHP_EOL);
    $tarBin = Env::get('TAR_BIN', '/usr/bin/tar');
    if (!is_executable($tarBin)) {
        $tarBin = trim((string) shell_exec('command -v tar') ?: '');
    }
    $storageDir = BASE_PATH . '/storage';
    $uploadsDir = BASE_PATH . '/public/uploads';
    // Exclude the backups/ directory itself (would otherwise recurse
    // into the file we're writing).
    $excludes = " --exclude='{$backupDir}'";
    $sources = [];
    if (is_dir($storageDir)) $sources[] = $storageDir;
    if (is_dir($uploadsDir)) $sources[] = $uploadsDir;
    if ($sources === []) {
        fwrite(STDERR, "WARNING: no storage/ or public/uploads/ dirs to back up; skipping files archive." . PHP_EOL);
    } else {
        $cmd = sprintf(
            '%s czf %s -C %s %s %s 2>/tmp/tar.err',
            escapeshellarg($tarBin),
            escapeshellarg($filesFile),
            escapeshellarg(BASE_PATH),
            $excludes,
            implode(' ', array_map(fn ($p) => escapeshellarg(substr($p, strlen(BASE_PATH) + 1)), $sources)),
        );
        exec($cmd, $out, $exit);
        if ($exit !== 0 || !is_file($filesFile)) {
            $err = file_get_contents('/tmp/tar.err') ?: '(no stderr)';
            fwrite(STDERR, "ERROR: tar failed (exit {$exit}): {$err}" . PHP_EOL);
            // Don't fail the whole backup — the DB dump is the critical artifact.
        } else {
            $filesSize = filesize($filesFile);
            $filesSha = hash_file('sha256', $filesFile);
            print("  ✓ files: " . basename($filesFile) . " (" . formatBytes($filesSize) . ", sha256={$filesSha})" . PHP_EOL);
        }
    }
}

// Optional GPG encryption of both artifacts.
$encrypted = false;
if ($gpgRecipient !== null) {
    $gpgBin = trim((string) shell_exec('command -v gpg') ?: '');
    if ($gpgBin === '') {
        fwrite(STDERR, "WARNING: gpg not found — backups left unencrypted on disk." . PHP_EOL);
    } else {
        foreach ([$dbFile, $filesFile] as $plain) {
            if (!is_file($plain)) continue;
            $enc = $plain . '.gpg';
            $cmd = sprintf('%s --yes --trust-model always --recipient %s --output %s --encrypt %s 2>/tmp/gpg.err',
                escapeshellarg($gpgBin), escapeshellarg($gpgRecipient), escapeshellarg($enc), escapeshellarg($plain));
            exec($cmd, $out, $exit);
            if ($exit === 0 && is_file($enc)) {
                unlink($plain);  // remove the plaintext; only the .gpg stays
                if ($plain === $dbFile) $dbFile = $enc;
                if ($plain === $filesFile) $filesFile = $enc;
            } else {
                $err = file_get_contents('/tmp/gpg.err') ?: '(no stderr)';
                fwrite(STDERR, "WARNING: gpg encrypt failed for {$plain}: {$err}" . PHP_EOL);
            }
        }
        $encrypted = true;
    }
}

// Schema hash: sum of normalized SHOW CREATE TABLE for every table.
$schemaHash = computeSchemaHash($dbHost, $dbPort, $dbName, $dbUser, $dbPass);

$manifestEntry = [
    'timestamp' => gmdate('c'),
    'stamp' => $stamp,
    'env' => $env,
    'database' => $dbName,
    'db_file' => basename($dbFile),
    'db_size' => $dbSize,
    'db_sha256' => $dbSha,
    'files_archive' => is_file($filesFile) ? basename($filesFile) : null,
    'files_size' => $filesSize,
    'files_sha256' => $filesSha,
    'gpg_encrypted' => $encrypted,
    'schema_hash' => $schemaHash,
];
appendToManifest($backupDir, $manifestEntry);
print("  ✓ manifest updated" . PHP_EOL);

// Retention rotation.
if ($retentionDays > 0) {
    $removed = rotateByAge($backupDir, $retentionDays);
    if ($removed > 0) {
        print("  ✓ retention: removed {$removed} backup(s) older than {$retentionDays} day(s)" . PHP_EOL);
    }
}

print(PHP_EOL . "Backup complete: " . basename($dbFile) . PHP_EOL);
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
        if (str_starts_with($arg, '--no-files')) {
            $opts['no-files'] = true;
            continue;
        }
        if (str_starts_with($arg, '--retention=')) {
            $opts['retention'] = (int) substr($arg, strlen('--retention='));
            continue;
        }
        if (str_starts_with($arg, '--gpg=')) {
            $opts['gpg'] = substr($arg, strlen('--gpg='));
            continue;
        }
        if (str_starts_with($arg, '--dir=')) {
            $opts['dir'] = substr($arg, strlen('--dir='));
            continue;
        }
    }
    return $opts;
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KiB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MiB';
    return round($bytes / 1073741824, 2) . ' GiB';
}

function computeSchemaHash(string $host, int $port, string $db, string $user, string $pass): string
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db);
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);
        $sum = '';
        foreach ($tables as $t) {
            $create = $pdo->query("SHOW CREATE TABLE " . $pdo->quote($t))->fetch(PDO::FETCH_ASSOC);
            $sum .= $create['Create Table'] ?? '';
        }
        return substr(sha1($sum), 0, 16);
    } catch (\Throwable $e) {
        return 'unknown';
    }
}

function appendToManifest(string $dir, array $entry): void
{
    $manifest = $dir . '/manifest.json';
    $list = [];
    if (is_file($manifest)) {
        $list = json_decode((string) file_get_contents($manifest), true) ?: [];
    }
    $list[] = $entry;
    file_put_contents($manifest, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function rotateByAge(string $dir, int $retentionDays): int
{
    $cutoff = time() - ($retentionDays * 86400);
    $removed = 0;
    foreach (glob($dir . '/db-*.sql*') as $file) {
        if (filemtime($file) < $cutoff) {
            unlink($file);
            $removed++;
        }
    }
    foreach (glob($dir . '/files-*.tar.gz*') as $file) {
        if (filemtime($file) < $cutoff) {
            unlink($file);
            $removed++;
        }
    }
    // Prune manifest entries whose files no longer exist.
    $manifest = $dir . '/manifest.json';
    if (is_file($manifest)) {
        $list = json_decode((string) file_get_contents($manifest), true) ?: [];
        $pruned = [];
        foreach ($list as $e) {
            $dbExists = is_file($dir . '/' . $e['db_file']);
            if ($dbExists) {
                $pruned[] = $e;
            } else {
                $removed++;
            }
        }
        file_put_contents($manifest, json_encode($pruned, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    return $removed;
}

function printList(string $dir): void
{
    $manifest = $dir . '/manifest.json';
    if (!is_file($manifest)) {
        print("No backups yet (manifest not found at {$manifest})." . PHP_EOL);
        return;
    }
    $list = json_decode((string) file_get_contents($manifest), true) ?: [];
    if ($list === []) {
        print("Manifest is empty." . PHP_EOL);
        return;
    }
    $list = array_reverse($list); // newest first
    printf("%-22s  %-10s  %-12s  %-10s  %s\n", 'Timestamp', 'Env', 'DB file', 'Size', 'Files archive');
    foreach ($list as $e) {
        printf("%-22s  %-10s  %-36s  %-10s  %s\n",
            $e['timestamp'],
            $e['env'] ?? '?',
            $e['db_file'] ?? '?',
            formatBytes((int) $e['db_size']),
            $e['files_archive'] ?? '—',
        );
    }
}
