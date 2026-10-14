<?php

declare(strict_types=1);

/**
 * Customer reviews: storefront review display with an explicit demo flag.
 *
 * Real reviews will later be written from confirmed orders only; rows seeded
 * for local preview carry is_demo = 1 and are always labelled as demo content
 * in the storefront (never presented as verified customers).
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE customer_reviews (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                reviewer_name VARCHAR(120) NOT NULL,
                city VARCHAR(80) NULL,
                rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
                title VARCHAR(190) NULL,
                body TEXT NOT NULL,
                product_model_id BIGINT UNSIGNED NULL,
                is_demo TINYINT(1) NOT NULL DEFAULT 0,
                is_published TINYINT(1) NOT NULL DEFAULT 1,
                display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_reviews_published (is_published, display_order),
                KEY idx_reviews_demo (is_demo),
                CONSTRAINT fk_reviews_model FOREIGN KEY (product_model_id)
                    REFERENCES product_models (id) ON DELETE SET NULL,
                CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS customer_reviews');
    },
];
