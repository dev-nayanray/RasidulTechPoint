<?php

declare(strict_types=1);

/**
 * Purchase orders and import batches.
 *
 * A purchase order (PO) is created against a supplier, with line items
 * specifying product model, quantity, and agreed unit cost. The PO
 * flows through statuses: draft → submitted → approved → received
 * (or cancelled at any point before receiving).
 *
 * Stock is NOT added when the PO is created or approved. Stock is
 * only added when individual inventory_units are received through
 * the existing InventoryService::receive() flow — the PO just
 * provides the agreed costs and batch reference.
 *
 * The Dubai import batch reference (import_batch_ref) tracks which
 * shipment container / lot a PO belongs to.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE purchase_orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                reference VARCHAR(30) NOT NULL,
                supplier_id BIGINT UNSIGNED NOT NULL,
                status ENUM('draft','submitted','approved','received','cancelled') NOT NULL DEFAULT 'draft',
                import_batch_ref VARCHAR(120) NULL,
                subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                discount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                currency CHAR(3) NOT NULL DEFAULT 'BDT',
                notes VARCHAR(500) NULL,
                created_by BIGINT UNSIGNED NULL,
                approved_by BIGINT UNSIGNED NULL,
                approved_at DATETIME NULL,
                submitted_at DATETIME NULL,
                received_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_po_reference (reference),
                KEY idx_po_supplier (supplier_id),
                KEY idx_po_status (status),
                KEY idx_po_batch (import_batch_ref),
                CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT,
                CONSTRAINT fk_po_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_po_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_po_totals CHECK (subtotal >= 0 AND discount >= 0 AND total >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE purchase_order_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                purchase_order_id BIGINT UNSIGNED NOT NULL,
                product_model_id BIGINT UNSIGNED NOT NULL,
                description VARCHAR(220) NULL,
                quantity INT UNSIGNED NOT NULL DEFAULT 1,
                unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_poi_po (purchase_order_id),
                KEY idx_poi_model (product_model_id),
                CONSTRAINT fk_poi_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE,
                CONSTRAINT fk_poi_model FOREIGN KEY (product_model_id) REFERENCES product_models (id) ON DELETE RESTRICT,
                CONSTRAINT chk_poi_prices CHECK (unit_cost >= 0 AND line_total >= 0 AND quantity >= 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS purchase_order_items');
        $pdo->exec('DROP TABLE IF EXISTS purchase_orders');
    },
];
