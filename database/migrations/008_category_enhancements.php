<?php

declare(strict_types=1);

/**
 * Category enhancements: adds sort_order, SEO fields, and image_path
 * to the categories table. The existing columns (name, slug, parent_id,
 * description, is_active) are unchanged.
 *
 * Reversible (down drops the new columns).
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE categories ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active");
        $pdo->exec("ALTER TABLE categories ADD COLUMN image_path VARCHAR(255) NULL AFTER sort_order");
        $pdo->exec("ALTER TABLE categories ADD COLUMN seo_title VARCHAR(190) NULL AFTER description");
        $pdo->exec("ALTER TABLE categories ADD COLUMN seo_description VARCHAR(300) NULL AFTER seo_title");
        $pdo->exec("ALTER TABLE categories ADD KEY idx_categories_sort (sort_order)");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE categories DROP KEY idx_categories_sort");
        $pdo->exec("ALTER TABLE categories DROP COLUMN seo_description");
        $pdo->exec("ALTER TABLE categories DROP COLUMN seo_title");
        $pdo->exec("ALTER TABLE categories DROP COLUMN image_path");
        $pdo->exec("ALTER TABLE categories DROP COLUMN sort_order");
    },
];
