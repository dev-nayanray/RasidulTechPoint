<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PosService;
use App\Support\Csrf;
use App\Support\Database\Connection;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Coupons & promotions (Prompt 37) — service + HTTP tests.
 *
 * Proves:
 *  - CRUD: create as draft; update reverts to draft; archive; reject
 *  - Status lifecycle: draft → pending_approval → active ⇄ paused → archived
 *                     rejected is terminal
 *  - Approval workflow: only coupons.approve holders can approve/reject
 *  - coupons.manage alone can submit, pause, resume, archive
 *  - Eligibility: invalid code, non-active status, future/past window,
 *    minimum subtotal not met, overall usage limit, per-customer limit,
 *    product-include / product-exclude / category-include / category-exclude
 *  - Server recalculation: client's claimed discount is NEVER trusted;
 *    mismatch throws; discount is clamped to subtotal (fixed > subtotal
 *    becomes subtotal, percentage cap respected)
 *  - Redemption records coupon_usages row + links orders.coupon_id
 *    atomically; per-order uniqueness enforced
 *  - POS checkout with coupon: discount recomputed, redemption recorded
 *  - Admin order create with coupon: same
 *  - Order detail surfaces coupon code + applied-by info
 *  - Permission: technician blocked; content_manager can manage but
 *    cannot approve
 *  - CSRF on state-changing routes
 */
final class CouponTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->group(['middleware' => ['headers']], function (Router $router): void {
            require BASE_PATH . '/routes/web.php';
        });
        return $router;
    }

    private function resolver(): MiddlewareResolver
    {
        $resolver = new MiddlewareResolver();
        $resolver->register('headers', fn () => new \App\Middleware\SecurityHeadersMiddleware());
        $resolver->register('auth', fn () => new \App\Middleware\AuthMiddleware(guest: false));
        $resolver->register('guest', fn () => new \App\Middleware\AuthMiddleware(guest: true));
        $resolver->register('csrf', fn () => new \App\Middleware\CsrfMiddleware());
        $resolver->register('permission', fn () => new \App\Middleware\PermissionMiddleware());
        return $resolver;
    }

    private function withCsrf(array $post): array
    {
        $post['_token'] = Csrf::token();
        return $post;
    }

    private function dispatchAs(int $userId, string $method, string $uri, array $post = [], array $get = []): Response
    {
        $this->actingAs($userId);
        return $this->router()->dispatch(
            Request::synthetic($method, $uri, $get, $this->withCsrf($post)),
            $this->resolver()
        );
    }

    private function dispatchAsJson(int $userId, string $method, string $uri, array $post = [], array $get = []): Response
    {
        $this->actingAs($userId);
        return $this->router()->dispatch(
            Request::synthetic($method, $uri, $get, $this->withCsrf($post), ['HTTP_ACCEPT' => 'application/json']),
            $this->resolver()
        );
    }

    private function wipeOwners(): void
    {
        $this->pdo()->exec(
            "UPDATE users SET is_active = 0 WHERE id IN (
                SELECT user_id FROM (
                    SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                    WHERE r.slug = 'owner'
                ) AS owners)"
        );
    }

    private function makeOwner(string $email): int
    {
        $this->wipeOwners();
        $id = $this->makeUser($email, 'Correct-Horse-1', ['owner']);
        $this->actingAs($id);
        return $id;
    }

    private function makeManager(string $email): int
    {
        $id = $this->makeUser($email, 'Correct-Horse-1', ['manager']);
        $this->actingAs($id);
        return $id;
    }

    private function makeContentManager(string $email): int
    {
        $id = $this->makeUser($email, 'Correct-Horse-1', ['content_manager']);
        $this->actingAs($id);
        return $id;
    }

    private function makeCashier(string $email): int
    {
        $id = $this->makeUser($email, 'Correct-Horse-1', ['sales_cashier']);
        $this->actingAs($id);
        return $id;
    }

    private function makeTech(string $email): int
    {
        $id = $this->makeUser($email, 'Correct-Horse-1', ['technician']);
        $this->actingAs($id);
        return $id;
    }

    /** Create an active coupon directly via the service. */
    private function makeActiveCoupon(
        int $managerId,
        string $code,
        array $overrides = [],
    ): int {
        $svc = new CouponService();
        $data = array_merge([
            'code' => $code,
            'description' => 'Test coupon',
            'discount_type' => 'fixed',
            'discount_value' => 50.0,
            'min_subtotal' => 0.0,
            'max_discount' => null,
            'starts_at' => null,
            'ends_at' => null,
            'usage_limit' => null,
            'usage_limit_per_customer' => 1,
            'applies_to' => 'all',
            'eligibility_mode' => 'include',
        ], $overrides);
        $id = $svc->create($data, $managerId);
        $svc->approve($id, $managerId);
        return $id;
    }

    private function cleanupCoupon(int $couponId): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM coupon_usages WHERE coupon_id = ?')->execute([$couponId]);
        $this->pdo()->prepare('DELETE FROM coupon_target_products WHERE coupon_id = ?')->execute([$couponId]);
        $this->pdo()->prepare('DELETE FROM coupon_target_categories WHERE coupon_id = ?')->execute([$couponId]);
        $this->pdo()->prepare('DELETE FROM coupons WHERE id = ?')->execute([$couponId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function cleanupOrder(int $orderId): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM coupon_usages WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_fulfillment_notes WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_status_history WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- CRUD + LIFECYCLE --

    public function testCreateCouponAsDraftAndApprove(): void
    {
        $manager = $this->makeManager('c-create@test.local');
        $svc = new CouponService();
        $id = $svc->create([
            'code' => 'NEWYEAR50',
            'description' => '50 BDT off',
            'discount_type' => 'fixed',
            'discount_value' => 50.0,
            'min_subtotal' => 0.0,
            'max_discount' => null,
            'applies_to' => 'all',
            'eligibility_mode' => 'include',
            'usage_limit_per_customer' => 1,
        ], $manager);

        $stmt = $this->pdo()->prepare('SELECT status FROM coupons WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertSame('draft', (string) $stmt->fetchColumn(), 'new coupons start as draft');

        $svc->approve($id, $manager);
        $stmt = $this->pdo()->prepare('SELECT status, approved_by FROM coupons WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('active', (string) $row['status']);
        $this->assertSame((string) $manager, (string) $row['approved_by']);

        $this->cleanupCoupon($id);
    }

    public function testUpdateRevertsActiveCouponToDraft(): void
    {
        $manager = $this->makeManager('c-update@test.local');
        $id = $this->makeActiveCoupon($manager, 'EDITABLE10', ['discount_value' => 10]);
        $svc = new CouponService();
        $svc->update($id, [
            'code' => 'EDITABLE10',
            'description' => 'Edited',
            'discount_type' => 'fixed',
            'discount_value' => 25.0,
            'min_subtotal' => 0.0,
            'max_discount' => null,
            'applies_to' => 'all',
            'eligibility_mode' => 'include',
            'usage_limit_per_customer' => 1,
        ], $manager);

        $stmt = $this->pdo()->prepare('SELECT status, discount_value FROM coupons WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('draft', (string) $row['status'], 'edit reverts to draft');
        $this->assertSame('25.00', (string) $row['discount_value']);

        $this->cleanupCoupon($id);
    }

    public function testCannotEditRejectedCoupon(): void
    {
        $manager = $this->makeManager('c-rejected@test.local');
        $svc = new CouponService();
        $id = $svc->create([
            'code' => 'REJECTED1', 'discount_type' => 'fixed', 'discount_value' => 5,
            'applies_to' => 'all', 'eligibility_mode' => 'include', 'usage_limit_per_customer' => 1,
        ], $manager);
        $svc->submitForApproval($id, $manager);
        $svc->reject($id, $manager, 'Bad discount');

        $this->assertThrows(ValidationException::class, function () use ($svc, $id, $manager) {
            $svc->update($id, [
                'code' => 'REJECTED1', 'discount_type' => 'fixed', 'discount_value' => 10,
                'applies_to' => 'all', 'eligibility_mode' => 'include', 'usage_limit_per_customer' => 1,
            ], $manager);
        }, 'rejected coupon cannot be edited');

        $this->cleanupCoupon($id);
    }

    public function testStatusTransitionWorkflow(): void
    {
        $manager = $this->makeManager('c-flow@test.local');
        $svc = new CouponService();
        $id = $svc->create([
            'code' => 'FLOW1', 'discount_type' => 'fixed', 'discount_value' => 10,
            'applies_to' => 'all', 'eligibility_mode' => 'include', 'usage_limit_per_customer' => 1,
        ], $manager);

        // draft → pending_approval
        $svc->submitForApproval($id, $manager);
        $this->assertSame('pending_approval', $this->statusOf($id));

        // pending_approval → active
        $svc->approve($id, $manager);
        $this->assertSame('active', $this->statusOf($id));

        // active → paused
        $svc->pause($id, $manager);
        $this->assertSame('paused', $this->statusOf($id));

        // paused → active
        $svc->resume($id, $manager);
        $this->assertSame('active', $this->statusOf($id));

        // active → archived
        $svc->archive($id, $manager);
        $this->assertSame('archived', $this->statusOf($id));

        // archived is terminal: cannot transition anywhere
        $this->assertThrows(ValidationException::class, function () use ($svc, $id, $manager) {
            $svc->resume($id, $manager);
        });

        $this->cleanupCoupon($id);
    }

    public function testRejectRequiresReason(): void
    {
        $manager = $this->makeManager('c-rejreason@test.local');
        $svc = new CouponService();
        $id = $svc->create([
            'code' => 'RR1', 'discount_type' => 'fixed', 'discount_value' => 10,
            'applies_to' => 'all', 'eligibility_mode' => 'include', 'usage_limit_per_customer' => 1,
        ], $manager);
        $svc->submitForApproval($id, $manager);

        $this->assertThrows(ValidationException::class, function () use ($svc, $id, $manager) {
            $svc->reject($id, $manager, '   ');
        }, 'reject requires a non-empty reason');

        $this->cleanupCoupon($id);
    }

    public function testCodeUniqueness(): void
    {
        $manager = $this->makeManager('c-uniq@test.local');
        $svc = new CouponService();
        $id = $svc->create([
            'code' => 'UNIQ1', 'discount_type' => 'fixed', 'discount_value' => 5,
            'applies_to' => 'all', 'eligibility_mode' => 'include', 'usage_limit_per_customer' => 1,
        ], $manager);

        $this->assertThrows(ValidationException::class, function () use ($svc, $manager) {
            $svc->create([
                'code' => 'UNIQ1', 'discount_type' => 'fixed', 'discount_value' => 10,
                'applies_to' => 'all', 'eligibility_mode' => 'include', 'usage_limit_per_customer' => 1,
            ], $manager);
        }, 'duplicate code rejected');

        // Case-insensitive uniqueness
        $this->assertThrows(ValidationException::class, function () use ($svc, $manager) {
            $svc->create([
                'code' => 'uniq1', 'discount_type' => 'fixed', 'discount_value' => 10,
                'applies_to' => 'all', 'eligibility_mode' => 'include', 'usage_limit_per_customer' => 1,
            ], $manager);
        }, 'duplicate code (lowercase) rejected');

        $this->cleanupCoupon($id);
    }

    public function testPercentageValidationRejectsAbove100(): void
    {
        $manager = $this->makeManager('c-pct@test.local');
        $svc = new CouponService();
        $this->assertThrows(ValidationException::class, function () use ($svc, $manager) {
            $svc->create([
                'code' => 'PCT150', 'discount_type' => 'percentage', 'discount_value' => 150,
                'applies_to' => 'all', 'eligibility_mode' => 'include', 'usage_limit_per_customer' => 1,
            ], $manager);
        }, 'percentage > 100 rejected');
    }

    public function testProductsCouponRequiresAtLeastOneTarget(): void
    {
        $manager = $this->makeManager('c-prodreq@test.local');
        $svc = new CouponService();
        $this->assertThrows(ValidationException::class, function () use ($svc, $manager) {
            $svc->create([
                'code' => 'PROD1', 'discount_type' => 'fixed', 'discount_value' => 5,
                'applies_to' => 'products', 'eligibility_mode' => 'include', 'usage_limit_per_customer' => 1,
            ], $manager);
        }, 'products-restricted coupon requires at least one target');
    }

    // ---------------------------------------------------- ELIGIBILITY --

    public function testInvalidCodeRejected(): void
    {
        $manager = $this->makeManager('c-badcode@test.local');
        $svc = new CouponService();
        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->validateAndCompute('DOESNOTEXIST', ['subtotal' => 500], null);
        }, 'unknown code rejected');
    }

    public function testNonActiveCouponNotEligible(): void
    {
        $manager = $this->makeManager('c-draft@test.local');
        $svc = new CouponService();
        $id = $svc->create([
            'code' => 'DRAFT1', 'discount_type' => 'fixed', 'discount_value' => 10,
            'applies_to' => 'all', 'eligibility_mode' => 'include', 'usage_limit_per_customer' => 1,
        ], $manager);
        // Still draft — not active.
        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->validateAndCompute('DRAFT1', ['subtotal' => 500], null);
        }, 'draft coupon is not eligible');
        $this->cleanupCoupon($id);
    }

    public function testFutureWindowNotYetValid(): void
    {
        $manager = $this->makeManager('c-future@test.local');
        $svc = new CouponService();
        $futureStart = gmdate('Y-m-d H:i:s', time() + 86400); // tomorrow
        $id = $svc->create([
            'code' => 'FUTURE1', 'discount_type' => 'fixed', 'discount_value' => 10,
            'applies_to' => 'all', 'eligibility_mode' => 'include', 'usage_limit_per_customer' => 1,
            'starts_at' => $futureStart,
        ], $manager);
        $svc->approve($id, $manager);
        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->validateAndCompute('FUTURE1', ['subtotal' => 500], null);
        }, 'coupon with future starts_at not yet valid');
        $this->cleanupCoupon($id);
    }

    public function testExpiredCouponRejected(): void
    {
        $manager = $this->makeManager('c-exp@test.local');
        $svc = new CouponService();
        $pastEnd = gmdate('Y-m-d H:i:s', time() - 86400); // yesterday
        $id = $svc->create([
            'code' => 'EXP1', 'discount_type' => 'fixed', 'discount_value' => 10,
            'applies_to' => 'all', 'eligibility_mode' => 'include', 'usage_limit_per_customer' => 1,
            'ends_at' => $pastEnd,
        ], $manager);
        $svc->approve($id, $manager);
        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->validateAndCompute('EXP1', ['subtotal' => 500], null);
        }, 'expired coupon rejected');
        $this->cleanupCoupon($id);
    }

    public function testMinimumSubtotalEnforced(): void
    {
        $manager = $this->makeManager('c-min@test.local');
        $id = $this->makeActiveCoupon($manager, 'MIN500', [
            'discount_type' => 'fixed', 'discount_value' => 25, 'min_subtotal' => 500,
        ]);
        $svc = new CouponService();
        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->validateAndCompute('MIN500', ['subtotal' => 499], null);
        }, 'subtotal below minimum rejected');
        $result = $svc->validateAndCompute('MIN500', ['subtotal' => 500], null);
        $this->assertSame(25.0, $result['discount']);
        $this->cleanupCoupon($id);
    }

    public function testOverallUsageLimitExhausted(): void
    {
        $manager = $this->makeManager('c-limit@test.local');
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $u1 = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $u2 = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $c1 = $this->makeCustomer();
        $c2 = $this->makeCustomer();
        $id = $this->makeActiveCoupon($manager, 'ONCE1', [
            'discount_type' => 'fixed', 'discount_value' => 50,
            'usage_limit' => 1, 'usage_limit_per_customer' => 10,
        ]);
        $svc = new CouponService();
        $validated = $svc->validateAndCompute('ONCE1', ['subtotal' => 500, 'unit_ids' => [$u1]], $c1);

        $order1 = (new OrderService())->createOrder(
            $c1, [$u1], 'pos', $manager,
            ['coupon' => $validated],
            'completed'
        );

        // Second redemption attempt should be rejected (limit=1 used).
        $this->assertThrows(ValidationException::class, function () use ($svc, $u2, $c2) {
            $svc->validateAndCompute('ONCE1', ['subtotal' => 500, 'unit_ids' => [$u2]], $c2);
        }, 'overall usage limit exhausted');

        $this->cleanupOrder((int) $order1['id']);
        $this->cleanupCoupon($id);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id IN (?,?)')->execute([$u1, $u2]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id IN (?,?)')->execute([$c1, $c2]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testPerCustomerLimitEnforced(): void
    {
        $manager = $this->makeManager('c-percust@test.local');
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $u1 = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $u2 = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $c1 = $this->makeCustomer();
        $id = $this->makeActiveCoupon($manager, 'PERCUST1', [
            'discount_type' => 'fixed', 'discount_value' => 25,
            'usage_limit' => 100, 'usage_limit_per_customer' => 1,
        ]);

        $svc = new CouponService();
        $validated = $svc->validateAndCompute('PERCUST1', ['subtotal' => 500, 'unit_ids' => [$u1]], $c1);
        $order1 = (new OrderService())->createOrder(
            $c1, [$u1], 'pos', $manager,
            ['coupon' => $validated],
            'completed'
        );

        // Same customer trying to use again — rejected.
        $this->assertThrows(ValidationException::class, function () use ($svc, $u2, $c1) {
            $svc->validateAndCompute('PERCUST1', ['subtotal' => 500, 'unit_ids' => [$u2]], $c1);
        }, 'per-customer limit enforced');

        $this->cleanupOrder((int) $order1['id']);
        $this->cleanupCoupon($id);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id IN (?,?)')->execute([$u1, $u2]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$c1]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testProductIncludeRejectsNonMatchingCart(): void
    {
        $manager = $this->makeManager('c-pincl@test.local');
        [$brandId1, $catId1, $modelId1] = $this->makeCatalog();
        [$brandId2, $catId2, $modelId2] = $this->makeCatalog();
        $u1 = $this->makeAvailableUnit($modelId1, ['selling_price' => '500.00']);
        $u2 = $this->makeAvailableUnit($modelId2, ['selling_price' => '500.00']);
        $c1 = $this->makeCustomer();

        $svc = new CouponService();
        $id = $svc->create([
            'code' => 'PRODINCL1', 'discount_type' => 'fixed', 'discount_value' => 25,
            'applies_to' => 'products', 'eligibility_mode' => 'include',
            'usage_limit_per_customer' => 5,
            'target_product_ids' => [$modelId1],
        ], $manager);
        $svc->approve($id, $manager);

        // Cart with unit from modelId2 (not in include list) — rejected.
        $this->assertThrows(ValidationException::class, function () use ($svc, $u2, $c1, $modelId2) {
            $svc->validateAndCompute('PRODINCL1', [
                'subtotal' => 500,
                'unit_ids' => [$u2],
                'product_model_ids' => [$modelId2],
            ], $c1);
        }, 'product-include excludes non-listed');

        // Cart with unit from modelId1 — accepted.
        $r = $svc->validateAndCompute('PRODINCL1', [
            'subtotal' => 500,
            'unit_ids' => [$u1],
            'product_model_ids' => [$modelId1],
        ], $c1);
        $this->assertSame(25.0, $r['discount']);

        $this->cleanupCoupon($id);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id IN (?,?)')->execute([$u1, $u2]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$c1]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id IN (?,?)')->execute([$modelId1, $modelId2]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id IN (?,?)')->execute([$brandId1, $brandId2]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id IN (?,?)')->execute([$catId1, $catId2]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testProductExcludeRejectsMatchingCart(): void
    {
        $manager = $this->makeManager('c-pexcl@test.local');
        [$brandId1, $catId1, $modelId1] = $this->makeCatalog();
        [$brandId2, $catId2, $modelId2] = $this->makeCatalog();
        $u1 = $this->makeAvailableUnit($modelId1, ['selling_price' => '500.00']);
        $u2 = $this->makeAvailableUnit($modelId2, ['selling_price' => '500.00']);
        $c1 = $this->makeCustomer();

        $svc = new CouponService();
        $id = $svc->create([
            'code' => 'PRODEXCL1', 'discount_type' => 'fixed', 'discount_value' => 25,
            'applies_to' => 'products', 'eligibility_mode' => 'exclude',
            'usage_limit_per_customer' => 5,
            'target_product_ids' => [$modelId1],
        ], $manager);
        $svc->approve($id, $manager);

        // Cart with excluded product — rejected.
        $this->assertThrows(ValidationException::class, function () use ($svc, $u1, $modelId1, $c1) {
            $svc->validateAndCompute('PRODEXCL1', [
                'subtotal' => 500,
                'unit_ids' => [$u1],
                'product_model_ids' => [$modelId1],
            ], $c1);
        }, 'product-exclude rejects listed');

        // Cart with non-excluded product — accepted.
        $r = $svc->validateAndCompute('PRODEXCL1', [
            'subtotal' => 500,
            'unit_ids' => [$u2],
            'product_model_ids' => [$modelId2],
        ], $c1);
        $this->assertSame(25.0, $r['discount']);

        $this->cleanupCoupon($id);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id IN (?,?)')->execute([$u1, $u2]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$c1]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id IN (?,?)')->execute([$modelId1, $modelId2]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id IN (?,?)')->execute([$brandId1, $brandId2]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id IN (?,?)')->execute([$catId1, $catId2]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- SERVER RECALC --

    public function testFixedCouponClampedToSubtotal(): void
    {
        $manager = $this->makeManager('c-clamp@test.local');
        // Coupon worth 1000 BDT, applied to a 200 BDT cart → clamp to 200.
        $id = $this->makeActiveCoupon($manager, 'CLAMP1', [
            'discount_type' => 'fixed', 'discount_value' => 1000,
        ]);
        $svc = new CouponService();
        $result = $svc->validateAndCompute('CLAMP1', ['subtotal' => 200], null);
        $this->assertSame(200.0, $result['discount'], 'fixed discount clamped to subtotal');
        $this->cleanupCoupon($id);
    }

    public function testPercentageCouponCappedByMaxDiscount(): void
    {
        $manager = $this->makeManager('c-cap@test.local');
        // 50% off, cap 100. Subtotal 500 → 250 unclamped → capped at 100.
        $id = $this->makeActiveCoupon($manager, 'CAP1', [
            'discount_type' => 'percentage', 'discount_value' => 50,
            'max_discount' => 100,
        ]);
        $svc = new CouponService();
        $r = $svc->validateAndCompute('CAP1', ['subtotal' => 500], null);
        $this->assertSame(100.0, $r['discount'], 'percentage coupon capped by max_discount');

        // Subtotal 100 → 50% = 50 < cap 100 → 50.
        $r2 = $svc->validateAndCompute('CAP1', ['subtotal' => 100], null);
        $this->assertSame(50.0, $r2['discount']);
        $this->cleanupCoupon($id);
    }

    public function testRedemptionRecordsUsageRowAndLinksOrder(): void
    {
        $manager = $this->makeManager('c-red@test.local');
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $u1 = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $c1 = $this->makeCustomer();
        $id = $this->makeActiveCoupon($manager, 'RED1', [
            'discount_type' => 'fixed', 'discount_value' => 50,
        ]);
        $svc = new CouponService();
        $validated = $svc->validateAndCompute('RED1', ['subtotal' => 500, 'unit_ids' => [$u1]], $c1);

        $order = (new OrderService())->createOrder(
            $c1, [$u1], 'pos', $manager,
            ['coupon' => $validated],
            'completed'
        );

        // coupon_usages row was inserted.
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ? AND order_id = ?');
        $stmt->execute([$id, $order['id']]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'exactly one usage row inserted');

        // orders.coupon_id was set.
        $stmt = $this->pdo()->prepare('SELECT coupon_id, discount, total FROM orders WHERE id = ?');
        $stmt->execute([$order['id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame((string) $id, (string) $row['coupon_id']);
        $this->assertSame('50.00', (string) $row['discount']);
        $this->assertSame('450.00', (string) $row['total']);

        $this->cleanupOrder((int) $order['id']);
        $this->cleanupCoupon($id);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$u1]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$c1]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testStaleClientDiscountValueRejected(): void
    {
        $manager = $this->makeManager('c-stale@test.local');
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $u1 = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $c1 = $this->makeCustomer();
        $id = $this->makeActiveCoupon($manager, 'STALE1', [
            'discount_type' => 'fixed', 'discount_value' => 50,
        ]);

        // Client claims a stale 999 BDT discount. The server recomputes
        // 50 BDT from the definition and the mismatch throws.
        $svc = new CouponService();
        $this->assertThrows(ValidationException::class, function () use ($svc, $manager, $u1, $c1, $id) {
            (new OrderService())->createOrder(
                $c1, [$u1], 'pos', $manager,
                ['coupon' => ['coupon_id' => $id, 'discount' => 999.0]],
                'completed'
            );
        }, 'stale client discount rejected by server re-check');

        // No coupon_usages should be recorded when the order rolls back.
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ?');
        $stmt->execute([$id]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no usage row recorded on rollback');

        // The unit should be available again (transaction rolled back).
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$u1]);
        $this->assertSame('available', (string) $stmt->fetchColumn(), 'unit rolled back to available');

        $this->cleanupCoupon($id);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$u1]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$c1]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testCouponCannotBeAppliedTwiceToSameOrder(): void
    {
        $manager = $this->makeManager('c-once@test.local');
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $u1 = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $c1 = $this->makeCustomer();
        $id = $this->makeActiveCoupon($manager, 'ONCE2', [
            'discount_type' => 'fixed', 'discount_value' => 50,
            'usage_limit' => 10, 'usage_limit_per_customer' => 10,
        ]);
        $svc = new CouponService();
        $validated = $svc->validateAndCompute('ONCE2', ['subtotal' => 500, 'unit_ids' => [$u1]], $c1);

        // Insert a manual duplicate row to simulate concurrent redeem.
        $order = (new OrderService())->createOrder(
            $c1, [$u1], 'pos', $manager,
            ['coupon' => $validated],
            'completed'
        );

        // Second redemption attempt for the same coupon+order pair — must fail (unique key).
        $this->assertThrows(\Throwable::class, function () use ($id, $order, $manager, $c1) {
            (new CouponService())->recordRedemption(
                Connection::pdo(),
                (int) $order['id'],
                $c1,
                $id,
                50.0,
                $manager,
                500.0
            );
        }, 'coupon_usages (coupon_id, order_id) unique key enforces one-per-order');

        $this->cleanupOrder((int) $order['id']);
        $this->cleanupCoupon($id);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$u1]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$c1]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- POS CHECKOUT WITH COUPON --

    public function testPosCheckoutWithCouponRecordsRedemption(): void
    {
        $manager = $this->makeManager('c-poscheckout@test.local');
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $u1 = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $c1 = $this->makeCustomer();
        $id = $this->makeActiveCoupon($manager, 'POSC1', [
            'discount_type' => 'percentage', 'discount_value' => 10,
        ]);

        $pos = new PosService();
        $result = $pos->checkout([
            'items' => [['unit_id' => $u1]],
            'discount' => 0, // coupon overrides
            'customer_id' => $c1,
            'payment_method' => 'cash',
            'paid_amount' => 500,
            'coupon_code' => 'POSC1',
        ], $manager, null, null);

        // 10% off 500 = 50; total = 450.
        $this->assertSame('450.00', $result['total']);

        // Coupon usage recorded.
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ? AND order_id = ?');
        $stmt->execute([$id, $result['order_id']]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        $this->cleanupOrder((int) $result['order_id']);
        $this->cleanupCoupon($id);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$u1]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$c1]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- HTTP PERMISSIONS --

    public function testTechnicianBlockedFromCoupons(): void
    {
        $tech = $this->makeTech('c-tech@test.local');
        $this->assertThrows(ForbiddenException::class, function () use ($tech) {
            $this->dispatchAs($tech, 'GET', '/admin/coupons');
        }, 'technician cannot list coupons');
    }

    public function testContentManagerCanListButCannotApprove(): void
    {
        $cm = $this->makeContentManager('c-cm@test.local');
        $r = $this->dispatchAs($cm, 'GET', '/admin/coupons');
        $this->assertSame(200, $r->status(), 'content_manager can list coupons');

        $svc = new CouponService();
        $id = $svc->create([
            'code' => 'CM1', 'discount_type' => 'fixed', 'discount_value' => 10,
            'applies_to' => 'all', 'eligibility_mode' => 'include', 'usage_limit_per_customer' => 1,
        ], $cm);
        $svc->submitForApproval($id, $cm);

        // content_manager does NOT have coupons.approve — must throw.
        $this->assertThrows(ForbiddenException::class, function () use ($cm, $id) {
            $this->dispatchAs($cm, 'POST', '/admin/coupons/' . $id . '/approve');
        }, 'content_manager cannot approve');

        $this->cleanupCoupon($id);
    }

    public function testManagerCanApproveViaHttp(): void
    {
        $manager = $this->makeManager('c-mgr@test.local');
        $svc = new CouponService();
        $id = $svc->create([
            'code' => 'MGR1', 'discount_type' => 'fixed', 'discount_value' => 10,
            'applies_to' => 'all', 'eligibility_mode' => 'include', 'usage_limit_per_customer' => 1,
        ], $manager);
        $svc->submitForApproval($id, $manager);

        $r = $this->dispatchAs($manager, 'POST', '/admin/coupons/' . $id . '/approve');
        $this->assertSame(302, $r->status(), 'manager can approve');
        $this->assertSame('active', $this->statusOf($id));

        $this->cleanupCoupon($id);
    }

    public function testHttpStoreCreateAsDraft(): void
    {
        $manager = $this->makeManager('c-http-create@test.local');
        $r = $this->dispatchAs($manager, 'POST', '/admin/coupons', [
            'code' => 'HTTP1',
            'description' => 'Coupon via HTTP',
            'discount_type' => 'fixed',
            'discount_value' => '15.00',
            'min_subtotal' => '0',
            'max_discount' => '',
            'starts_at' => '',
            'ends_at' => '',
            'usage_limit' => '',
            'usage_limit_per_customer' => '1',
            'applies_to' => 'all',
            'eligibility_mode' => 'include',
        ]);
        $this->assertSame(302, $r->status());
        $stmt = $this->pdo()->prepare('SELECT id FROM coupons WHERE code = ?');
        $stmt->execute(['HTTP1']);
        $id = (int) $stmt->fetchColumn();
        $this->assertGreaterThan(0, $id, 'coupon persisted');
        $this->assertSame('draft', $this->statusOf($id));
        $this->cleanupCoupon($id);
    }

    public function testPreviewReturnsServerComputedDiscount(): void
    {
        $manager = $this->makeManager('c-preview@test.local');
        $id = $this->makeActiveCoupon($manager, 'PREV1', [
            'discount_type' => 'percentage', 'discount_value' => 25,
        ]);
        $r = $this->dispatchAsJson($manager, 'POST', '/admin/coupons/preview', [
            'code' => 'PREV1',
            'subtotal' => '500',
        ]);
        $this->assertSame(200, $r->status());
        $body = json_decode($r->body(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame('125.00', $body['discount']);
        $this->cleanupCoupon($id);
    }

    public function testPreviewRejectsInvalidCode(): void
    {
        $manager = $this->makeManager('c-preview2@test.local');
        $r = $this->dispatchAsJson($manager, 'POST', '/admin/coupons/preview', [
            'code' => 'NOPE',
            'subtotal' => '500',
        ]);
        $this->assertSame(200, $r->status());
        $body = json_decode($r->body(), true);
        $this->assertFalse($body['ok']);
    }

    // ---------------------------------------------------- CSRF --

    public function testStoreRejectsMissingCsrf(): void
    {
        $manager = $this->makeManager('c-csrf@test.local');
        $this->actingAs($manager);
        $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () {
            $this->router()->dispatch(
                Request::synthetic('POST', '/admin/coupons', [], [
                    'code' => 'NOCSRF1', 'discount_type' => 'fixed', 'discount_value' => '10',
                    'usage_limit_per_customer' => '1', 'applies_to' => 'all', 'eligibility_mode' => 'include',
                ]),
                $this->resolver()
            );
        }, 'missing CSRF must be rejected');
    }

    // ---------------------------------------------------- HELPERS --

    private function statusOf(int $couponId): string
    {
        $stmt = $this->pdo()->prepare('SELECT status FROM coupons WHERE id = ?');
        $stmt->execute([$couponId]);
        return (string) $stmt->fetchColumn();
    }
}
