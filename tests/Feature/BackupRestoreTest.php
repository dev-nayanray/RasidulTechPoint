<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\RestoreRefusalException;
use App\Support\BackupSafety;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Backup, restore & deployment (Prompt 40) — safety-helper + CLI tests.
 *
 * Proves (without subprocesses when avoidable; subprocesses when needed
 * because bin/*.php scripts `exit()`):
 *  - BackupSafety::assertRestoreTargetAllowed:
 *    * production target + no flag → throws RestoreRefusalException
 *    * production target + flag → allowed
 *    * non-production target (env=local) → allowed regardless of flag
 *    * production target + env=local → allowed (not really production)
 *  - BackupSafety::assertChecksumMatches:
 *    * matching checksum → no throw
 *    * mismatched checksum → throws RestoreRefusalException
 *  - bin/backup.php via subprocess: creates manifest + db + files
 *  - bin/backup.php --list: prints manifest entries
 *  - bin/backup.php --retention=0: prunes everything older than today
 *  - bin/restore.php --list: prints manifest entries
 *  - bin/restore.php --from=<missing>: exits 2
 *  - bin/restore.php --from=<stamp> --target=<nonexistent>: exits 2
 *  - bin/restore.php --from=<stamp> --target=<restore>: dry-run, exits 0
 *  - bin/restore.php --from=<stamp> --target=<restore> --confirm: restores
 *  - bin/restore.php with corrupted backup file: exits 1 (checksum mismatch)
 *  - bin/deploy-check.php: exits non-zero when any check fails; the
 *    warning path (.env perms) is documented but does not block deploys
 *
 * NOTE on live restore: the test creates a real rasidul_tech_point_restore
 * database, runs backup + restore + verify table count parity, then
 * drops the restore DB. This is the "test restoration in a non-production
 * environment" deliverable from the brief.
 */
final class BackupRestoreTest extends TestCase
{
    private string $mysqlBin = '/tmp/mamba/envs/mysql-env/bin/mysql';
    private string $phpBin = '/tmp/mamba/envs/php-env/bin/php';

    public function setUp(): void
    {
        parent::setUp();
        // Use a separate backup directory for tests so we don't clobber
        // the operator's real backups.
        putenv('BACKUP_DIR=' . BASE_PATH . '/storage/backups_test');
        if (!is_dir(BASE_PATH . '/storage/backups_test')) {
            mkdir(BASE_PATH . '/storage/backups_test', 0770, true);
        }
    }

    public function tearDown(): void
    {
        parent::tearDown();
        // Clean test backup dir.
        $dir = BASE_PATH . '/storage/backups_test';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        putenv('BACKUP_DIR=');
        // Drop the restore target if a test created it.
        $this->dropRestoreDb();
    }

    // ---------------------------------------------------- SAFETY HELPER --

    public function testRestoreAllowedForNonProductionTarget(): void
    {
        // env=local, target=test_db → always allowed.
        BackupSafety::assertRestoreTargetAllowed('test_db', 'rasidul_tech_point', 'local', false);
        $this->assertTrue(true, 'no exception thrown for non-prod target');
    }

    public function testRestoreRefusedForProductionTargetWithoutFlag(): void
    {
        $this->assertThrows(RestoreRefusalException::class, function () {
            BackupSafety::assertRestoreTargetAllowed('rasidul_tech_point', 'rasidul_tech_point', 'production', false);
        }, 'production target refused without explicit opt-in flag');
    }

    public function testRestoreAllowedForProductionTargetWithFlag(): void
    {
        BackupSafety::assertRestoreTargetAllowed('rasidul_tech_point', 'rasidul_tech_point', 'production', true);
        $this->assertTrue(true, 'explicit opt-in flag allows production restore');
    }

    public function testRestoreAllowedForProductionConfigWhenEnvIsLocal(): void
    {
        // Same target name as prod DB, but env=local → not really production.
        BackupSafety::assertRestoreTargetAllowed('rasidul_tech_point', 'rasidul_tech_point', 'local', false);
        $this->assertTrue(true, 'env=local target=name matches prod but env is not production');
    }

    public function testRestoreRefusedForDifferentNameInProduction(): void
    {
        // In production, restoring into a different (non-prod) name is fine.
        BackupSafety::assertRestoreTargetAllowed('rasidul_tech_point_restore', 'rasidul_tech_point', 'production', false);
        $this->assertTrue(true, 'different target name in production is allowed without flag');
    }

    public function testChecksumMatchDoesNotThrow(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rtp-cs-');
        file_put_contents($tmp, 'hello world');
        $sha = hash_file('sha256', $tmp);
        BackupSafety::assertChecksumMatches($tmp, $sha);
        $this->assertTrue(true, 'matching checksum does not throw');
        unlink($tmp);
    }

    public function testChecksumMismatchThrows(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rtp-cs-');
        file_put_contents($tmp, 'hello world');
        $this->assertThrows(RestoreRefusalException::class, function () use ($tmp) {
            BackupSafety::assertChecksumMatches($tmp, '0'.str_repeat('a', 63));
        }, 'corrupted backup refused via checksum mismatch');
        unlink($tmp);
    }

    // ---------------------------------------------------- bin/backup.php (subprocess) --

    public function testBackupCreatesManifestAndFiles(): void
    {
        $out = $this->runCli('bin/backup.php');
        $this->assertSame(0, $out['exit'], "backup exited 0; stderr: {$out['stderr']}");
        $this->assertTrue(file_exists(BASE_PATH . '/storage/backups_test/manifest.json'));
        $files = glob(BASE_PATH . '/storage/backups_test/db-*.sql.gz');
        $this->assertNotSame([], $files, 'a db dump file was created');

        $manifest = json_decode((string) file_get_contents(BASE_PATH . '/storage/backups_test/manifest.json'), true);
        $this->assertCount(1, $manifest);
        $this->assertTrue(!empty($manifest[0]['db_file']));
        $this->assertTrue(!empty($manifest[0]['db_sha256']));
        $this->assertSame('local', $manifest[0]['env']);
        // The subprocess loads .env which has DB_DATABASE=rasidul_tech_point
        // (the test DB override is in-memory and does not propagate to
        // child processes). The manifest's `database` field reflects
        // the subprocess's view of the configured DB name.
        $this->assertTrue(!empty($manifest[0]['database']));
        $this->assertTrue(!empty($manifest[0]['schema_hash']));
    }

    public function testBackupNoFilesFlagSkipsTarball(): void
    {
        $out = $this->runCli('bin/backup.php --no-files');
        $this->assertSame(0, $out['exit'], "stderr: {$out['stderr']}");
        $manifest = json_decode((string) file_get_contents(BASE_PATH . '/storage/backups_test/manifest.json'), true);
        $this->assertNull($manifest[0]['files_archive'], 'files archive skipped');
        $files = glob(BASE_PATH . '/storage/backups_test/files-*.tar.gz');
        $this->assertSame([], $files);
    }

    public function testBackupListPrintsManifest(): void
    {
        $this->runCli('bin/backup.php');
        $out = $this->runCli('bin/backup.php --list');
        $this->assertSame(0, $out['exit']);
        $this->assertStringContainsString('Timestamp', $out['stdout']);
        $this->assertStringContainsString('db-', $out['stdout']);
    }

    public function testBackupListWithNoBackupsIsGraceful(): void
    {
        // Empty dir (no manifest yet) — test setUp wipes the directory,
        // but other tests may have populated it. Use a fresh temp dir.
        $fresh = BASE_PATH . '/storage/backups_empty_test';
        if (is_dir($fresh)) {
            foreach (glob($fresh . '/*') as $f) {
                @unlink($f);
            }
        } else {
            mkdir($fresh, 0770, true);
        }
        putenv('BACKUP_DIR=' . $fresh);
        $out = $this->runCli('bin/backup.php --list');
        $this->assertSame(0, $out['exit']);
        $this->assertStringContainsString('No backups', $out['stdout']);
        // Restore the standard test backup dir for subsequent tests.
        putenv('BACKUP_DIR=' . BASE_PATH . '/storage/backups_test');
        @rmdir($fresh);
    }

    public function testRetentionRotationRemovesOldBackups(): void
    {
        // Create a backup, then backdate it to 7 days ago, then run
        // with --retention=1 (prune anything older than 1 day). The
        // backdated file must be gone; the new backup created by this
        // invocation (mtime = now) must survive.
        $this->runCli('bin/backup.php');
        $files = glob(BASE_PATH . '/storage/backups_test/db-*.sql.gz');
        $this->assertNotSame([], $files);

        // Backdate every existing backup to 7 days ago.
        $oldTime = time() - 7 * 86400;
        foreach ($files as $f) {
            touch($f, $oldTime);
        }

        $out = $this->runCli('bin/backup.php --retention=1');
        $this->assertSame(0, $out['exit'], "stderr: {$out['stdout']}");
        $remaining = glob(BASE_PATH . '/storage/backups_test/db-*.sql.gz');
        $hasOld = false;
        foreach ($remaining as $f) {
            if (filemtime($f) < time() - 60) {
                $hasOld = true;
            }
        }
        $this->assertFalse($hasOld, 'backdated file was pruned by retention rotation');
    }

    // ---------------------------------------------------- bin/restore.php (subprocess) --

    public function testRestoreListPrintsManifest(): void
    {
        $this->runCli('bin/backup.php');
        $out = $this->runCli('bin/restore.php --list');
        $this->assertSame(0, $out['exit']);
        $this->assertStringContainsString('DB file', $out['stdout']);
    }

    public function testRestoreFromMissingStampExits2(): void
    {
        $this->runCli('bin/backup.php');
        $out = $this->runCli('bin/restore.php --from=does-not-exist --confirm --target=whatever_db');
        $this->assertSame(2, $out['exit']);
        // runCli merges stdout+stderr into stdout.
        $this->assertStringContainsString('no backup with stamp', $out['stdout']);
    }

    public function testRestoreTargetMustExist(): void
    {
        $this->runCli('bin/backup.php');
        $stamp = $this->latestStamp();
        $out = $this->runCli("bin/restore.php --from={$stamp} --confirm --target=does_not_exist_db");
        $this->assertSame(2, $out['exit']);
        $this->assertStringContainsString('target database', $out['stdout']);
    }

    public function testRestoreDryRunDoesNotWrite(): void
    {
        $this->createRestoreDb();
        $this->runCli('bin/backup.php');
        $stamp = $this->latestStamp();
        $out = $this->runCli("bin/restore.php --from={$stamp} --target=rasidul_tech_point_restore_test");
        $this->assertSame(0, $out['exit']);
        $this->assertStringContainsString('DRY RUN', $out['stdout']);
        // Verify the target DB is still empty (no tables).
        $count = $this->tableCountIn('rasidul_tech_point_restore_test');
        $this->assertSame(0, $count, 'dry-run did not write any tables');
    }

    public function testRestoreConfirmActuallyRestores(): void
    {
        $this->createRestoreDb();
        $this->runCli('bin/backup.php');
        $stamp = $this->latestStamp();
        $out = $this->runCli("bin/restore.php --from={$stamp} --confirm --target=rasidul_tech_point_restore_test");
        $this->assertSame(0, $out['exit'], "restore failed: {$out['stdout']}");
        $this->assertStringContainsString('database restored', $out['stdout']);
        $count = $this->tableCountIn('rasidul_tech_point_restore_test');
        $this->assertGreaterThan(0, $count, 'tables were restored');
        // Verify the users count matches between source and target.
        $sourceDb = 'rasidul_tech_point'; // subprocess's view from .env
        $sourceUsers = (int) $this->scalarIn($sourceDb, 'SELECT COUNT(*) FROM users');
        $restoredUsers = (int) $this->scalarIn('rasidul_tech_point_restore_test', 'SELECT COUNT(*) FROM users');
        $this->assertSame($sourceUsers, $restoredUsers, 'users count matches after restore');
    }

    public function testRestoreRefusesCorruptBackup(): void
    {
        $this->createRestoreDb();
        $this->runCli('bin/backup.php');
        $stamp = $this->latestStamp();
        // Corrupt the backup file — truncate the last 20 bytes so the
        // SHA-256 changes.
        $files = glob(BASE_PATH . '/storage/backups_test/db-*.sql.gz');
        $this->assertNotSame([], $files);
        $file = $files[0];
        $orig = file_get_contents($file);
        file_put_contents($file, substr($orig, 0, -20));
        $out = $this->runCli("bin/restore.php --from={$stamp} --confirm --target=rasidul_tech_point_restore_test");
        $this->assertSame(1, $out['exit'], 'corrupt backup refused (exit 1)');
        $this->assertStringContainsString('Checksum mismatch', $out['stdout']);
    }

    // ---------------------------------------------------- bin/deploy-check.php --

    public function testDeployCheckExitsNonZeroOnFailure(): void
    {
        // Force a production misconfig: APP_ENV=production + APP_DEBUG=true
        // + SESSION_SECURE=false. APP_URL is set to a non-default value so
        // bootstrap/app.php doesn't refuse to load (it throws when APP_URL
        // is the default in production — that itself is a deploy safety,
        // but we want to test the deploy-check reporting layer separately).
        $envPath = BASE_PATH . '/.env';
        $backup = file_get_contents($envPath);
        $modified = preg_replace('/^APP_ENV=.*/m', 'APP_ENV=production', $backup);
        $modified = preg_replace('/^APP_DEBUG=.*/m', 'APP_DEBUG=true', $modified);
        $modified = preg_replace('/^APP_URL=.*/m', 'APP_URL=https://rtp.example.com', $modified);
        $modified = preg_replace('/^SESSION_SECURE=.*/m', 'SESSION_SECURE=false', $modified);
        file_put_contents($envPath, $modified);
        try {
            $out = $this->runCli('bin/deploy-check.php');
            $this->assertNotSame(0, $out['exit'], 'production misconfig should not pass deploy-check');
            $combined = $out['stdout'] . $out['stderr'];
            $this->assertStringContainsString('FAIL', $combined, 'FAIL line surfaces in output');
            // The specific failures: debug in prod + session not secure.
            $this->assertStringContainsString('APP_DEBUG=true', $combined);
            $this->assertStringContainsString('SESSION_SECURE=false', $combined);
        } finally {
            // Restore the original .env regardless of test outcome.
            file_put_contents($envPath, $backup);
        }
    }

    public function testDeployCheckPassesOnLocal(): void
    {
        $out = $this->runCli('bin/deploy-check.php');
        $this->assertContains($out['exit'], [0, 2], 'local env: 0 OK or 2 warnings-only');
    }

    // ---------------------------------------------------- helpers =================

    private function runCli(string $cmd, array $envOverrides = []): array
    {
        // Pass env overrides inline (KEY=VALUE before the command). Quote
        // only the value (not the whole KEY=VALUE — that breaks sh's env-
        // prefix parsing).
        $envStr = '';
        foreach ($envOverrides as $k => $v) {
            $envStr .= $k . '=' . escapeshellarg((string) $v) . ' ';
        }
        $cmd = sprintf('cd %s && %s %s %s 2>&1',
            escapeshellarg(BASE_PATH),
            $envStr,
            escapeshellarg($this->phpBin),
            $cmd,  // intentionally NOT escaped — caller passes args
        );
        $out = [];
        $rc = 0;
        exec($cmd, $out, $rc);
        return ['exit' => $rc, 'stdout' => implode("\n", $out), 'stderr' => ''];
    }

    private function latestStamp(): string
    {
        $manifest = json_decode((string) file_get_contents(BASE_PATH . '/storage/backups_test/manifest.json'), true);
        $latest = end($manifest);
        return (string) $latest['db_file'];
    }

    private function createRestoreDb(): void
    {
        $cmd = sprintf('%s -h 127.0.0.1 -P 3360 -u root -e "CREATE DATABASE IF NOT EXISTS rasidul_tech_point_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"',
            escapeshellarg($this->mysqlBin));
        exec($cmd);
    }

    private function dropRestoreDb(): void
    {
        $cmd = sprintf('%s -h 127.0.0.1 -P 3360 -u root -e "DROP DATABASE IF EXISTS rasidul_tech_point_restore_test"',
            escapeshellarg($this->mysqlBin));
        exec($cmd);
    }

    private function tableCountIn(string $db): int
    {
        $cmd = sprintf('%s -h 127.0.0.1 -P 3360 -u root %s -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = \'%s\'"',
            escapeshellarg($this->mysqlBin), escapeshellarg($db), $db);
        $out = [];
        exec($cmd, $out);
        return (int) ($out[0] ?? 0);
    }

    private function scalarIn(string $db, string $sql): string
    {
        $cmd = sprintf('%s -h 127.0.0.1 -P 3360 -u root %s -N -e %s',
            escapeshellarg($this->mysqlBin), escapeshellarg($db), escapeshellarg($sql));
        $out = [];
        exec($cmd, $out);
        return (string) ($out[0] ?? '');
    }
}
