<?php

declare(strict_types=1);

/**
 * CRM and follow-ups (Prompt 30).
 *
 * What this migration adds:
 *
 *  - crm_leads: pre-sale leads with a lifecycle (new → contacted →
 *    qualified → quotation_sent → won | lost). Each lead can link to
 *    a customer record (if they're an existing customer) or carry
 *    standalone name/phone/email for walk-in leads. Leads have a
 *    source (walk_in / whatsapp / phone_call / facebook / website /
 *    referral / other), an assigned staff member, a next follow-up
 *    date, and a budget estimate. When a lead is 'won', the
 *    won_order_id links to the order created from the conversion.
 *
 *  - crm_inquiries: customer inquiries about products/services.
 *    Each inquiry has a channel (whatsapp / phone / email / in_store
 *    / website), a subject, a message, and a status (open / responded
 *    / closed). Can be linked to a lead or a customer or standalone.
 *
 *  - crm_quotations: price quotations sent to leads/customers. Each
 *    quotation has items (JSON payload with name/sku/qty/unit_price/
 *    line_total), subtotal, discount, total, a valid_until date, and
 *    a status lifecycle (draft → sent → accepted | rejected | expired
 *    | converted). When accepted, it can be converted to an order.
 *
 *  - crm_followups: scheduled follow-up actions with a method
 *    (whatsapp / phone_call / email / in_person), a scheduled date,
 *    an outcome (pending / contacted / no_answer / not_interested /
 *    converted), and completion tracking.
 *
 *  - crm_communications: append-only log of every communication
 *    (channel, direction inbound/outbound, subject, body, occurred_at).
 *    This is a manual log — the staff member records what happened
 *    after the fact. The system does NOT send automated messages.
 *
 * WhatsApp integration:
 *  - The service generates click-to-chat WhatsApp links (wa.me/<phone>
 *    ?text=<message>). These open WhatsApp with a pre-filled message
 *    for the staff member to review and manually send. NO automated
 *    messages are sent without a properly configured integration and
 *    appropriate consent — the links are click-to-chat only.
 *
 * Financial integrity:
 *  - Quotation totals are separate from order totals. A quotation
 *    is a pre-sale estimate; it does not touch orders, payments, or
 *    inventory. Only the convertToOrder() flow creates a new order
 *    (via OrderService), and that's an explicit manual action.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE crm_leads (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                lead_number VARCHAR(40) NOT NULL,
                customer_id BIGINT UNSIGNED NULL,
                name VARCHAR(120) NOT NULL,
                phone VARCHAR(30) NOT NULL,
                email VARCHAR(190) NULL,
                source ENUM('walk_in','whatsapp','phone_call','facebook','website','referral','other') NOT NULL DEFAULT 'walk_in',
                status ENUM('new','contacted','qualified','quotation_sent','won','lost') NOT NULL DEFAULT 'new',
                interest TEXT NULL,
                budget DECIMAL(14,2) NULL,
                assigned_to BIGINT UNSIGNED NULL,
                lost_reason VARCHAR(255) NULL,
                won_order_id BIGINT UNSIGNED NULL,
                next_followup_at DATETIME NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cl_number (lead_number),
                KEY idx_cl_customer (customer_id),
                KEY idx_cl_status (status),
                KEY idx_cl_assigned (assigned_to),
                KEY idx_cl_followup (next_followup_at),
                CONSTRAINT fk_cl_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
                CONSTRAINT fk_cl_assigned FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_cl_won_order FOREIGN KEY (won_order_id) REFERENCES orders (id) ON DELETE SET NULL,
                CONSTRAINT fk_cl_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE crm_inquiries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inquiry_number VARCHAR(40) NOT NULL,
                lead_id BIGINT UNSIGNED NULL,
                customer_id BIGINT UNSIGNED NULL,
                product_model_id BIGINT UNSIGNED NULL,
                subject VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                channel ENUM('whatsapp','phone','email','in_store','website') NOT NULL DEFAULT 'whatsapp',
                status ENUM('open','responded','closed') NOT NULL DEFAULT 'open',
                assigned_to BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ci_number (inquiry_number),
                KEY idx_ci_lead (lead_id),
                KEY idx_ci_customer (customer_id),
                KEY idx_ci_status (status),
                CONSTRAINT fk_ci_lead FOREIGN KEY (lead_id) REFERENCES crm_leads (id) ON DELETE SET NULL,
                CONSTRAINT fk_ci_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
                CONSTRAINT fk_ci_model FOREIGN KEY (product_model_id) REFERENCES product_models (id) ON DELETE SET NULL,
                CONSTRAINT fk_ci_assigned FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE crm_quotations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                quote_number VARCHAR(40) NOT NULL,
                lead_id BIGINT UNSIGNED NULL,
                customer_id BIGINT UNSIGNED NOT NULL,
                product_model_id BIGINT UNSIGNED NULL,
                items_payload TEXT NOT NULL,
                subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                discount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                currency CHAR(3) NOT NULL DEFAULT 'BDT',
                status ENUM('draft','sent','accepted','rejected','expired','converted') NOT NULL DEFAULT 'draft',
                valid_until DATE NULL,
                notes TEXT NULL,
                created_by BIGINT UNSIGNED NULL,
                sent_at DATETIME NULL,
                accepted_at DATETIME NULL,
                converted_order_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cq_number (quote_number),
                KEY idx_cq_lead (lead_id),
                KEY idx_cq_customer (customer_id),
                KEY idx_cq_status (status),
                CONSTRAINT fk_cq_lead FOREIGN KEY (lead_id) REFERENCES crm_leads (id) ON DELETE SET NULL,
                CONSTRAINT fk_cq_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT,
                CONSTRAINT fk_cq_model FOREIGN KEY (product_model_id) REFERENCES product_models (id) ON DELETE SET NULL,
                CONSTRAINT fk_cq_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_cq_converted FOREIGN KEY (converted_order_id) REFERENCES orders (id) ON DELETE SET NULL,
                CONSTRAINT chk_cq_total CHECK (subtotal >= 0 AND discount >= 0 AND total >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE crm_followups (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                lead_id BIGINT UNSIGNED NULL,
                customer_id BIGINT UNSIGNED NULL,
                inquiry_id BIGINT UNSIGNED NULL,
                scheduled_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                method ENUM('whatsapp','phone_call','email','in_person') NOT NULL DEFAULT 'whatsapp',
                notes TEXT NULL,
                outcome ENUM('pending','contacted','no_answer','not_interested','converted') NOT NULL DEFAULT 'pending',
                completed_by BIGINT UNSIGNED NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_cf_lead (lead_id),
                KEY idx_cf_customer (customer_id),
                KEY idx_cf_scheduled (scheduled_at, outcome),
                CONSTRAINT fk_cf_lead FOREIGN KEY (lead_id) REFERENCES crm_leads (id) ON DELETE SET NULL,
                CONSTRAINT fk_cf_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
                CONSTRAINT fk_cf_inquiry FOREIGN KEY (inquiry_id) REFERENCES crm_inquiries (id) ON DELETE SET NULL,
                CONSTRAINT fk_cf_completed_by FOREIGN KEY (completed_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_cf_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE crm_communications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                lead_id BIGINT UNSIGNED NULL,
                customer_id BIGINT UNSIGNED NULL,
                inquiry_id BIGINT UNSIGNED NULL,
                channel ENUM('whatsapp','phone_call','email','in_person','sms') NOT NULL DEFAULT 'whatsapp',
                direction ENUM('inbound','outbound') NOT NULL DEFAULT 'outbound',
                subject VARCHAR(255) NULL,
                body TEXT NULL,
                initiated_by BIGINT UNSIGNED NULL,
                occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_cc_lead (lead_id),
                KEY idx_cc_customer (customer_id),
                KEY idx_cc_occurred (occurred_at),
                CONSTRAINT fk_cc_lead FOREIGN KEY (lead_id) REFERENCES crm_leads (id) ON DELETE SET NULL,
                CONSTRAINT fk_cc_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
                CONSTRAINT fk_cc_inquiry FOREIGN KEY (inquiry_id) REFERENCES crm_inquiries (id) ON DELETE SET NULL,
                CONSTRAINT fk_cc_initiated_by FOREIGN KEY (initiated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS crm_communications');
        $pdo->exec('DROP TABLE IF EXISTS crm_followups');
        $pdo->exec('DROP TABLE IF EXISTS crm_quotations');
        $pdo->exec('DROP TABLE IF EXISTS crm_inquiries');
        $pdo->exec('DROP TABLE IF EXISTS crm_leads');
    },
];
