<?php

declare(strict_types=1);

/**
 * POS and cashier panel (Prompt 23).
 *
 * What this migration adds:
 *
 *  - cashier_shifts: per-cashier session with opening float, closing
 *    float, expected cash (computed from cash payments collected during
 *    the shift + opening float), actual cash counted at close, and
 *    discrepancy. A cashier can have at most one open shift at a time;
 *    the service enforces this. Reconciliation is the act of comparing
 *    expected vs actual and recording the difference.
 *
 *  - held_sales: saved carts that the cashier parked while serving
 *    another customer. Items are stored as a JSON payload (snapshot
 *    of unit_id/sku/name/price at hold time). Held sales DO NOT
 *    reserve inventory at the DB level — when resumed, the service
 *    re-validates each unit_id is still 'available' and surfaces
 *    any that have since been sold to someone else. The cashier must
 *    remove the unavailable items before checkout.
 *
 *  - orders.discount_authorized_by: who authorised a discount above
 *    the cashier threshold. NULL when no discount was applied or
 *    when the discount was within the cashier's allowed range.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            ALTER TABLE orders
                ADD COLUMN discount_authorized_by BIGINT UNSIGNED NULL AFTER placed_by
        ");
        try {
            $pdo->exec(
                'ALTER TABLE orders ADD CONSTRAINT fk_orders_discount_authorizer
                 FOREIGN KEY (discount_authorized_by) REFERENCES users (id) ON DELETE SET NULL'
            );
        } catch (\Throwable) {}

        $pdo->exec("
            CREATE TABLE cashier_shifts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                status ENUM('open','closed','discrepancy') NOT NULL DEFAULT 'open',
                opening_float DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                expected_cash DECIMAL(14,2) NULL,
                actual_cash DECIMAL(14,2) NULL,
                discrepancy DECIMAL(14,2) NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ended_at DATETIME NULL,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_shifts_user (user_id, status),
                KEY idx_shifts_started (started_at),
                CONSTRAINT fk_shifts_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_shift_float CHECK (opening_float >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE held_sales (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                reference VARCHAR(24) NOT NULL,
                shift_id BIGINT UNSIGNED NULL,
                customer_id BIGINT UNSIGNED NULL,
                cashier_id BIGINT UNSIGNED NULL,
                customer_name VARCHAR(120) NOT NULL DEFAULT 'Walk-in customer',
                customer_phone VARCHAR(30) NULL,
                items_payload TEXT NOT NULL,
                subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                discount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                notes VARCHAR(500) NULL,
                status ENUM('held','resumed','cancelled','expired') NOT NULL DEFAULT 'held',
                held_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resumed_at DATETIME NULL,
                resumed_by BIGINT UNSIGNED NULL,
                cancelled_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_held_sales_reference (reference),
                KEY idx_held_status (status),
                KEY idx_held_cashier (cashier_id, status),
                KEY idx_held_shift (shift_id),
                CONSTRAINT fk_held_shift FOREIGN KEY (shift_id)
                    REFERENCES cashier_shifts (id) ON DELETE SET NULL,
                CONSTRAINT fk_held_customer FOREIGN KEY (customer_id)
                    REFERENCES customers (id) ON DELETE SET NULL,
                CONSTRAINT fk_held_cashier FOREIGN KEY (cashier_id)
                    REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_held_resumed_by FOREIGN KEY (resumed_by)
                    REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_held_total CHECK (subtotal >= 0 AND discount >= 0 AND total >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS held_sales');
        $pdo->exec('DROP TABLE IF EXISTS cashier_shifts');

        try { $pdo->exec('ALTER TABLE orders DROP FOREIGN KEY fk_orders_discount_authorizer'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE orders DROP COLUMN discount_authorized_by'); } catch (\Throwable) {}
    },
];
