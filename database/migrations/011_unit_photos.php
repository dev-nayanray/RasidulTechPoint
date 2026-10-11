<?php

declare(strict_types=1);

/**
 * Unit photos: individual physical-laptop photos stored per inventory
 * unit. This is separate from product_images (which are model-level
 * catalog photos). A single unit can have multiple photos (front, back,
 * ports, screen, etc.).
 *
 * Files are stored in public/uploads/units/ with .htaccess protection
 * (same as products/brands/logos).
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE unit_photos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inventory_unit_id BIGINT UNSIGNED NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                caption VARCHAR(120) NULL,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_up_unit (inventory_unit_id),
                CONSTRAINT fk_up_unit FOREIGN KEY (inventory_unit_id)
                    REFERENCES inventory_units (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create the upload directory with .htaccess protection.
        $dir = BASE_PATH . '/public/uploads/units';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $htaccess = BASE_PATH . '/public/uploads/units/.htaccess';
        if (!file_exists($htaccess)) {
            copy(BASE_PATH . '/public/uploads/.htaccess', $htaccess);
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS unit_photos');
    },
];
