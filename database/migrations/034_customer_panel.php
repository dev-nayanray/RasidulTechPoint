<?php

declare(strict_types=1);

/**
 * Customer panel support tables (Customer Panel Prompt).
 *
 * Adds:
 *  - customer_addresses: multi-address book per customer (shipping,
 *    billing, default). Customers can add/edit/delete their own
 *    addresses. A snapshot of the selected address is stored on the
 *    order at checkout (delivery_snapshot JSON) — editing the
 *    address book does NOT alter a historical order.
 *
 *  - wishlist_items: saved product models per customer. Keyed by
 *    product_model_id (not inventory_unit_id) because a wishlist
 *    tracks interest in a model, not a specific physical unit.
 *    Availability is revalidated at display time from live inventory.
 *
 * Privacy:
 *  - Both tables FK to customers.id. The customer_id column is the
 *    ownership boundary — all queries in AccountController scope by
 *    the authenticated user's customer_id. No route accepts a
 *    customer_id from the client; it always comes from the session.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE customer_addresses (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id BIGINT UNSIGNED NOT NULL,
                label VARCHAR(60) NOT NULL DEFAULT 'Home',
                recipient_name VARCHAR(120) NOT NULL,
                recipient_phone VARCHAR(30) NOT NULL,
                address_line VARCHAR(255) NOT NULL,
                area VARCHAR(120) NULL,
                city VARCHAR(100) NOT NULL,
                postal_code VARCHAR(20) NULL,
                delivery_instructions VARCHAR(500) NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ca_customer (customer_id, is_default),
                CONSTRAINT fk_ca_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE wishlist_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id BIGINT UNSIGNED NOT NULL,
                product_model_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_wishlist_customer_model (customer_id, product_model_id),
                KEY idx_wishlist_customer (customer_id),
                CONSTRAINT fk_wishlist_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
                CONSTRAINT fk_wishlist_model FOREIGN KEY (product_model_id) REFERENCES product_models (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS wishlist_items');
        $pdo->exec('DROP TABLE IF EXISTS customer_addresses');
    },
];
