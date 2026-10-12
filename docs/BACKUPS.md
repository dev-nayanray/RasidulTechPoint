# Backup & Restore Procedures

This document describes the on-disk backup format, the backup / restore
CLI commands, the retention policy, and the restore-from-disaster runbook.

## 1. Backup tool

```
php bin/backup.php                       # create a new backup
php bin/backup.php --list                # list recent backups from the manifest
php bin/backup.php --retention=30        # rotate: keep last 30 days
php bin/backup.php --no-files             # skip the storage/ + uploads/ tarball
php bin/backup.php --gpg=admin@example.com  # GPG-encrypt both archives
```

### What is backed up

1. The full database schema + data via `mysqldump --single-transaction
   --routines --skip-lock-tables --no-tablespaces --set-gtid-purged=OFF`.
   `--single-transaction` produces a consistent InnoDB snapshot without
   blocking writes.
2. The `storage/` directory (logs, sessions, cache, private uploads)
   and the `public/uploads/` directory (uploaded images, banners,
   logos). The backup directory itself is excluded from the tarball to
   avoid recursion.

### Storage layout

```
storage/backups/
  ├─ manifest.json                  append-only log of every backup
  ├─ db-YYYYMMDD-HHMMSS-<rand>.sql.gz
  ├─ files-YYYYMMDD-HHMMSS-<rand>.tar.gz
  └─ db-…sql.gz.gpg / files-…tar.gz.gpg   (when --gpg is set)
```

The backup directory is private:
- It MUST live outside `public/`. The tool refuses to write backups
  inside `public/` and aborts with exit 2.
- The tool writes `.htaccess` (`Deny from all`) + `index.html` as
  defense in depth even when the directory is outside the web root.
- Override the default location with the `BACKUP_DIR` env var (e.g.
  point at `/var/backups/rasidul-tech-point/`).

### Manifest

`manifest.json` is an append-only JSON array. Each entry records:

```json
{
  "timestamp": "2026-10-12T13:46:25+00:00",
  "stamp": "20261012-134625",
  "env": "local",
  "database": "rasidul_tech_point",
  "db_file": "db-20261012-134625-2b269c.sql.gz",
  "db_size": 18248,
  "db_sha256": "f1f3a704f34fa048e8ab30f85b9e0744de5c30eccc6ddfe56e2344329154bfe5",
  "files_archive": "files-20261012-134625-2b269c.tar.gz",
  "files_size": 47377,
  "files_sha256": "006efa076d8561d15c8e96d54133a1cf27922b12fdac0dcd645e317e7a97410a",
  "gpg_encrypted": false,
  "schema_hash": "da39a3ee5e6b4b0d"
}
```

The `db_sha256` is verified by `bin/restore.php` BEFORE any data is
written — detects bit-rot or tampering on disk before the restore
pipeline opens the file.

The `schema_hash` is the SHA-1 of the sum of `SHOW CREATE TABLE` for
every table. The restore tool compares it to the target DB's current
hash and emits a warning on mismatch (a migration was likely applied
between backup and restore — `php bin/migrate.php` should be run after
the restore to catch up).

## 2. Restore tool

```
php bin/restore.php --list                                  # list available backups
php bin/restore.php --from=<stamp>                          # dry-run: print the plan, do not write
php bin/restore.php --from=<stamp> --confirm --target=<db>  # execute
php bin/restore.php --from=<stamp> --confirm --target=<db> --files  # also restore the files archive
```

### Safety guards (encoded in code)

1. **Refuse production target without explicit opt-in.** When
   `APP_ENV=production` AND `--target` matches the configured production
   database, the tool refuses (exit 2) unless
   `--i-know-this-is-production` is also present. This prevents an
   accidental `php bin/restore.php --confirm --target=rasidul_tech_point`
   in a dev shell from destroying live data.

2. **Verify the SHA-256 before opening the file.** A corrupt backup is
   refused (exit 1) with `RestoreRefusalException` — the on-disk file's
   hash must match the manifest's `db_sha256`.

3. **Dry-run by default.** Without `--confirm`, the tool prints every
   action it WOULD take and exits without touching the target DB.

4. **Target DB must already exist.** The tool never runs
   `CREATE DATABASE` — that requires privileges the least-priv DB user
   should not have. The operator creates the restore DB first:
   ```
   mysql -u root -e "CREATE DATABASE rtp_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   ```

5. **Verify table count after restore.** Even if mysql exits 0, the
   tool queries `information_schema.tables` to confirm the target has
   more than 0 tables — catches the case where gzip returns
   non-zero (SIGPIPE) but mysql wrote nothing.

6. **Schema-hash warning.** If the target's current schema hash
   differs from the backup's, the tool prints a WARNING and recommends
   `php bin/migrate.php` after the restore. The restore is NOT blocked
   — the operator decides.

### Restoring GPG-encrypted backups

If the backup was created with `--gpg=user@example.com`, the dump file
is saved as `.gpg`. The restore tool detects this, calls `gpg --decrypt`
to a temp file, and pipes the result through `gzip -dc | mysql`. The
plaintext temp file is deleted after the restore. The original
plaintext dump file was deleted at backup time — only the `.gpg`
artifact exists on disk.

## 3. Retention

Default retention: 30 days. Override with the `BACKUP_RETENTION_DAYS`
env var or `--retention=N` (in days). Older backups are pruned at the
end of each successful backup invocation.

The retention rotation:
1. Globs `db-*.sql*` and `files-*.tar.gz*` in the backup dir.
2. Deletes any file whose `filemtime` is older than `time() - retention_days * 86400`.
3. Prunes manifest entries whose `db_file` no longer exists on disk.

## 4. Cron schedule (recommended)

```
# /etc/cron.d/rasidul-tech-point
SHELL=/bin/sh

# Daily 02:00 — backup + retention
0 2 * * *  deploy-user  cd /var/www/rasidul-tech-point && /usr/bin/php bin/backup.php --retention=30 >> storage/logs/cron-backup.log 2>&1

# Daily 03:00 — alert if pending migrations exist on the live DB
0 3 * * *  deploy-user  cd /var/www/rasidul-tech-point && /usr/bin/php bin/migrate.php status | grep -q 'No pending' || echo "Pending migrations on production DB" | mail -s 'RTP ALERT' ops@example.com

# Daily 04:00 — operator reminder of available backups
0 4 * * *  deploy-user  cd /var/www/rasidul-tech-point && /usr/bin/php bin/restore.php --list | mail -s 'RTP backups available' ops@example.com

# Hourly — retry failed notification deliveries (Prompt 38)
0 * * * *  deploy-user  cd /var/www/rasidul-tech-point && /usr/bin/php -r 'require "bootstrap/app.php"; App\Services\NotificationService::retryFailed();' >> storage/logs/cron-notif-retry.log 2>&1
```

Never schedule `bin/restore.php` from cron — restore is always a manual,
operator-supervised operation.

## 5. Disaster recovery runbook

**Scenario:** production database is corrupted; restore from last
night's backup.

1. **Stop the bleeding.** Put the site in maintenance mode (e.g.
   `mv public/.htaccess public/.htaccess.real && cp public/maintenance.htaccess public/.htaccess`).
   Or stop Apache.
2. **Identify the backup.** From the production server:
   ```
   php bin/restore.php --list
   ```
   Pick the most recent successful backup. Avoid any with `status=failed`
   in the manifest (would not be listed — failed backups are not
   recorded as manifest entries).
3. **Test in a non-production environment first.** On a staging server
   with a copy of the production `.env` (but `APP_ENV=local`):
   ```
   mysql -u root -e "CREATE DATABASE rtp_drill CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   php bin/restore.php --from=<stamp> --confirm --target=rtp_drill
   php bin/migrate.php status   # verify schema is up-to-date
   ```
   Spot-check the data (orders, customers, financial totals).
4. **Restore to production** — explicit confirmation required:
   ```
   php bin/restore.php --from=<stamp> --confirm --target=rasidul_tech_point --i-know-this-is-production
   ```
   The tool will refuse unless `--i-know-this-is-production` is present
   AND `APP_ENV=production`.
5. **Re-apply any pending migrations.** The backup is a snapshot from
   last night; migrations applied today (if any) need re-applying:
   ```
   php bin/migrate.php
   ```
6. **Restore the files archive** (only if the storage/uploaded files
   were also lost — e.g. disk failure):
   ```
   php bin/restore.php --from=<stamp> --confirm --target=rasidul_tech_point --i-know-this-is-production --files
   ```
7. **Bring the site back.** Restore `.htaccess`, restart Apache, smoke-test
   `/admin/dashboard`, `/admin/orders`, `/admin/payments`.
8. **Post-mortem.** File an incident report. Document the root cause.
   Verify the next night's backup runs cleanly.
