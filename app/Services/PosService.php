<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\CustomerRepository;
use App\Support\Database\Connection;
use PDO;

/**
 * Point-of-sale operations: cashier shifts, held sales, fast unit
 * search, walk-in customer creation, and checkout.
 *
 * Concurrency model:
 *  - Held sales DO NOT reserve inventory at the DB level. They are a
 *    cart snapshot. When the cashier resumes a held sale, the service
 *    re-validates every unit_id is still 'available' and surfaces any
 *    that have since been sold to someone else. The cashier must
 *    remove the unavailable items before checkout.
 *  - Checkout goes through OrderService::createOrder which uses
 *    SELECT ... FOR UPDATE on the inventory_units rows. Two cashiers
 *    attempting to sell the same physical unit at the same time will
 *    serialise: the second transaction sees the unit as 'sold' and
 *    OrderService throws "Unit X is not sellable" before any payment
 *    is recorded. The double-sell is impossible at the DB level.
 *
 * Discount authorization:
 *  - Discounts up to DISCOUNT_AUTHORIZATION_THRESHOLD (default 5% of
 *    the subtotal) can be applied by any cashier with pos.access.
 *  - Discounts above the threshold require the pos.authorize_discount
 *    permission (granted to manager + owner). The authorizer's user
 *    id is recorded on the order so the audit trail shows who signed
 *    off on the discount.
 */
final class PosService
{
    /** Default discount threshold above which a manager must authorize. */
    public const DISCOUNT_AUTHORIZATION_THRESHOLD = 0.05; // 5%

    public const SHIFT_STATUSES = ['open', 'closed', 'discrepancy'];
    public const HELD_SALE_STATUSES = ['held', 'resumed', 'cancelled', 'expired'];

    /** @var array<int|string, string> */
    private array $labels = [
        'cash' => 'Cash',
        'card' => 'Card',
        'bank_transfer' => 'Bank transfer',
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'other' => 'Other',
    ];

    // ---------------------------------------------------- CASHIER SHIFTS --

    /**
     * Start a new cashier shift. Refuses if the user already has an
     * open shift (one per cashier at a time).
     */
    public function startShift(int $userId, float $openingFloat, ?string $notes = null): array
    {
        if ($openingFloat < 0) {
            throw new ValidationException(['opening_float' => 'Opening float cannot be negative.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($userId, $openingFloat, $notes) {
            $stmt = $pdo->prepare(
                "SELECT id FROM cashier_shifts WHERE user_id = ? AND status = 'open' FOR UPDATE"
            );
            $stmt->execute([$userId]);
            if ($stmt->fetchColumn() !== false) {
                throw new ValidationException(['shift' => 'You already have an open shift. Close it before starting a new one.']);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO cashier_shifts (user_id, status, opening_float, notes)
                 VALUES (?, "open", ?, ?)'
            );
            $stmt->execute([
                $userId,
                number_format($openingFloat, 2, '.', ''),
                $notes,
            ]);
            $shiftId = (int) $pdo->lastInsertId();

            app_audit('pos.shift_opened', 'cashier_shift', $shiftId, [
                'opening_float' => number_format($openingFloat, 2, '.', ''),
            ]);

            return $this->getShift($shiftId);
        });
    }

    /**
     * Close a shift. Computes expected_cash = opening_float + sum of
     * cash payments collected during the shift; compares to actual
     * and records the discrepancy. Marks the shift 'closed' (or
     * 'discrepancy' when the difference is non-zero).
     */
    public function closeShift(int $shiftId, int $closerId, float $actualCash, ?string $notes = null): array
    {
        if ($actualCash < 0) {
            throw new ValidationException(['actual_cash' => 'Actual cash counted cannot be negative.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($shiftId, $closerId, $actualCash, $notes) {
            $stmt = $pdo->prepare('SELECT * FROM cashier_shifts WHERE id = ? FOR UPDATE');
            $stmt->execute([$shiftId]);
            $shift = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($shift === false) {
                throw new ValidationException(['shift_id' => 'Shift does not exist.']);
            }
            if ($shift['status'] !== 'open') {
                throw new ValidationException(['shift_id' => 'Shift is already closed.']);
            }

            // Cash collected during this shift: cash payments on POS orders
            // placed_by the cashier between started_at and now.
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(p.amount), 0) AS cash_collected
                 FROM payments p
                 JOIN orders o ON o.id = p.order_id
                 WHERE p.method = "cash"
                   AND p.status = "completed"
                   AND o.channel = "pos"
                   AND o.placed_by = ?
                   AND p.paid_at >= ?'
            );
            $stmt->execute([$shift['user_id'], $shift['started_at']]);
            $cashCollected = (float) $stmt->fetchColumn();

            $opening = (float) $shift['opening_float'];
            $expected = $opening + $cashCollected;
            $discrepancy = round($actualCash - $expected, 2);

            $newStatus = abs($discrepancy) < 0.005 ? 'closed' : 'discrepancy';

            $stmt = $pdo->prepare(
                'UPDATE cashier_shifts
                 SET status = ?, expected_cash = ?, actual_cash = ?, discrepancy = ?, ended_at = UTC_TIMESTAMP(), notes = CONCAT(IFNULL(notes, ""), ?)
                 WHERE id = ?'
            );
            $stmt->execute([
                $newStatus,
                number_format($expected, 2, '.', ''),
                number_format($actualCash, 2, '.', ''),
                number_format($discrepancy, 2, '.', ''),
                ($notes !== null ? "\nClose notes: " . $notes : ''),
                $shiftId,
            ]);

            app_audit('pos.shift_closed', 'cashier_shift', $shiftId, [
                'expected' => number_format($expected, 2, '.', ''),
                'actual' => number_format($actualCash, 2, '.', ''),
                'discrepancy' => number_format($discrepancy, 2, '.', ''),
                'final_status' => $newStatus,
            ]);

            return $this->getShift($shiftId);
        });
    }

    public function getShift(int $shiftId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT s.*, u.name AS cashier_name
             FROM cashier_shifts s JOIN users u ON u.id = s.user_id
             WHERE s.id = ?'
        );
        $stmt->execute([$shiftId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ValidationException(['shift_id' => 'Shift does not exist.']);
        }
        return $row;
    }

    public function getCurrentShift(int $userId): ?array
    {
        $stmt = Connection::pdo()->prepare(
            "SELECT s.*, u.name AS cashier_name
             FROM cashier_shifts s JOIN users u ON u.id = s.user_id
             WHERE s.user_id = ? AND s.status = 'open'
             ORDER BY s.id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    // ---------------------------------------------------- HELD SALES --

    /**
     * Save the current cart as a held sale. Items are stored as a JSON
     * payload (snapshot of unit_id/sku/name/price). Held sales do NOT
     * reserve inventory — see class header.
     *
     * @param array{items:list<array{unit_id:int,sku:string,name:string,price:float}>, subtotal:float, discount:float, total:float} $cart
     */
    public function holdSale(array $cart, int $cashierId, ?int $shiftId, ?int $customerId, string $customerName, ?string $customerPhone, ?string $notes = null): array
    {
        if ($cart['items'] === []) {
            throw new ValidationException(['items' => 'Cannot hold an empty cart.']);
        }

        $reference = $this->generateHeldReference();
        $payload = json_encode($cart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = Connection::pdo()->prepare(
            'INSERT INTO held_sales (reference, shift_id, customer_id, cashier_id, customer_name, customer_phone, items_payload, subtotal, discount, total, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $reference,
            $shiftId,
            $customerId,
            $cashierId,
            $customerName,
            $customerPhone,
            $payload,
            number_format((float) $cart['subtotal'], 2, '.', ''),
            number_format((float) $cart['discount'], 2, '.', ''),
            number_format((float) $cart['total'], 2, '.', ''),
            $notes,
        ]);
        $id = (int) Connection::pdo()->lastInsertId();

        app_audit('pos.sale_held', 'held_sale', $id, [
            'reference' => $reference,
            'items' => count($cart['items']),
            'total' => number_format((float) $cart['total'], 2, '.', ''),
        ]);

        return ['id' => $id, 'reference' => $reference];
    }

    /**
     * Resume a held sale. Returns the stored cart payload plus a list
     * of unit_ids that are no longer sellable (sold to someone else
     * since the sale was held). The cashier must remove those items
     * before checkout.
     */
    public function resumeHeldSale(int $heldSaleId, int $cashierId): array
    {
        return Connection::transaction(function (PDO $pdo) use ($heldSaleId, $cashierId) {
            $stmt = $pdo->prepare('SELECT * FROM held_sales WHERE id = ? FOR UPDATE');
            $stmt->execute([$heldSaleId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new ValidationException(['held_sale' => 'Held sale does not exist.']);
            }
            if ($row['status'] !== 'held') {
                throw new ValidationException(['held_sale' => "Held sale is in status '{$row['status']}' — cannot resume."]);
            }

            $stmt = $pdo->prepare(
                'UPDATE held_sales SET status = "resumed", resumed_at = UTC_TIMESTAMP(), resumed_by = ? WHERE id = ?'
            );
            $stmt->execute([$cashierId, $heldSaleId]);

            app_audit('pos.sale_resumed', 'held_sale', $heldSaleId, [
                'reference' => $row['reference'],
            ]);

            $cart = json_decode((string) $row['items_payload'], true) ?: [];
            $unavailable = $this->unavailableUnitsInCart($cart['items'] ?? []);

            return [
                'held_sale' => $row,
                'cart' => $cart,
                'unavailable_unit_ids' => $unavailable,
            ];
        });
    }

    public function cancelHeldSale(int $heldSaleId, int $cashierId, ?string $reason = null): void
    {
        Connection::transaction(function (PDO $pdo) use ($heldSaleId, $cashierId, $reason) {
            $stmt = $pdo->prepare('SELECT * FROM held_sales WHERE id = ? FOR UPDATE');
            $stmt->execute([$heldSaleId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new ValidationException(['held_sale' => 'Held sale does not exist.']);
            }
            if ($row['status'] !== 'held') {
                throw new ValidationException(['held_sale' => "Cannot cancel a held sale in status '{$row['status']}'."]);
            }

            $stmt = $pdo->prepare('UPDATE held_sales SET status = "cancelled", cancelled_at = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->execute([$heldSaleId]);

            app_audit('pos.sale_cancelled', 'held_sale', $heldSaleId, [
                'reference' => $row['reference'],
                'reason' => $reason,
            ]);
        });
    }

    /** @return list<array<string,mixed>> held sales for the cashier (or all for managers) */
    public function heldSales(int $cashierId, bool $allCashiers = false): array
    {
        $sql = 'SELECT h.*, u.name AS cashier_name
                FROM held_sales h LEFT JOIN users u ON u.id = h.cashier_id
                WHERE h.status IN ("held","resumed")';
        $params = [];
        if (!$allCashiers) {
            $sql .= ' AND h.cashier_id = ?';
            $params[] = $cashierId;
        }
        $sql .= ' ORDER BY h.id DESC LIMIT 100';
        $stmt = Connection::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getHeldSale(int $heldSaleId): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT h.*, u.name AS cashier_name
             FROM held_sales h LEFT JOIN users u ON u.id = h.cashier_id
             WHERE h.id = ?'
        );
        $stmt->execute([$heldSaleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['cart'] = json_decode((string) $row['items_payload'], true) ?: [];
        return $row;
    }

    // ---------------------------------------------------- SEARCH + CUSTOMERS --

    /**
     * Fast unit search for the POS picker: SKU, serial, or model name.
     * Returns only 'available' units (no sold/reserved/damaged).
     *
     * @return list<array<string,mixed>>
     */
    public function searchUnits(string $q, int $limit = 25): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $like = '%' . $q . '%';
        $stmt = Connection::pdo()->prepare(
            "SELECT iu.id, iu.sku, iu.serial_number, iu.condition_grade, iu.selling_price,
                    pm.name AS product_name, b.name AS brand_name
             FROM inventory_units iu
             JOIN product_models pm ON pm.id = iu.product_model_id
             JOIN brands b ON b.id = pm.brand_id
             WHERE iu.status = 'available'
               AND (iu.sku LIKE ? OR iu.serial_number LIKE ? OR pm.name LIKE ?)
             ORDER BY pm.name ASC, iu.sku ASC
             LIMIT " . max(1, min(100, $limit))
        );
        $stmt->execute([$like, $like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function searchCustomers(string $q, int $limit = 25): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $like = '%' . $q . '%';
        $stmt = Connection::pdo()->prepare(
            'SELECT id, name, phone, email, account_status
             FROM customers
             WHERE deleted_at IS NULL
               AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)
             ORDER BY name ASC
             LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute([$like, $like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function quickCreateCustomer(string $name, string $phone, ?string $email = null): int
    {
        $repo = new CustomerRepository();
        $errors = [];
        if (mb_strlen($name) < 2) {
            $errors['name'] = 'Name is required (min 2 characters).';
        }
        if (mb_strlen($phone) < 6) {
            $errors['phone'] = 'Phone is required (min 6 digits).';
        }
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email is invalid.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $repo->create([
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'account_status' => 'active',
        ]);
    }

    // ---------------------------------------------------- CHECKOUT --

    /**
     * Finalise a POS sale: create the order (pos, completed), record
     * the payment, mark any held sale as resumed. Throws on any
     * validation failure (units no longer available, payment rejected,
     * discount not authorized, etc.).
     *
     * Coupon handling: when $cart['coupon_code'] is provided, the
     * server validates the coupon against the actual subtotal and the
     * customer id (per-customer limit), recomputes the discount from
     * the coupon definition, and passes the resulting
     * {coupon_id, discount} pair to OrderService::createOrder, which
     * records the redemption atomically. The coupon discount
     * OVERRIDES any manual discount the cashier may have entered —
     * only one of the two is allowed per sale.
     *
     * @param array{items:list<array{unit_id:int}>, discount:float, customer_id:int, payment_method:string, paid_amount:float, payment_reference?:?string, coupon_code?:?string} $cart
     */
    public function checkout(array $cart, int $cashierId, ?int $shiftId, ?int $discountAuthorizedBy): array
    {
        $unitIds = array_values(array_unique(array_map(
            fn ($i) => (int) ($i['unit_id'] ?? 0),
            $cart['items'] ?? [],
        )));
        $unitIds = array_values(array_filter($unitIds, fn ($v) => $v > 0));
        if ($unitIds === []) {
            throw new ValidationException(['items' => 'Cannot checkout an empty cart.']);
        }

        $customerId = (int) ($cart['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new ValidationException(['customer_id' => 'Customer is required for POS checkout.']);
        }

        $discount = (float) ($cart['discount'] ?? 0);
        $method = (string) ($cart['payment_method'] ?? 'cash');
        $paid = (float) ($cart['paid_amount'] ?? 0);
        $reference = $cart['payment_reference'] ?? null;
        $heldSaleId = isset($cart['held_sale_id']) ? (int) $cart['held_sale_id'] : null;
        $couponCode = isset($cart['coupon_code']) ? trim((string) $cart['coupon_code']) : '';

        // Pre-flight: compute subtotal from current prices (server-side
        // always wins; the client's claimed prices are advisory). Then
        // enforce discount authorization against the actual subtotal.
        $subtotal = $this->computeSubtotal($unitIds);

        // Coupon: when supplied, the server recalculates the discount
        // from the definition and uses that instead of any manual
        // discount. The cashier cannot combine a coupon with a manual
        // discount on the same sale.
        $couponOption = null;
        if ($couponCode !== '') {
            $coupon = (new CouponService())->validateAndCompute(
                $couponCode,
                [
                    'subtotal' => $subtotal,
                    'unit_ids' => $unitIds,
                ],
                $customerId,
            );
            $couponOption = $coupon;
            // Coupon overrides manual discount.
            $discount = (float) $coupon['discount'];
            // Manual-discount authorization no longer applies — the
            // coupon's discount is server-trusted.
            $discountAuthorizedBy = null;
        } else {
            $this->assertDiscountAuthorized($discount, $subtotal, $cashierId, $discountAuthorizedBy);
        }

        $orderService = new OrderService();
        $order = $orderService->createOrder(
            $customerId,
            $unitIds,
            'pos',
            $cashierId,
            [
                'discount' => $discount,
                'discount_authorized_by' => $discountAuthorizedBy,
                'notes' => $cart['notes'] ?? null,
                'coupon' => $couponOption,
            ],
            'completed',
        );

        $paymentResult = $orderService->recordPayment(
            $order['id'],
            $paid,
            $method,
            $cashierId,
            $reference,
        );

        if ($heldSaleId !== null) {
            $this->markHeldSaleResumed($heldSaleId, $cashierId, $order['id']);
        }

        app_audit('pos.checkout', 'order', $order['id'], [
            'reference' => $order['reference'],
            'total' => $order['total'],
            'method' => $method,
            'paid' => number_format($paid, 2, '.', ''),
            'shift_id' => $shiftId,
            'coupon' => $couponOption['code'] ?? null,
            'coupon_discount' => isset($couponOption['discount']) ? number_format((float) $couponOption['discount'], 2, '.', '') : null,
        ]);

        return [
            'order_id' => $order['id'],
            'reference' => $order['reference'],
            'total' => $order['total'],
            'payment_status' => $paymentResult['payment_status'],
            'paid' => $paymentResult['paid'],
            'change' => max(0.0, $paid - (float) $order['total']),
        ];
    }

    // ---------------------------------------------------- DISCOUNT AUTH --

    /**
     * Throws unless the discount is within the cashier's authorised
     * range OR a manager/owner (with pos.authorize_discount) has signed
     * off. Returns the authorizer id to record on the order.
     */
    public function assertDiscountAuthorized(float $discount, float $subtotal, int $cashierId, ?int $authorizerId): ?int
    {
        if ($discount <= 0) {
            return null;
        }
        if ($subtotal <= 0) {
            throw new ValidationException(['discount' => 'Cannot discount an empty subtotal.']);
        }

        $ratio = $discount / $subtotal;
        $threshold = (float) SettingService::get('pos_discount_authorization_threshold', self::DISCOUNT_AUTHORIZATION_THRESHOLD);

        if ($ratio <= $threshold + 0.0001) {
            // Within cashier's authorised range — no manager needed.
            return null;
        }

        if ($authorizerId === null || $authorizerId <= 0) {
            throw new ValidationException([
                'discount' => sprintf(
                    'Discount of %s BDT (%.1f%%) exceeds the %.0f%% cashier threshold. A manager must authorise.',
                    number_format($discount, 2, '.', ''),
                    $ratio * 100,
                    $threshold * 100,
                ),
            ]);
        }

        if (!AuthService::can('pos.authorize_discount')) {
            // The authorizer is the *current* user (manager authorising
            // at their own terminal). For multi-user authorisation
            // flows where the manager enters their own credentials,
            // the controller would assert via a separate session —
            // out of scope here.
            throw new ValidationException([
                'discount' => 'You do not have permission to authorise discounts above the cashier threshold.',
            ]);
        }

        return $authorizerId;
    }

    // ---------------------------------------------------- HELPERS --

    private function computeSubtotal(array $unitIds): float
    {
        if ($unitIds === []) {
            return 0.0;
        }
        sort($unitIds);
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $stmt = Connection::pdo()->prepare(
            "SELECT COALESCE(SUM(selling_price), 0) FROM inventory_units
             WHERE id IN ({$placeholders}) AND status IN ('available','reserved')"
        );
        $stmt->execute($unitIds);
        return (float) $stmt->fetchColumn();
    }

    /**
     * @param list<array{unit_id:int}> $items
     * @return list<int> unit_ids that are NOT currently sellable
     */
    private function unavailableUnitsInCart(array $items): array
    {
        $ids = array_values(array_unique(array_map(
            fn ($i) => (int) ($i['unit_id'] ?? 0),
            $items,
        )));
        $ids = array_filter($ids, fn ($v) => $v > 0);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Connection::pdo()->prepare(
            "SELECT id FROM inventory_units
             WHERE id IN ({$placeholders}) AND status NOT IN ('available','reserved')"
        );
        $stmt->execute($ids);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function markHeldSaleResumed(int $heldSaleId, int $cashierId, int $orderId): void
    {
        $stmt = Connection::pdo()->prepare(
            'UPDATE held_sales
             SET status = "resumed", resumed_at = UTC_TIMESTAMP(), resumed_by = ?,
                 notes = CONCAT(IFNULL(notes, ""), ?)
             WHERE id = ? AND status = "held"'
        );
        $stmt->execute([
            $cashierId,
            "\nConverted to order #" . $orderId,
            $heldSaleId,
        ]);
    }

    private function generateHeldReference(): string
    {
        return sprintf('HOLD-%s-%s', gmdate('ymd'), strtoupper(bin2hex(random_bytes(3))));
    }

    /** @return array<string,string> */
    public function paymentMethodLabels(): array
    {
        return $this->labels;
    }
}
