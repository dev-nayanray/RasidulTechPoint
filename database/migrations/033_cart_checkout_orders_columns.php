<?php

declare(strict_types=1);

/**
 * Cart + checkout support (Marketing-Website Prompt 3).
 *
 * Adds two columns to the existing `orders` table so an order carries
 * a sale-time snapshot of the delivery address + delivery method,
 * independent of any future change to the customer's profile or
 * the shipping rules. Historical orders without these columns are
 * unaffected (NULL is treated as "no delivery / POS sale").
 *
 *   - delivery_method ENUM('home_delivery','store_pickup') NULL
 *     — set only on online orders; NULL on POS.
 *
 *   - delivery_snapshot JSON NULL
 *     — full snapshot of the delivery address + fee + instructions
 *       + chosen zone/courier (if any) at checkout time. Stored as
 *       JSON to insulate the order from future schema changes on
 *       the customer address book + delivery zones tables.
 *
 * No new tables, no FK changes. Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            ALTER TABLE orders
                ADD COLUMN delivery_method ENUM('home_delivery','store_pickup') NULL AFTER notes,
                ADD COLUMN delivery_snapshot JSON NULL AFTER delivery_method
        ");
    },

    'down' => function (PDO $pdo): void {
        try { $pdo->exec('ALTER TABLE orders DROP COLUMN delivery_snapshot'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE orders DROP COLUMN delivery_method'); } catch (\Throwable) {}
    },
];
