<?php

declare(strict_types=1);

/**
 * Product catalog: brands, categories, product_models (laptop specs),
 * product_images. Catalog models are separate from physical units.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE brands (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                description VARCHAR(500) NULL,
                logo_path VARCHAR(255) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_brands_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE categories (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                parent_id BIGINT UNSIGNED NULL,
                description VARCHAR(500) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_categories_slug (slug),
                KEY idx_categories_parent (parent_id),
                CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE product_models (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                brand_id BIGINT UNSIGNED NOT NULL,
                category_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(190) NOT NULL,
                slug VARCHAR(220) NOT NULL,
                processor VARCHAR(190) NULL,
                ram_gb SMALLINT UNSIGNED NULL,
                storage_type ENUM('HDD','SSD','HDD+SSD') NULL,
                storage_capacity_gb INT UNSIGNED NULL,
                graphics VARCHAR(190) NULL,
                display_size_inches DECIMAL(4,2) NULL,
                resolution VARCHAR(60) NULL,
                operating_system VARCHAR(100) NULL,
                description TEXT NULL,
                seo_title VARCHAR(190) NULL,
                seo_description VARCHAR(300) NULL,
                is_published TINYINT(1) NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_product_models_slug (slug),
                KEY idx_pm_brand (brand_id),
                KEY idx_pm_category (category_id),
                KEY idx_pm_published (is_published),
                CONSTRAINT fk_pm_brand FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE RESTRICT,
                CONSTRAINT fk_pm_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT,
                CONSTRAINT fk_pm_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE product_images (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_model_id BIGINT UNSIGNED NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_pi_model (product_model_id),
                CONSTRAINT fk_pi_model FOREIGN KEY (product_model_id)
                    REFERENCES product_models (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        foreach (['product_images', 'product_models', 'categories', 'brands'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    },
];
