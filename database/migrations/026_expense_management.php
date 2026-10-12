<?php

declare(strict_types=1);

/**
 * Expense management (Prompt 31).
 *
 * What this migration adds:
 *
 *  - expense_categories: admin-managed categories (rent, utilities,
 *    salaries, marketing, supplies, travel, other). Each category
 *    has a name, slug, description, active flag, and display order.
 *
 *  - expense_entries: per-expense records with sequential numbers
 *    (EXP-YYYY-NNNNN), category link, description, amount, expense
 *    date, payment account (cash/bank/bkash/nagad/card/other),
 *    payment reference, receipt attachment path, and a status
 *    lifecycle:
 *
 *      pending → approved → paid (terminal)
 *                       ↘ rejected (terminal)
 *
 *    Critical: approved expenses cannot be edited. The service
 *    enforces this — updateEntry() throws if the status is 'approved'
 *    or 'paid'. This prevents unauthorized edits to approved expenses
 *    and ensures the audit trail is trustworthy.
 *
 * Financial integrity:
 *  - Expense amounts are separate from sale revenue and payment
 *    reconciliation. They live on the expense_entries table, never
 *    in orders, payments, or reconciliation_batches.
 *  - The approval workflow (pending → approved → paid) separates
 *    the recording of an expense from the authorisation to pay it.
 *  - All financial changes are audited via app_audit().
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE expense_categories (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                description VARCHAR(255) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ec_slug (slug),
                KEY idx_ec_active (is_active, display_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed default categories.
        $defaults = [
            ['Rent', 'rent', 'Shop/office rent', 1],
            ['Utilities', 'utilities', 'Electricity, water, internet, gas', 2],
            ['Salaries', 'salaries', 'Staff salaries and wages', 3],
            ['Marketing', 'marketing', 'Advertising, social media, promotions', 4],
            ['Supplies', 'supplies', 'Office and shop supplies', 5],
            ['Travel', 'travel', 'Business travel and transportation', 6],
            ['Maintenance', 'maintenance', 'Equipment and facility maintenance', 7],
            ['Other', 'other', 'Miscellaneous expenses', 99],
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO expense_categories (name, slug, description, display_order) VALUES (?, ?, ?, ?)'
        );
        foreach ($defaults as $row) {
            $stmt->execute($row);
        }

        $pdo->exec("
            CREATE TABLE expense_entries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                expense_number VARCHAR(40) NOT NULL,
                category_id BIGINT UNSIGNED NOT NULL,
                description VARCHAR(255) NOT NULL,
                amount DECIMAL(14,2) NOT NULL,
                expense_date DATE NOT NULL,
                payment_account ENUM('cash','bank','bkash','nagad','card','other') NOT NULL DEFAULT 'cash',
                payment_reference VARCHAR(120) NULL,
                receipt_path VARCHAR(255) NULL,
                status ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
                approved_by BIGINT UNSIGNED NULL,
                approved_at DATETIME NULL,
                rejection_reason VARCHAR(255) NULL,
                paid_at DATETIME NULL,
                notes TEXT NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ee_number (expense_number),
                KEY idx_ee_category (category_id),
                KEY idx_ee_status (status),
                KEY idx_ee_date (expense_date),
                KEY idx_ee_payment_account (payment_account),
                CONSTRAINT fk_ee_category FOREIGN KEY (category_id) REFERENCES expense_categories (id) ON DELETE RESTRICT,
                CONSTRAINT fk_ee_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_ee_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_ee_amount CHECK (amount > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create the receipt upload directory with .htaccess protection.
        $dir = BASE_PATH . '/public/uploads/expenses';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $source = BASE_PATH . '/public/uploads/.htaccess';
            if (file_exists($source)) {
                copy($source, $htaccess);
            } else {
                file_put_contents($htaccess, "php_flag engine off\n");
            }
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS expense_entries');
        $pdo->exec('DROP TABLE IF EXISTS expense_categories');
    },
];
