<?php

declare(strict_types=1);

/**
 * Repairs and service jobs (Prompt 28).
 *
 * What this migration adds:
 *
 *  - service_jobs: per-customer repair/service records. Each job
 *    links to a customer + (optionally) an inventory_unit (the
 *    physical laptop being repaired) + (optionally) a warranty_claim
 *    + (optionally) the sale order. A job can be:
 *      service_type = 'warranty'  — warranty repair, linked to a
 *                                   warranty_claim; no charge to the
 *                                   customer.
 *      service_type = 'paid'      — paid repair; customer pays for
 *                                   labor + parts.
 *      service_type = 'internal'  — internal repair/inspection not
 *                                   tied to a customer sale.
 *
 *    Status lifecycle:
 *      received → diagnosing → awaiting_parts → in_progress →
 *      quality_check → completed (terminal)
 *                                            ↘ cancelled (terminal)
 *      Any non-terminal status can transition to 'cancelled'.
 *
 *    Financial fields (labor_charge, parts_charge, total_charge) are
 *    separate from sale revenue — they live on the service job, not
 *    on orders or payments. A warranty job has zero charges; a paid
 *    job accumulates labor + parts charges.
 *
 *  - service_notes: append-only technician/customer-update log.
 *    note_type ENUM: diagnosis / repair / customer_update / internal.
 *
 *  - service_parts: billable parts used in the repair. Each part
 *    has a name, code, quantity, unit_cost (what the shop paid),
 *    unit_price (what the customer is charged), and line_total.
 *    parts_charge on the parent job is the sum of all line_totals.
 *
 *  - service_history: append-only status transition log. INSERT-
 *    only — history cannot be rewritten.
 *
 * Financial integrity:
 *  - Service costs (labor_charge + parts_charge + total_charge) are
 *    separate from sale revenue. They never appear in orders,
 *    payments, or reconciliation_batches. A paid repair's charges
 *    are tracked here; the cashier can optionally create a separate
 *    POS order for the repair payment if needed, but that's a
 *    separate flow.
 *  - A warranty job (service_type = 'warranty') has all charges set
 *    to 0.00 and is linked to a warranty_claim for the financial
 *    trail.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE service_jobs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_number VARCHAR(40) NOT NULL,
                customer_id BIGINT UNSIGNED NULL,
                inventory_unit_id BIGINT UNSIGNED NULL,
                warranty_claim_id BIGINT UNSIGNED NULL,
                order_id BIGINT UNSIGNED NULL,
                service_type ENUM('warranty','paid','internal') NOT NULL DEFAULT 'paid',
                status ENUM('received','diagnosing','awaiting_parts','in_progress','quality_check','completed','cancelled') NOT NULL DEFAULT 'received',
                priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
                reported_issue TEXT NOT NULL,
                diagnosis TEXT NULL,
                assigned_to BIGINT UNSIGNED NULL,
                estimated_cost DECIMAL(14,2) NULL,
                actual_cost DECIMAL(14,2) NULL,
                labor_charge DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                parts_charge DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total_charge DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                currency CHAR(3) NOT NULL DEFAULT 'BDT',
                created_by BIGINT UNSIGNED NULL,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                diagnosed_at DATETIME NULL,
                started_at DATETIME NULL,
                quality_checked_at DATETIME NULL,
                quality_checked_by BIGINT UNSIGNED NULL,
                completed_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_sj_number (job_number),
                KEY idx_sj_customer (customer_id),
                KEY idx_sj_unit (inventory_unit_id),
                KEY idx_sj_warranty_claim (warranty_claim_id),
                KEY idx_sj_status (status),
                KEY idx_sj_assigned (assigned_to),
                CONSTRAINT fk_sj_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
                CONSTRAINT fk_sj_unit FOREIGN KEY (inventory_unit_id) REFERENCES inventory_units (id) ON DELETE SET NULL,
                CONSTRAINT fk_sj_wc FOREIGN KEY (warranty_claim_id) REFERENCES warranty_claims (id) ON DELETE SET NULL,
                CONSTRAINT fk_sj_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL,
                CONSTRAINT fk_sj_assigned_to FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_sj_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_sj_qc_by FOREIGN KEY (quality_checked_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_sj_charges CHECK (labor_charge >= 0 AND parts_charge >= 0 AND total_charge >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE service_notes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                service_job_id BIGINT UNSIGNED NOT NULL,
                author_id BIGINT UNSIGNED NULL,
                body TEXT NOT NULL,
                note_type ENUM('diagnosis','repair','customer_update','internal') NOT NULL DEFAULT 'internal',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_sn_job (service_job_id, id),
                CONSTRAINT fk_sn_job FOREIGN KEY (service_job_id) REFERENCES service_jobs (id) ON DELETE CASCADE,
                CONSTRAINT fk_sn_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE service_parts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                service_job_id BIGINT UNSIGNED NOT NULL,
                part_name VARCHAR(220) NOT NULL,
                part_code VARCHAR(40) NULL,
                quantity INT UNSIGNED NOT NULL DEFAULT 1,
                unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                unit_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_sp_job (service_job_id),
                CONSTRAINT fk_sp_job FOREIGN KEY (service_job_id) REFERENCES service_jobs (id) ON DELETE CASCADE,
                CONSTRAINT chk_sp_qty CHECK (quantity > 0),
                CONSTRAINT chk_sp_costs CHECK (unit_cost >= 0 AND unit_price >= 0 AND line_total >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE service_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                service_job_id BIGINT UNSIGNED NOT NULL,
                from_status VARCHAR(30) NOT NULL,
                to_status VARCHAR(30) NOT NULL,
                actor_id BIGINT UNSIGNED NULL,
                reason VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_sh_job (service_job_id, id),
                CONSTRAINT fk_sh_job FOREIGN KEY (service_job_id) REFERENCES service_jobs (id) ON DELETE CASCADE,
                CONSTRAINT fk_sh_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS service_history');
        $pdo->exec('DROP TABLE IF EXISTS service_parts');
        $pdo->exec('DROP TABLE IF EXISTS service_notes');
        $pdo->exec('DROP TABLE IF EXISTS service_jobs');
    },
];
