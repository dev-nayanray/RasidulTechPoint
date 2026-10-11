# Rasidul Tech Point — Business Management System

Production-oriented foundation for a laptop retail & business management platform
(Dubai-imported used laptops, online shop + physical store), built with
**native PHP 8.3, MySQL/MariaDB, Tailwind CSS and modern vanilla JavaScript (ES modules)**.
No full-stack framework, no frontend framework, no jQuery, no Bootstrap.

> **Status: foundation release (Phase 1–6 complete).** Authentication, RBAC,
> catalog, inventory integrity, orders/payments, audit logging, admin console
> and storefront are real and database-backed. See *What is not built yet*.

---

## 1. Technology stack

| Layer      | Choice |
|------------|--------|
| Runtime    | PHP 8.3+ (modular custom MVC, PSR-4 via Composer) |
| Database   | MySQL 8.x / MariaDB 10.4+ (InnoDB, utf8mb4, PDO prepared statements) |
| Styling    | Tailwind CSS 3.4 — compiled locally, Play CDN never used |
| Scripts    | Vanilla JS ES modules (no framework) |
| Routing    | Custom lightweight router (groups, params, middleware, method spoofing) |
| Testing    | Dependency-free runner (`php bin/test.php`) with PHPUnit-style API |

Verified during development on **PHP 8.3.28 + MariaDB 10.4.32 (XAMPP)** on Windows,
served through PHP's built-in server.

## 2. Requirements

- PHP >= 8.3 with extensions: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`
- Composer 2.x
- Node.js 18+ / npm 8+ (frontend build only — **not needed in production**)
- MySQL 8.x or MariaDB 10.4+
- Git

## 3. Local setup (Windows or Linux)

```bash
git clone <repository-url> rasidul-tech-point
cd rasidul-tech-point

# 1. PHP dependencies (autoloader only — no framework is pulled in)
composer install

# 2. Frontend build tooling + compiled CSS
npm install
npm run build          # produces public/assets/css/app.css (minified)

# 3. Environment
copy .env.example .env        # Windows
# cp .env.example .env        # Linux/macOS
#   → set DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD
#   → keep APP_ENV=local and APP_DEBUG=true for development

# 4. Create the database (once)
mysql -u root -p -e "CREATE DATABASE rasidul_tech_point CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 5. Migrations + seeders
php bin/migrate.php
php bin/seed.php              # roles, permissions, business profile, settings
php bin/seed.php --demo       # LOCAL-ONLY demo catalog/inventory/orders

# 6. First administrator (secure CLI setup — no web installer!)
php bin/create-admin.php

# 7. Run
php -S 127.0.0.1:8090 -t public public/router.php
# → http://127.0.0.1:8090
```

Demo staff accounts (only with `--demo`, local env): `manager@rtp.local`,
`cashier@rtp.local`, `inventory@rtp.local`, `technician@rtp.local` — password
`demo-password-123`. These are **not** administrators.

## 4. CLI commands

| Command | Behaviour |
|---|---|
| `php bin/migrate.php` | Applies pending migrations in deterministic order; records each version only after success. |
| `php bin/migrate.php status` | Lists applied/pending migrations. |
| `php bin/migrate.php rollback` | Rolls back the last batch (only migrations defining a reversible `down`). |
| `php bin/seed.php` | Idempotent core seeds: roles, permissions, role matrix, business profile, locations, settings. |
| `php bin/seed.php --demo` | Local-only demo data; refuses outside `APP_ENV=local`. |
| `php bin/create-admin.php` | Interactive secure first-admin setup (`--name= --email= --password=` supported). Requires a ≥10-char password with letters and numbers; refuses when an active owner exists unless `--force`. |
| `php bin/test.php` | Runs the test suite against `rasidul_tech_point_test` (created automatically). |
| `php bin/check.php` | Structural self-check (no DB needed): every route maps to an existing controller method, every view/component target exists, JS imports resolve, built assets and critical files are present. Exits non-zero on any gap — suitable for CI. |

No installation or admin-creation route is exposed over HTTP.

## 5. Configuration

All runtime configuration comes from `.env` (never committed; `.env.example`
holds placeholders only). Key variables:

```
APP_ENV=local|production   APP_DEBUG=true|false   APP_URL=...   APP_TIMEZONE=Asia/Dhaka
DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD
SESSION_NAME / SESSION_LIFETIME / SESSION_SECURE / SESSION_HTTPONLY
LOGIN_MAX_ATTEMPTS / LOGIN_DECAY_SECONDS
MAIL_DRIVER=log|null   MAIL_FROM_ADDRESS / MAIL_FROM_NAME
```

- **Production safety:** with `APP_ENV=production` the bootstrap fails fast if
  `APP_URL` is left at its default, debug output is disabled, stack traces are
  never rendered to visitors, and the local-only password-reset link hint is
  hard-disabled.
- Timestamps are always **stored in UTC**; presentation converts through
  `APP_TIMEZONE` (helper `local_datetime()`). Currency is BDT (`money()` helper).

## 6. Architecture

```
rasidul-tech-point/
├── app/
│   ├── Controllers/        # Auth / Storefront / Account / Admin (thin, per-domain)
│   ├── Middleware/         # auth, guest, permission, csrf, security headers
│   ├── Models/ Repositories/ Services/ Policies/ Validators/ Exceptions/
│   ├── Support/            # Router, Request, Response, Session, Csrf, View,
│   │   └── Database/       #   Env, Config, Validator, Logger + PDO Connection & Migrator
│   └── Helpers/functions.php
├── bootstrap/app.php       # env + config + error handling (shared web & CLI)
├── config/                 # app, database, security, permissions, mail
├── database/migrations/    # 5 versioned migrations (25 tables)
├── database/… seeders      # roles/permissions, business profile, demo (local only)
├── public/                 # ONLY web-exposed directory (front controller, assets)
├── resources/views/        # layouts, components, storefront/account/admin/errors
├── routes/web.php          # public / auth / customer / admin(perm-gated) groups
├── storage/                # logs, cache, sessions, private (outside public/)
├── tests/                  # Unit / Feature / Security (92 tests)
└── bin/                    # migrate, seed, create-admin, test
```

Request lifecycle: `public/index.php` → bootstrap (env, config, error handlers)
→ session start → auth boot → middleware pipeline (security headers → CSRF →
auth/guest → permission) → controller → View render → Response send. Any
exception is converted centrally into a proper status page or JSON error
without leaking details in production.

## 7. Database schema (25 tables)

**Identity & access:** `users`, `roles`, `permissions`, `user_roles`,
`role_permissions`, `password_reset_tokens`, `login_attempts`, `audit_logs`

**Business foundation:** `business_profiles`, `locations`, `settings`, `notifications`

**Catalog:** `brands` → `product_models` ← `categories`, `product_images`

**Inventory:** `inventory_units` (per-physical-laptop: SKU, serial, grade, costs,
price, location, status machine), `stock_movements` (append-only history)

**Sales:** `customers` (1:1 optional `users`), `orders` (non-sequential public
`reference`), `order_items` (sale-time snapshots; unique `inventory_unit_id`
makes double-selling structurally impossible), `payments`

**System:** `migrations`

Key constraints: all money columns are `DECIMAL(14,2)`; FKs with `RESTRICT`
on audit-critical relations (units ↔ movements, order items ↔ units);
unique SKU/serial/phone/email/slug/reference; `CHECK` constraints on money
non-negativity; timestamps stored in UTC (`SET time_zone = '+00:00'` per
connection); soft deletes only where justified (`users`, `customers`,
`product_models`).

## 8. Security baseline (implemented & tested)

- PDO prepared statements everywhere; emulated prepares disabled.
- CSRF synchronizer token on every state-changing request (419 page otherwise).
- Output escaped via `e()`; CSP and baseline security headers on every response.
- Session: HttpOnly + SameSite=Lax (+ Secure in production), strict mode,
  ID regeneration on login, server-side invalidation on logout.
- Login throttling via `login_attempts` (default 5 failures / 15 min, per email **and** IP).
- Password resets: 60-minute, single-use, SHA-256-hashed tokens; no account
  existence disclosure; local-only dev link display.
- Permission middleware on every admin route **and** re-checks inside sensitive
  services (e.g. inventory transitions). Navigation hiding is never security.
- IDOR guard: customer order lookup is scoped by the signed-in customer.
- Guards: last-active-owner cannot be deactivated/demoted; only `roles.manage`
  holders may change role assignments; users cannot deactivate themselves.
- Audit trail for privileged actions (actor, action, entity, metadata, IP,
  correlation id). Secrets are redacted from logs by the logger.
- `public/` is the only web-exposed directory; `.htaccess` additionally blocks
  dotfiles, `.sql`, `.log`, `.env`, backups; uploads validate MIME + size.
- No default production credentials: the first admin exists only after
  `bin/create-admin.php`.

See `docs/SECURITY.md` for the production deployment checklist.

## 9. Testing

```bash
php bin/test.php          # everything (92 tests / ~1.4k assertions)
php bin/test.php Auth     # filter by class/file substring
```

Coverage: validator rules; router (params, spoofing, 404, middleware);
migrations (tables, FK rejection, unique indexes, decimal money, utf8mb4,
transaction rollback); auth (login success/fail, unknown email, deactivated
account, throttling, logout invalidation, full reset lifecycle incl. expiry
and reuse); RBAC (role matrices, middleware allow/deny, last-owner protection,
immediate effect of permission sync, mid-session deactivation); inventory
(receipt movement, happy path, every rejected transition, price floor,
append-only history, FK protection, competing reservations); orders
(server-side totals, snapshots, non-sequential references, double-sell
prevention, order/payment state separation, duplicate gateway references,
cancel returns units, customer scoping); security (CSRF accept/reject, XSS
escaping incl. view rendering, SQL-injection payloads, security headers,
cookie flags, `.env.example` hygiene, nothing dangerous under `public/`).

The suite runs against a dedicated `rasidul_tech_point_test` database that is
dropped and re-migrated automatically. MySQL/MariaDB must be reachable with
the credentials from `.env`.

## 10. Production deployment (Apache)

1. `composer install --no-dev --optimize-autoloader`
2. `npm ci && npm run build` (commit or upload the built CSS; Node is not needed at runtime)
3. Upload the project with `public/` as **DocumentRoot** — nothing else may be served.
4. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL`,
   least-privilege DB user, `SESSION_SECURE=true` (HTTPS required),
   `MAIL_DRIVER` configured for a real transport.
5. `php bin/migrate.php && php bin/seed.php && php bin/create-admin.php`
6. Follow `docs/SECURITY.md` (headers/TLS/backup hardening checklist).

Backups: `mysqldump --single-transaction --routines rasidul_tech_point > backup.sql`
plus `storage/` and `public/uploads/`. Restore by importing the dump into a
fresh database and pointing `.env` at it.

## 11. What is not built yet (planned phases)

Clearly labeled **"SOON"** in the admin sidebar — these pages do not exist yet,
and no fake data suggests otherwise:

- POS terminal UI (order service already supports `channel=pos` end-to-end)
- Supplier/purchase management, QA inspection workflow screens
- Warranty & repair tracking, delivery/courier management, CRM
- Expenses module, full finance reports, exports (CSV/PDF)
- Online checkout with payment gateway integration, delivery-address capture
- Product image uploads (schema ready in `product_images`; MIME validation helper present)
- Email transport beyond the log driver; background task runner
- Bengali localization (font stack prepared)

## 12. Known limitations

- `rollback` cannot reverse every migration; MySQL DDL auto-commits, so a failed
  migration may need manual repair before re-running (`bin/migrate.php status`
  shows the state; never run it blindly against production).
- Concurrent-reservation protection relies on InnoDB row locks; the automated
  test exercises the lock contention path with a short lock-wait timeout.
- Migration runner has no multi-environment diffing (no `migrate:fresh` on prod).
- Charts (Chart.js) not yet included — dashboard is tabular in this phase.
