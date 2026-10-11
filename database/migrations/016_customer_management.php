<?php

declare(strict_types=1);

/**
 * Customer management enhancements (Prompt 21).
 *
 * What this migration adds:
 *
 *  - account_status: lifecycle state separate from the soft-delete flag.
 *    A customer can be active, suspended (cannot place new orders),
 *    blacklisted (permanently blocked) or vip (priority handling).
 *    A safe status filter is enforced by the repository and UI.
 *
 *  - alt_phone: optional secondary contact number. Useful for delivery
 *    drivers and emergency reach-out without a separate contacts table.
 *
 *  - lifetime_value / orders_count / last_order_at: denormalized rollups
 *    refreshed after each order is finalised. Cheap to render on the
 *    customer index page, accurate enough for the dashboard summary.
 *
 *  - customer_notes: an append-only staff notes log per customer. The
 *    notes column on customers itself is preserved as a free-text
 *    summary (it was already in use by the storefront registration
 *    flow); customer_notes holds time-stamped entries authored by
 *    staff for an audit-style paper trail.
 *
 *  - Downgrades uq_customers_phone and uq_customers_email from UNIQUE
 *    to regular indexes. The strict UNIQUE constraint from migration
 *    005 conflicts with soft-delete reuse: once a customer was
 *    soft-deleted their phone/email was forever "taken" at the DB
 *    layer even though the application layer treats them as free.
 *    Application-level uniqueness (CustomerRepository::phoneTaken
 *    and ::emailTaken, both filtering deleted_at IS NULL) now guards
 *    against creating duplicates; the duplicates endpoint surfaces
 *    any pairs that slipped through (e.g. legacy imports).
 *
 * Soft-delete behaviour is unchanged: `deleted_at` marks a customer
 * as removed from active lists, but the row is retained forever so
 * historical orders keep a valid FK target (orders.customer_id has
 * ON DELETE RESTRICT anyway).
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        // Drop the strict unique indexes from migration 005 first so
        // the soft-delete reuse pattern actually works at the DB layer.
        // (MySQL silently no-ops if the index does not exist.)
        try { $pdo->exec('ALTER TABLE customers DROP INDEX uq_customers_phone'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE customers DROP INDEX uq_customers_email'); } catch (\Throwable) {}

        $pdo->exec("
            ALTER TABLE customers
                ADD COLUMN account_status ENUM('active','suspended','blacklisted','vip')
                    NOT NULL DEFAULT 'active' AFTER city,
                ADD COLUMN alt_phone VARCHAR(30) NULL AFTER account_status,
                ADD COLUMN lifetime_value DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER notes,
                ADD COLUMN orders_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER lifetime_value,
                ADD COLUMN last_order_at DATETIME NULL AFTER orders_count,
                MODIFY notes TEXT NULL,
                ADD KEY idx_customers_status (account_status),
                ADD KEY idx_customers_phone (phone),
                ADD KEY idx_customers_email (email)
        ");

        $pdo->exec("
            CREATE TABLE customer_notes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id BIGINT UNSIGNED NOT NULL,
                author_id BIGINT UNSIGNED NULL,
                body TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_cn_customer (customer_id, id),
                CONSTRAINT fk_cn_customer FOREIGN KEY (customer_id)
                    REFERENCES customers (id) ON DELETE CASCADE,
                CONSTRAINT fk_cn_author FOREIGN KEY (author_id)
                    REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS customer_notes');

        // Drop the new indexes added by this migration.
        try { $pdo->exec('ALTER TABLE customers DROP KEY idx_customers_status'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE customers DROP KEY idx_customers_phone'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE customers DROP KEY idx_customers_email'); } catch (\Throwable) {}

        // Restore the original strict unique indexes from migration 005.
        try { $pdo->exec('ALTER TABLE customers ADD UNIQUE KEY uq_customers_phone (phone)'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE customers ADD UNIQUE KEY uq_customers_email (email)'); } catch (\Throwable) {}

        $pdo->exec("
            ALTER TABLE customers
                DROP COLUMN last_order_at,
                DROP COLUMN orders_count,
                DROP COLUMN lifetime_value,
                DROP COLUMN alt_phone,
                DROP COLUMN account_status,
                MODIFY notes VARCHAR(500) NULL
        ");
    },
];
