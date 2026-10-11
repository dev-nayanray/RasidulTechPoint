<?php

declare(strict_types=1);

/**
 * Invoices and refunds (Prompt 25).
 *
 * What this migration adds:
 *
 *  - invoices: formal printable invoices with sequential per-year
 *    invoice numbers, billing details (name/address/phone/email),
 *    tax calculation (name + rate + amount), and a status lifecycle
 *    (draft → issued → paid | void). A voided invoice is preserved
 *    for audit; a new invoice can be issued with the next sequential
 *    number. One 'active' invoice per order at a time, linked via
 *    orders.invoice_id.
 *
 *  - refunds: unified order-level refund records. Each refund links
 *    to an order + (optionally) the specific payment being refunded,
 *    carries an amount, reason, the authorizer, and an inventory
 *    action (return_to_stock / scrap / return_to_supplier /
 *    no_action). A refund starts as 'pending', is 'completed' when
 *    the cashier processes it (which calls PaymentService.refund on
 *    the linked payment and InventoryService.markReturned on each
 *    unit if inventory_action = return_to_stock), or 'cancelled' if
 *    the manager abandons it.
 *
 *    Refund limit enforcement: refundable_amount = sum(completed
 *    payments) - sum(refunds already completed for this order). The
 *    service rejects any new refund whose amount + existing refunds'
 *    amounts > refundable_amount.
 *
 *  - order_corrections: authorized manager corrections to order
 *    fields (line item prices, notes, etc.) AFTER sale. Each row
 *    records field, old_value, new_value, reason, authorized_by, and
 *    ip_address. The table is append-only — corrections cannot be
 *    edited or deleted. orders.correction_count tracks how many
 *    corrections have been applied.
 *
 *  - order_cancellations: structured cancellation records with a
 *    restocking_decision (return_to_stock / scrap / return_to_supplier
 *    / no_action). One row per order (UNIQUE on order_id). Complements
 *    the existing order_status_history timeline by carrying the
 *    structured restocking decision.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        // ----- invoices -------------------------------------------------------
        $pdo->exec("
            CREATE TABLE invoices (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                invoice_number VARCHAR(40) NOT NULL,
                issue_date DATE NOT NULL,
                due_date DATE NULL,
                billing_name VARCHAR(120) NOT NULL,
                billing_address VARCHAR(255) NULL,
                billing_phone VARCHAR(30) NULL,
                billing_email VARCHAR(190) NULL,
                tax_name VARCHAR(60) NOT NULL DEFAULT 'VAT',
                tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
                tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                discount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                currency CHAR(3) NOT NULL DEFAULT 'BDT',
                notes VARCHAR(500) NULL,
                status ENUM('draft','issued','paid','void') NOT NULL DEFAULT 'draft',
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                issued_at DATETIME NULL,
                voided_at DATETIME NULL,
                voided_by BIGINT UNSIGNED NULL,
                void_reason VARCHAR(255) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_invoices_number (invoice_number),
                KEY idx_invoices_order (order_id),
                KEY idx_invoices_status (status),
                CONSTRAINT fk_invoices_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
                CONSTRAINT fk_invoices_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_invoices_voided_by FOREIGN KEY (voided_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_invoices_total CHECK (subtotal >= 0 AND discount >= 0 AND total >= 0 AND tax_rate >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ----- orders: link to active invoice + correction count -------------
        $pdo->exec("
            ALTER TABLE orders
                ADD COLUMN invoice_id BIGINT UNSIGNED NULL AFTER discount_authorized_by,
                ADD COLUMN correction_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER invoice_id,
                ADD KEY idx_orders_invoice (invoice_id)
        ");
        try {
            $pdo->exec('ALTER TABLE orders ADD CONSTRAINT fk_orders_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE SET NULL');
        } catch (\Throwable) {}

        // ----- refunds --------------------------------------------------------
        $pdo->exec("
            CREATE TABLE refunds (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                refund_number VARCHAR(40) NOT NULL,
                order_id BIGINT UNSIGNED NOT NULL,
                payment_id BIGINT UNSIGNED NULL,
                amount DECIMAL(14,2) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                inventory_action ENUM('return_to_stock','scrap','return_to_supplier','no_action') NOT NULL DEFAULT 'no_action',
                status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
                authorized_by BIGINT UNSIGNED NULL,
                processed_by BIGINT UNSIGNED NULL,
                processed_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_refunds_number (refund_number),
                KEY idx_refunds_order (order_id),
                KEY idx_refunds_status (status),
                CONSTRAINT fk_refunds_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
                CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE SET NULL,
                CONSTRAINT fk_refunds_authorized_by FOREIGN KEY (authorized_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_refunds_processed_by FOREIGN KEY (processed_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_refunds_amount CHECK (amount > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ----- order_corrections --------------------------------------------
        $pdo->exec("
            CREATE TABLE order_corrections (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                field VARCHAR(60) NOT NULL,
                old_value TEXT NULL,
                new_value TEXT NULL,
                reason VARCHAR(255) NOT NULL,
                authorized_by BIGINT UNSIGNED NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_address VARCHAR(45) NULL,
                PRIMARY KEY (id),
                KEY idx_oc_order (order_id, applied_at),
                CONSTRAINT fk_oc_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
                CONSTRAINT fk_oc_authorized_by FOREIGN KEY (authorized_by) REFERENCES users (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ----- order_cancellations ------------------------------------------
        $pdo->exec("
            CREATE TABLE order_cancellations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                reason VARCHAR(255) NOT NULL,
                restocking_decision ENUM('return_to_stock','scrap','return_to_supplier','no_action') NOT NULL DEFAULT 'no_action',
                cancelled_by BIGINT UNSIGNED NOT NULL,
                cancelled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ocancel_order (order_id),
                KEY idx_ocancel_decision (restocking_decision),
                CONSTRAINT fk_ocancel_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
                CONSTRAINT fk_ocancel_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS order_cancellations');
        $pdo->exec('DROP TABLE IF EXISTS order_corrections');
        $pdo->exec('DROP TABLE IF EXISTS refunds');

        try { $pdo->exec('ALTER TABLE orders DROP FOREIGN KEY fk_orders_invoice'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE orders DROP KEY idx_orders_invoice'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE orders DROP COLUMN correction_count'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE orders DROP COLUMN invoice_id'); } catch (\Throwable) {}

        $pdo->exec('DROP TABLE IF EXISTS invoices');
    },
];
