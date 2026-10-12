<?php

declare(strict_types=1);

/**
 * Returns and exchanges (Prompt 29).
 *
 * What this migration adds:
 *
 *  - return_requests: per-order customer return records. Each request
 *    links to an order + customer, has a request_type (refund /
 *    exchange / store_credit), a reason, a status lifecycle:
 *
 *      requested → approved → received → inspected → completed (terminal)
 *                          ↘ rejected (terminal)
 *                          ↘ cancelled (terminal)
 *
 *    Sequential return number (RTN-YYYY-NNNNN). The approval step
 *    happens BEFORE the customer ships the unit back. The receive
 *    step calls InventoryService.markReturned (sold → returned).
 *    The inspect step records per-item inspection results. The
 *    process/complete step moves inventory to its final disposition
 *    (available / damaged / scrap) and triggers the refund or
 *    exchange flow.
 *
 *    Critical rule: returned laptops do NOT automatically become
 *    sellable. The inventory state machine enforces sold → returned
 *    (not sold → available). Only the explicit inspection step
 *    (returned → available via passInspection) can make a unit
 *    sellable again. The process() method calls passInspection ONLY
 *    AFTER the inspection step has been completed with a 'pass'
 *    result.
 *
 *  - return_items: per-item detail within a return request. Each item
 *    links to the original order_item + inventory_unit, captures
 *    the sale-time product_name/sku/unit_price as a snapshot, and
 *    carries its own inspection_result (pass/fail/pending) and
 *    inventory_disposition (return_to_stock / scrap /
 *    return_to_supplier / repair). Different items in the same
 *    return can have different dispositions.
 *
 *  - return_history: append-only status transition log. INSERT-only.
 *
 * Inventory + financial integrity:
 *  - Inventory moves ONLY through the InventoryService state machine.
 *    The ReturnService calls markReturned (sold → returned) on
 *    receive, and passInspection/failInspection (returned →
 *    available/damaged) on process. No direct DB writes to
 *    inventory_units.
 *  - Refunds flow through the existing RefundService, not a
 *    separate refund path here. The ReturnService calls
 *    RefundService.create + process when request_type = 'refund'.
 *  - Exchanges create a new POS order via OrderService for the
 *    replacement units. The original order's units are dispositioned
 *    per the inspection results.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE return_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                return_number VARCHAR(40) NOT NULL,
                order_id BIGINT UNSIGNED NOT NULL,
                customer_id BIGINT UNSIGNED NOT NULL,
                request_type ENUM('refund','exchange','store_credit') NOT NULL DEFAULT 'refund',
                status ENUM('requested','approved','rejected','received','inspected','completed','cancelled') NOT NULL DEFAULT 'requested',
                reason TEXT NOT NULL,
                customer_reported TEXT NULL,
                refund_amount DECIMAL(14,2) NULL,
                replacement_order_id BIGINT UNSIGNED NULL,
                inventory_disposition ENUM('return_to_stock','scrap','return_to_supplier','repair') NULL,
                approved_by BIGINT UNSIGNED NULL,
                approved_at DATETIME NULL,
                rejection_reason VARCHAR(255) NULL,
                received_at DATETIME NULL,
                received_by BIGINT UNSIGNED NULL,
                inspected_at DATETIME NULL,
                inspected_by BIGINT UNSIGNED NULL,
                inspection_notes TEXT NULL,
                completed_at DATETIME NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_rr_number (return_number),
                KEY idx_rr_order (order_id),
                KEY idx_rr_customer (customer_id),
                KEY idx_rr_status (status),
                CONSTRAINT fk_rr_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
                CONSTRAINT fk_rr_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT,
                CONSTRAINT fk_rr_replacement FOREIGN KEY (replacement_order_id) REFERENCES orders (id) ON DELETE SET NULL,
                CONSTRAINT fk_rr_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_rr_received_by FOREIGN KEY (received_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_rr_inspected_by FOREIGN KEY (inspected_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_rr_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_rr_refund CHECK (refund_amount IS NULL OR refund_amount >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE return_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                return_request_id BIGINT UNSIGNED NOT NULL,
                order_item_id BIGINT UNSIGNED NOT NULL,
                inventory_unit_id BIGINT UNSIGNED NOT NULL,
                product_name VARCHAR(220) NOT NULL,
                sku VARCHAR(40) NOT NULL,
                unit_price DECIMAL(14,2) NOT NULL,
                return_reason VARCHAR(255) NULL,
                inspection_result ENUM('pass','fail','pending') NOT NULL DEFAULT 'pending',
                inspection_notes TEXT NULL,
                inventory_disposition ENUM('return_to_stock','scrap','return_to_supplier','repair') NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ri_return (return_request_id),
                KEY idx_ri_unit (inventory_unit_id),
                CONSTRAINT fk_ri_return FOREIGN KEY (return_request_id) REFERENCES return_requests (id) ON DELETE CASCADE,
                CONSTRAINT fk_ri_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE RESTRICT,
                CONSTRAINT fk_ri_unit FOREIGN KEY (inventory_unit_id) REFERENCES inventory_units (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE return_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                return_request_id BIGINT UNSIGNED NOT NULL,
                from_status VARCHAR(30) NOT NULL,
                to_status VARCHAR(30) NOT NULL,
                actor_id BIGINT UNSIGNED NULL,
                reason VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_rh_return (return_request_id, id),
                CONSTRAINT fk_rh_return FOREIGN KEY (return_request_id) REFERENCES return_requests (id) ON DELETE CASCADE,
                CONSTRAINT fk_rh_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS return_history');
        $pdo->exec('DROP TABLE IF EXISTS return_items');
        $pdo->exec('DROP TABLE IF EXISTS return_requests');
    },
];
