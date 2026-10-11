<?php

declare(strict_types=1);

/**
 * Receiving enhancements: link inventory_units to purchase orders, and
 * add landed cost allocations for import expenses (shipping, customs,
 * other) allocated across units in a batch.
 *
 * - inventory_units.purchase_order_id links a physical unit to its PO.
 * - inventory_units.purchase_order_item_id links to the specific PO line.
 * - landed_cost_allocations records each allocation batch with the
 *   method used (equal / by_cost), the total expense, and per-unit
 *   breakdown stored in metadata JSON.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE inventory_units ADD COLUMN purchase_order_id BIGINT UNSIGNED NULL AFTER purchase_ref");
        $pdo->exec("ALTER TABLE inventory_units ADD COLUMN purchase_order_item_id BIGINT UNSIGNED NULL AFTER purchase_order_id");
        $pdo->exec("ALTER TABLE inventory_units ADD KEY idx_units_po (purchase_order_id)");
        $pdo->exec("
            ALTER TABLE inventory_units
            ADD CONSTRAINT fk_units_po FOREIGN KEY (purchase_order_id)
                REFERENCES purchase_orders (id) ON DELETE SET NULL
        ");
        $pdo->exec("
            ALTER TABLE inventory_units
            ADD CONSTRAINT fk_units_poi FOREIGN KEY (purchase_order_item_id)
                REFERENCES purchase_order_items (id) ON DELETE SET NULL
        ");

        $pdo->exec("
            CREATE TABLE landed_cost_allocations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                purchase_order_id BIGINT UNSIGNED NOT NULL,
                expense_type ENUM('shipping','customs','transport','other') NOT NULL DEFAULT 'other',
                description VARCHAR(255) NULL,
                total_amount DECIMAL(14,2) NOT NULL,
                allocation_method ENUM('equal','by_cost') NOT NULL DEFAULT 'equal',
                allocated_count INT UNSIGNED NOT NULL DEFAULT 0,
                per_unit_breakdown TEXT NULL,
                allocated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_lca_po (purchase_order_id),
                CONSTRAINT fk_lca_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE,
                CONSTRAINT fk_lca_user FOREIGN KEY (allocated_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_lca_amount CHECK (total_amount >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS landed_cost_allocations');
        $pdo->exec('ALTER TABLE inventory_units DROP FOREIGN KEY fk_units_poi');
        $pdo->exec('ALTER TABLE inventory_units DROP FOREIGN KEY fk_units_po');
        $pdo->exec('ALTER TABLE inventory_units DROP KEY idx_units_po');
        $pdo->exec('ALTER TABLE inventory_units DROP COLUMN purchase_order_item_id');
        $pdo->exec('ALTER TABLE inventory_units DROP COLUMN purchase_order_id');
    },
];
