<?php

declare(strict_types=1);

/**
 * Business foundation: business profile, locations, settings, notifications.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE business_profiles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(150) NOT NULL,
                legal_name VARCHAR(190) NULL,
                tagline VARCHAR(255) NULL,
                email VARCHAR(190) NULL,
                phone VARCHAR(30) NULL,
                address VARCHAR(255) NULL,
                city VARCHAR(100) NULL,
                country VARCHAR(100) NULL,
                currency CHAR(3) NOT NULL DEFAULT 'BDT',
                timezone VARCHAR(60) NOT NULL DEFAULT 'Asia/Dhaka',
                logo_path VARCHAR(255) NULL,
                about_text TEXT NULL,
                default_warranty_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE locations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                type ENUM('shop','warehouse','service_center') NOT NULL DEFAULT 'shop',
                address VARCHAR(255) NULL,
                phone VARCHAR(30) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_locations_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE settings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                setting_key VARCHAR(120) NOT NULL,
                setting_value TEXT NULL,
                setting_type ENUM('string','int','bool','json') NOT NULL DEFAULT 'string',
                setting_group VARCHAR(60) NOT NULL DEFAULT 'general',
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_settings_key (setting_key),
                CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                type VARCHAR(60) NOT NULL DEFAULT 'info',
                title VARCHAR(190) NOT NULL,
                body TEXT NULL,
                link VARCHAR(255) NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_notifications_user_read (user_id, is_read, created_at),
                CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        foreach (['notifications', 'settings', 'locations', 'business_profiles'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    },
];
