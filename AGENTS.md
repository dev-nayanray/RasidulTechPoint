# PROJECT RULES FOR EVERY DEVELOPMENT TASK

You are working on Rasidul Tech Point, a laptop retail and business management system.

## Mandatory stack

- Raw PHP, no Laravel or full-stack PHP framework.
- MySQL with PDO and prepared statements.
- Tailwind CSS compiled for production.
- Modern vanilla JavaScript with ES modules.
- Existing modular project architecture.

## Before making changes

1. Inspect the existing code and database migrations.
2. Reuse existing layouts, components, services, routes, and conventions.
3. Check dependencies and permissions before changing a shared component.
4. Identify the exact feature scope and the files that need changes.
5. Preserve all existing working functionality and data.

## During implementation

1. Implement a complete, functional feature, not just its UI.
2. Connect forms, filters, actions, and tables to real PHP and MySQL operations.
3. Validate all inputs on the server.
4. Enforce authentication and permissions on every protected action.
5. Use CSRF protection, output escaping, PDO prepared statements, and safe error handling.
6. Use database transactions for operations involving multiple related records.
7. Add appropriate migrations, constraints, indexes, and audit events where required.
8. Use reusable Tailwind components and accessible responsive layouts.
9. Handle loading, success, error, empty, and validation states.
10. Use vanilla JavaScript only; do not introduce Laravel, React, Vue, Angular, Bootstrap, or jQuery.
11. Never use fake production data or hardcoded dashboard statistics.
12. Never overwrite or delete existing business data without explicit authorization.

## After implementation

1. Run relevant tests and available static checks.
2. Fix errors caused by the changes.
3. Verify database persistence and permission enforcement.
4. Test mobile and desktop layouts.
5. Update documentation if the feature adds configuration or commands.
6. Report files changed, tests actually executed, results, limitations, and the next recommended feature.

## Ground rules

- Do not claim success without verification. If the database or required service is unavailable, explain exactly what could not be tested.
- Implement only the feature requested in the current task. Do not start unrelated future modules.
- Keep the application compatible with previously completed features.

Reference: `README.md` (architecture, commands), `docs/SECURITY.md` (production checklist), `config/permissions.php` (RBAC catalog).

## Local development environment (micromamba)

The project is developed against PHP 8.5 and MySQL 9.7 installed via micromamba. The conda-forge `php` package is built WITHOUT `pdo_mysql`, so the extension must be compiled and loaded separately. If a request returns `500 — Something went wrong. The problem has been logged.`, the very first thing to check is whether `pdo_mysql` is loaded:

```bash
export PATH=/tmp/mamba/envs/php-env/bin:/tmp/mamba/envs/mysql-env/bin:$PATH
export LD_LIBRARY_PATH=/tmp/mamba/envs/mysql-env/lib:$LD_LIBRARY_PATH
php -m | grep pdo_mysql     # MUST list pdo_mysql
```

If `pdo_mysql` is missing, rebuild it from PHP source against the micromamba MySQL client:

```bash
cd /tmp && mkdir -p php-build && cd php-build
curl -sSL https://php.net/distributions/php-8.5.9.tar.gz -o php-8.5.9.tar.gz
tar xzf php-8.5.9.tar.gz
cd php-8.5.9/ext/pdo_mysql
/tmp/mamba/envs/php-env/bin/phpize
./configure --with-pdo-mysql=/tmp/mamba/envs/mysql-env \
            --with-php-config=/tmp/mamba/envs/php-env/bin/php-config
make -j2
mkdir -p /tmp/mamba/envs/php-env/lib/php/extensions/no-debug-non-zts-20250925/
cp modules/pdo_mysql.so /tmp/mamba/envs/php-env/lib/php/extensions/no-debug-non-zts-20250925/
# Then ensure php.ini loads it:
cat > /tmp/mamba/envs/php-env/lib/php.ini <<'INI'
extension=pdo_mysql.so
date.timezone = "UTC"
display_errors = Off
log_errors = On
error_reporting = E_ALL
INI
```

Start MySQL before running the test suite:

```bash
/tmp/mamba/envs/mysql-env/bin/mysqld_safe --defaults-file=/tmp/mysql.cnf --user=root &
/tmp/mamba/envs/mysql-env/bin/mysql -h 127.0.0.1 -P 3360 -u root -e "SELECT 1"
```

The full test suite is the source of truth for a clean state:

```bash
php bin/test.php             # expect: OK: 346 passed, 0 failed
```
