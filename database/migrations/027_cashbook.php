<?php

declare(strict_types=1);

/**
 * Cashbook and balances (Prompt 32).
 *
 * What this migration adds:
 *
 *  - cash_accounts: cash/bank/mobile wallet accounts with opening
 *    balance, current balance, account type (cash/bank/mobile/other),
 *    and active flag. The current_balance is updated atomically
 *    within the same transaction as each movement — no separate
 *    "recompute" step needed.
 *
 *  - cash_movements: every cash flow in/out of an account. Each
 *    movement has:
 *      movement_type: deposit / withdrawal / transfer_in /
 *        transfer_out / payment_in / payment_out / expense /
 *        adjustment
 *      direction: in / out
 *      balance_after: the account's balance AFTER this movement
 *        (snapshot for audit)
 *      reference_type + reference_id: links to the source record
 *        (e.g. 'payment' → payments.id, 'expense' → expense_entries.id,
 *        'transfer' → the linked movement's id)
 *      external_reference: the bank slip / gateway txn id
 *      settlement_status: unverified / verified / disputed
 *
 *    CRITICAL: settlement_status separates "we recorded a payment"
 *    from "the bank confirmed the money arrived". A movement starts
 *    as 'unverified' and becomes 'verified' when the external
 *    settlement is confirmed. This prevents treating recorded
 *    payments as settled funds.
 *
 *  - Duplicate prevention: the service checks for an existing
 *    movement with the same (reference_type, reference_id) before
 *    creating a new one — if found, throws. This prevents double-
 *    posting the same payment or expense.
 *
 *  - Transfers: create two linked movements (transfer_out from
 *    source, transfer_in to destination) in a single transaction.
 *    Both movements share the same reference_id.
 *
 * Financial integrity:
 *  - All balance updates are inside DB transactions with FOR UPDATE
 *    locking on the account row.
 *  - The current_balance is the authoritative running balance.
 *  - balance_after is a snapshot for audit — even if the running
 *    balance is later updated, the historical snapshot shows what
 *    the balance was at the time of each movement.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE cash_accounts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                account_type ENUM('cash','bank','mobile','other') NOT NULL DEFAULT 'cash',
                account_number VARCHAR(60) NULL,
                bank_name VARCHAR(120) NULL,
                branch VARCHAR(120) NULL,
                opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                current_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                currency CHAR(3) NOT NULL DEFAULT 'BDT',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ca_type (account_type),
                KEY idx_ca_active (is_active),
                CONSTRAINT chk_ca_balances CHECK (opening_balance >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE cash_movements (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                movement_number VARCHAR(40) NOT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                movement_type ENUM('deposit','withdrawal','transfer_in','transfer_out','payment_in','payment_out','expense','adjustment') NOT NULL,
                direction ENUM('in','out') NOT NULL,
                amount DECIMAL(14,2) NOT NULL,
                balance_after DECIMAL(14,2) NOT NULL,
                reference_type VARCHAR(60) NULL,
                reference_id BIGINT UNSIGNED NULL,
                external_reference VARCHAR(120) NULL,
                settlement_status ENUM('unverified','verified','disputed') NOT NULL DEFAULT 'unverified',
                verified_by BIGINT UNSIGNED NULL,
                verified_at DATETIME NULL,
                description VARCHAR(255) NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cm_number (movement_number),
                KEY idx_cm_account (account_id, created_at),
                KEY idx_cm_type (movement_type),
                KEY idx_cm_settlement (settlement_status),
                KEY idx_cm_reference (reference_type, reference_id),
                CONSTRAINT fk_cm_account FOREIGN KEY (account_id) REFERENCES cash_accounts (id) ON DELETE RESTRICT,
                CONSTRAINT fk_cm_verified_by FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_cm_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_cm_amount CHECK (amount > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS cash_movements');
        $pdo->exec('DROP TABLE IF EXISTS cash_accounts');
    },
];
