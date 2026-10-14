<?php

declare(strict_types=1);

/**
 * Demo catalog support:
 *  - product_models.is_demo / inventory_units.is_demo flag demo records
 *    seeded by DemoCatalogSeeder (local-only). Everything defaults to 0
 *    (real data), so existing queries/workflows are unaffected.
 *  - product_images.alt adds optional meaningful alt text for catalog
 *    images (used by storefront rendering; falls back to product name).
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE product_models ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER show_price');
        $pdo->exec('ALTER TABLE product_models ADD KEY idx_pm_is_demo (is_demo)');
        $pdo->exec('ALTER TABLE inventory_units ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER notes');
        $pdo->exec('ALTER TABLE inventory_units ADD KEY idx_units_is_demo (is_demo)');
        $pdo->exec('ALTER TABLE product_images ADD COLUMN alt VARCHAR(190) NULL AFTER file_path');
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE product_images DROP COLUMN alt');
        $pdo->exec('ALTER TABLE inventory_units DROP KEY idx_units_is_demo');
        $pdo->exec('ALTER TABLE inventory_units DROP COLUMN is_demo');
        $pdo->exec('ALTER TABLE product_models DROP KEY idx_pm_is_demo');
        $pdo->exec('ALTER TABLE product_models DROP COLUMN is_demo');
    },
];
