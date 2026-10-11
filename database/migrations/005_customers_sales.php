<?php

declare(strict_types=1);

/**
 * Customers, orders, order items and payments.
 *
 * - Orders use a non-sequential public reference (RTP-...) for customer
 *   visibility; the auto-increment id stays internal.
 * - order_items snapshot name/SKU/price at sale time so catalog changes
 *   never rewrite history.
 * - Order state and payment state are SEPARATE columns/flows.
 * - All money columns are DECIMAL(14,2). No floats for financial data.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE customers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                name VARCHAR(120) NOT NULL,
                phone VARCHAR(30) NOT NULL,
                email VARCHAR(190) NULL,
                address VARCHAR(255) NULL,
                city VARCHAR(100) NULL,
                notes VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_customers_user (user_id),
                UNIQUE KEY uq_customers_phone (phone),
                KEY idx_customers_name (name),
                CONSTRAINT fk_customers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                reference VARCHAR(24) NOT NULL,
                customer_id BIGINT UNSIGNED NOT NULL,
                channel ENUM('online','pos') NOT NULL DEFAULT 'online',
                status ENUM('pending','confirmed','processing','completed','cancelled','refunded') NOT NULL DEFAULT 'pending',
                payment_status ENUM('unpaid','partial','paid','refunded') NOT NULL DEFAULT 'unpaid',
                subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                discount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                currency CHAR(3) NOT NULL DEFAULT 'BDT',
                notes VARCHAR(500) NULL,
                placed_by BIGINT UNSIGNED NULL,
                completed_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_orders_reference (reference),
                KEY idx_orders_customer (customer_id, created_at),
                KEY idx_orders_status (status),
                KEY idx_orders_payment_status (payment_status),
                KEY idx_orders_created (created_at),
                CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT,
                CONSTRAINT fk_orders_placed_by FOREIGN KEY (placed_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_orders_total CHECK (subtotal >= 0 AND discount >= 0 AND total >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE order_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                inventory_unit_id BIGINT UNSIGNED NOT NULL,
                product_name VARCHAR(220) NOT NULL,
                sku VARCHAR(40) NOT NULL,
                unit_price DECIMAL(14,2) NOT NULL,
                line_total DECIMAL(14,2) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_order_items_unit (inventory_unit_id),
                KEY idx_order_items_order (order_id),
                CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
                CONSTRAINT fk_oi_unit FOREIGN KEY (inventory_unit_id)
                    REFERENCES inventory_units (id) ON DELETE RESTRICT,
                CONSTRAINT chk_oi_prices CHECK (unit_price >= 0 AND line_total >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE payments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                amount DECIMAL(14,2) NOT NULL,
                method ENUM('cash','card','bank_transfer','bkash','nagad','other') NOT NULL DEFAULT 'cash',
                status ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'completed',
                reference VARCHAR(120) NULL,
                received_by BIGINT UNSIGNED NULL,
                paid_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_payments_order (order_id),
                KEY idx_payments_paid_at (paid_at),
                KEY idx_payments_reference (reference),
                CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
                CONSTRAINT fk_payments_user FOREIGN KEY (received_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_payments_amount CHECK (amount > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        foreach (['payments', 'order_items', 'orders', 'customers'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    },
];
