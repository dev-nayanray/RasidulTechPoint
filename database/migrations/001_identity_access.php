<?php

declare(strict_types=1);

/**
 * Identity and access tables.
 * PKs are internal BIGINT AUTO_INCREMENT; soft deletes only where justified.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE users (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL,
                phone VARCHAR(30) NULL,
                password VARCHAR(255) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_login_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_users_email (email),
                KEY idx_users_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE roles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(80) NOT NULL,
                slug VARCHAR(80) NOT NULL,
                description VARCHAR(255) NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_roles_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE permissions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                module VARCHAR(60) NOT NULL,
                description VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_permissions_name (name),
                KEY idx_permissions_module (module)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE user_roles (
                user_id BIGINT UNSIGNED NOT NULL,
                role_id BIGINT UNSIGNED NOT NULL,
                assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, role_id),
                KEY idx_user_roles_role (role_id),
                CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE role_permissions (
                role_id BIGINT UNSIGNED NOT NULL,
                permission_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (role_id, permission_id),
                KEY idx_role_permissions_permission (permission_id),
                CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
                CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id)
                    REFERENCES permissions (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE password_reset_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_prt_hash (token_hash),
                KEY idx_prt_user (user_id),
                CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE login_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(190) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                was_successful TINYINT(1) NOT NULL DEFAULT 0,
                attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_login_attempts_email_time (email, attempted_at),
                KEY idx_login_attempts_ip_time (ip, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                action VARCHAR(120) NOT NULL,
                entity_type VARCHAR(60) NULL,
                entity_id BIGINT UNSIGNED NULL,
                metadata TEXT NULL,
                ip VARCHAR(45) NULL,
                correlation_id VARCHAR(32) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_audit_user (user_id),
                KEY idx_audit_entity (entity_type, entity_id),
                KEY idx_audit_created (created_at),
                CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        foreach (['audit_logs', 'login_attempts', 'password_reset_tokens',
                  'role_permissions', 'user_roles', 'permissions', 'roles', 'users'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    },
];
