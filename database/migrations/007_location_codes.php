<?php

declare(strict_types=1);

/**
 * Locations enhancement: adds a short code and an is_primary flag so
 * the business can designate its main shop. The code is unique and
 * suitable for quick reference in SKU prefixes, transfer notes, etc.
 *
 * This migration is reversible (down drops the columns).
 */

return [
    'up' => function (PDO $pdo): void {
        // Add the code column (nullable for backward compatibility with
        // existing rows; the application layer enforces uniqueness on new
        // creates and on updates).
        $pdo->exec("ALTER TABLE locations ADD COLUMN code VARCHAR(10) NULL AFTER name");
        $pdo->exec("ALTER TABLE locations ADD UNIQUE KEY uq_locations_code (code)");

        // Add the is_primary flag.
        $pdo->exec("ALTER TABLE locations ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");

        // Set the first 'shop' type location as primary.
        $pdo->exec(
            "UPDATE locations SET is_primary = 1
             WHERE id = (SELECT id FROM (
                 SELECT id FROM locations WHERE type = 'shop' ORDER BY id LIMIT 1
             ) AS t)"
        );
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE locations DROP KEY uq_locations_code");
        $pdo->exec("ALTER TABLE locations DROP COLUMN code");
        $pdo->exec("ALTER TABLE locations DROP COLUMN is_primary");
    },
];
