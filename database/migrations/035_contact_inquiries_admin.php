<?php

declare(strict_types=1);

/**
 * Contact inquiries admin (Marketing-Website Prompt 2 follow-up).
 *
 * The public contact form already persists to `contact_inquiries`
 * (migration 032); this migration adds what the back office needs:
 *
 *  - `admin_note` column for internal triage notes (never public).
 *  - `contact_inquiries.view` / `contact_inquiries.manage` permissions
 *    so the section is authorization-gated like every other module.
 *    Granted to `manager` for day-to-day triage; the `owner` role
 *    short-circuits permission checks in AuthService::can().
 *
 * Additive only — no existing column or data is touched.
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        // Idempotent: safe to re-run if a previous attempt half-applied.
        $hasColumn = $pdo->query("SHOW COLUMNS FROM contact_inquiries LIKE 'admin_note'")->fetchColumn() !== false;
        if (!$hasColumn) {
            $pdo->exec('ALTER TABLE contact_inquiries ADD COLUMN admin_note TEXT NULL AFTER order_reference');
        }

        $insert = $pdo->prepare(
            'INSERT INTO permissions (name, module, description)
             SELECT ?, ?, ?
             WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = ?)'
        );
        $insert->execute(['contact_inquiries.view', 'contact_inquiries', 'View contact form inquiries', 'contact_inquiries.view']);
        $insert->execute(['contact_inquiries.manage', 'contact_inquiries', 'Manage, reply to, and delete contact form inquiries', 'contact_inquiries.manage']);

        $pdo->exec(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             JOIN permissions p ON p.name IN ('contact_inquiries.view', 'contact_inquiries.manage')
             WHERE r.slug = 'manager'"
        );
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE contact_inquiries DROP COLUMN admin_note');
        $pdo->exec(
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.name IN ('contact_inquiries.view', 'contact_inquiries.manage')"
        );
        $pdo->exec("DELETE FROM permissions WHERE name IN ('contact_inquiries.view', 'contact_inquiries.manage')");
    },
];
