<?php

declare(strict_types=1);

/**
 * Notifications & templates (Prompt 38).
 *
 * What this migration adds:
 *
 *  - notification_templates: configurable notification templates
 *    keyed by event_key (e.g. 'order.created', 'payment.confirmed').
 *    Each template defines a title/body/link pattern with {placeholder}
 *    substitution, a recipient resolver (by roles / permissions /
 *    specific users / all staff), an enabled flag, and a dedupe
 *    window so the same event for the same recipient is not
 *    re-delivered within N seconds.
 *
 *  - notification_deliveries: append-only delivery log + retry
 *    state. One row per logical delivery (one event → one recipient).
 *    Records template_id, event_key, recipient_user_id, dedupe_key,
 *    status (delivered / failed / suppressed_duplicate / no_template /
 *    no_recipients), attempts counter (for retry), last_error, and
 *    delivered_at. Failed rows with attempts < MAX can be picked up
 *    by NotificationService::retryFailed() and re-attempted in place.
 *
 * The existing `notifications` table (from migration 002) is left
 * untouched — backward compatibility with manual pushes is preserved.
 * The new delivery log associates a delivery with its notification
 * row via notification_id (nullable, set when the notification row
 * was actually inserted).
 *
 * Privacy:
 *  - Recipients are resolved server-side from the template definition
 *    (roles / permissions / explicit user IDs) — never from the
 *    caller's input. A caller cannot direct a notification at an
 *    arbitrary user.
 *  - The NotificationController still scopes reads / markRead to the
 *    signed-in user; the new delivery log viewer is gated behind
 *    notifications.manage and never exposes another user's body
 *    content (only event_key + status + timestamps).
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE notification_templates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_key VARCHAR(80) NOT NULL,
                title VARCHAR(190) NOT NULL,
                body TEXT NULL,
                link_template VARCHAR(255) NULL,
                recipient_mode ENUM('roles','permissions','specific_users','all_staff') NOT NULL DEFAULT 'roles',
                recipient_value VARCHAR(255) NOT NULL DEFAULT '',
                channel ENUM('in_app') NOT NULL DEFAULT 'in_app',
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                dedupe_window_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_nt_event_key (event_key),
                KEY idx_nt_enabled (is_enabled),
                CONSTRAINT fk_nt_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_nt_dedupe CHECK (dedupe_window_seconds >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE notification_deliveries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                notification_id BIGINT UNSIGNED NULL,
                template_id BIGINT UNSIGNED NULL,
                event_key VARCHAR(80) NOT NULL,
                recipient_user_id BIGINT UNSIGNED NULL,
                dedupe_key VARCHAR(190) NULL,
                status ENUM('delivered','failed','suppressed_duplicate','no_template','no_recipients') NOT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
                last_error VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                delivered_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_nd_event (event_key, created_at),
                KEY idx_nd_recipient (recipient_user_id, created_at),
                KEY idx_nd_status (status, attempts),
                KEY idx_nd_dedupe (event_key, recipient_user_id, dedupe_key),
                CONSTRAINT fk_nd_notification FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE SET NULL,
                CONSTRAINT fk_nd_template FOREIGN KEY (template_id) REFERENCES notification_templates (id) ON DELETE SET NULL,
                CONSTRAINT fk_nd_recipient FOREIGN KEY (recipient_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_nd_attempts CHECK (attempts >= 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed default templates for the wired events. All enabled by
        // default with a 5-minute dedupe window so a re-dispatch within
        // 5 minutes for the same entity is suppressed.
        $defaults = [
            [
                'order.created',
                'New order {reference}',
                'Order {reference} for {customer_name} totalling {total} BDT was placed via {channel}.',
                '/admin/orders/{order_id}',
                'roles', 'manager,accountant', 300,
            ],
            [
                'order.status_updated',
                'Order {reference}: {to}',
                'Order {reference} moved from {from} to {to}.{reason_clause}',
                '/admin/orders/{order_id}',
                'roles', 'manager', 60,
            ],
            [
                'payment.confirmed',
                'Payment confirmed for {reference}',
                'Payment of {amount} BDT via {method} was confirmed for order {reference}.',
                '/admin/orders/{order_id}',
                'permissions', 'payments.manage', 300,
            ],
            [
                'refund.processed',
                'Refund processed for {reference}',
                'Refund of {amount} BDT was processed for order {reference}.',
                '/admin/orders/{order_id}',
                'roles', 'manager,accountant', 300,
            ],
            [
                'coupon.redeemed',
                'Coupon {code} redeemed',
                'Coupon {code} was applied to order {reference}, discounting {discount} BDT.',
                '/admin/coupons/{coupon_id}',
                'roles', 'manager', 60,
            ],
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO notification_templates
                (event_key, title, body, link_template, recipient_mode, recipient_value,
                 is_enabled, dedupe_window_seconds, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, NULL)'
        );
        foreach ($defaults as $row) {
            $stmt->execute($row);
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS notification_deliveries');
        $pdo->exec('DROP TABLE IF EXISTS notification_templates');
    },
];
