<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Delivery and courier operations: create shipments, dispatch, track,
 * deliver, fail, and return-to-origin.
 *
 * Dispatch status matrix:
 *   pending → dispatched, cancelled
 *   dispatched → in_transit, delivered, failed, returned_to_origin, cancelled
 *   in_transit → delivered, failed, returned_to_origin, cancelled
 *   delivered → (terminal)
 *   failed → returned_to_origin, cancelled
 *   returned_to_origin → (terminal)
 *   cancelled → (terminal)
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
 * External tracking integration:
 *  - The couriers table has api_provider + api_credentials columns
 *    for future gateway integration. When real credentials and APIs
 *    are configured, a fetchTrackingStatus() method can be added here
 *    without another migration. For now, tracking is manual — the
 *    operator pastes in the tracking reference and URL.
 */
final class DeliveryService
{
    public const SHIPMENT_STATUSES = [
        'pending', 'dispatched', 'in_transit', 'delivered',
        'failed', 'returned_to_origin', 'cancelled',
    ];

    /**
     * Allowed status transitions: from_status => list of allowed targets.
     * Terminal statuses (delivered, returned_to_origin, cancelled) have
     * empty arrays — no further transitions allowed.
     */
    public const STATUS_TRANSITIONS = [
        'pending' => ['dispatched', 'cancelled'],
        'dispatched' => ['in_transit', 'delivered', 'failed', 'returned_to_origin', 'cancelled'],
        'in_transit' => ['delivered', 'failed', 'returned_to_origin', 'cancelled'],
        'delivered' => [],
        'failed' => ['returned_to_origin', 'cancelled'],
        'returned_to_origin' => [],
        'cancelled' => [],
    ];

    /**
     * Create a pending shipment for an order. Snapshots the recipient
     * details (name/phone/address) at creation time so the shipment
     * survives customer edits.
     */
    public function createShipment(
        int $orderId,
        ?int $courierId,
        ?int $zoneId,
        float $fee,
        string $recipientName,
        string $recipientPhone,
        string $deliveryAddress,
        ?string $notes,
        int $createdBy,
    ): array {
        if (trim($recipientName) === '') {
            throw new ValidationException(['recipient_name' => 'Recipient name is required.']);
        }
        if (trim($recipientPhone) === '') {
            throw new ValidationException(['recipient_phone' => 'Recipient phone is required.']);
        }
        if (trim($deliveryAddress) === '') {
            throw new ValidationException(['delivery_address' => 'Delivery address is required.']);
        }
        if ($fee < 0) {
            throw new ValidationException(['fee' => 'Delivery fee cannot be negative.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($orderId, $courierId, $zoneId, $fee, $recipientName, $recipientPhone, $deliveryAddress, $notes, $createdBy) {
            // Verify the order exists.
            $stmt = $pdo->prepare('SELECT id FROM orders WHERE id = ?');
            $stmt->execute([$orderId]);
            if ($stmt->fetchColumn() === false) {
                throw new ValidationException(['order_id' => 'Order does not exist.']);
            }

            // Verify the courier exists and is active (if provided).
            if ($courierId !== null) {
                $stmt = $pdo->prepare('SELECT id FROM couriers WHERE id = ? AND is_active = 1 AND deleted_at IS NULL');
                $stmt->execute([$courierId]);
                if ($stmt->fetchColumn() === false) {
                    throw new ValidationException(['courier_id' => 'Courier does not exist or is inactive.']);
                }
            }

            // Verify the zone exists (if provided).
            if ($zoneId !== null) {
                $stmt = $pdo->prepare('SELECT id FROM delivery_zones WHERE id = ? AND is_active = 1');
                $stmt->execute([$zoneId]);
                if ($stmt->fetchColumn() === false) {
                    throw new ValidationException(['delivery_zone_id' => 'Delivery zone does not exist or is inactive.']);
                }
            }

            $shipmentNumber = $this->generateShipmentNumber($pdo);

            $stmt = $pdo->prepare(
                'INSERT INTO shipments
                    (shipment_number, order_id, courier_id, delivery_zone_id, status, fee,
                     recipient_name, recipient_phone, delivery_address, notes, created_by)
                 VALUES (?, ?, ?, ?, "pending", ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $shipmentNumber,
                $orderId,
                $courierId,
                $zoneId,
                number_format($fee, 2, '.', ''),
                $recipientName,
                $recipientPhone,
                $deliveryAddress,
                $notes,
                $createdBy,
            ]);
            $shipmentId = (int) $pdo->lastInsertId();

            app_audit('delivery.shipment_created', 'shipment', $shipmentId, [
                'shipment_number' => $shipmentNumber,
                'order_id' => $orderId,
                'courier_id' => $courierId,
                'fee' => number_format($fee, 2, '.', ''),
            ]);

            return [
                'id' => $shipmentId,
                'shipment_number' => $shipmentNumber,
                'status' => 'pending',
            ];
        });
    }

    /**
     * Dispatch a pending shipment: assign a courier, set the tracking
     * reference + URL, mark as 'dispatched'.
     */
    public function dispatch(int $shipmentId, int $courierId, ?string $trackingReference, ?string $trackingUrl, int $dispatchedBy): array
    {
        return Connection::transaction(function (PDO $pdo) use ($shipmentId, $courierId, $trackingReference, $trackingUrl, $dispatchedBy) {
            $stmt = $pdo->prepare('SELECT * FROM shipments WHERE id = ? FOR UPDATE');
            $stmt->execute([$shipmentId]);
            $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($shipment === false) {
                throw new ValidationException(['shipment_id' => 'Shipment does not exist.']);
            }
            $this->assertTransitionAllowed((string) $shipment['status'], 'dispatched');

            // Verify courier.
            $stmt = $pdo->prepare('SELECT id, tracking_url_template FROM couriers WHERE id = ? AND is_active = 1 AND deleted_at IS NULL');
            $stmt->execute([$courierId]);
            $courier = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($courier === false) {
                throw new ValidationException(['courier_id' => 'Courier does not exist or is inactive.']);
            }

            // If no tracking URL was supplied but the courier has a
            // template, generate one from the tracking reference.
            $finalTrackingUrl = $trackingUrl;
            if ($finalTrackingUrl === null || $finalTrackingUrl === '') {
                if ($trackingReference !== null && $trackingReference !== '' && !empty($courier['tracking_url_template'])) {
                    $finalTrackingUrl = str_replace('{ref}', $trackingReference, $courier['tracking_url_template']);
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE shipments
                 SET status = "dispatched", courier_id = ?, tracking_reference = ?, tracking_url = ?,
                     dispatched_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            );
            $stmt->execute([
                $courierId,
                $trackingReference,
                $finalTrackingUrl,
                $shipmentId,
            ]);

            app_audit('delivery.dispatched', 'shipment', $shipmentId, [
                'courier_id' => $courierId,
                'tracking_reference' => mb_substr((string) $trackingReference ?? '', 0, 80),
            ]);

            return ['id' => $shipmentId, 'status' => 'dispatched'];
        });
    }

    /**
     * Mark a dispatched shipment as in_transit.
     */
    public function markInTransit(int $shipmentId): array
    {
        return $this->transition($shipmentId, 'in_transit', null);
    }

    /**
     * Mark a shipment as delivered. Proof of delivery is captured as
     * a free-text reference (file path, photo URL, or signature
     * reference) — never a binary blob.
     */
    public function markDelivered(int $shipmentId, ?string $proofOfDelivery): array
    {
        return $this->transition($shipmentId, 'delivered', function (PDO $pdo, array $shipment) use ($proofOfDelivery) {
            $stmt = $pdo->prepare(
                'UPDATE shipments SET proof_of_delivery = ?, delivered_at = UTC_TIMESTAMP() WHERE id = ?'
            );
            $stmt->execute([$proofOfDelivery, $shipment['id']]);
        });
    }

    /**
     * Mark a shipment as failed. A failure reason is required.
     */
    public function markFailed(int $shipmentId, string $failureReason): array
    {
        $failureReason = trim($failureReason);
        if ($failureReason === '') {
            throw new ValidationException(['failure_reason' => 'A failure reason is required.']);
        }
        return $this->transition($shipmentId, 'failed', function (PDO $pdo, array $shipment) use ($failureReason) {
            $stmt = $pdo->prepare(
                'UPDATE shipments SET failure_reason = ?, failed_at = UTC_TIMESTAMP() WHERE id = ?'
            );
            $stmt->execute([mb_substr($failureReason, 0, 255), $shipment['id']]);
        });
    }

    /**
     * Return a failed (or dispatched/in_transit) shipment to origin.
     * This is a physical-state label — it does NOT automatically
     * return units to stock or refund the customer. That requires a
     * separate refund/cancellation workflow.
     */
    public function returnToOrigin(int $shipmentId, ?string $reason = null): array
    {
        return $this->transition($shipmentId, 'returned_to_origin', function (PDO $pdo, array $shipment) use ($reason) {
            $stmt = $pdo->prepare(
                'UPDATE shipments SET returned_at = UTC_TIMESTAMP(), notes = CONCAT(IFNULL(notes, ""), ?) WHERE id = ?'
            );
            $stmt->execute([
                $reason !== null ? "\nReturned to origin: " . $reason : '',
                $shipment['id'],
            ]);
        });
    }

    /**
     * Cancel a shipment. Only allowed from non-terminal statuses.
     */
    public function cancel(int $shipmentId): array
    {
        return $this->transition($shipmentId, 'cancelled', function (PDO $pdo, array $shipment) {
            $stmt = $pdo->prepare('UPDATE shipments SET cancelled_at = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->execute([$shipment['id']]);
        });
    }

    /**
     * Throw a ValidationException if $from → $to is not whitelisted by
     * STATUS_TRANSITIONS.
     */
    public function assertTransitionAllowed(string $from, string $to): void
    {
        $allowed = self::STATUS_TRANSITIONS[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new ValidationException([
                'status' => "Shipment status transition '{$from}' → '{$to}' is not allowed.",
            ]);
        }
    }

    /** @return list<array<string,mixed>> allowed transitions from $from */
    public function allowedTransitions(string $from): array
    {
        $list = self::STATUS_TRANSITIONS[$from] ?? [];
        $out = [];
        foreach ($list as $to) {
            $out[] = ['from' => $from, 'to' => $to, 'label' => ucfirst(str_replace('_', ' ', $to))];
        }
        return $out;
    }

    // ---------------------------------------------------- READS --

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function searchShipments(
        string $q = '',
        string $status = '',
        ?int $courierId = null,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (s.shipment_number LIKE ? OR s.tracking_reference LIKE ? OR o.reference LIKE ? OR s.recipient_name LIKE ? OR s.recipient_phone LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($status !== '' && in_array($status, self::SHIPMENT_STATUSES, true)) {
            $where .= ' AND s.status = ?';
            $params[] = $status;
        }
        if ($courierId !== null && $courierId > 0) {
            $where .= ' AND s.courier_id = ?';
            $params[] = $courierId;
        }

        $countStmt = Connection::pdo()->prepare(
            "SELECT COUNT(*) FROM shipments s JOIN orders o ON o.id = s.order_id{$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = Connection::pdo()->prepare(
            "SELECT s.*, o.reference AS order_reference, c.name AS courier_name,
                    z.name AS zone_name
             FROM shipments s
             JOIN orders o ON o.id = s.order_id
             LEFT JOIN couriers c ON c.id = s.courier_id
             LEFT JOIN delivery_zones z ON z.id = s.delivery_zone_id
             {$where}
             ORDER BY s.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function findShipment(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT s.*, o.reference AS order_reference, o.customer_id,
                    c.name AS courier_name,
                    z.name AS zone_name,
                    u.name AS created_by_name
             FROM shipments s
             JOIN orders o ON o.id = s.order_id
             LEFT JOIN couriers c ON c.id = s.courier_id
             LEFT JOIN delivery_zones z ON z.id = s.delivery_zone_id
             LEFT JOIN users u ON u.id = s.created_by
             WHERE s.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['allowed_transitions'] = $this->allowedTransitions((string) $row['status']);
        return $row;
    }

    /** @return list<array<string,mixed>> shipments for an order */
    public function forOrder(int $orderId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT s.*, c.name AS courier_name
             FROM shipments s
             LEFT JOIN couriers c ON c.id = s.courier_id
             WHERE s.order_id = ?
             ORDER BY s.id DESC'
        );
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------- HELPERS --

    private function transition(int $shipmentId, string $to, ?callable $extraUpdate = null): array
    {
        return Connection::transaction(function (PDO $pdo) use ($shipmentId, $to, $extraUpdate) {
            $stmt = $pdo->prepare('SELECT * FROM shipments WHERE id = ? FOR UPDATE');
            $stmt->execute([$shipmentId]);
            $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($shipment === false) {
                throw new ValidationException(['shipment_id' => 'Shipment does not exist.']);
            }
            $from = (string) $shipment['status'];
            $this->assertTransitionAllowed($from, $to);

            $stmt = $pdo->prepare('UPDATE shipments SET status = ? WHERE id = ?');
            $stmt->execute([$to, $shipmentId]);

            if ($extraUpdate !== null) {
                $shipment['id'] = (int) $shipment['id'];
                $extraUpdate($pdo, $shipment);
            }

            app_audit('delivery.status_updated', 'shipment', $shipmentId, [
                'from' => $from,
                'to' => $to,
            ]);

            return ['id' => $shipmentId, 'status' => $to];
        });
    }

    private function generateShipmentNumber(PDO $pdo): string
    {
        $prefix = 'SHP';
        for ($i = 0; $i < 10; $i++) {
            $candidate = sprintf(
                '%s-%s-%s',
                $prefix,
                gmdate('ymd'),
                strtoupper(bin2hex(random_bytes(3)))
            );
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM shipments WHERE shipment_number = ?');
            $stmt->execute([$candidate]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $candidate;
            }
        }
        throw new \RuntimeException('Could not generate a unique shipment number.');
    }
}
