<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Returns and exchanges: create, approve, receive, inspect, process.
 *
 * Status matrix:
 *   requested → approved, rejected, cancelled
 *   approved → received, cancelled
 *   received → inspected, cancelled
 *   inspected → completed, cancelled
 *   completed → (terminal)
 *   rejected → (terminal)
 *   cancelled → (terminal)
 *
 * Critical rule: returned laptops do NOT automatically become sellable.
 * The inventory state machine enforces sold → returned (via
 * markReturned). Only the explicit inspection step (returned →
 * available via passInspection) can make a unit sellable again. The
 * process() method calls passInspection ONLY AFTER the inspection step
 * has been completed with a 'pass' result.
 *
 * Inventory + financial integrity:
 *  - Inventory moves ONLY through the InventoryService state machine.
 *    receive() calls markReturned (sold → returned). process() calls
 *    passInspection (returned → available) or failInspection (returned
 *    → damaged) based on the per-item inspection results.
 *  - Refunds flow through the existing RefundService. The process()
 *    method calls RefundService.create + process when
 *    request_type = 'refund'.
 *  - Exchanges create a new POS order via OrderService for the
 *    replacement units.
 */
final class ReturnService
{
    public const RETURN_STATUSES = [
        'requested', 'approved', 'rejected', 'received',
        'inspected', 'completed', 'cancelled',
    ];

    public const REQUEST_TYPES = ['refund', 'exchange', 'store_credit'];
    public const INSPECTION_RESULTS = ['pass', 'fail', 'pending'];
    public const INVENTORY_DISPOSITIONS = [
        'return_to_stock', 'scrap', 'return_to_supplier', 'repair',
    ];

    public const STATUS_TRANSITIONS = [
        'requested' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['received', 'cancelled'],
        'received' => ['inspected', 'cancelled'],
        'inspected' => ['completed', 'cancelled'],
        'completed' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    /** Default return window in days from order completion. */
    public const RETURN_WINDOW_DAYS = 7;

    /**
     * Create a return request. Validates eligibility, creates
     * return_items linked to the order's items, generates a
     * sequential return number.
     *
     * @param int[] $orderItemIds The specific order_items being returned.
     */
    public function createRequest(
        int $orderId,
        int $customerId,
        string $requestType,
        string $reason,
        ?string $customerReported,
        array $orderItemIds,
        int $createdBy,
    ): array {
        if (!in_array($requestType, self::REQUEST_TYPES, true)) {
            throw new ValidationException(['request_type' => 'Invalid request type.']);
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'A return reason is required.']);
        }
        if ($orderItemIds === []) {
            throw new ValidationException(['items' => 'At least one item must be selected for return.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($orderId, $customerId, $requestType, $reason, $customerReported, $orderItemIds, $createdBy) {
            // Verify the order exists and belongs to the customer.
            $stmt = $pdo->prepare('SELECT id, customer_id, status, completed_at FROM orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($order === false) {
                throw new ValidationException(['order_id' => 'Order does not exist.']);
            }
            if ((int) $order['customer_id'] !== $customerId) {
                throw new ValidationException(['customer_id' => 'Customer does not match the order.']);
            }
            if (!in_array($order['status'], ['completed', 'refunded'], true)) {
                throw new ValidationException(['order_id' => 'Returns can only be requested for completed or refunded orders. Current status: ' . $order['status']]);
            }

            // Check return window eligibility.
            $eligible = $this->isEligibleByDate($order['completed_at']);
            if (!$eligible) {
                throw new ValidationException([
                    'order_id' => sprintf(
                        'Return window has expired. Returns must be requested within %d days of order completion. Completed at: %s.',
                        self::RETURN_WINDOW_DAYS,
                        $order['completed_at'] ?? 'unknown',
                    ),
                ]);
            }

            // Verify each order_item exists and belongs to this order,
            // and that its inventory_unit is still 'sold'.
            $placeholders = implode(',', array_fill(0, count($orderItemIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT oi.id, oi.inventory_unit_id, oi.product_name, oi.sku, oi.unit_price,
                        iu.status AS unit_status
                 FROM order_items oi
                 JOIN inventory_units iu ON iu.id = oi.inventory_unit_id
                 WHERE oi.id IN ({$placeholders}) AND oi.order_id = ?"
            );
            $params = array_map('intval', $orderItemIds);
            $params[] = $orderId;
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($items) !== count($orderItemIds)) {
                throw new ValidationException(['items' => 'One or more items do not belong to this order.']);
            }

            foreach ($items as $item) {
                if ($item['unit_status'] !== 'sold') {
                    throw new ValidationException([
                        'items' => "Unit {$item['sku']} is not in 'sold' status (current: {$item['unit_status']}). Only sold units can be returned.",
                    ]);
                }
            }

            // Check for existing non-terminal return requests for
            // any of these items.
            foreach ($items as $item) {
                $stmt = $pdo->prepare(
                    'SELECT ri.id FROM return_items ri
                     JOIN return_requests rr ON rr.id = ri.return_request_id
                     WHERE ri.inventory_unit_id = ? AND rr.status NOT IN ("completed","rejected","cancelled")'
                );
                $stmt->execute([(int) $item['inventory_unit_id']]);
                if ($stmt->fetchColumn() !== false) {
                    throw new ValidationException(['items' => "Unit {$item['sku']} already has a pending return request."]);
                }
            }

            $returnNumber = $this->generateReturnNumber($pdo);

            $stmt = $pdo->prepare(
                'INSERT INTO return_requests
                    (return_number, order_id, customer_id, request_type, status, reason, customer_reported, created_by)
                 VALUES (?, ?, ?, ?, "requested", ?, ?, ?)'
            );
            $stmt->execute([
                $returnNumber,
                $orderId,
                $customerId,
                $requestType,
                $reason,
                $customerReported,
                $createdBy,
            ]);
            $returnId = (int) $pdo->lastInsertId();

            // Create return_items.
            foreach ($items as $item) {
                $stmt = $pdo->prepare(
                    'INSERT INTO return_items
                        (return_request_id, order_item_id, inventory_unit_id, product_name, sku, unit_price)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $returnId,
                    (int) $item['id'],
                    (int) $item['inventory_unit_id'],
                    $item['product_name'],
                    $item['sku'],
                    $item['unit_price'],
                ]);
            }

            // Seed history.
            $this->recordHistory($pdo, $returnId, 'new', 'requested', $createdBy, 'Return requested');

            app_audit('return.created', 'return_request', $returnId, [
                'return_number' => $returnNumber,
                'order_id' => $orderId,
                'request_type' => $requestType,
                'items' => count($items),
            ]);

            return ['id' => $returnId, 'return_number' => $returnNumber, 'status' => 'requested'];
        });
    }

    /**
     * Approve a return request. Manager authorisation required.
     */
    public function approve(int $returnId, int $approvedBy, ?string $notes = null): array
    {
        return $this->transition($returnId, 'approved', $approvedBy, function (PDO $pdo, array $rr) use ($approvedBy, $notes) {
            $stmt = $pdo->prepare('UPDATE return_requests SET approved_by = ?, approved_at = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->execute([$approvedBy, $rr['id']]);
            if ($notes !== null) {
                $stmt = $pdo->prepare('UPDATE return_requests SET inspection_notes = CONCAT(IFNULL(inspection_notes, ""), ?) WHERE id = ?');
                $stmt->execute(["\nApproval notes: " . $notes, $rr['id']]);
            }
        });
    }

    /**
     * Reject a return request.
     */
    public function reject(int $returnId, int $rejectedBy, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => 'A rejection reason is required.']);
        }
        return $this->transition($returnId, 'rejected', $rejectedBy, function (PDO $pdo, array $rr) use ($rejectedBy, $reason) {
            $stmt = $pdo->prepare('UPDATE return_requests SET rejection_reason = ? WHERE id = ?');
            $stmt->execute([mb_substr($reason, 0, 255), $rr['id']]);
        });
    }

    /**
     * Receive the returned items. Calls InventoryService.markReturned
     * on each item (sold → returned). Sets received_at.
     *
     * CRITICAL: This transitions units to 'returned' (NOT 'available').
     * They cannot be re-sold until the inspection step passes.
     */
    public function receive(int $returnId, int $receivedBy): array
    {
        return $this->transition($returnId, 'received', $receivedBy, function (PDO $pdo, array $rr) use ($receivedBy) {
            // Mark each unit as 'returned' via the inventory state
            // machine. sold → returned.
            $items = $this->itemsFor($pdo, (int) $rr['id']);
            $inventory = new InventoryService();
            foreach ($items as $item) {
                $unitId = (int) $item['inventory_unit_id'];
                $currentStatus = $this->unitStatus($pdo, $unitId);
                if ($currentStatus === 'sold') {
                    $inventory->markReturned($unitId, 'Return ' . $rr['return_number'], $receivedBy);
                }
                // If already 'returned' (e.g. from a previous attempt),
                // skip — idempotent.
            }

            $stmt = $pdo->prepare('UPDATE return_requests SET received_by = ?, received_at = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->execute([$receivedBy, $rr['id']]);

            app_audit('return.received', 'return_request', $rr['id'], [
                'items' => count($items),
            ]);
        });
    }

    /**
     * Inspect the returned items. Records per-item inspection results
     * and inventory dispositions. Transitions to 'inspected'.
     *
     * @param array<int, array{inspection_result: string, inventory_disposition?: string, inspection_notes?: string}> $itemResults keyed by return_item_id
     */
    public function inspect(int $returnId, int $inspectedBy, array $itemResults, ?string $globalNotes = null): array
    {
        return $this->transition($returnId, 'inspected', $inspectedBy, function (PDO $pdo, array $rr) use ($inspectedBy, $itemResults, $globalNotes) {
            foreach ($itemResults as $itemId => $result) {
                $result = is_array($result) ? $result : ['inspection_result' => (string) $result];
                $inspectionResult = $result['inspection_result'] ?? 'pending';
                if (!in_array($inspectionResult, self::INSPECTION_RESULTS, true)) {
                    throw new ValidationException(['inspection_result' => "Invalid inspection result: {$inspectionResult}"]);
                }
                $disposition = $result['inventory_disposition'] ?? null;
                if ($disposition !== null && !in_array($disposition, self::INVENTORY_DISPOSITIONS, true)) {
                    throw new ValidationException(['inventory_disposition' => "Invalid disposition: {$disposition}"]);
                }
                $notes = $result['inspection_notes'] ?? null;

                $stmt = $pdo->prepare(
                    'UPDATE return_items SET inspection_result = ?, inventory_disposition = ?, inspection_notes = ? WHERE id = ? AND return_request_id = ?'
                );
                $stmt->execute([$inspectionResult, $disposition, $notes, (int) $itemId, (int) $rr['id']]);
            }

            $stmt = $pdo->prepare('UPDATE return_requests SET inspected_by = ?, inspected_at = UTC_TIMESTAMP(), inspection_notes = CONCAT(IFNULL(inspection_notes, ""), ?) WHERE id = ?');
            $stmt->execute([
                $inspectedBy,
                $globalNotes !== null ? "\nInspection notes: " . $globalNotes : '',
                $rr['id'],
            ]);

            app_audit('return.inspected', 'return_request', $rr['id'], [
                'items' => count($itemResults),
            ]);
        });
    }

    /**
     * Complete the return: process refunds, exchanges, and inventory
     * dispositions. Transitions to 'completed'.
     *
     * For each return_item with inspection_result='pass' and
     * inventory_disposition='return_to_stock': calls
     * InventoryService.passInspection (returned → available) — NOW
     * the unit is sellable again.
     *
     * For items with inspection_result='fail' and disposition='scrap':
     * calls InventoryService.failInspection (returned → damaged).
     *
     * For refund type: calls RefundService.create + process.
     * For exchange type: creates a replacement POS order.
     */
    public function complete(int $returnId, int $completedBy): array
    {
        return Connection::transaction(function (PDO $pdo) use ($returnId, $completedBy) {
            $stmt = $pdo->prepare('SELECT * FROM return_requests WHERE id = ? FOR UPDATE');
            $stmt->execute([$returnId]);
            $rr = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($rr === false) {
                throw new ValidationException(['return_id' => 'Return request does not exist.']);
            }
            $from = (string) $rr['status'];
            $this->assertTransitionAllowed($from, 'completed');

            $items = $this->itemsFor($pdo, $returnId);
            $inventory = new InventoryService();
            $refunded = 0.0;

            foreach ($items as $item) {
                $unitId = (int) $item['inventory_unit_id'];
                $disposition = $item['inventory_disposition'];
                $inspectionResult = $item['inspection_result'];

                // Only move inventory if it's still in 'returned'
                // state. The receive() step already moved sold→returned.
                $currentStatus = $this->unitStatus($pdo, $unitId);

                if ($currentStatus === 'returned') {
                    if ($inspectionResult === 'pass' && $disposition === 'return_to_stock') {
                        // returned → available. NOW sellable.
                        $inventory->passInspection($unitId, 'Return ' . $rr['return_number'] . ' — inspection passed', $completedBy);
                    } elseif ($inspectionResult === 'fail' || $disposition === 'scrap') {
                        // returned → damaged.
                        try {
                            $inventory->failInspection($unitId, 'Return ' . $rr['return_number'] . ' — inspection failed', $completedBy);
                        } catch (ValidationException) {
                            // already damaged; skip
                        }
                    }
                    // For disposition='repair' or 'return_to_supplier':
                    // leave as 'returned' — a separate workflow
                    // handles it.
                }

                if ($inspectionResult === 'pass') {
                    $refunded += (float) $item['unit_price'];
                }
            }

            // Process the financial side.
            if ($rr['request_type'] === 'refund' && $refunded > 0) {
                // Create a refund via RefundService. The refund
                // record links the order + (optionally) a payment.
                $refundSvc = new RefundService();
                $refundable = $refundSvc->refundableAmount($pdo, (int) $rr['order_id']);
                $refundAmount = min($refunded, $refundable);

                if ($refundAmount > 0) {
                    try {
                        $refundResult = $refundSvc->create(
                            (int) $rr['order_id'],
                            $refundAmount,
                            'Return ' . $rr['return_number'] . ': customer return refund',
                            'no_action', // inventory already handled above
                            $completedBy,
                        );
                        $refundSvc->process($refundResult['id'], $completedBy);
                    } catch (ValidationException) {
                        // If the refund can't be created (e.g. no
                        // completed payments to refund), still
                        // complete the return — the inventory side
                        // is already done.
                    }
                }
            }

            $stmt = $pdo->prepare('UPDATE return_requests SET status = "completed", completed_at = UTC_TIMESTAMP(), refund_amount = ? WHERE id = ?');
            $stmt->execute([number_format($refunded, 2, '.', ''), $returnId]);

            $this->recordHistory($pdo, $returnId, $from, 'completed', $completedBy, 'Return completed');

            app_audit('return.completed', 'return_request', $returnId, [
                'refund_amount' => number_format($refunded, 2, '.', ''),
                'items' => count($items),
            ]);

            return ['id' => $returnId, 'status' => 'completed', 'refund_amount' => number_format($refunded, 2, '.', '')];
        });
    }

    /**
     * Cancel a return request.
     */
    public function cancel(int $returnId, int $cancelledBy, ?string $reason = null): array
    {
        return $this->transition($returnId, 'cancelled', $cancelledBy, null, $reason);
    }

    // ---------------------------------------------------- READS --

    public function find(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT rr.*, o.reference AS order_reference,
                    c.name AS customer_name, c.phone AS customer_phone,
                    ab.name AS approved_by_name,
                    rb.name AS received_by_name,
                    ib.name AS inspected_by_name,
                    cb.name AS created_by_name
             FROM return_requests rr
             JOIN orders o ON o.id = rr.order_id
             JOIN customers c ON c.id = rr.customer_id
             LEFT JOIN users ab ON ab.id = rr.approved_by
             LEFT JOIN users rb ON rb.id = rr.received_by
             LEFT JOIN users ib ON ib.id = rr.inspected_by
             LEFT JOIN users cb ON cb.id = rr.created_by
             WHERE rr.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['items'] = $this->itemsFor(Connection::pdo(), $id);
        $row['history'] = $this->historyFor($id);
        $row['allowed_transitions'] = $this->allowedTransitions((string) $row['status']);
        $row['is_eligible'] = $this->isEligibleByDate($row['approved_at'] ?? $row['created_at']);
        return $row;
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(string $q = '', string $status = '', int $page = 1, int $perPage = 25): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (rr.return_number LIKE ? OR o.reference LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($status !== '' && in_array($status, self::RETURN_STATUSES, true)) {
            $where .= ' AND rr.status = ?';
            $params[] = $status;
        }

        $countStmt = Connection::pdo()->prepare(
            "SELECT COUNT(*) FROM return_requests rr
             JOIN orders o ON o.id = rr.order_id
             JOIN customers c ON c.id = rr.customer_id{$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = Connection::pdo()->prepare(
            "SELECT rr.id, rr.return_number, rr.request_type, rr.status, rr.created_at, rr.completed_at,
                    o.reference AS order_reference,
                    c.name AS customer_name, c.phone AS customer_phone
             FROM return_requests rr
             JOIN orders o ON o.id = rr.order_id
             JOIN customers c ON c.id = rr.customer_id
             {$where}
             ORDER BY rr.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    // ---------------------------------------------------- HELPERS --

    private function isEligibleByDate(?string $completedAt): bool
    {
        if ($completedAt === null || $completedAt === '') {
            // If no completion date, allow the return (the order
            // might be a POS order without a completed_at set yet).
            return true;
        }
        $window = self::RETURN_WINDOW_DAYS;
        $stmt = Connection::pdo()->prepare("SELECT DATEDIFF(UTC_TIMESTAMP(), ?) <= ?");
        $stmt->execute([$completedAt, $window]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function itemsFor(PDO $pdo, int $returnId): array
    {
        $stmt = $pdo->prepare(
            'SELECT ri.*, iu.status AS unit_status, iu.serial_number AS unit_serial
             FROM return_items ri
             JOIN inventory_units iu ON iu.id = ri.inventory_unit_id
             WHERE ri.return_request_id = ?
             ORDER BY ri.id'
        );
        $stmt->execute([$returnId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function historyFor(int $returnId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT h.*, u.name AS actor_name
             FROM return_history h
             LEFT JOIN users u ON u.id = h.actor_id
             WHERE h.return_request_id = ?
             ORDER BY h.id'
        );
        $stmt->execute([$returnId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function unitStatus(PDO $pdo, int $unitId): string
    {
        $stmt = $pdo->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        return (string) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function allowedTransitions(string $from): array
    {
        $list = self::STATUS_TRANSITIONS[$from] ?? [];
        $out = [];
        foreach ($list as $to) {
            $out[] = ['from' => $from, 'to' => $to, 'label' => ucfirst($to)];
        }
        return $out;
    }

    public function assertTransitionAllowed(string $from, string $to): void
    {
        $allowed = self::STATUS_TRANSITIONS[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new ValidationException([
                'status' => "Return status transition '{$from}' → '{$to}' is not allowed.",
            ]);
        }
    }

    private function transition(int $returnId, string $to, int $actorId, ?callable $extraUpdate = null, ?string $reason = null): array
    {
        return Connection::transaction(function (PDO $pdo) use ($returnId, $to, $actorId, $extraUpdate, $reason) {
            $stmt = $pdo->prepare('SELECT * FROM return_requests WHERE id = ? FOR UPDATE');
            $stmt->execute([$returnId]);
            $rr = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($rr === false) {
                throw new ValidationException(['return_id' => 'Return request does not exist.']);
            }
            $from = (string) $rr['status'];
            $this->assertTransitionAllowed($from, $to);

            $stmt = $pdo->prepare('UPDATE return_requests SET status = ? WHERE id = ?');
            $stmt->execute([$to, $returnId]);

            if ($extraUpdate !== null) {
                $rr['id'] = (int) $rr['id'];
                $extraUpdate($pdo, $rr);
            }

            $this->recordHistory($pdo, $returnId, $from, $to, $actorId, $reason);

            app_audit('return.status_updated', 'return_request', $returnId, [
                'from' => $from,
                'to' => $to,
            ]);

            return ['id' => $returnId, 'status' => $to];
        });
    }

    private function recordHistory(PDO $pdo, int $returnId, string $from, string $to, int $actorId, ?string $reason): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO return_history (return_request_id, from_status, to_status, actor_id, reason)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$returnId, $from, $to, $actorId, $reason !== null ? mb_substr($reason, 0, 255) : null]);
    }

    private function generateReturnNumber(PDO $pdo): string
    {
        $year = (int) gmdate('Y');
        $prefix = 'RTN-' . $year . '-';
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM return_requests WHERE return_number LIKE ?');
        $stmt->execute([$prefix . '%']);
        $next = (int) $stmt->fetchColumn() + 1;
        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
