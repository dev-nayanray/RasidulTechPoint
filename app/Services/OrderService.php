<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Order creation and payment recording.
 *
 * - Prices/totals are ALWAYS computed server-side from inventory_units.
 * - Order items snapshot product name, SKU and price at sale time.
 * - Every involved unit is locked (FOR UPDATE) so the same physical unit
 *   can never be sold twice, online or through POS.
 * - Order state and payment state are separate flows by design.
 */
final class OrderService
{
    public const ORDER_STATUSES = ['pending', 'confirmed', 'processing', 'completed', 'cancelled', 'refunded'];
    public const PAYMENT_STATUSES = ['unpaid', 'partial', 'paid', 'refunded'];
    public const PAYMENT_METHODS = ['cash', 'card', 'bank_transfer', 'bkash', 'nagad', 'other'];

    /**
     * Create an order for specific inventory units.
     *
     * @param int[] $unitIds
     * @param array{discount?:float,notes?:string} $options
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
                "SELECT iu.id, iu.sku, iu.selling_price, iu.status, pm.name AS product_name
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
            }

            $discount = (float) ($options['discount'] ?? 0);
            if ($discount < 0 || $discount > $subtotal) {
                throw new ValidationException(['discount' => 'Discount must be between 0 and the order subtotal.']);
            }
            $total = round($subtotal - $discount, 2);

            $reference = $this->generateReference($pdo);
            $isCompleted = $initialStatus === 'completed';

            $stmt = $pdo->prepare(
                'INSERT INTO orders
                    (reference, customer_id, channel, status, payment_status, subtotal, discount, total, notes, placed_by, completed_at)
                 VALUES (?, ?, ?, ?, "unpaid", ?, ?, ?, ?, ?, ' . ($isCompleted ? 'UTC_TIMESTAMP()' : 'NULL') . ')'
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

            app_audit('order.created', 'order', $orderId, [
                'reference' => $reference,
                'channel' => $channel,
                'total' => number_format($total, 2, '.', ''),
                'units' => count($lines),
            ]);

            return ['id' => $orderId, 'reference' => $reference, 'total' => number_format($total, 2, '.', '')];
        });
    }

    /**
     * Record a payment against an order. Updates payment_status based on
     * the sum of completed payments vs. total. Duplicate external
     * references are rejected (idempotency guard for gateway callbacks).
     */
    public function recordPayment(
        int $orderId,
        float $amount,
        string $method,
        int $receivedByUserId,
        ?string $reference = null,
    ): array {
        if (!in_array($method, self::PAYMENT_METHODS, true)) {
            throw new ValidationException(['method' => 'Invalid payment method.']);
        }
        if ($amount <= 0) {
            throw new ValidationException(['amount' => 'Payment amount must be positive.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($orderId, $amount, $method, $receivedByUserId, $reference) {
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
                'INSERT INTO payments (order_id, amount, method, status, reference, received_by, paid_at)
                 VALUES (?, ?, ?, "completed", ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute([
                $orderId,
                number_format($amount, 2, '.', ''),
                $method,
                $reference,
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
                'payment_status' => $paymentStatus,
            ]);

            return ['id' => $paymentId, 'payment_status' => $paymentStatus, 'paid' => $paid];
        });
    }

    /**
     * Update order status along its own lifecycle (never payment state).
     */
    public function updateStatus(int $orderId, string $status, int $actorUserId): void
    {
        if (!in_array($status, self::ORDER_STATUSES, true)) {
            throw new ValidationException(['status' => 'Invalid order status.']);
        }

        Connection::transaction(function (PDO $pdo) use ($orderId, $status, $actorUserId) {
            $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$orderId]);
            $current = $stmt->fetchColumn();
            if ($current === false) {
                throw new ValidationException(['order_id' => 'Order does not exist.']);
            }

            $timestamp = match ($status) {
                'completed' => 'completed_at = UTC_TIMESTAMP(), ',
                'cancelled' => 'cancelled_at = UTC_TIMESTAMP(), ',
                default => '',
            };

            $stmt = $pdo->prepare("UPDATE orders SET {$timestamp}status = ? WHERE id = ?");
            $stmt->execute([$status, $orderId]);

            // Cancelling releases sold units back into stock.
            if ($status === 'cancelled' && $current !== 'cancelled') {
                $units = $pdo->prepare(
                    'SELECT inventory_unit_id FROM order_items WHERE order_id = ?'
                );
                $units->execute([$orderId]);
                $inventory = new InventoryService();
                foreach ($units->fetchAll(PDO::FETCH_COLUMN) as $unitId) {
                    $inventory->markReturned((int) $unitId, 'Order cancelled', $actorUserId);
                }
            }

            app_audit('order.status_updated', 'order', $orderId, [
                'from' => $current,
                'to' => $status,
            ]);
        });
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
