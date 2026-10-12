<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Order creation, status transitions and payment recording.
 *
 * - Prices/totals are ALWAYS computed server-side from inventory_units.
 * - Order items snapshot product name, SKU and price at sale time.
 * - Every involved unit is locked (FOR UPDATE) so the same physical unit
 *   can never be sold twice, online or through POS.
 * - Order state and payment state are SEPARATE flows by design.
 * - Status transitions are enforced by STATUS_TRANSITIONS — no jumps
 *   like pending → completed are allowed; cancelled and refunded are
 *   terminal.
 * - Non-cash payments require verified evidence (gateway transaction
 *   id / bank slip number) before they count toward order.payment_status.
 *   Cash is verified at the till by nature.
 */
final class OrderService
{
    public const ORDER_STATUSES = ['pending', 'confirmed', 'processing', 'completed', 'cancelled', 'refunded'];
    public const PAYMENT_STATUSES = ['unpaid', 'partial', 'paid', 'refunded'];
    public const PAYMENT_METHODS = ['cash', 'card', 'bank_transfer', 'bkash', 'nagad', 'other'];

    /** Cash-equivalent methods that are verified at the till. */
    public const VERIFIED_AT_TILL_METHODS = ['cash'];

    /**
     * Allowed status transitions: from_status => list of to_status values.
     * Anything not listed here is rejected by assertTransitionAllowed().
     * cancelled and refunded are terminal — once you cancel or refund,
     * the order is immutable.
     */
    public const STATUS_TRANSITIONS = [
        'pending' => ['confirmed', 'processing', 'cancelled'],
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['completed', 'cancelled'],
        'completed' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
    ];

    /**
     * Create an order for specific inventory units.
     *
     * @param int[] $unitIds
     * @param array{
     *   discount?:float, notes?:string, discount_authorized_by?:int,
     *   coupon?:?array{coupon_id:int,discount:float}
     * } $options
     *   - coupon: optional pre-validated coupon + its server-computed discount.
     *     When present, the coupon discount OVERRIDES any manual $options['discount']
     *     and the redemption is recorded atomically inside this transaction.
     * @return array{id:int,reference:string,total:string}
     */
    public function createOrder(
        int $customerId,
        array $unitIds,
        string $channel,
        int $staffUserId,
        array $options = [],
        string $initialStatus = 'pending',
    ): array {
        if (!in_array($channel, ['online', 'pos'], true)) {
            throw new ValidationException(['channel' => 'Invalid sales channel.']);
        }
        if (!in_array($initialStatus, self::ORDER_STATUSES, true)) {
            throw new ValidationException(['status' => 'Invalid order status.']);
        }
        if ($initialStatus === 'completed' && $channel !== 'pos') {
            $initialStatus = 'processing'; // online orders complete through fulfilment
        }

        $unitIds = array_values(array_unique(array_map('intval', $unitIds)));
        if ($unitIds === []) {
            throw new ValidationException(['units' => 'An order needs at least one unit.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($customerId, $unitIds, $channel, $staffUserId, $options, $initialStatus) {
            // Verify customer under the same lock scope.
            $stmt = $pdo->prepare('SELECT id FROM customers WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute([$customerId]);
            if ($stmt->fetchColumn() === false) {
                throw new ValidationException(['customer_id' => 'Customer does not exist.']);
            }

            // Lock every unit in deterministic order (avoid deadlocks).
            sort($unitIds);
            $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT iu.id, iu.sku, iu.selling_price, iu.status, pm.name AS product_name,
                        iu.product_model_id, pm.category_id
                 FROM inventory_units iu
                 JOIN product_models pm ON pm.id = iu.product_model_id
                 WHERE iu.id IN ({$placeholders})
                 FOR UPDATE"
            );
            $stmt->execute($unitIds);
            $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($units) !== count($unitIds)) {
                throw new ValidationException(['units' => 'One or more units do not exist.']);
            }

            $subtotal = 0.0;
            $lines = [];
            $productModelIds = [];
            $categoryIds = [];
            foreach ($units as $unit) {
                if (!in_array($unit['status'], ['available', 'reserved'], true)) {
                    throw new ValidationException([
                        'units' => "Unit {$unit['sku']} is not sellable (status: {$unit['status']}).",
                    ]);
                }
                $price = (float) $unit['selling_price'];
                $subtotal += $price;
                $lines[] = [
                    'unit_id' => (int) $unit['id'],
                    'product_name' => $unit['product_name'],
                    'sku' => $unit['sku'],
                    'unit_price' => $price,
                ];
                $productModelIds[] = (int) $unit['product_model_id'];
                $categoryIds[] = (int) $unit['category_id'];
            }

            // Coupon: when supplied, the server-recomputed discount overrides
            // any manual discount. The actual redemption is recorded inside
            // this same transaction (CouponService::recordRedemption locks
            // the coupon row + re-validates eligibility + recomputes the
            // value, so the client's claimed discount is never trusted).
            $coupon = $options['coupon'] ?? null;
            if ($coupon !== null && is_array($coupon) && (int) ($coupon['coupon_id'] ?? 0) > 0) {
                $discount = (float) $coupon['discount'];
            } else {
                $discount = (float) ($options['discount'] ?? 0);
            }
            if ($discount < 0 || $discount > $subtotal) {
                throw new ValidationException(['discount' => 'Discount must be between 0 and the order subtotal.']);
            }
            $total = round($subtotal - $discount, 2);

            $reference = $this->generateReference($pdo);
            $isCompleted = $initialStatus === 'completed';
            $isConfirmed = in_array($initialStatus, ['confirmed', 'processing', 'completed'], true);
            $discountAuthorizer = isset($options['discount_authorized_by'])
                && $discount > 0
                ? (int) $options['discount_authorized_by']
                : null;

            $stmt = $pdo->prepare(
                'INSERT INTO orders
                    (reference, customer_id, channel, status, payment_status, subtotal, discount, total, notes, placed_by, discount_authorized_by, confirmed_at, completed_at)
                 VALUES (?, ?, ?, ?, "unpaid", ?, ?, ?, ?, ?, ?, '
                 . ($isConfirmed ? 'UTC_TIMESTAMP()' : 'NULL') . ', '
                 . ($isCompleted ? 'UTC_TIMESTAMP()' : 'NULL') . ')'
            );
            $stmt->execute([
                $reference,
                $customerId,
                $channel,
                $initialStatus,
                number_format($subtotal, 2, '.', ''),
                number_format($discount, 2, '.', ''),
                number_format($total, 2, '.', ''),
                $options['notes'] ?? null,
                $staffUserId,
                $discountAuthorizer,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO order_items (order_id, inventory_unit_id, product_name, sku, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($lines as $line) {
                $stmt->execute([
                    $orderId,
                    $line['unit_id'],
                    $line['product_name'],
                    $line['sku'],
                    number_format($line['unit_price'], 2, '.', ''),
                    number_format($line['unit_price'], 2, '.', ''),
                ]);
            }

            // Allocate the units through the inventory state machine.
            $inventory = new InventoryService();
            foreach ($lines as $line) {
                $inventory->markSold($line['unit_id'], 'Sold on order ' . $reference, $staffUserId);
            }

            // Record coupon redemption inside this same transaction.
            // CouponService locks the coupon row, re-validates eligibility,
            // recomputes the discount from the definition (client value
            // is advisory only), inserts the coupon_usages row, and links
            // the order to the coupon. Any failure rolls back the entire
            // order — coupons are never partially applied.
            if ($coupon !== null && is_array($coupon) && (int) ($coupon['coupon_id'] ?? 0) > 0) {
                (new CouponService())->recordRedemption(
                    $pdo,
                    $orderId,
                    $customerId,
                    (int) $coupon['coupon_id'],
                    (float) $coupon['discount'],
                    $staffUserId,
                    $subtotal,
                );
            }

            // Seed the status history with the initial transition so the
            // timeline has a "from nothing → pending/confirmed/..." row.
            $this->recordStatusHistory($pdo, $orderId, null, $initialStatus, $staffUserId, 'Order created');

            app_audit('order.created', 'order', $orderId, [
                'reference' => $reference,
                'channel' => $channel,
                'total' => number_format($total, 2, '.', ''),
                'units' => count($lines),
                'coupon_id' => $coupon['coupon_id'] ?? null,
                'coupon_discount' => isset($coupon['discount']) ? number_format((float) $coupon['discount'], 2, '.', '') : null,
            ]);

            return ['id' => $orderId, 'reference' => $reference, 'total' => number_format($total, 2, '.', '')];
        });
    }

    /**
     * Record a payment against an order. Updates payment_status based on
     * the sum of completed payments vs. total. Duplicate external
     * references are rejected (idempotency guard for gateway callbacks).
     *
     * Payment evidence rules:
     *  - cash: payment is 'completed' immediately (verified at the till)
     *  - card / bank_transfer / bkash / nagad / other: payment is
     *    'completed' only when $reference (the gateway transaction id /
     *    bank slip number) is non-empty; otherwise the payment is recorded
     *    as 'pending' and does NOT count toward order.payment_status until
     *    confirmPayment() is called with verified evidence.
     */
    public function recordPayment(
        int $orderId,
        float $amount,
        string $method,
        int $receivedByUserId,
        ?string $reference = null,
        ?string $evidenceRef = null,
    ): array {
        if (!in_array($method, self::PAYMENT_METHODS, true)) {
            throw new ValidationException(['method' => 'Invalid payment method.']);
        }
        if ($amount <= 0) {
            throw new ValidationException(['amount' => 'Payment amount must be positive.']);
        }

        // The 'reference' column is the human-visible receipt id (e.g.
        // 'BKASH-TXN-777'); the 'evidence_ref' column is the verified
        // gateway slip number that the operator pastes in AFTER manual
        // reconciliation. Both are persisted, but only evidence_ref (or
        // being a cash payment) unlocks the 'completed' state for non-cash.
        $evidence = $evidenceRef ?? ($reference !== null && $reference !== '' ? $reference : null);

        $isVerifiedAtTill = in_array($method, self::VERIFIED_AT_TILL_METHODS, true);
        $isVerifiedNonCash = !$isVerifiedAtTill && $evidence !== null && $evidence !== '';
        $isCompleted = $isVerifiedAtTill || $isVerifiedNonCash;

        return Connection::transaction(function (PDO $pdo) use ($orderId, $amount, $method, $receivedByUserId, $reference, $evidence, $isCompleted) {
            $stmt = $pdo->prepare(
                'SELECT id, reference, total, status, payment_status FROM orders WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($order === false) {
                throw new ValidationException(['order_id' => 'Order does not exist.']);
            }
            if (in_array($order['status'], ['cancelled', 'refunded'], true)) {
                throw new ValidationException(['order_id' => 'Cannot accept payments for a cancelled or refunded order.']);
            }

            if ($reference !== null && $reference !== '') {
                $dup = $pdo->prepare('SELECT id FROM payments WHERE reference = ? AND status = "completed"');
                $dup->execute([$reference]);
                if ($dup->fetchColumn() !== false) {
                    throw new ValidationException(['reference' => 'This payment reference was already recorded.']);
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO payments (order_id, amount, method, status, reference, evidence_ref, received_by, paid_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ' . ($isCompleted ? 'UTC_TIMESTAMP()' : 'NULL') . ')'
            );
            $stmt->execute([
                $orderId,
                number_format($amount, 2, '.', ''),
                $method,
                $isCompleted ? 'completed' : 'pending',
                $reference,
                $evidence,
                $receivedByUserId,
            ]);
            $paymentId = (int) $pdo->lastInsertId();

            $paid = (float) $pdo->query(
                'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ' . (int) $orderId . ' AND status = "completed"'
            )->fetchColumn();

            $paymentStatus = 'unpaid';
            if ($paid >= (float) $order['total'] - 0.001) {
                $paymentStatus = 'paid';
            } elseif ($paid > 0) {
                $paymentStatus = 'partial';
            }

            $stmt = $pdo->prepare('UPDATE orders SET payment_status = ? WHERE id = ?');
            $stmt->execute([$paymentStatus, $orderId]);

            app_audit('payment.recorded', 'order', $orderId, [
                'amount' => number_format($amount, 2, '.', ''),
                'method' => $method,
                'status' => $isCompleted ? 'completed' : 'pending',
                'payment_status' => $paymentStatus,
                'has_evidence' => $evidence !== null,
            ]);

            return ['id' => $paymentId, 'payment_status' => $paymentStatus, 'paid' => $paid, 'payment_state' => $isCompleted ? 'completed' : 'pending'];
        });
    }

    /**
     * Promote a pending payment to 'completed' once verified evidence
     * (a gateway transaction id or bank slip number) has been supplied.
     * Recomputes the order payment_status after the promotion.
     */
    public function confirmPayment(int $paymentId, string $evidenceRef, int $actorUserId): array
    {
        $evidenceRef = trim($evidenceRef);
        if ($evidenceRef === '') {
            throw new ValidationException(['evidence_ref' => 'Evidence reference is required to confirm a payment.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($paymentId, $evidenceRef, $actorUserId) {
            $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($payment === false) {
                throw new ValidationException(['payment_id' => 'Payment does not exist.']);
            }
            if ($payment['status'] === 'completed') {
                throw new ValidationException(['payment_id' => 'Payment is already completed.']);
            }
            if ($payment['status'] === 'refunded') {
                throw new ValidationException(['payment_id' => 'Cannot confirm a refunded payment.']);
            }

            $stmt = $pdo->prepare(
                'UPDATE payments SET status = "completed", evidence_ref = ?, paid_at = COALESCE(paid_at, UTC_TIMESTAMP()) WHERE id = ?'
            );
            $stmt->execute([$evidenceRef, $paymentId]);

            $orderId = (int) $payment['order_id'];
            $paid = (float) $pdo->query(
                'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ' . $orderId . ' AND status = "completed"'
            )->fetchColumn();

            $orderStmt = $pdo->prepare('SELECT total FROM orders WHERE id = ?');
            $orderStmt->execute([$orderId]);
            $total = (float) $orderStmt->fetchColumn();

            $paymentStatus = 'unpaid';
            if ($paid >= $total - 0.001) {
                $paymentStatus = 'paid';
            } elseif ($paid > 0) {
                $paymentStatus = 'partial';
            }

            $stmt = $pdo->prepare('UPDATE orders SET payment_status = ? WHERE id = ?');
            $stmt->execute([$paymentStatus, $orderId]);

            app_audit('payment.confirmed', 'order', $orderId, [
                'payment_id' => $paymentId,
                'evidence_ref' => mb_substr($evidenceRef, 0, 80),
                'payment_status' => $paymentStatus,
            ]);

            return ['payment_id' => $paymentId, 'payment_status' => $paymentStatus, 'paid' => $paid];
        });
    }

    /**
     * Update order status along its own lifecycle (never payment state).
     * The transition must be allowed by STATUS_TRANSITIONS — invalid
     * jumps like pending → completed are rejected. The change is recorded
     * in order_status_history (append-only).
     */
    public function updateStatus(int $orderId, string $status, int $actorUserId, ?string $reason = null): void
    {
        if (!in_array($status, self::ORDER_STATUSES, true)) {
            throw new ValidationException(['status' => 'Invalid order status.']);
        }

        Connection::transaction(function (PDO $pdo) use ($orderId, $status, $actorUserId, $reason) {
            $stmt = $pdo->prepare('SELECT status, payment_status FROM orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$orderId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new ValidationException(['order_id' => 'Order does not exist.']);
            }
            $current = (string) $row['status'];

            if ($current === $status) {
                // No-op: still record a history entry so the audit trail
                // shows the operator attempted the change.
                $this->recordStatusHistory($pdo, $orderId, $current, $status, $actorUserId, $reason ?? 'No change (re-affirmed)');
                return;
            }

            $this->assertTransitionAllowed($current, $status);

            // Refund requires a prior completed state with collected money.
            if ($status === 'refunded') {
                if (!in_array($row['payment_status'], ['paid', 'partial', 'refunded'], true)) {
                    throw new ValidationException([
                        'status' => 'Cannot refund an order that has not collected any payment.',
                    ]);
                }
            }

            $timestamp = match ($status) {
                'confirmed' => 'confirmed_at = UTC_TIMESTAMP(), ',
                'completed' => 'completed_at = UTC_TIMESTAMP(), ',
                'cancelled' => 'cancelled_at = UTC_TIMESTAMP(), ',
                'refunded' => 'refunded_at = UTC_TIMESTAMP(), ',
                default => '',
            };

            $stmt = $pdo->prepare("UPDATE orders SET {$timestamp}status = ? WHERE id = ?");
            $stmt->execute([$status, $orderId]);

            // Cancelling releases sold units back into stock as 'returned'
            // (the same state used for customer-initiated returns). They can
            // then be re-inspected and moved back to available via the
            // inspection state machine.
            if ($status === 'cancelled' && $current !== 'cancelled') {
                $this->releaseUnits($pdo, $orderId, $actorUserId, 'Order cancelled');
            }
            // Refunding returns the units too (customer gets their money
            // back, the stock comes back for re-sale).
            if ($status === 'refunded' && $current !== 'refunded') {
                $this->releaseUnits($pdo, $orderId, $actorUserId, 'Order refunded');
            }

            $this->recordStatusHistory($pdo, $orderId, $current, $status, $actorUserId, $reason);

            app_audit('order.status_updated', 'order', $orderId, [
                'from' => $current,
                'to' => $status,
            ]);
        });
    }

    /**
     * Throw a ValidationException if $from → $to is not whitelisted by
     * STATUS_TRANSITIONS. This is the central enforcement point — every
     * caller (controller, service) goes through here.
     */
    public function assertTransitionAllowed(string $from, string $to): void
    {
        $allowed = self::STATUS_TRANSITIONS[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new ValidationException([
                'status' => "Status transition '{$from}' → '{$to}' is not allowed.",
            ]);
        }
    }

    /** @return list<array<string,mixed>> allowed transitions from $from */
    public function allowedTransitions(string $from): array
    {
        $list = self::STATUS_TRANSITIONS[$from] ?? [];
        $out = [];
        foreach ($list as $to) {
            $out[] = ['from' => $from, 'to' => $to, 'label' => ucfirst($to)];
        }
        return $out;
    }

    /**
     * Append a fulfillment note (operator-facing, not customer-visible).
     * The order_fulfillment_notes table is INSERT-only — there is no
     * update or delete path in this service or any controller.
     */
    public function addFulfillmentNote(int $orderId, int $authorId, string $body): int
    {
        $body = trim($body);
        if ($body === '') {
            throw new ValidationException(['body' => 'Fulfillment note body cannot be empty.']);
        }
        if (mb_strlen($body) > 2000) {
            throw new ValidationException(['body' => 'Fulfillment note may not exceed 2000 characters.']);
        }

        $stmt = Connection::pdo()->prepare(
            'INSERT INTO order_fulfillment_notes (order_id, author_id, body) VALUES (?, ?, ?)'
        );
        $stmt->execute([$orderId, $authorId, $body]);
        return (int) Connection::pdo()->lastInsertId();
    }

    /** Release all units attached to an order back to 'returned'. */
    private function releaseUnits(PDO $pdo, int $orderId, int $actorUserId, string $reason): void
    {
        $units = $pdo->prepare('SELECT inventory_unit_id FROM order_items WHERE order_id = ?');
        $units->execute([$orderId]);
        $inventory = new InventoryService();
        foreach ($units->fetchAll(PDO::FETCH_COLUMN) as $unitId) {
            // markReturned transitions sold → returned. If the unit was
            // already returned (idempotent), the state machine throws;
            // swallow that so cancellations are re-entrant.
            try {
                $inventory->markReturned((int) $unitId, $reason, $actorUserId);
            } catch (ValidationException) {
                // already returned; nothing to do
            }
        }
    }

    private function recordStatusHistory(PDO $pdo, int $orderId, ?string $from, string $to, int $actorUserId, ?string $reason): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO order_status_history (order_id, from_status, to_status, actor_id, reason)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $orderId,
            $from ?? 'new',
            $to,
            $actorUserId,
            $reason !== null ? mb_substr($reason, 0, 255) : null,
        ]);
    }

    /**
     * Non-sequential customer-visible reference: date + random suffix.
     */
    private function generateReference(PDO $pdo): string
    {
        $prefix = 'RTP';
        for ($i = 0; $i < 10; $i++) {
            $candidate = sprintf(
                '%s-%s-%s',
                $prefix,
                gmdate('ymd'),
                strtoupper(bin2hex(random_bytes(3)))
            );
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE reference = ?');
            $stmt->execute([$candidate]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $candidate;
            }
        }
        throw new \RuntimeException('Could not generate a unique order reference.');
    }

    // ------------------------------------------------------------------
    // Seeding entry points (same code path as production logic).
    // ------------------------------------------------------------------

    public static function forSeeding(): self
    {
        return new self();
    }

    /** @internal DemoDataSeeder only */
    public function createOrderWithStatus(
        int $customerId,
        array $unitIds,
        string $channel,
        string $status,
        int $staffUserId,
    ): array {
        return $this->createOrder($customerId, $unitIds, $channel, $staffUserId, [], $status);
    }

    /** @internal DemoDataSeeder only */
    public function recordPaymentForSeededOrder(int $orderId, float $amount, string $method): array
    {
        return $this->recordPayment($orderId, $amount, $method, 1);
    }
}
