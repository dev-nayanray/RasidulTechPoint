<?php

declare(strict_types=1);

/**
 * Delivery and courier management (Prompt 26).
 *
 * What this migration adds:
 *
 *  - couriers: external delivery partners (Pathao, RedX, Steadfast,
 *    in-house rider, etc.) with contact details, base fee + per-km
 *    fee for cost estimation, a tracking URL template (so the system
 *    can generate a clickable tracking link from a shipment reference),
 *    and api_provider + api_credentials columns for future gateway
 *    integration. External tracking is intentionally stubbed: the
 *    migration adds the columns so when real credentials and APIs are
 *    configured, the DeliveryService can be extended with a
 *    fetchTrackingStatus() method without another migration.
 *
 *  - delivery_zones: geographic areas with a preferred courier and a
 *    base fee + estimated delivery hours. Used to pre-fill the
 *    shipment form when the customer's address matches a zone.
 *
 *  - shipments: per-order delivery records. Each shipment links to an
 *    order + courier + delivery zone, carries a shipment_number
 *    (sequential, customer-visible), tracking reference + URL, fee,
 *    recipient details (name/phone/address snapshot at creation time
 *    so the shipment survives customer edits), and a status lifecycle:
 *
 *      pending → dispatched → in_transit → delivered (terminal)
 *                                      ↘ failed → returned_to_origin (terminal)
 *                          ↘ cancelled (terminal)
 *
 *    Proof of delivery is captured as a free-text reference (file path,
 *    photo URL, or signature reference) — never a binary blob.
 *    failure_reason captures why a delivery failed (customer not
 *    available, wrong address, damaged in transit, etc.).
 *
 * Inventory + financial integrity:
 *  - A shipment does NOT move inventory. The order's units stay in
 *    their current status. The shipment only tracks the physical
 *    delivery of the order to the customer.
 *  - A failed delivery that results in return-to-origin does NOT
 *    automatically return units to stock — that requires a separate
 *    refund/cancellation workflow via OrderService or RefundService.
 *    The shipment's return_to_origin status is a label that surfaces
 *    the physical state; the financial/inventory state is owned by
 *    the order + payment + refund tables.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        // ----- couriers -------------------------------------------------------
        $pdo->exec("
            CREATE TABLE couriers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                contact_name VARCHAR(120) NULL,
                email VARCHAR(190) NULL,
                phone VARCHAR(30) NULL,
                api_provider ENUM('manual','pathao','redx','steadfast','other') NOT NULL DEFAULT 'manual',
                api_credentials TEXT NULL,
                base_fee DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                per_km_fee DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                tracking_url_template VARCHAR(255) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_couriers_slug (slug),
                KEY idx_couriers_active (is_active),
                KEY idx_couriers_name (name),
                CONSTRAINT chk_courier_fees CHECK (base_fee >= 0 AND per_km_fee >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ----- delivery_zones -------------------------------------------------
        $pdo->exec("
            CREATE TABLE delivery_zones (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                description VARCHAR(255) NULL,
                courier_id BIGINT UNSIGNED NULL,
                base_fee DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                estimated_delivery_hours INT UNSIGNED NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_zones_name (name),
                KEY idx_zones_active (is_active),
                CONSTRAINT fk_zones_courier FOREIGN KEY (courier_id) REFERENCES couriers (id) ON DELETE SET NULL,
                CONSTRAINT chk_zone_fee CHECK (base_fee >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ----- shipments ------------------------------------------------------
        $pdo->exec("
            CREATE TABLE shipments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                shipment_number VARCHAR(40) NOT NULL,
                order_id BIGINT UNSIGNED NOT NULL,
                courier_id BIGINT UNSIGNED NULL,
                delivery_zone_id BIGINT UNSIGNED NULL,
                status ENUM('pending','dispatched','in_transit','delivered','failed','returned_to_origin','cancelled') NOT NULL DEFAULT 'pending',
                tracking_reference VARCHAR(120) NULL,
                tracking_url VARCHAR(255) NULL,
                fee DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                recipient_name VARCHAR(120) NOT NULL,
                recipient_phone VARCHAR(30) NOT NULL,
                delivery_address VARCHAR(255) NOT NULL,
                proof_of_delivery VARCHAR(255) NULL,
                failure_reason VARCHAR(255) NULL,
                notes TEXT NULL,
                created_by BIGINT UNSIGNED NULL,
                dispatched_at DATETIME NULL,
                delivered_at DATETIME NULL,
                failed_at DATETIME NULL,
                returned_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_shipments_number (shipment_number),
                KEY idx_shipments_order (order_id),
                KEY idx_shipments_courier (courier_id),
                KEY idx_shipments_status (status),
                CONSTRAINT fk_shipments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
                CONSTRAINT fk_shipments_courier FOREIGN KEY (courier_id) REFERENCES couriers (id) ON DELETE SET NULL,
                CONSTRAINT fk_shipments_zone FOREIGN KEY (delivery_zone_id) REFERENCES delivery_zones (id) ON DELETE SET NULL,
                CONSTRAINT fk_shipments_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_shipments_fee CHECK (fee >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS shipments');
        $pdo->exec('DROP TABLE IF EXISTS delivery_zones');
        $pdo->exec('DROP TABLE IF EXISTS couriers');
    },
];
