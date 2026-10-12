<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\CouponRepository;
use App\Support\Database\Connection;
use PDO;

/**
 * Coupons & promotions (Prompt 37).
 *
 * What this service is responsible for:
 *
 *  - CRUD on coupon definitions (create, update, archive).
 *  - Approval workflow: submit → approve / reject. Coupons are not
 *    live until they reach status='active' AND the validity window is
 *    open AND usage limits are not exhausted.
 *  - Server-side recalculation of the discount for a cart. The
 *    client's claimed discount is NEVER trusted — the value comes
 *    from the coupon definition.
 *  - Eligibility validation: code lookup, validity window, usage
 *    limits (overall + per-customer), minimum subtotal, and
 *    product/category eligibility rules.
 *  - Atomic redemption inside an order transaction. The coupon_usages
 *    row and the orders.coupon_id link are written together so a
 *    partial failure rolls back both.
 *
 * Financial integrity:
 *  - The recomputed discount is clamped to the order subtotal. A
 *    fixed coupon above subtotal is reduced to the subtotal; a
 *    percentage coupon's value is capped by max_discount if set.
 *  - The order's existing CHECK constraint (subtotal >= 0 AND
 *    discount >= 0 AND total >= 0) makes negative totals
 *    structurally impossible.
 *  - A coupon can be applied at most once per order (unique index on
 *    coupon_usages (coupon_id, order_id)).
 */
final class CouponService
{
    public const DISCOUNT_TYPES = ['fixed', 'percentage'];
    public const APPLIES_TO = ['all', 'categories', 'products'];
    public const ELIGIBILITY_MODES = ['include', 'exclude'];
    public const STATUSES = ['draft', 'pending_approval', 'active', 'paused', 'rejected', 'archived'];

    /**
     * Status transitions allowed for a coupon. Rejected and archived
     * are terminal — once a coupon is rejected or archived it cannot
     * be revived (a new coupon must be created).
     */
    public const STATUS_TRANSITIONS = [
        'draft' => ['pending_approval', 'active', 'archived'],
        'pending_approval' => ['active', 'rejected', 'draft'],
        'active' => ['paused', 'archived'],
        'paused' => ['active', 'archived'],
        'rejected' => [],
        'archived' => [],
    ];

    /**
     * Statuses where a coupon is considered "live" (eligible for
     * redemption). Only 'active' qualifies — paused / pending /
     * rejected / archived coupons cannot be applied at checkout.
     */
    public const REDEEMABLE_STATUS = 'active';

    /**
     * Normalize a coupon code (uppercase, alphanumerics + dash + underscore).
     * Strips spaces and dangerous characters.
     */
    public static function normalizeCode(string $code): string
    {
        $code = trim($code);
        $code = preg_replace('/[^A-Za-z0-9_\-]/', '', $code) ?? '';
        return mb_strtoupper($code);
    }

    // ---------------------------------------------------- CRUD --

    /**
     * @param array{
     *   code:string,description?:?string,discount_type:string,discount_value:float,
     *   min_subtotal?:float,max_discount?:?float,starts_at?:?string,ends_at?:?string,
     *   usage_limit?:?int,usage_limit_per_customer?:int,applies_to:string,
     *   eligibility_mode:string,target_product_ids?:list<int>,target_category_ids?:list<int>
     * } $data
     */
    public function create(array $data, int $createdBy): int
    {
        $this->validateDefinition($data, couponId: null);
        $code = self::normalizeCode((string) $data['code']);
        if ($code === '') {
            throw new ValidationException(['code' => 'Coupon code is required.']);
        }
        // Enforce code uniqueness.
        if ((new CouponRepository())->findByCode($code) !== null) {
            throw new ValidationException(['code' => "Coupon code '{$code}' is already in use."]);
        }

        return Connection::transaction(function (PDO $pdo) use ($data, $code, $createdBy) {
            $stmt = $pdo->prepare(
                'INSERT INTO coupons
                    (code, description, discount_type, discount_value, min_subtotal, max_discount,
                     starts_at, ends_at, usage_limit, usage_limit_per_customer, applies_to,
                     eligibility_mode, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)'
            );
            $stmt->execute([
                $code,
                $this->cleanString($data['description'] ?? null, 255),
                $data['discount_type'],
                number_format((float) $data['discount_value'], 2, '.', ''),
                number_format((float) ($data['min_subtotal'] ?? 0), 2, '.', ''),
                $this->optionalDecimal($data['max_discount'] ?? null),
                $this->optionalDatetime($data['starts_at'] ?? null),
                $this->optionalDatetime($data['ends_at'] ?? null),
                $this->optionalInt($data['usage_limit'] ?? null),
                (int) ($data['usage_limit_per_customer'] ?? 1),
                $data['applies_to'],
                $data['eligibility_mode'] ?? 'include',
                $createdBy,
            ]);
            $id = (int) $pdo->lastInsertId();

            $this->syncTargets(
                $pdo,
                $id,
                (string) $data['applies_to'],
                $data['target_product_ids'] ?? [],
                $data['target_category_ids'] ?? [],
            );

            app_audit('coupon.created', 'coupon', $id, [
                'code' => $code,
                'discount_type' => $data['discount_type'],
                'discount_value' => number_format((float) $data['discount_value'], 2, '.', ''),
                'applies_to' => $data['applies_to'],
            ]);

            return $id;
        });
    }

    /**
     * Update an existing coupon. Only coupons in non-terminal states
     * (draft, pending_approval, active, paused) may be edited. The
     * discount_value can be lowered on an active coupon (correction)
     * but raising it requires re-approval (transition back to
     * pending_approval). For simplicity, any edit to a non-draft
     * coupon reverts it to draft so it goes through approval again.
     */
    public function update(int $couponId, array $data, int $actorId): void
    {
        $repo = new CouponRepository();
        $existing = $repo->find($couponId);
        if ($existing === null) {
            throw new ValidationException(['coupon_id' => 'Coupon does not exist.']);
        }
        if (in_array($existing['status'], ['rejected', 'archived'], true)) {
            throw new ValidationException(['status' => 'Cannot edit a rejected or archived coupon.']);
        }

        $this->validateDefinition($data, couponId: $couponId);
        $code = self::normalizeCode((string) $data['code']);
        if ($code === '') {
            throw new ValidationException(['code' => 'Coupon code is required.']);
        }
        // Uniqueness (excluding self).
        $other = $repo->findByCode($code);
        if ($other !== null && (int) $other['id'] !== $couponId) {
            throw new ValidationException(['code' => "Coupon code '{$code}' is already in use."]);
        }

        Connection::transaction(function (PDO $pdo) use ($couponId, $data, $code, $actorId, $existing) {
            // Editing an active coupon downgrades it to draft so a
            // manager must re-approve. This stops a content_manager
            // from silently changing a live discount.
            $newStatus = $existing['status'] === 'draft' ? 'draft' : 'draft';

            $stmt = $pdo->prepare(
                'UPDATE coupons SET
                    code = ?, description = ?, discount_type = ?, discount_value = ?,
                    min_subtotal = ?, max_discount = ?, starts_at = ?, ends_at = ?,
                    usage_limit = ?, usage_limit_per_customer = ?, applies_to = ?,
                    eligibility_mode = ?, status = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $code,
                $this->cleanString($data['description'] ?? null, 255),
                $data['discount_type'],
                number_format((float) $data['discount_value'], 2, '.', ''),
                number_format((float) ($data['min_subtotal'] ?? 0), 2, '.', ''),
                $this->optionalDecimal($data['max_discount'] ?? null),
                $this->optionalDatetime($data['starts_at'] ?? null),
                $this->optionalDatetime($data['ends_at'] ?? null),
                $this->optionalInt($data['usage_limit'] ?? null),
                (int) ($data['usage_limit_per_customer'] ?? 1),
                $data['applies_to'],
                $data['eligibility_mode'] ?? 'include',
                $newStatus,
                $couponId,
            ]);

            $this->syncTargets(
                $pdo,
                $couponId,
                (string) $data['applies_to'],
                $data['target_product_ids'] ?? [],
                $data['target_category_ids'] ?? [],
            );

            app_audit('coupon.updated', 'coupon', $couponId, [
                'code' => $code,
                'reverted_to' => $newStatus,
            ]);
        });
    }

    /**
     * Submit a draft coupon for manager approval.
     */
    public function submitForApproval(int $couponId, int $actorId): void
    {
        $this->transition($couponId, 'pending_approval', $actorId, 'Submitted for approval');
    }

    /**
     * Approve a pending coupon (manager / owner with coupons.approve).
     * The coupon immediately becomes 'active' and is live for use.
     */
    public function approve(int $couponId, int $approverId): void
    {
        Connection::transaction(function (PDO $pdo) use ($couponId, $approverId) {
            $stmt = $pdo->prepare('SELECT * FROM coupons WHERE id = ? FOR UPDATE');
            $stmt->execute([$couponId]);
            $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($coupon === false) {
                throw new ValidationException(['coupon_id' => 'Coupon does not exist.']);
            }

            // Manager can approve from either 'draft' or 'pending_approval'
            // (bypass submission step is allowed for the approver).
            if (!in_array($coupon['status'], ['draft', 'pending_approval'], true)) {
                throw new ValidationException([
                    'status' => "Cannot approve a coupon in status '{$coupon['status']}'.",
                ]);
            }

            $stmt = $pdo->prepare(
                'UPDATE coupons
                 SET status = "active", approved_by = ?, approved_at = UTC_TIMESTAMP(),
                     rejected_by = NULL, rejected_at = NULL, rejection_reason = NULL
                 WHERE id = ?'
            );
            $stmt->execute([$approverId, $couponId]);

            app_audit('coupon.approved', 'coupon', $couponId, [
                'code' => $coupon['code'],
                'approver' => $approverId,
            ]);
        });
    }

    /**
     * Reject a pending coupon with a reason. Rejected is terminal.
     */
    public function reject(int $couponId, int $approverId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => 'A rejection reason is required.']);
        }
        Connection::transaction(function (PDO $pdo) use ($couponId, $approverId, $reason) {
            $stmt = $pdo->prepare('SELECT * FROM coupons WHERE id = ? FOR UPDATE');
            $stmt->execute([$couponId]);
            $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($coupon === false) {
                throw new ValidationException(['coupon_id' => 'Coupon does not exist.']);
            }
            if (!in_array($coupon['status'], ['draft', 'pending_approval'], true)) {
                throw new ValidationException([
                    'status' => "Cannot reject a coupon in status '{$coupon['status']}'.",
                ]);
            }

            $stmt = $pdo->prepare(
                'UPDATE coupons
                 SET status = "rejected", rejected_by = ?, rejected_at = UTC_TIMESTAMP(),
                     rejection_reason = ?
                 WHERE id = ?'
            );
            $stmt->execute([$approverId, mb_substr($reason, 0, 255), $couponId]);

            app_audit('coupon.rejected', 'coupon', $couponId, [
                'code' => $coupon['code'],
                'reason' => mb_substr($reason, 0, 80),
            ]);
        });
    }

    /**
     * Pause / resume an active coupon.
     */
    public function pause(int $couponId, int $actorId): void
    {
        $this->transition($couponId, 'paused', $actorId, 'Paused');
    }

    public function resume(int $couponId, int $actorId): void
    {
        $this->transition($couponId, 'active', $actorId, 'Resumed');
    }

    /**
     * Archive a coupon (soft-delete equivalent — no longer selectable
     * for new orders but historical usages remain intact).
     */
    public function archive(int $couponId, int $actorId): void
    {
        $this->transition($couponId, 'archived', $actorId, 'Archived');
    }

    private function transition(int $couponId, string $to, int $actorId, string $reason): void
    {
        Connection::transaction(function (PDO $pdo) use ($couponId, $to, $actorId, $reason) {
            $stmt = $pdo->prepare('SELECT * FROM coupons WHERE id = ? FOR UPDATE');
            $stmt->execute([$couponId]);
            $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($coupon === false) {
                throw new ValidationException(['coupon_id' => 'Coupon does not exist.']);
            }
            $from = (string) $coupon['status'];
            $allowed = self::STATUS_TRANSITIONS[$from] ?? [];
            if (!in_array($to, $allowed, true)) {
                throw new ValidationException([
                    'status' => "Coupon status transition '{$from}' → '{$to}' is not allowed.",
                ]);
            }
            $stmt = $pdo->prepare('UPDATE coupons SET status = ? WHERE id = ?');
            $stmt->execute([$to, $couponId]);
            app_audit('coupon.status_changed', 'coupon', $couponId, [
                'from' => $from, 'to' => $to, 'reason' => $reason,
            ]);
        });
    }

    // ---------------------------------------------------- ELIGIBILITY + RECALC --

    /**
     * Recalculate the discount for a cart from the coupon definition.
     * The client's claimed discount is NEVER used — this method is
     * the single source of truth.
     *
     * The returned discount is clamped to the order subtotal so it
     * can never produce a negative total.
     *
     * @param array{subtotal:float, unit_ids?:list<int>, product_model_ids?:list<int>, category_ids?:list<int>} $cart
     * @return array{coupon_id:int,code:string,discount:float,reason?:string}
     * @throws ValidationException when the coupon is not eligible
     */
    public function validateAndCompute(string $code, array $cart, ?int $customerId = null): array
    {
        $repo = new CouponRepository();
        $coupon = $repo->findByCode(self::normalizeCode($code));
        if ($coupon === null) {
            throw new ValidationException(['code' => "Coupon code '{$code}' was not recognised."]);
        }

        $subtotal = (float) ($cart['subtotal'] ?? 0);
        $this->assertEligible($coupon, $subtotal, $customerId, $cart);

        $discount = $this->computeDiscount($coupon, $subtotal);
        return [
            'coupon_id' => (int) $coupon['id'],
            'code' => (string) $coupon['code'],
            'discount' => $discount,
        ];
    }

    /**
     * Compute the discount amount from the coupon definition, clamped
     * to the subtotal. Pure function — does no DB eligibility checks.
     */
    public function computeDiscount(array $coupon, float $subtotal): float
    {
        if ($subtotal <= 0) {
            return 0.0;
        }
        $value = (float) $coupon['discount_value'];
        $type = (string) $coupon['discount_type'];

        if ($type === 'percentage') {
            $pct = max(0.0, min(100.0, $value)); // 0-100
            $discount = $subtotal * ($pct / 100.0);
            $cap = $coupon['max_discount'] !== null ? (float) $coupon['max_discount'] : null;
            if ($cap !== null && $cap > 0) {
                $discount = min($discount, $cap);
            }
        } else { // fixed
            $discount = max(0.0, $value);
            $cap = $coupon['max_discount'] !== null ? (float) $coupon['max_discount'] : null;
            if ($cap !== null && $cap > 0 && $discount > $cap) {
                $discount = $cap;
            }
        }

        // NEVER let discount exceed subtotal — total would go negative.
        return round(min($discount, $subtotal), 2);
    }

    /**
     * Throw a ValidationException unless the coupon is eligible for the
     * given cart and customer. Checks status, validity window, usage
     * limits, minimum subtotal, and product/category eligibility.
     *
     * @param array{subtotal:float, unit_ids?:list<int>, product_model_ids?:list<int>, category_ids?:list<int>} $cart
     */
    public function assertEligible(array $coupon, float $subtotal, ?int $customerId, array $cart): void
    {
        if ($coupon['status'] !== self::REDEEMABLE_STATUS) {
            throw new ValidationException([
                'code' => "This coupon is not active (status: {$coupon['status']}).",
            ]);
        }

        $now = time();
        $starts = $coupon['starts_at'] !== null ? strtotime((string) $coupon['starts_at']) : null;
        $ends = $coupon['ends_at'] !== null ? strtotime((string) $coupon['ends_at']) : null;
        if ($starts !== false && $starts !== null && $now < $starts) {
            throw new ValidationException(['code' => 'This coupon is not yet valid.']);
        }
        if ($ends !== false && $ends !== null && $now > $ends) {
            throw new ValidationException(['code' => 'This coupon has expired.']);
        }

        $minSubtotal = (float) $coupon['min_subtotal'];
        if ($minSubtotal > 0 && $subtotal < $minSubtotal - 0.001) {
            throw new ValidationException([
                'code' => sprintf(
                    'This coupon requires a minimum purchase of %s. Your cart subtotal is %s.',
                    number_format($minSubtotal, 2, '.', ''),
                    number_format($subtotal, 2, '.', ''),
                ),
            ]);
        }

        // Usage limits — overall + per-customer.
        $repo = new CouponRepository();
        $overallLimit = $coupon['usage_limit'] !== null ? (int) $coupon['usage_limit'] : null;
        if ($overallLimit !== null && $overallLimit > 0) {
            $used = $repo->countUsages((int) $coupon['id']);
            if ($used >= $overallLimit) {
                throw new ValidationException(['code' => 'This coupon has reached its usage limit.']);
            }
        }
        $perCustomer = (int) $coupon['usage_limit_per_customer'];
        if ($perCustomer > 0 && $customerId !== null && $customerId > 0) {
            $myUsage = $repo->countUsagesByCustomer((int) $coupon['id'], $customerId);
            if ($myUsage >= $perCustomer) {
                throw new ValidationException([
                    'code' => "You have already used this coupon {$myUsage} time(s); the per-customer limit is {$perCustomer}.",
                ]);
            }
        }

        // Product / category eligibility.
        $applies = (string) $coupon['applies_to'];
        if ($applies === 'all') {
            return;
        }
        $mode = (string) $coupon['eligibility_mode'];
        $matchingIds = $applies === 'products'
            ? ($cart['product_model_ids'] ?? [])
            : ($cart['category_ids'] ?? []);

        $targetIds = $applies === 'products'
            ? array_column($repo->targetProducts((int) $coupon['id']), 'id')
            : array_column($repo->targetCategories((int) $coupon['id']), 'id');
        $targetIds = array_map('intval', $targetIds);

        if ($applies === 'products' && $cart['unit_ids'] !== [] && $cart['product_model_ids'] === []) {
            // Caller passed only unit_ids; resolve product_models from units.
            $cart['product_model_ids'] = $this->productModelIdsForUnits($cart['unit_ids']);
        }

        $matched = !empty(array_intersect(array_map('intval', $matchingIds), $targetIds));
        if ($mode === 'include' && !$matched) {
            throw new ValidationException([
                'code' => 'This coupon is restricted to specific ' . ($applies === 'products' ? 'products' : 'categories') . ' not in your cart.',
            ]);
        }
        if ($mode === 'exclude' && $matched) {
            throw new ValidationException([
                'code' => 'This coupon excludes ' . ($applies === 'products' ? 'products' : 'categories') . ' in your cart.',
            ]);
        }
    }

    /**
     * Record a coupon redemption inside an open transaction. The
     * caller (OrderService::createOrder) holds the orders row lock
     * and provides its PDO handle so coupon_usages + orders.coupon_id
     * update + the order itself commit atomically.
     *
     * The discount is recomputed here from the coupon definition — the
     * caller's $computedDiscount is advisory and must match (within a
     * rounding tolerance); otherwise we throw. This prevents a stale
     * client from submitting a discount value computed under old
     * prices.
     *
     * @param array{coupon_id:int,discount:float} $computed The discount previously returned by validateAndCompute()
     */
    public function recordRedemption(PDO $pdo, int $orderId, int $customerId, int $couponId, float $computedDiscount, int $appliedBy, float $actualSubtotal): void
    {
        $stmt = $pdo->prepare('SELECT * FROM coupons WHERE id = ? FOR UPDATE');
        $stmt->execute([$couponId]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($coupon === false) {
            throw new ValidationException(['coupon' => 'Coupon does not exist.']);
        }

        // Final eligibility check inside the lock (status, validity, usage).
        $this->assertEligible($coupon, $actualSubtotal, $customerId, [
            'subtotal' => $actualSubtotal,
        ]);

        // Recompute from definition — the authoritative value.
        $recomputed = $this->computeDiscount($coupon, $actualSubtotal);
        if (abs($recomputed - $computedDiscount) > 0.01) {
            throw new ValidationException([
                'discount' => sprintf(
                    'Coupon discount mismatch. Server recomputed %s, client sent %s. Please re-apply the coupon.',
                    number_format($recomputed, 2, '.', ''),
                    number_format($computedDiscount, 2, '.', ''),
                ),
            ]);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO coupon_usages (coupon_id, order_id, customer_id, discount_amount, applied_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $couponId,
            $orderId,
            $customerId > 0 ? $customerId : null,
            number_format($recomputed, 2, '.', ''),
            $appliedBy,
        ]);

        $stmt = $pdo->prepare('UPDATE orders SET coupon_id = ? WHERE id = ?');
        $stmt->execute([$couponId, $orderId]);

        app_audit('coupon.redeemed', 'order', $orderId, [
            'coupon_id' => $couponId,
            'code' => (string) $coupon['code'],
            'discount' => number_format($recomputed, 2, '.', ''),
        ]);
    }

    // ---------------------------------------------------- HELPERS --

    private function validateDefinition(array $data, ?int $couponId): void
    {
        $errors = [];
        if (!in_array($data['discount_type'] ?? '', self::DISCOUNT_TYPES, true)) {
            $errors['discount_type'] = 'Discount type must be fixed or percentage.';
        }
        $value = (float) ($data['discount_value'] ?? -1);
        if ($value < 0) {
            $errors['discount_value'] = 'Discount value must be a positive number.';
        }
        if (($data['discount_type'] ?? '') === 'percentage' && $value > 100) {
            $errors['discount_value'] = 'Percentage discount cannot exceed 100.';
        }
        if ((float) ($data['min_subtotal'] ?? 0) < 0) {
            $errors['min_subtotal'] = 'Minimum subtotal cannot be negative.';
        }
        if (($data['max_discount'] ?? null) !== null && $data['max_discount'] !== '' && (float) $data['max_discount'] < 0) {
            $errors['max_discount'] = 'Maximum discount cap cannot be negative.';
        }
        if (!in_array($data['applies_to'] ?? '', self::APPLIES_TO, true)) {
            $errors['applies_to'] = 'Applies-to must be all, categories, or products.';
        }
        if (!in_array($data['eligibility_mode'] ?? 'include', self::ELIGIBILITY_MODES, true)) {
            $errors['eligibility_mode'] = 'Eligibility mode must be include or exclude.';
        }
        if (($data['usage_limit'] ?? null) !== null && $data['usage_limit'] !== '' && (int) $data['usage_limit'] < 1) {
            $errors['usage_limit'] = 'Usage limit must be at least 1, or empty for unlimited.';
        }
        if (isset($data['starts_at'], $data['ends_at']) && $data['starts_at'] !== '' && $data['ends_at'] !== '') {
            $start = strtotime((string) $data['starts_at']);
            $end = strtotime((string) $data['ends_at']);
            if ($start !== false && $end !== false && $start > $end) {
                $errors['ends_at'] = 'End date must be after the start date.';
            }
        }
        if (($data['applies_to'] ?? '') === 'products' && empty($data['target_product_ids'])) {
            $errors['target_product_ids'] = 'Select at least one product for product-restricted coupons.';
        }
        if (($data['applies_to'] ?? '') === 'categories' && empty($data['target_category_ids'])) {
            $errors['target_category_ids'] = 'Select at least one category for category-restricted coupons.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private function syncTargets(PDO $pdo, int $couponId, string $appliesTo, array $productIds, array $categoryIds): void
    {
        $pdo->prepare('DELETE FROM coupon_target_products WHERE coupon_id = ?')->execute([$couponId]);
        $pdo->prepare('DELETE FROM coupon_target_categories WHERE coupon_id = ?')->execute([$couponId]);
        if ($appliesTo === 'products') {
            $stmt = $pdo->prepare('INSERT IGNORE INTO coupon_target_products (coupon_id, product_model_id) VALUES (?, ?)');
            foreach (array_unique(array_map('intval', $productIds)) as $pid) {
                if ($pid > 0) {
                    $stmt->execute([$couponId, $pid]);
                }
            }
        }
        if ($appliesTo === 'categories') {
            $stmt = $pdo->prepare('INSERT IGNORE INTO coupon_target_categories (coupon_id, category_id) VALUES (?, ?)');
            foreach (array_unique(array_map('intval', $categoryIds)) as $cid) {
                if ($cid > 0) {
                    $stmt->execute([$couponId, $cid]);
                }
            }
        }
    }

    private function cleanString(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function optionalDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (float) $value;
        if ($value <= 0) {
            return null;
        }
        return number_format($value, 2, '.', '');
    }

    private function optionalDatetime(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    /** @param list<int> $unitIds */
    private function productModelIdsForUnits(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $stmt = Connection::pdo()->prepare(
            "SELECT DISTINCT product_model_id FROM inventory_units WHERE id IN ({$placeholders})"
        );
        $stmt->execute(array_map('intval', $unitIds));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
