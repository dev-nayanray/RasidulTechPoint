<?php

declare(strict_types=1);

/**
 * Hero slider slides (ZCODE Master Prompt).
 *
 * Stores premium full-width homepage hero slides managed entirely
 * from the admin panel. Each slide has:
 *  - Content: headline, highlighted phrase, description, eyebrow,
 *    primary + secondary CTA labels and URLs
 *  - Media: desktop image, mobile image, product foreground image,
 *    alt text
 *  - Styling: background color/gradient, text color, content position,
 *    image position, overlay strength
 *  - Animation: transition style, intensity, duration
 *  - Behavior: auto-rotation, slide duration, nav/pagination visibility
 *  - Scheduling: start/end dates, active status, sort order
 *  - SEO: accessibility description
 *
 * The existing `banners` table (migration 028) is preserved — it
 * serves a different purpose (simple sidebar/footer banners).
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        // Use IF NOT EXISTS so the migration is idempotent and safe to
        // re-run after a partial failure (MySQL DDL auto-commits, so a
        // failed INSERT after a successful CREATE TABLE leaves the table
        // in place but the migration is not recorded as applied).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS hero_slides (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                internal_name VARCHAR(120) NOT NULL DEFAULT 'Slide',
                eyebrow VARCHAR(80) NULL,
                headline VARCHAR(255) NOT NULL,
                highlight_phrase VARCHAR(120) NULL,
                description TEXT NULL,
                cta1_label VARCHAR(60) NOT NULL DEFAULT 'Shop Now',
                cta1_url VARCHAR(255) NOT NULL DEFAULT '/shop',
                cta2_label VARCHAR(60) NULL,
                cta2_url VARCHAR(255) NULL,
                desktop_image VARCHAR(500) NULL,
                mobile_image VARCHAR(500) NULL,
                product_image VARCHAR(500) NULL,
                image_alt VARCHAR(190) NULL,
                bg_color VARCHAR(60) NOT NULL DEFAULT '#01011D',
                text_color VARCHAR(60) NOT NULL DEFAULT '#ffffff',
                content_position ENUM('left','right','center') NOT NULL DEFAULT 'left',
                image_position ENUM('right','left','center','background') NOT NULL DEFAULT 'right',
                overlay_strength TINYINT UNSIGNED NOT NULL DEFAULT 0,
                transition_style ENUM('fade','slide','zoom') NOT NULL DEFAULT 'fade',
                animation_intensity ENUM('subtle','normal','bold') NOT NULL DEFAULT 'normal',
                auto_rotate TINYINT(1) NOT NULL DEFAULT 1,
                slide_duration INT UNSIGNED NOT NULL DEFAULT 5000,
                show_nav TINYINT(1) NOT NULL DEFAULT 1,
                show_pagination TINYINT(1) NOT NULL DEFAULT 1,
                start_at DATETIME NULL,
                end_at DATETIME NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                accessibility_desc VARCHAR(500) NULL,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_hs_active (is_active, sort_order),
                KEY idx_hs_schedule (start_at, end_at),
                CONSTRAINT fk_hs_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_hs_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed 3 default slides matching the brand — only if the table
        // is empty (so re-runs on a partially-failed migration don't
        // duplicate rows, and admins who deleted all slides don't get
        // the defaults re-injected on the next migration run).
        $count = (int) $pdo->query('SELECT COUNT(*) FROM hero_slides')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $defaults = [
            [
                'Find Your Next Laptop.',
                'Explore available laptops for work, study, and everyday productivity.',
                'Explore Laptops', '/shop',
                'Premium Discovery', '#01011D', '#ffffff',
            ],
            [
                'Work Smarter. Choose Better.',
                'Discover laptops matched to your everyday work and productivity needs.',
                'Find Your Laptop', '/shop',
                'Business & Productivity', '#0A1628', '#ffffff',
            ],
            [
                'The Right Laptop for Your Budget.',
                'Compare available laptops and find the right balance of features and price.',
                'Browse by Budget', '/shop?budget=1',
                'Budget Friendly', '#14243A', '#ffffff',
            ],
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO hero_slides
                (internal_name, headline, description, cta1_label, cta1_url, eyebrow, bg_color, text_color, sort_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        foreach ($defaults as $i => $row) {
            [$headline, $desc, $cta1Label, $cta1Url, $eyebrow, $bg, $textColor] = $row;
            $stmt->execute([
                'Slide ' . ($i + 1), $headline, $desc, $cta1Label, $cta1Url,
                $eyebrow, $bg, $textColor, $i,
            ]);
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS hero_slides');
    },
];
