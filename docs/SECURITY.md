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

- [ ] Scheduled backup of the database
      (`mysqldump --single-transaction`) **and** `storage/` + `public/uploads/`;
      restore tested at least once.
- [ ] Logs reviewed: `storage/logs/*-security.log` (auth failures,
      permission denials), `*-app.log` (errors), `mail.log`.
- [ ] Audit log (`/admin/audit-logs`) monitored for unexpected
      `roles.permissions_updated` / `user.*` events.
- [ ] PHP errors logged to file (`storage/logs/php-error.log`), `display_errors=Off`.
- [ ] MariaDB/MySQL and PHP versions kept patched; `pdo_mysql`, `openssl`,
      `mbstring`, `fileinfo` enabled.

## Verification smoke tests after deploy

1. Unauthenticated `/admin/dashboard` → redirect to `/login`.
2. POST any form without CSRF token → 419.
3. Sign in with a wrong password 5 times → throttled (429 message).
4. Sign in as a non-privileged role and open `/admin/users` → 403.
5. Response headers include `Content-Security-Policy`, `X-Frame-Options: DENY`,
   `X-Content-Type-Options: nosniff`.
6. A traceable error (e.g. stopped DB) shows the generic 500 page — no stack
   trace, no credentials.
