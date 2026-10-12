<?php

declare(strict_types=1);

/**
 * Contact inquiries (Marketing-Website Prompt 2).
 *
 * Stores public contact-form submissions from /contact. Anonymous
 * visitors (no auth) submit; the inquiry is persisted + optionally
 * forwarded to the business via MailService (log driver by default
 * — real email is a separate deployment step).
 *
 * Privacy + safety:
 *  - IP is recorded for rate-limiting + abuse response. It is NOT
 *    shown in any admin UI that exposes it to non-staff roles.
 *  - Rate-limit: max 5 inquiries per (email+IP) per 15 minutes.
 *  - The form submission is NEVER auto-delivered to a real customer
 *    email — it lands in this table for staff triage. Email delivery
 *    is gated on the configured `MAIL_DRIVER` (when 'log', the message
 *    is appended to storage/logs/mail.log; when 'null', nothing is
 *    written).
 *
 * Schema is deliberately minimal — the existing `crm_inquiries` table
 * is for lead-tracked CRM work (FK to leads/customers/product_models);
 * the public contact form is for anonymous visitors and should not
 * require a lead/customer row to exist. Staff can later promote a
 * contact_inquiries row to a CRM lead if useful.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE contact_inquiries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inquiry_number VARCHAR(40) NOT NULL,
                full_name VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL,
                phone VARCHAR(30) NULL,
                topic VARCHAR(60) NOT NULL DEFAULT 'general',
                subject VARCHAR(255) NULL,
                message TEXT NOT NULL,
                order_reference VARCHAR(40) NULL,
                ip_address VARCHAR(64) NULL,
                user_agent VARCHAR(255) NULL,
                status ENUM('new','responded','closed','spam') NOT NULL DEFAULT 'new',
                responded_at DATETIME NULL,
                responded_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_contact_inquiries_number (inquiry_number),
                KEY idx_ci_email_ip (email, ip_address),
                KEY idx_ci_status (status, created_at),
                KEY idx_ci_topic (topic),
                CONSTRAINT chk_ci_message CHECK (CHAR_LENGTH(message) BETWEEN 10 AND 5000)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS contact_inquiries');
    },
];
