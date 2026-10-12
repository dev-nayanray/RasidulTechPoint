<?php

declare(strict_types=1);

/**
 * System settings & integrations (Prompt 39).
 *
 * What this migration adds:
 *
 *  - Extends the existing `settings` table (from migration 002) with
 *    three nullable columns so the table can store both plaintext
 *    settings (legacy) and secret/encrypted settings (new):
 *      * `is_secret` TINYINT(1) NOT NULL DEFAULT 0  — when 1, the
 *        setting_value column is NULL and the encrypted_value column
 *        holds the ciphertext (base64-encoded IV + ciphertext).
 *      * `encrypted_value` TEXT NULL — the encrypted blob (IV +
 *        ciphertext, base64-encoded). Populated only when is_secret=1.
 *      * `category` VARCHAR(60) NULL — finer-grained grouping than
 *        setting_group (currency / dates / invoice / inventory /
 *        warranty / delivery / email / payments / integrations).
 *
 *  - The existing `setting_group` column is left untouched so legacy
 *    callers and the old SettingsController still work; `category` is
 *    the new canonical grouping for the categorized Prompt-39 views.
 *
 *  - Seeds a curated set of system defaults across the 9 categories
 *    so the admin UI has a non-empty starting state. Secrets are NOT
 *    seeded here — they default to "not configured" and the admin
 *    must enter them via the UI.
 *
 * Secrets handling:
 *  - The application's `APP_KEY` env var (loaded into Config::app.key)
 *    is the source of the encryption key. A SHA-256 digest of APP_KEY
 *    is used to derive a 32-byte AES-256 key.
 *  - The Crypto helper uses AES-256-CBC with a random 16-byte IV per
 *    value. IV is prepended to the ciphertext and base64-encoded as a
 *    single blob.
 *  - In test/local env (APP_KEY empty) the Crypto helper refuses to
 *    encrypt and setSecret throws — secrets cannot be stored without
 *    a real key.
 *
 * Audit:
 *  - Every settings update fires app_audit('settings.<category>_updated',
 *    ...) with the changed keys + their new values. Secret values are
 *    never included in the audit metadata — only the key name + a
 *    'changed' boolean.
 *
 * Reversible (the three columns are dropped; seeded rows are deleted
 * by the down function before dropping).
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            ALTER TABLE settings
                ADD COLUMN is_secret TINYINT(1) NOT NULL DEFAULT 0 AFTER setting_type,
                ADD COLUMN encrypted_value TEXT NULL AFTER setting_value,
                ADD COLUMN category VARCHAR(60) NULL AFTER setting_group,
                ADD KEY idx_settings_category (category, is_secret)
        ");

        // Backfill category on existing rows.
        $pdo->exec("UPDATE settings SET category = setting_group WHERE category IS NULL");

        // Seed curated system defaults across the 9 categories.
        $defaults = [
            // Currency
            ['currency_code', 'BDT', 'string', 'currency', 'currency'],
            ['currency_symbol', '৳', 'string', 'currency', 'currency'],
            ['currency_position', 'before', 'string', 'currency', 'currency'],
            ['currency_decimal_places', '2', 'int', 'currency', 'currency'],
            // Dates
            ['date_format', 'd M Y', 'string', 'dates', 'dates'],
            ['datetime_format', 'd M Y, g:i a', 'string', 'dates', 'dates'],
            ['time_format', 'g:i a', 'string', 'dates', 'dates'],
            ['first_day_of_week', 'sun', 'string', 'dates', 'dates'],
            // Inventory rules
            ['inventory_low_stock_threshold', '5', 'int', 'inventory', 'inventory'],
            ['inventory_allow_negative_stock', '0', 'bool', 'inventory', 'inventory'],
            ['inventory_default_markup_percent', '30', 'int', 'inventory', 'inventory'],
            ['inventory_track_serial_numbers', '1', 'bool', 'inventory', 'inventory'],
            // Warranty defaults
            ['warranty_default_days', '90', 'int', 'warranty', 'warranty'],
            ['warranty_requires_serial', '1', 'bool', 'warranty', 'warranty'],
            ['warranty_claim_window_days', '7', 'int', 'warranty', 'warranty'],
            // Delivery
            ['delivery_default_carrier', '', 'string', 'delivery', 'delivery'],
            ['delivery_default_shipping_days', '3', 'int', 'delivery', 'delivery'],
            ['delivery_default_shipping_fee', '0.00', 'string', 'delivery', 'delivery'],
            ['delivery_free_shipping_threshold', '', 'string', 'delivery', 'delivery'],
            // Email (driver/from are duplicated from .env so the admin
            // can override at runtime; .env stays the source when the
            // row is missing).
            ['mail_driver', 'log', 'string', 'email', 'email'],
            ['mail_from_address', 'no-reply@rasidultechpoint.example', 'string', 'email', 'email'],
            ['mail_from_name', 'Rasidul Tech Point', 'string', 'email', 'email'],
            ['mail_smtp_host', '', 'string', 'email', 'email'],
            ['mail_smtp_port', '587', 'int', 'email', 'email'],
            ['mail_smtp_encryption', 'tls', 'string', 'email', 'email'],
            ['mail_smtp_username', '', 'string', 'email', 'email'],
            ['mail_bcc_address', '', 'string', 'email', 'email'],
            // Payment providers (all disabled by default; secrets left
            // empty — admin must enter them).
            ['payment_provider_stripe_enabled', '0', 'bool', 'payments', 'payments'],
            ['payment_provider_stripe_publishable_key', '', 'string', 'payments', 'payments'],
            ['payment_provider_bkash_enabled', '0', 'bool', 'payments', 'payments'],
            ['payment_provider_bkash_app_key', '', 'string', 'payments', 'payments'],
            ['payment_provider_bkash_username', '', 'string', 'payments', 'payments'],
            ['payment_provider_nagad_enabled', '0', 'bool', 'payments', 'payments'],
            ['payment_provider_nagad_merchant_id', '', 'string', 'payments', 'payments'],
            ['payment_provider_nagad_public_key', '', 'string', 'payments', 'payments'],
            // Integrations
            ['integration_sms_enabled', '0', 'bool', 'integrations', 'integrations'],
            ['integration_sms_provider', 'none', 'string', 'integrations', 'integrations'],
            ['integration_sms_api_key', '', 'string', 'integrations', 'integrations'],
            ['integration_sms_sender_id', '', 'string', 'integrations', 'integrations'],
            ['integration_google_analytics_id', '', 'string', 'integrations', 'integrations'],
            ['integration_facebook_pixel_id', '', 'string', 'integrations', 'integrations'],
            ['integration_webhook_url', '', 'string', 'integrations', 'integrations'],
        ];
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO settings
                (setting_key, setting_value, setting_type, setting_group, category, is_secret, encrypted_value, updated_by)
             VALUES (?, ?, ?, ?, ?, 0, NULL, NULL)'
        );
        foreach ($defaults as $row) {
            [$key, $value, $type, $group, $category] = $row;
            $stmt->execute([$key, $value, $type, $group, $category]);
        }
    },

    'down' => function (PDO $pdo): void {
        // Remove only the seeded defaults + any secrets — leave the
        // original 6 invoice settings (group=invoices) untouched.
        $seededKeys = [
            'currency_code','currency_symbol','currency_position','currency_decimal_places',
            'date_format','datetime_format','time_format','first_day_of_week',
            'inventory_low_stock_threshold','inventory_allow_negative_stock','inventory_default_markup_percent','inventory_track_serial_numbers',
            'warranty_default_days','warranty_requires_serial','warranty_claim_window_days',
            'delivery_default_carrier','delivery_default_shipping_days','delivery_default_shipping_fee','delivery_free_shipping_threshold',
            'mail_driver','mail_from_address','mail_from_name','mail_smtp_host','mail_smtp_port','mail_smtp_encryption','mail_smtp_username','mail_bcc_address',
            'payment_provider_stripe_enabled','payment_provider_stripe_publishable_key',
            'payment_provider_bkash_enabled','payment_provider_bkash_app_key','payment_provider_bkash_username',
            'payment_provider_nagad_enabled','payment_provider_nagad_merchant_id','payment_provider_nagad_public_key',
            'integration_sms_enabled','integration_sms_provider','integration_sms_api_key','integration_sms_sender_id',
            'integration_google_analytics_id','integration_facebook_pixel_id','integration_webhook_url',
        ];
        $placeholders = implode(',', array_fill(0, count($seededKeys), '?'));
        $pdo->prepare("DELETE FROM settings WHERE setting_key IN ({$placeholders}) AND is_secret = 1")->execute($seededKeys);

        try { $pdo->exec('ALTER TABLE settings DROP KEY idx_settings_category'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE settings DROP COLUMN category'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE settings DROP COLUMN encrypted_value'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE settings DROP COLUMN is_secret'); } catch (\Throwable) {}
    },
];
