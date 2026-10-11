<?php

declare(strict_types=1);

/**
 * Payment records (Prompt 24).
 *
 * What this migration adds:
 *
 *  - payments gateway + audit columns:
 *      gateway_name         — which gateway was used (manual / sslcommerz /
 *                              bkash / nagad / bank). NULL for cash.
 *      gateway_txn_id       — the gateway's own transaction id (distinct
 *                              from evidence_ref which is the operator-
 *                              pasted bank slip number).
 *      verified_by, verified_at — who promoted a non-cash payment from
 *                              'pending' to 'completed' and when.
 *      refunded_by, refunded_at, refund_reason
 *                            — payment-level refund trail (separate
 *                              from order-level refund in OrderService).
 *      shift_id             — which cashier shift collected this payment
 *                              (links to cashier_shifts for the daily
 *                              reconciliation rollup).
 *      reconciliation_batch_id — which daily batch this payment was
 *                              reconciled against.
 *      parent_payment_id    — for refund rows, the original payment id.
 *      ip_address           — capture source IP for audit.
 *      payment_metadata     — JSON-safe audit metadata. MUST NOT contain
 *                              card security codes or banking PINs; the
 *                              PaymentService asserts this defensively.
 *
 *  - payment_methods_catalog: admin-managed list of accepted payment
 *    methods with their gateway classification, instructions, and
 *    active flag. Seeded with the six methods that already exist as
 *    enum values on payments.method so the catalog is the single
 *    source of truth going forward.
 *
 *  - reconciliation_batches: daily summaries. expected_total is the
 *    sum of completed payments for the batch date; actual_total is
 *    counted at end-of-day; discrepancy = actual - expected. A batch
 *    starts as 'draft', is 'confirmed' when actual matches, or
 *    'discrepancy' when there is a shortfall or overage.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        // ----- payments columns --------------------------------------------------
        $pdo->exec("
            ALTER TABLE payments
                ADD COLUMN gateway_name VARCHAR(60) NULL AFTER method,
                ADD COLUMN gateway_txn_id VARCHAR(120) NULL AFTER evidence_ref,
                ADD COLUMN verified_by BIGINT UNSIGNED NULL AFTER received_by,
                ADD COLUMN verified_at DATETIME NULL AFTER verified_by,
                ADD COLUMN refunded_by BIGINT UNSIGNED NULL AFTER verified_at,
                ADD COLUMN refunded_at DATETIME NULL AFTER refunded_by,
                ADD COLUMN refund_reason VARCHAR(255) NULL AFTER refunded_at,
                ADD COLUMN shift_id BIGINT UNSIGNED NULL AFTER refund_reason,
                ADD COLUMN reconciliation_batch_id BIGINT UNSIGNED NULL AFTER shift_id,
                ADD COLUMN parent_payment_id BIGINT UNSIGNED NULL AFTER reconciliation_batch_id,
                ADD COLUMN ip_address VARCHAR(45) NULL AFTER parent_payment_id,
                ADD COLUMN payment_metadata TEXT NULL AFTER ip_address
        ");

        // FK + indexes (each wrapped so re-runs are safe).
        foreach ([
            ['fk_payments_verified_by', 'verified_by', 'users (id)', 'SET NULL'],
            ['fk_payments_refunded_by', 'refunded_by', 'users (id)', 'SET NULL'],
            ['fk_payments_shift', 'shift_id', 'cashier_shifts (id)', 'SET NULL'],
            ['fk_payments_parent', 'parent_payment_id', 'payments (id)', 'SET NULL'],
            ['fk_payments_batch', 'reconciliation_batch_id', 'reconciliation_batches (id)', 'SET NULL'],
        ] as [$name, $col, $ref, $onDelete]) {
            try {
                $pdo->exec("ALTER TABLE payments ADD CONSTRAINT {$name} FOREIGN KEY ({$col}) REFERENCES {$ref} ON DELETE {$onDelete}");
            } catch (\Throwable) {}
        }
        // fk_payments_batch references reconciliation_batches which is created
        // below; if the above failed because the table does not exist yet,
        // re-add it now.
        try {
            $pdo->exec('ALTER TABLE payments ADD CONSTRAINT fk_payments_batch FOREIGN KEY (reconciliation_batch_id) REFERENCES reconciliation_batches (id) ON DELETE SET NULL');
        } catch (\Throwable) {}

        $pdo->exec("
            ALTER TABLE payments
                ADD KEY idx_payments_gateway (gateway_name),
                ADD KEY idx_payments_verified (verified_at),
                ADD KEY idx_payments_refunded (refunded_at),
                ADD KEY idx_payments_shift (shift_id)
        ");

        // ----- payment_methods_catalog ------------------------------------------
        $pdo->exec("
            CREATE TABLE payment_methods_catalog (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(30) NOT NULL,
                label VARCHAR(60) NOT NULL,
                gateway ENUM('manual','sslcommerz','bkash','nagad','bank','other') NOT NULL DEFAULT 'manual',
                description VARCHAR(255) NULL,
                instructions TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_pmc_code (code),
                KEY idx_pmc_active (is_active, display_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $seed = $pdo->prepare(
            'INSERT INTO payment_methods_catalog (code, label, gateway, display_order, description, instructions) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $defaults = [
            ['cash', 'Cash', 'manual', 1,
             'Verified at the till by the cashier.',
             'Count the cash before marking the payment complete. The till float plus all cash payments are reconciled at the end of each cashier shift.'],
            ['card', 'Card (Visa/MasterCard/Amex)', 'manual', 2,
             'Card present at the till. NEVER store the CVV/CVC.',
             'The card security code (CVV/CVC) MUST NOT be stored in any field. The terminal will refuse any payload containing cvv, cvc, pin, security_code or card_pin keys.'],
            ['bank_transfer', 'Bank transfer', 'bank', 3,
             'Customer wired money to the business bank account.',
             'Record the bank slip number in the Reference field. Mark the payment completed only after the bank statement has been visually verified.'],
            ['bkash', 'bKash', 'bkash', 4,
             'Mobile wallet payment via bKash.',
             'Capture the bKash transaction ID. Without evidence the payment stays pending; the cashier manager verifies via the bKash merchant portal before marking completed.'],
            ['nagad', 'Nagad', 'nagad', 5,
             'Mobile wallet payment via Nagad.',
             'Capture the Nagad transaction ID. Verification same as bKash.'],
            ['other', 'Other', 'other', 6,
             'Use only when no other method fits.',
             'Provide a clear note explaining what was tendered. Manual verification required.'],
        ];
        foreach ($defaults as $row) {
            $seed->execute($row);
        }

        // ----- reconciliation_batches --------------------------------------------
        $pdo->exec("
            CREATE TABLE reconciliation_batches (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                batch_date DATE NOT NULL,
                prepared_by BIGINT UNSIGNED NOT NULL,
                confirmed_by BIGINT UNSIGNED NULL,
                status ENUM('draft','confirmed','discrepancy') NOT NULL DEFAULT 'draft',
                expected_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                actual_total DECIMAL(14,2) NULL,
                discrepancy DECIMAL(14,2) NULL,
                payment_count INT UNSIGNED NOT NULL DEFAULT 0,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                confirmed_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_rb_date (batch_date),
                KEY idx_rb_status (status),
                CONSTRAINT fk_rb_prepared_by FOREIGN KEY (prepared_by) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_rb_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Re-try the payments.reconciliation_batch_id FK now that the
        // reconciliation_batches table exists (no-op if it succeeded above).
        try {
            $pdo->exec('ALTER TABLE payments ADD CONSTRAINT fk_payments_batch FOREIGN KEY (reconciliation_batch_id) REFERENCES reconciliation_batches (id) ON DELETE SET NULL');
        } catch (\Throwable) {}
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS reconciliation_batches');
        $pdo->exec('DROP TABLE IF EXISTS payment_methods_catalog');

        foreach (['fk_payments_verified_by', 'fk_payments_refunded_by', 'fk_payments_shift', 'fk_payments_parent', 'fk_payments_batch'] as $fk) {
            try { $pdo->exec("ALTER TABLE payments DROP FOREIGN KEY {$fk}"); } catch (\Throwable) {}
        }

        try { $pdo->exec('ALTER TABLE payments DROP KEY idx_payments_gateway'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE payments DROP KEY idx_payments_verified'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE payments DROP KEY idx_payments_refunded'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE payments DROP KEY idx_payments_shift'); } catch (\Throwable) {}

        $pdo->exec("
            ALTER TABLE payments
                DROP COLUMN gateway_name,
                DROP COLUMN gateway_txn_id,
                DROP COLUMN verified_by,
                DROP COLUMN verified_at,
                DROP COLUMN refunded_by,
                DROP COLUMN refunded_at,
                DROP COLUMN refund_reason,
                DROP COLUMN shift_id,
                DROP COLUMN reconciliation_batch_id,
                DROP COLUMN parent_payment_id,
                DROP COLUMN ip_address,
                DROP COLUMN payment_metadata
        ");
    },
];
