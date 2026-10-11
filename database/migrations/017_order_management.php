<?php

declare(strict_types=1);

/**
 * Online order management enhancements (Prompt 22).
 *
 * What this migration adds:
 *
 *  - orders.confirmed_at / refunded_at: lifecycle timestamps so the
 *    show page can render a complete timeline (created → confirmed →
 *    completed → refunded, plus cancelled_at for the cancel branch).
 *
 *  - idx_orders_channel: index for the channel filter on the orders
 *    list page (online vs pos).
 *
 *  - payments.evidence_ref: a free-text column holding the gateway
 *    transaction id, bank slip number, or operator-verified reference
 *    that proves a non-cash payment actually settled. Cash payments
 *    are evidence by nature (verified at the till); non-cash payments
 *    only mark the order paid when this column is populated.
 *
 *  - order_status_history: append-only log of every status transition.
 *    Each row records from_status, to_status, actor_id (the staff
 *    user who triggered the change) and an optional reason. The
 *    timeline cannot be rewritten — the table is INSERT-only.
 *
 *  - order_fulfillment_notes: append-only staff notes log per order.
 *    Separate from orders.notes (which is the customer-visible note
 *    captured at order time) so that fulfilment staff can record
 *    dispatch / courier / wrapping / hold-reasons without exposing
 *    internal chatter to the customer.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            ALTER TABLE orders
                ADD COLUMN confirmed_at DATETIME NULL AFTER placed_by,
                ADD COLUMN refunded_at DATETIME NULL AFTER cancelled_at,
                ADD KEY idx_orders_channel (channel)
        ");

        $pdo->exec("
            ALTER TABLE payments
                ADD COLUMN evidence_ref VARCHAR(120) NULL AFTER reference
        ");

        $pdo->exec("
            CREATE TABLE order_status_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                from_status VARCHAR(30) NOT NULL,
                to_status VARCHAR(30) NOT NULL,
                actor_id BIGINT UNSIGNED NULL,
                reason VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_osh_order (order_id, id),
                CONSTRAINT fk_osh_order FOREIGN KEY (order_id)
                    REFERENCES orders (id) ON DELETE CASCADE,
                CONSTRAINT fk_osh_actor FOREIGN KEY (actor_id)
                    REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE order_fulfillment_notes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                author_id BIGINT UNSIGNED NULL,
                body TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ofn_order (order_id, id),
                CONSTRAINT fk_ofn_order FOREIGN KEY (order_id)
                    REFERENCES orders (id) ON DELETE CASCADE,
                CONSTRAINT fk_ofn_author FOREIGN KEY (author_id)
                    REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS order_fulfillment_notes');
        $pdo->exec('DROP TABLE IF EXISTS order_status_history');

        try { $pdo->exec('ALTER TABLE payments DROP COLUMN evidence_ref'); } catch (\Throwable) {}

        try { $pdo->exec('ALTER TABLE orders DROP KEY idx_orders_channel'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE orders DROP COLUMN refunded_at'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE orders DROP COLUMN confirmed_at'); } catch (\Throwable) {}
    },
];
