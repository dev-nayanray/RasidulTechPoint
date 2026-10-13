# Production Security Checklist

Work through every item before exposing the application to the internet.
The application fails safe on most of these, but deployment mistakes happen
outside the code.

## Hosting & exposure

- [ ] `DocumentRoot` points at `public/` only. Verify `https://host/.env`,
      `https://host/storage/logs/...` and `https://host/composer.json` all
      return **404**.
- [ ] HTTPS enabled site-wide (redirect HTTP → HTTPS); HSTS is sent
      automatically on secure requests.
- [ ] `mod_rewrite` enabled (`.htaccess` ships with the project); directory
      listing disabled (`Options -Indexes` is preset).
- [ ] `storage/` and `database/` are not readable by the web server user.

## Configuration

- [ ] `.env` exists on the server with **non-default** values and file
      permissions restricted (e.g. `chmod 640`, owner = deploy user).
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL` (bootstrap
      refuses the default in production).
- [ ] `SESSION_SECURE=true` (cookies only over HTTPS).
- [ ] Database user is least-privilege: `SELECT/INSERT/UPDATE/DELETE` on the
      one schema; **no** `DROP`/`FILE`/`GRANT`.
- [ ] `MAIL_DRIVER` set to a real transport before enabling customer-facing
      password reset (the shipped `log` driver only writes to storage/logs).
- [ ] No `--demo` seed data in production (`bin/seed.php --demo` refuses
      outside `APP_ENV=local`; verify `users` contains no `@rtp.local` staff).

## Accounts

- [ ] First administrator created only through `php bin/create-admin.php`
      (interactive, strong password). No public installer exists.
- [ ] Every staff account has an explicit minimal role; review
      `/admin/roles` after go-live.
- [ ] Demo staff users (`manager@rtp.local` etc.) exist **only** in local
      environments.

## Operations

- [ ] Scheduled backup of the database **and** `storage/` + `public/uploads/`
      via `php bin/backup.php` (see `docs/BACKUPS.md`). Schedule via cron:
      ```
      0 2 * * *  cd /var/www/rasidul-tech-point && /usr/bin/php bin/backup.php --retention=30 >> storage/logs/cron-backup.log 2>&1
      ```
- [ ] Restore procedure tested at least once in a **non-production**
      environment (`php bin/restore.php --from=<stamp> --target=rtp_restore --confirm`).
      Never run `bin/restore.php` against the configured production DB
      without `--i-know-this-is-production` — the tool refuses by default.
- [ ] Backup directory (`storage/backups/` by default; override with
      `BACKUP_DIR` env var) MUST live **outside** `public/` — the tool
      enforces this and writes a `.htaccess` deny rule + blank `index.html`
      as defense in depth.
- [ ] Optional GPG encryption at rest for off-site backups:
      `php bin/backup.php --gpg=admin@example.com` produces `.gpg` files
      (and removes the plaintext originals). Test decryption:
      `gpg --decrypt backup.sql.gz.gpg | gzip -dc | mysql rtp_restore`.
- [ ] Retention: default 30 days (override with `BACKUP_RETENTION_DAYS`
      env var or `--retention=N`). Older backups are pruned after each
      successful backup.
- [ ] Logs reviewed: `storage/logs/*-security.log` (auth failures,
      permission denials), `*-app.log` (errors), `mail.log`, `cron-backup.log`.
- [ ] Audit log (`/admin/audit-logs`) monitored for unexpected
      `roles.permissions_updated` / `user.*` / `settings.*` /
      `notification.*` / `coupon.*` events.
- [ ] PHP errors logged to file (`storage/logs/php-error.log`), `display_errors=Off`.
- [ ] MariaDB/MySQL and PHP versions kept patched; `pdo_mysql`, `openssl`,
      `mbstring`, `fileinfo` enabled.
- [ ] `APP_KEY` (32+ random bytes) set in `.env` — required for the
      secret-encryption layer (Prompt 39 payment provider keys, SMTP
      password, SMS API secret). Without `APP_KEY`, the system refuses
      to store any new secret.

## Cron / scheduled tasks

| Frequency | Command | Purpose |
|---|---|---|
| Daily 02:00 | `php bin/backup.php --retention=30` | Database + files backup; rotate old backups |
| Daily 03:00 | `php bin/migrate.php status \| grep -q 'No pending'` (alerting on the inverse) | Detect pending migrations on the live DB |
| Daily 04:00 | `php bin/restore.php --list \| mail -s 'RTP backups' ops@example.com` | Operator reminder of available backups |
| Hourly | `php -r '…call NotificationService::retryFailed()…'` | Retry failed notification deliveries (Prompt 38) |
| As needed | `php bin/deploy-check.php` (CI / post-deploy) | Production-readiness battery |

Never run `bin/restore.php` from cron — restore is a manual,
operator-supervised operation.

## Deployment checks

Run `php bin/deploy-check.php` after every deploy. Exits 0 on success,
1 on any failure (must block), 2 on any warning (informational).

The battery covers:
- `.env` exists and is non-default in production
- `APP_ENV` / `APP_DEBUG` / `APP_URL` consistency
- `APP_KEY` is set (required for secrets + sessions)
- `SESSION_SECURE=true` in production
- All migrations are applied (no pending)
- `public/` is the only web-exposed dir (no `.env`, `composer.json`,
  `storage/logs`, etc. leaked there)
- `storage/backups/` is not web-accessible
- Critical PHP extensions loaded (`pdo_mysql`, `openssl`, `mbstring`, `fileinfo`)
- `.env` file permissions are restrictive (chmod 0640 or better)
- No demo seed data (`*@rtp.local`) in production
- An active owner account exists

## Verification smoke tests after deploy

1. Unauthenticated `/admin/dashboard` → redirect to `/login`.
2. POST any form without CSRF token → 419.
3. Sign in with a wrong password 5 times → throttled (429 message).
4. Sign in as a non-privileged role and open `/admin/users` → 403.
5. Response headers include `Content-Security-Policy`, `X-Frame-Options: DENY`,
   `X-Content-Type-Options: nosniff`.
6. A traceable error (e.g. stopped DB) shows the generic 500 page — no stack
   trace, no credentials.

## Front-end code and the CSP

The Content-Security-Policy is `script-src 'self'; font-src 'self'`. Inline
`<script>` blocks, `onclick=`-style attributes and Google Fonts are blocked
without any visible error. Put JavaScript in `public/assets/js/` and load it
with `<script type="module" src="...">`, and self-host fonts under
`public/assets/fonts/`. See `docs/HERO_SLIDER.md` for a worked example.
