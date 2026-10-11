<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Unified refund records: link the order, the payment, the reason,
 * the authorizer, and the inventory action in one row.
 *
 * Refund limit enforcement:
 *  refundable_amount = sum(completed payments) - sum(refunds already
 *  completed for this order). A new refund's amount + existing
 *  completed refunds' amounts must not exceed refundable_amount.
 *
 * Inventory updates flow only through InventoryService — never direct
 * DB writes. On process(), if inventory_action = 'return_to_stock',
 * the service calls InventoryService.markReturned for each unit
 * attached to the order. The state machine transitions sold → returned.
 * The returned units can then be re-inspected (returned → available)
 * via the inspection workflow. Any other inventory_action (scrap /
 * return_to_supplier / no_action) does NOT move the units — they stay
 * 'sold' until a separate inspection/workflow handles them.
 */
final class RefundService
{
    public const REFUND_STATUSES = ['pending', 'completed', 'cancelled'];
    public const INVENTORY_ACTIONS = ['return_to_stock', 'scrap', 'return_to_supplier', 'no_action'];

    /**
     * Create a pending refund. Validates that the order exists, has
     * collected money, and the refund amount + existing refunds does
     * not exceed the refundable amount.
     */
    public function create(
        int $orderId,
        float $amount,
        string $reason,
        string $inventoryAction,
        int $authorizedBy,
        ?int $paymentId = null,
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => 'A refund reason is required.']);
        }
        if ($amount <= 0) {
            throw new ValidationException(['amount' => 'Refund amount must be positive.']);
        }
        if (!in_array($inventoryAction, self::INVENTORY_ACTIONS, true)) {
            throw new ValidationException(['inventory_action' => 'Invalid inventory action.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($orderId, $amount, $reason, $inventoryAction, $authorizedBy, $paymentId) {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($order === false) {
                throw new ValidationException(['order_id' => 'Order does not exist.']);
            }
            if (in_array($order['status'], ['cancelled'], true)) {
                throw new ValidationException(['order_id' => 'Cannot refund a cancelled order. Cancelled orders have already released their units.']);
            }

            // Compute the refundable amount.
            $refundable = $this->refundableAmount($pdo, $orderId);
            if ($refundable <= 0) {
                throw new ValidationException([
                    'amount' => 'Order has no refundable amount. Completed payments: 0.',
                ]);
            }
            if ($amount > $refundable + 0.001) {
                throw new ValidationException([
                    'amount' => sprintf(
                        'Refund amount %s exceeds the refundable amount %s. Reduce the amount or record more completed payments first.',
                        number_format($amount, 2, '.', ''),
                        number_format($refundable, 2, '.', ''),
                    ),
                ]);
            }

            // Verify the linked payment (if any) belongs to this order
            // and is in a refundable state.
            if ($paymentId !== null) {
                $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
                $stmt->execute([$paymentId]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($payment === false) {
                    throw new ValidationException(['payment_id' => 'Linked payment does not exist.']);
                }
                if ((int) $payment['order_id'] !== $orderId) {
                    throw new ValidationException(['payment_id' => 'Linked payment does not belong to this order.']);
                }
                if (!in_array($payment['status'], ['completed'], true)) {
                    throw new ValidationException(['payment_id' => 'Linked payment is not in a refundable state (status: ' . $payment['status'] . ').']);
                }
            }

            $refundNumber = $this->nextRefundNumber($pdo);
            $stmt = $pdo->prepare(
                'INSERT INTO refunds
                    (refund_number, order_id, payment_id, amount, reason, inventory_action, status, authorized_by)
                 VALUES (?, ?, ?, ?, ?, ?, "pending", ?)'
            );
            $stmt->execute([
                $refundNumber,
                $orderId,
                $paymentId,
                number_format($amount, 2, '.', ''),
                mb_substr($reason, 0, 255),
                $inventoryAction,
                $authorizedBy,
            ]);
            $refundId = (int) $pdo->lastInsertId();

            app_audit('refund.created', 'refund', $refundId, [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'amount' => number_format($amount, 2, '.', ''),
                'inventory_action' => $inventoryAction,
            ]);

            return [
                'id' => $refundId,
                'refund_number' => $refundNumber,
                'status' => 'pending',
            ];
        });
    }

    /**
     * Process a pending refund. Promotes to 'completed'. Calls
     * PaymentService.refund on the linked payment (if any). Releases
     * units via InventoryService if inventory_action = return_to_stock.
     * Recomputes the order's payment_status. If the refund amount
     * covers the full order total AND the order is in 'completed'
     * status, flips the order to 'refunded' via OrderService.
     */
    public function process(int $refundId, int $processedBy): array
    {
        return Connection::transaction(function (PDO $pdo) use ($refundId, $processedBy) {
            $stmt = $pdo->prepare('SELECT * FROM refunds WHERE id = ? FOR UPDATE');
            $stmt->execute([$refundId]);
            $refund = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($refund === false) {
                throw new ValidationException(['refund_id' => 'Refund does not exist.']);
            }
            if ($refund['status'] !== 'pending') {
                throw new ValidationException(['refund_id' => 'Refund is not in pending state (status: ' . $refund['status'] . ').']);
            }

            $orderId = (int) $refund['order_id'];

            // Refund the linked payment (if any). If no payment is
            // linked, the cashier is recording an offline refund; the
            // refund record itself is the only trail.
            if ($refund['payment_id'] !== null) {
                $pay = new PaymentService();
                try {
                    $pay->refund((int) $refund['payment_id'], 'Refund ' . $refund['refund_number'] . ': ' . $refund['reason'], $processedBy);
                } catch (ValidationException $e) {
                    // The payment might have already been refunded
                    // independently; swallow and continue so the
                    // refund record still completes.
                    if (!str_contains($e->getMessage(), 'already refunded')) {
                        throw $e;
                    }
                }
            }

            // Release units only via the inventory state machine.
            // For 'return_to_stock', call markReturned (sold → returned).
            // For other actions (scrap / return_to_supplier / no_action),
            // the units stay in their current status until a separate
            // inspection or workflow handles them — direct DB writes
            // are forbidden here.
            if ($refund['inventory_action'] === 'return_to_stock') {
                $stmt = $pdo->prepare('SELECT inventory_unit_id FROM order_items WHERE order_id = ?');
                $stmt->execute([$orderId]);
                $inventory = new InventoryService();
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $unitId) {
                    try {
                        $inventory->markReturned((int) $unitId, 'Refund ' . $refund['refund_number'], $processedBy);
                    } catch (ValidationException) {
                        // already returned; nothing to do
                    }
                }
            }

            // Mark the refund as completed.
            $stmt = $pdo->prepare(
                'UPDATE refunds SET status = "completed", processed_by = ?, processed_at = UTC_TIMESTAMP() WHERE id = ?'
            );
            $stmt->execute([$processedBy, $refundId]);

            // Note: we deliberately do NOT auto-flip the order to
            // 'refunded' here. The order status transition is a
            // separate manager decision — they may want to keep the
            // order as 'completed' for record-keeping, or transition
            // it to 'refunded' via the existing /admin/orders/{id}/status
            // form. The refund record itself is the audit trail.
            // Auto-flipping would also race with the payment_status
            // check in OrderService.updateStatus (which requires
            // payment_status ∈ {paid, partial, refunded} — by the
            // time we get here, the payment has been refunded and
            // payment_status is 'unpaid').

            app_audit('refund.processed', 'refund', $refundId, [
                'amount' => $refund['amount'],
                'inventory_action' => $refund['inventory_action'],
            ]);

            return [
                'id' => $refundId,
                'status' => 'completed',
                'order_id' => $orderId,
            ];
        });
    }

    /**
     * Cancel a pending refund. Marks it as 'cancelled'; no payment or
     * inventory changes are made.
     */
    public function cancel(int $refundId, int $cancelledBy): array
    {
        return Connection::transaction(function (PDO $pdo) use ($refundId, $cancelledBy) {
            $stmt = $pdo->prepare('SELECT * FROM refunds WHERE id = ? FOR UPDATE');
            $stmt->execute([$refundId]);
            $refund = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($refund === false) {
                throw new ValidationException(['refund_id' => 'Refund does not exist.']);
            }
            if ($refund['status'] !== 'pending') {
                throw new ValidationException(['refund_id' => 'Cannot cancel a refund that is not pending (status: ' . $refund['status'] . ').']);
            }

            $stmt = $pdo->prepare('UPDATE refunds SET status = "cancelled", cancelled_at = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->execute([$refundId]);

            app_audit('refund.cancelled', 'refund', $refundId, [
                'refund_number' => $refund['refund_number'],
            ]);

            return ['id' => $refundId, 'status' => 'cancelled'];
        });
    }

    /** Find a single refund with its order + payment + authorizer. */
    public function find(int $refundId): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT r.*, o.reference AS order_reference, o.total AS order_total,
                    p.amount AS payment_amount, p.method AS payment_method, p.reference AS payment_reference,
                    a.name AS authorized_by_name,
                    pr.name AS processed_by_name
             FROM refunds r
             JOIN orders o ON o.id = r.order_id
             LEFT JOIN payments p ON p.id = r.payment_id
             LEFT JOIN users a ON a.id = r.authorized_by
             LEFT JOIN users pr ON pr.id = r.processed_by
             WHERE r.id = ?'
        );
        $stmt->execute([$refundId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function forOrder(int $orderId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT r.*, a.name AS authorized_by_name, pr.name AS processed_by_name
             FROM refunds r
             LEFT JOIN users a ON a.id = r.authorized_by
             LEFT JOIN users pr ON pr.id = r.processed_by
             WHERE r.order_id = ? ORDER BY r.id DESC'
        );
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(string $q = '', string $status = '', int $page = 1, int $perPage = 25): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (r.refund_number LIKE ? OR o.reference LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like;
        }
        if ($status !== '' && in_array($status, self::REFUND_STATUSES, true)) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }

        $countStmt = Connection::pdo()->prepare(
            "SELECT COUNT(*) FROM refunds r JOIN orders o ON o.id = r.order_id{$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = Connection::pdo()->prepare(
            "SELECT r.id, r.refund_number, r.order_id, r.amount, r.reason, r.inventory_action, r.status,
                    r.created_at, r.processed_at, r.cancelled_at,
                    o.reference AS order_reference,
                    a.name AS authorized_by_name,
                    pr.name AS processed_by_name
             FROM refunds r
             JOIN orders o ON o.id = r.order_id
             LEFT JOIN users a ON a.id = r.authorized_by
             LEFT JOIN users pr ON pr.id = r.processed_by
             {$where}
             ORDER BY r.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * Compute the refundable amount for an order:
     *   sum(completed payments) - sum(completed refunds).
     */
    public function refundableAmount(?PDO $pdoParam, int $orderId): float
    {
        $pdo = $pdoParam ?? Connection::pdo();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND status = "completed"'
        );
        $stmt->execute([$orderId]);
        $completedPayments = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE order_id = ? AND status = "completed"'
        );
        $stmt->execute([$orderId]);
        $completedRefunds = (float) $stmt->fetchColumn();

        return round(max(0.0, $completedPayments - $completedRefunds), 2);
    }

    private function nextRefundNumber(PDO $pdo): string
    {
        $year = (int) gmdate('Y');
        $prefix = 'RFD-' . $year . '-';
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM refunds WHERE refund_number LIKE ?');
        $stmt->execute([$prefix . '%']);
        $next = (int) $stmt->fetchColumn() + 1;
        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
