<?php

declare(strict_types=1);

/**
 * Coupons and promotions (Prompt 37).
 *
 * What this migration adds:
 *
 *  - coupons: discount code definitions with type (fixed / percentage),
 *    validity window (starts_at / ends_at), usage limits (overall and
 *    per-customer), minimum subtotal, optional max discount cap, and
 *    product/category eligibility rules. Coupons live in their own
 *    status workflow (draft → pending_approval → active → paused /
 *    rejected → archived) so creating a coupon never makes it live
 *    until a manager approves it.
 *
 *  - coupon_target_products / coupon_target_categories: junction
 *    tables listing the product_models / categories the coupon
 *    applies to when coupons.applies_to is 'products' or
 *    'categories'. The eligibility_mode column on the parent coupon
 *    controls whether the listed items are the only eligible set
 *    ('include') or the only excluded set ('exclude').
 *
 *  - coupon_usages: append-only ledger of every redemption. One row
 *    per redemption = one per order. The (coupon_id, order_id) pair
 *    is unique so a coupon can be applied at most once per order.
 *    Records the actual discount_amount applied (server-computed,
 *    never the client's claimed amount).
 *
 *  - orders.coupon_id: nullable FK so the order detail page can show
 *    which coupon (if any) was applied. The orders.discount column
 *    already holds the actual discount amount (coupon or otherwise).
 *
 * Financial integrity:
 *  - Coupon discount is ALWAYS recomputed server-side from the coupon
 *    definition (CouponService::computeDiscount). The client's
 *    claimed discount is never trusted.
 *  - The computed discount is capped so it can never exceed the
 *    order subtotal — fixed coupons above subtotal are clamped,
 *    percentage coupons are clamped by max_discount if set.
 *  - coupon_usages.discount_amount is CHECK-constrained to be >= 0
 *    and the order's CHECK (subtotal >= 0 AND discount >= 0 AND
 *    total >= 0) from migration 005 already forbids invalid totals.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE coupons (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(64) NOT NULL,
                description VARCHAR(255) NULL,
                discount_type ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed',
                discount_value DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                min_subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                max_discount DECIMAL(14,2) NULL,
                starts_at DATETIME NULL,
                ends_at DATETIME NULL,
                usage_limit INT UNSIGNED NULL,
                usage_limit_per_customer INT UNSIGNED NOT NULL DEFAULT 1,
                applies_to ENUM('all','categories','products') NOT NULL DEFAULT 'all',
                eligibility_mode ENUM('include','exclude') NOT NULL DEFAULT 'include',
                status ENUM('draft','pending_approval','active','paused','rejected','archived') NOT NULL DEFAULT 'draft',
                created_by BIGINT UNSIGNED NULL,
                approved_by BIGINT UNSIGNED NULL,
                approved_at DATETIME NULL,
                rejected_by BIGINT UNSIGNED NULL,
                rejected_at DATETIME NULL,
                rejection_reason VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_coupons_code (code),
                KEY idx_coupons_status (status, starts_at, ends_at),
                KEY idx_coupons_dates (starts_at, ends_at),
                CONSTRAINT fk_coupons_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_coupons_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_coupons_rejected_by FOREIGN KEY (rejected_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_coupons_value CHECK (
                    discount_value >= 0
                    AND min_subtotal >= 0
                    AND (max_discount IS NULL OR max_discount >= 0)
                    AND (discount_type = 'percentage' OR discount_value >= 0)
                    AND (discount_type != 'percentage' OR discount_value <= 100)
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE coupon_target_products (
                coupon_id BIGINT UNSIGNED NOT NULL,
                product_model_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (coupon_id, product_model_id),
                KEY idx_ctp_coupon (coupon_id),
                KEY idx_ctp_product (product_model_id),
                CONSTRAINT fk_ctp_coupon FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE CASCADE,
                CONSTRAINT fk_ctp_product FOREIGN KEY (product_model_id) REFERENCES product_models (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE coupon_target_categories (
                coupon_id BIGINT UNSIGNED NOT NULL,
                category_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (coupon_id, category_id),
                KEY idx_ctc_coupon (coupon_id),
                KEY idx_ctc_category (category_id),
                CONSTRAINT fk_ctc_coupon FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE CASCADE,
                CONSTRAINT fk_ctc_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE coupon_usages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                coupon_id BIGINT UNSIGNED NOT NULL,
                order_id BIGINT UNSIGNED NOT NULL,
                customer_id BIGINT UNSIGNED NULL,
                discount_amount DECIMAL(14,2) NOT NULL,
                applied_by BIGINT UNSIGNED NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_coupon_usages_order (coupon_id, order_id),
                KEY idx_cu_coupon (coupon_id, applied_at),
                KEY idx_cu_customer (customer_id),
                CONSTRAINT fk_cu_coupon FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE RESTRICT,
                CONSTRAINT fk_cu_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
                CONSTRAINT fk_cu_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
                CONSTRAINT fk_cu_applied_by FOREIGN KEY (applied_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_cu_amount CHECK (discount_amount >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Link applied coupon back to the order (nullable; legacy orders
        // pre-Prompt-37 simply have NULL here).
        $pdo->exec('ALTER TABLE orders ADD COLUMN coupon_id BIGINT UNSIGNED NULL AFTER discount_authorized_by');
        $pdo->exec('ALTER TABLE orders ADD KEY idx_orders_coupon (coupon_id)');
        $pdo->exec(
            'ALTER TABLE orders ADD CONSTRAINT fk_orders_coupon FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE SET NULL'
        );
    },

    'down' => function (PDO $pdo): void {
        try { $pdo->exec('ALTER TABLE orders DROP FOREIGN KEY fk_orders_coupon'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE orders DROP KEY idx_orders_coupon'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE orders DROP COLUMN coupon_id'); } catch (\Throwable) {}

        foreach (['coupon_usages', 'coupon_target_categories', 'coupon_target_products', 'coupons'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    },
];
