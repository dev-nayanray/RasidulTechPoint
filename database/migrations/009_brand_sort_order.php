<?php

declare(strict_types=1);

/**
 * Brand enhancements: adds sort_order to the brands table. The
 * logo_path column already exists (migration 003) but was unused —
 * this migration pairs with the BrandController logo upload feature.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE brands ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active");
        $pdo->exec("ALTER TABLE brands ADD KEY idx_brands_sort (sort_order)");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE brands DROP KEY idx_brands_sort");
        $pdo->exec("ALTER TABLE brands DROP COLUMN sort_order");
    },
];
