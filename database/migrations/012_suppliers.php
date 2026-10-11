<?php

declare(strict_types=1);

/**
 * Suppliers: profiles for businesses that supply inventory to Rasidul
 * Tech Point. Each supplier has contact details, address, notes, an
 * active status, and a manual outstanding balance (computed from
 * purchase orders once that module is built).
 *
 * The existing inventory_units.supplier_ref is a free-text VARCHAR(120)
 * — deleting a supplier does NOT break historical references because
 * they store the supplier name as text, not a FK. Suppliers are
 * soft-deleted (deleted_at) to preserve audit history.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE suppliers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(150) NOT NULL,
                slug VARCHAR(150) NOT NULL,
                contact_name VARCHAR(120) NULL,
                email VARCHAR(190) NULL,
                phone VARCHAR(30) NULL,
                address VARCHAR(255) NULL,
                city VARCHAR(100) NULL,
                country VARCHAR(100) NULL,
                notes TEXT NULL,
                outstanding_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_suppliers_slug (slug),
                KEY idx_suppliers_active (is_active),
                KEY idx_suppliers_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS suppliers');
    },
];
