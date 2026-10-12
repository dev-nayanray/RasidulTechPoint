<?php

declare(strict_types=1);

/**
 * Content management (Prompt 36).
 *
 * What this migration adds:
 *
 *  - content_blocks: editable HTML sections stored in the database.
 *    Each block has a unique key (e.g. 'homepage_html', 'about_hero',
 *    'contact_intro'), a title, HTML body, and publication status.
 *    The admin can edit the block's HTML via the CMS; the storefront
 *    controllers render blocks by key. Content management is
 *    separate from application configuration (the settings table).
 *
 *  - banners: promotional banner records with image upload, title,
 *    subtitle, link, publication status, and display order.
 *
 *  - content_pages: informational pages (About, FAQ, Terms, Privacy,
 *    etc.) with slug, title, HTML body, SEO metadata, and
 *    publication status. Published pages are accessible at
 *    /page/{slug}.
 *
 *  - faqs: FAQ entries with question, answer, category, display
 *    order, and publication status.
 *
 *  - seo_metadata: per-page SEO records (title, description,
 *    og_title, og_description, og_image, canonical_url, meta_tags).
 *
 * Safe HTML handling:
 *  - The CMS stores raw HTML in content_blocks and content_pages.
 *  - The admin has content.manage permission — only authorized staff
 *    can edit HTML.
 *  - The storefront renders the HTML via the View::e() helper for
 *    dynamic data, but the block/page HTML itself is trusted (it was
 *    authored by an authorized admin).
 *  - Image uploads use the same security pattern as ProductImageController:
 *    finfo MIME detection, 4 MiB max, random filenames, .htaccess
 *    disabling PHP execution.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE content_blocks (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                block_key VARCHAR(120) NOT NULL,
                title VARCHAR(255) NOT NULL,
                body LONGTEXT NOT NULL,
                is_published TINYINT(1) NOT NULL DEFAULT 1,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cb_key (block_key),
                KEY idx_cb_published (is_published),
                CONSTRAINT fk_cb_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE banners (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(255) NOT NULL,
                subtitle VARCHAR(500) NULL,
                image_path VARCHAR(255) NOT NULL,
                link_url VARCHAR(255) NULL,
                position ENUM('hero','sidebar','footer','inline') NOT NULL DEFAULT 'hero',
                is_published TINYINT(1) NOT NULL DEFAULT 1,
                display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_banners_position (position, is_published, display_order),
                CONSTRAINT fk_banners_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE content_pages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(190) NOT NULL,
                title VARCHAR(255) NOT NULL,
                body LONGTEXT NOT NULL,
                meta_title VARCHAR(255) NULL,
                meta_description VARCHAR(500) NULL,
                is_published TINYINT(1) NOT NULL DEFAULT 1,
                display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cp_slug (slug),
                KEY idx_cp_published (is_published),
                CONSTRAINT fk_cp_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE faqs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                question VARCHAR(500) NOT NULL,
                answer TEXT NOT NULL,
                category VARCHAR(120) NULL,
                is_published TINYINT(1) NOT NULL DEFAULT 1,
                display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_faqs_published (is_published, display_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE seo_metadata (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                page_key VARCHAR(190) NOT NULL,
                meta_title VARCHAR(255) NULL,
                meta_description VARCHAR(500) NULL,
                og_title VARCHAR(255) NULL,
                og_description VARCHAR(500) NULL,
                og_image_path VARCHAR(255) NULL,
                canonical_url VARCHAR(255) NULL,
                extra_head_tags TEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_seo_page_key (page_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create the banner image upload directory.
        $dir = BASE_PATH . '/public/uploads/banners';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $source = BASE_PATH . '/public/uploads/.htaccess';
            if (file_exists($source)) {
                copy($source, $htaccess);
            } else {
                file_put_contents($htaccess, "php_flag engine off\n");
            }
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS seo_metadata');
        $pdo->exec('DROP TABLE IF EXISTS faqs');
        $pdo->exec('DROP TABLE IF EXISTS content_pages');
        $pdo->exec('DROP TABLE IF EXISTS banners');
        $pdo->exec('DROP TABLE IF EXISTS content_blocks');
    },
];
