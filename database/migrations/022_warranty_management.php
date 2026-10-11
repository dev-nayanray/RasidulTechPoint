<?php

declare(strict_types=1);

/**
 * Warranty management (Prompt 27).
 *
 * What this migration adds:
 *
 *  - warranty_policies: admin-managed warranty policy catalog (e.g.
 *    "30-day repair warranty", "90-day replace warranty", "1-year
 *    repair or replace"). Each policy has a duration in days, a
 *    coverage_type (repair / replace / refund / repair_or_replace),
 *    terms text, and an active flag. Policies are soft-deleted via
 *    is_active = 0; historical registrations reference them by FK
 *    so deactivating a policy does not break existing warranties.
 *
 *  - warranty_registrations: per-physical-unit warranty records.
 *    Each registration links to an inventory_unit (the physical
 *    laptop), the order that sold it, the order_item line, the
 *    customer, and the policy applied. start_date and end_date are
 *    computed at registration time from the unit's sold_at timestamp
 *    and the policy's duration_days — they are SNAPSHOTTED, not
 *    computed at query time, so changing the policy later does not
 *    retroactively extend/shorten existing warranties.
 *
 *    UNIQUE on inventory_unit_id — one active warranty per physical
 *    unit. A voided warranty's row survives but a new one can be
 *    registered (the unique index is on (inventory_unit_id) which
 *    would block re-registration — so we use a partial approach:
 *    the unique is on (inventory_unit_id) WHERE status = 'active',
 *    implemented as a regular unique on inventory_unit_id + the
 *    service enforcing that voided rows are the only exception
 *    via a check before insert).
 *
 *    Actually MySQL doesn't support partial unique indexes. So the
 *    unique is on (inventory_unit_id) and voiding a registration
 *    DELETES the row's unique constraint target by setting status =
 *    'voided' — but the unique still blocks re-registration. To allow
 *    re-registration after void, we DON'T put a unique on
 *    inventory_unit_id; instead, the service checks for existing
 *    active warranties before creating a new one.
 *
 *  - warranty_claims: customer-initiated warranty claims against a
 *    registration. Each claim has a claim_type (repair / replace /
 *    refund), a description, the customer's reported issue, and a
 *    status lifecycle:
 *      pending → approved / rejected / in_progress / cancelled
 *      approved → in_progress / completed / cancelled
 *      in_progress → completed / cancelled
 *      completed → (terminal)
 *      rejected → (terminal)
 *      cancelled → (terminal)
 *
 *  - warranty_claim_history: append-only log of every claim status
 *    transition. Each row records from_status, to_status, actor_id,
 *    and an optional reason. The table is INSERT-only — claim
 *    history cannot be rewritten.
 *
 * Inventory + financial integrity:
 *  - A warranty claim does NOT move inventory. The physical unit
 *    stays in its current status. If a claim results in a replacement
 *    or refund, that flows through the existing RefundService /
 *    OrderService workflows, not through the warranty module.
 *  - Warranty registrations snapshot the policy's duration_days at
 *    registration time. Editing a policy later does not retroactively
 *    change existing warranties — the start/end dates are immutable
 *    unless the registration is voided and re-registered.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        // ----- warranty_policies ---------------------------------------------
        $pdo->exec("
            CREATE TABLE warranty_policies (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                description VARCHAR(255) NULL,
                duration_days INT UNSIGNED NOT NULL DEFAULT 30,
                coverage_type ENUM('repair','replace','refund','repair_or_replace') NOT NULL DEFAULT 'repair',
                terms TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_wp_slug (slug),
                KEY idx_wp_active (is_active),
                CONSTRAINT fk_wp_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_wp_duration CHECK (duration_days > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ----- warranty_registrations ----------------------------------------
        $pdo->exec("
            CREATE TABLE warranty_registrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                registration_number VARCHAR(40) NOT NULL,
                inventory_unit_id BIGINT UNSIGNED NOT NULL,
                order_id BIGINT UNSIGNED NOT NULL,
                order_item_id BIGINT UNSIGNED NOT NULL,
                customer_id BIGINT UNSIGNED NOT NULL,
                warranty_policy_id BIGINT UNSIGNED NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                status ENUM('active','expired','voided') NOT NULL DEFAULT 'active',
                notes TEXT NULL,
                registered_by BIGINT UNSIGNED NULL,
                voided_at DATETIME NULL,
                voided_by BIGINT UNSIGNED NULL,
                void_reason VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_wr_number (registration_number),
                KEY idx_wr_unit (inventory_unit_id, status),
                KEY idx_wr_customer (customer_id),
                KEY idx_wr_status (status),
                CONSTRAINT fk_wr_unit FOREIGN KEY (inventory_unit_id) REFERENCES inventory_units (id) ON DELETE RESTRICT,
                CONSTRAINT fk_wr_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
                CONSTRAINT fk_wr_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE RESTRICT,
                CONSTRAINT fk_wr_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT,
                CONSTRAINT fk_wr_policy FOREIGN KEY (warranty_policy_id) REFERENCES warranty_policies (id) ON DELETE SET NULL,
                CONSTRAINT fk_wr_registered_by FOREIGN KEY (registered_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_wr_voided_by FOREIGN KEY (voided_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ----- warranty_claims -----------------------------------------------
        $pdo->exec("
            CREATE TABLE warranty_claims (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                claim_number VARCHAR(40) NOT NULL,
                warranty_registration_id BIGINT UNSIGNED NOT NULL,
                claim_type ENUM('repair','replace','refund') NOT NULL DEFAULT 'repair',
                description TEXT NULL,
                customer_reported_issue TEXT NOT NULL,
                status ENUM('pending','approved','rejected','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
                decision_reason VARCHAR(255) NULL,
                resolution_notes TEXT NULL,
                filed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                decided_at DATETIME NULL,
                decided_by BIGINT UNSIGNED NULL,
                completed_at DATETIME NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_wc_number (claim_number),
                KEY idx_wc_registration (warranty_registration_id),
                KEY idx_wc_status (status),
                CONSTRAINT fk_wc_registration FOREIGN KEY (warranty_registration_id) REFERENCES warranty_registrations (id) ON DELETE RESTRICT,
                CONSTRAINT fk_wc_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_wc_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ----- warranty_claim_history ---------------------------------------
        $pdo->exec("
            CREATE TABLE warranty_claim_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                claim_id BIGINT UNSIGNED NOT NULL,
                from_status VARCHAR(30) NOT NULL,
                to_status VARCHAR(30) NOT NULL,
                actor_id BIGINT UNSIGNED NULL,
                reason VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_wch_claim (claim_id, id),
                CONSTRAINT fk_wch_claim FOREIGN KEY (claim_id) REFERENCES warranty_claims (id) ON DELETE CASCADE,
                CONSTRAINT fk_wch_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS warranty_claim_history');
        $pdo->exec('DROP TABLE IF EXISTS warranty_claims');
        $pdo->exec('DROP TABLE IF EXISTS warranty_registrations');
        $pdo->exec('DROP TABLE IF EXISTS warranty_policies');
    },
];
