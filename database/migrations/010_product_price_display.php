<?php

declare(strict_types=1);

/**
 * Product model price display rules: adds a show_price flag so the
 * business can hide prices on specific models (showing "Contact for
 * price" in the storefront) even when inventory units have selling
 * prices set.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE product_models ADD COLUMN show_price TINYINT(1) NOT NULL DEFAULT 1 AFTER is_published");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE product_models DROP COLUMN show_price");
    },
];
