<?php

declare(strict_types=1);

/**
 * Business hours: per-day open/close times for the storefront and
 * invoice numbering settings. Business hours are stored in a
 * dedicated table; invoice settings go into the existing settings
 * table via SettingService.
 *
 * day_of_week: 0=Sunday, 1=Monday, ..., 6=Saturday (PHP date('w')).
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE business_hours (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                day_of_week TINYINT UNSIGNED NOT NULL,
                is_closed TINYINT(1) NOT NULL DEFAULT 0,
                open_time TIME NULL,
                close_time TIME NULL,
                notes VARCHAR(100) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_business_hours_day (day_of_week),
                CONSTRAINT chk_bh_day CHECK (day_of_week BETWEEN 0 AND 6)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed default hours: Saturday–Thursday 10:00–20:00, Friday closed.
        $stmt = $pdo->prepare(
            'INSERT INTO business_hours (day_of_week, is_closed, open_time, close_time) VALUES (?, ?, ?, ?)'
        );
        $defaults = [
            [0, 0, '10:00:00', '20:00:00'], // Sunday
            [1, 0, '10:00:00', '20:00:00'], // Monday
            [2, 0, '10:00:00', '20:00:00'], // Tuesday
            [3, 0, '10:00:00', '20:00:00'], // Wednesday
            [4, 0, '10:00:00', '20:00:00'], // Thursday
            [5, 1, null, null],             // Friday closed
            [6, 0, '10:00:00', '20:00:00'], // Saturday
        ];
        foreach ($defaults as $row) {
            $stmt->execute($row);
        }

        // Default invoice settings in the settings table.
        $invoiceDefaults = [
            ['invoice_prefix', 'INV', 'string', 'invoices'],
            ['invoice_next_number', '1', 'int', 'invoices'],
            ['invoice_tax_name', 'VAT', 'string', 'invoices'],
            ['invoice_tax_rate', '0', 'string', 'invoices'],       // percentage, stored as string
            ['invoice_footer_text', '', 'string', 'invoices'],
            ['invoice_payment_terms', 'Payment due within 7 days.', 'string', 'invoices'],
        ];
        $exists = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = ?');
        $insert = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, setting_type, setting_group) VALUES (?, ?, ?, ?)'
        );
        foreach ($invoiceDefaults as [$key, $value, $type, $group]) {
            $exists->execute([$key]);
            if ((int) $exists->fetchColumn() === 0) {
                $insert->execute([$key, $value, $type, $group]);
            }
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS business_hours');
        $pdo->exec("DELETE FROM settings WHERE setting_group = 'invoices'");
    },
];
