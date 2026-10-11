<?php

declare(strict_types=1);

/**
 * Inventory: individual physical laptop units and their full movement history.
 *
 * inventory_units statuses:
 *   in_transit → awaiting_inspection → available ⇄ reserved → sold
 *   damaged / written_off reachable from defined states (see InventoryService).
 *
 * stock_movements is append-only: it is the audit trail and must never be
 * physically deleted.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE inventory_units (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                sku VARCHAR(40) NOT NULL,
                serial_number VARCHAR(80) NULL,
                product_model_id BIGINT UNSIGNED NOT NULL,
                condition_grade ENUM('A','B','C','D') NOT NULL DEFAULT 'B',
                status ENUM('in_transit','awaiting_inspection','available','reserved','sold',
                            'returned','damaged','written_off') NOT NULL DEFAULT 'awaiting_inspection',
                purchase_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                landed_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                selling_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                min_selling_price DECIMAL(14,2) NULL,
                location_id BIGINT UNSIGNED NULL,
                supplier_ref VARCHAR(120) NULL,
                purchase_ref VARCHAR(120) NULL,
                received_at DATETIME NULL,
                inspected_at DATETIME NULL,
                inspected_by BIGINT UNSIGNED NULL,
                sold_at DATETIME NULL,
                notes VARCHAR(500) NULL,
                version INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_units_sku (sku),
                UNIQUE KEY uq_units_serial (serial_number),
                KEY idx_units_model_status (product_model_id, status),
                KEY idx_units_status (status),
                KEY idx_units_location (location_id),
                CONSTRAINT fk_units_model FOREIGN KEY (product_model_id)
                    REFERENCES product_models (id) ON DELETE RESTRICT,
                CONSTRAINT fk_units_location FOREIGN KEY (location_id)
                    REFERENCES locations (id) ON DELETE SET NULL,
                CONSTRAINT fk_units_inspector FOREIGN KEY (inspected_by)
                    REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_units_prices CHECK (purchase_cost >= 0 AND landed_cost >= 0
                    AND selling_price >= 0 AND (min_selling_price IS NULL OR min_selling_price >= 0))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE stock_movements (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inventory_unit_id BIGINT UNSIGNED NOT NULL,
                movement_type ENUM('receipt','adjustment','reservation','release','sale',
                                   'return','transfer','inspection','write_off') NOT NULL,
                from_status VARCHAR(30) NULL,
                to_status VARCHAR(30) NULL,
                reason VARCHAR(255) NULL,
                metadata TEXT NULL,
                performed_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_movements_unit (inventory_unit_id, created_at),
                KEY idx_movements_created (created_at),
                CONSTRAINT fk_movements_unit FOREIGN KEY (inventory_unit_id)
                    REFERENCES inventory_units (id) ON DELETE RESTRICT,
                CONSTRAINT fk_movements_user FOREIGN KEY (performed_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        foreach (['stock_movements', 'inventory_units'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    },
];
