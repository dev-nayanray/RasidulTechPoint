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
