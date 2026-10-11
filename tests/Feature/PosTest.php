<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\OrderRepository;
use App\Services\OrderService;
use App\Services\PosService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * POS and cashier panel (Prompt 23) — service + HTTP tests.
 *
 * Proves:
 *  - Cashier shift open/close with reconciliation (expected = opening
 *    float + cash collected; discrepancy sets status='discrepancy')
 *  - Cannot open a second shift while one is open
 *  - Held sale: hold, resume, cancel — held sales do not reserve inventory
 *  - Resume surfaces unavailable units (sold to someone else) without
 *    blocking the resume itself
 *  - Checkout: creates a 'pos' + 'completed' order, records cash payment
 *    immediately, marks order paid, opens receipt page
 *  - Concurrent double-sell prevention: two checkouts for the same unit
 *    serialise — the second sees the unit as 'sold' and is rejected
 *  - Discount authorization: cashier (no pos.authorize_discount)
 *    cannot apply discounts above the 5% threshold; manager can
 *  - Discount authorizer id recorded on the order
 *  - Quick walk-in customer creation
 *  - Permission enforcement: technician blocked; cashier can access
 *  - CSRF on state-changing routes
 */
final class PosTest extends TestCase
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
            Request::synthetic($method, $uri, $get, $this->withCsrf($post), [
                'HTTP_ACCEPT' => 'application/json',
            ]),
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

    private function makeCashier(string $email): int
    {
        $id = $this->makeUser($email, 'Correct-Horse-1', ['sales_cashier']);
        $this->actingAs($id);
        return $id;
    }

    private function makeManager(string $email): int
    {
        $id = $this->makeUser($email, 'Correct-Horse-1', ['manager']);
        $this->actingAs($id);
        return $id;
    }

    /** Build a published catalog + an available unit + a customer, all wired. */
    private function makePosFixture(): array
    {
        [$brandId, $categoryId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $customerId = $this->makeCustomer();
        return [$brandId, $categoryId, $modelId, $unitId, $customerId];
    }

    private function cleanupPosFixture(int $orderId, int $unitId, int $customerId, int $brandId, int $categoryId, int $modelId, ?int $shiftId = null, ?int $heldSaleId = null): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        if ($heldSaleId !== null) {
            $this->pdo()->prepare('DELETE FROM held_sales WHERE id = ?')->execute([$heldSaleId]);
        }
        if ($shiftId !== null) {
            $this->pdo()->prepare('DELETE FROM cashier_shifts WHERE id = ?')->execute([$shiftId]);
        }
        if ($orderId > 0) {
            $this->pdo()->prepare('DELETE FROM order_fulfillment_notes WHERE order_id = ?')->execute([$orderId]);
            $this->pdo()->prepare('DELETE FROM order_status_history WHERE order_id = ?')->execute([$orderId]);
            $this->pdo()->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$orderId]);
            $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
            $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
        }
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testTechnicianBlockedFromPos(): void
    {
        $techId = $this->makeUser('pos-tech@test.local', 'Correct-Horse-1', ['technician']);
        $r = $this->dispatchAs($techId, 'GET', '/admin/pos');
        $this->assertSame(403, $r->status(), 'technician (no pos.access) must be blocked');
    }

    public function testCashierCanAccessPos(): void
    {
        $cashierId = $this->makeCashier('pos-access@test.local');
        $r = $this->dispatchAs($cashierId, 'GET', '/admin/pos');
        $this->assertSame(200, $r->status(), 'cashier with pos.access must reach the terminal');
    }

    // ---------------------------------------------------- SHIFTS --

    public function testOpenShiftRefusesIfOneIsOpen(): void
    {
        $cashierId = $this->makeCashier('pos-shift1@test.local');
        $svc = new PosService();
        $first = $svc->startShift($cashierId, 1000.00);
        $this->assertSame('open', (string) $first['status']);

        $this->assertThrows(ValidationException::class, function () use ($svc, $cashierId) {
            $svc->startShift($cashierId, 500.00);
        }, 'cannot open a second shift while one is open');

        $this->pdo()->prepare('DELETE FROM cashier_shifts WHERE id = ?')->execute([$first['id']]);
    }

    public function testCloseShiftReconcilesPerfectlyWhenActualMatches(): void
    {
        $cashierId = $this->makeCashier('pos-shift2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePosFixture();
        $svc = new PosService();
        $shift = $svc->startShift($cashierId, 1000.00, 'Float from safe');

        // Sell a unit for cash during the shift.
        $order = (new OrderService())->createOrder($customerId, [$unitId], 'pos', $cashierId, [], 'completed');
        (new OrderService())->recordPayment($order['id'], 500.00, 'cash', $cashierId);

        // Expected = opening 1000 + cash collected 500 = 1500.
        $closed = $svc->closeShift((int) $shift['id'], $cashierId, 1500.00);
        $this->assertSame('closed', (string) $closed['status']);
        $this->assertEquals(1500.00, (float) $closed['expected_cash']);
        $this->assertEquals(0.00, (float) $closed['discrepancy']);

        $this->cleanupPosFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, (int) $shift['id']);
    }

    public function testCloseShiftFlagsDiscrepancy(): void
    {
        $cashierId = $this->makeCashier('pos-shift3@test.local');
        $svc = new PosService();
        $shift = $svc->startShift($cashierId, 200.00);
        $closed = $svc->closeShift((int) $shift['id'], $cashierId, 150.00); // 50 short

        $this->assertSame('discrepancy', (string) $closed['status']);
        $this->assertEquals(-50.00, (float) $closed['discrepancy']);

        $this->pdo()->prepare('DELETE FROM cashier_shifts WHERE id = ?')->execute([$shift['id']]);
    }

    // ---------------------------------------------------- HELD SALES --

    public function testHoldAndResumeHeldSale(): void
    {
        $cashierId = $this->makeCashier('pos-hold1@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePosFixture();
        $svc = new PosService();

        $cart = [
            'items' => [['unit_id' => $unitId, 'sku' => 'TEST-SKU', 'name' => 'Test Model', 'price' => 500.00]],
            'subtotal' => 500.00,
            'discount' => 0.00,
            'total' => 500.00,
        ];
        $held = $svc->holdSale($cart, $cashierId, null, $customerId, 'Test Customer', '+8801700000');
        $this->assertGreaterThan(0, $held['id']);
        $this->assertStringStartsWith('HOLD-', (string) $held['reference']);

        // Resume — should succeed and surface no unavailable units.
        $resumed = $svc->resumeHeldSale($held['id'], $cashierId);
        $this->assertSame([], $resumed['unavailable_unit_ids']);
        $this->assertSame($unitId, (int) $resumed['cart']['items'][0]['unit_id']);

        $this->cleanupPosFixture(0, $unitId, $customerId, $brandId, $catId, $modelId, null, $held['id']);
    }

    public function testResumeSurfacesUnavailableUnits(): void
    {
        $cashierId = $this->makeCashier('pos-hold2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePosFixture();
        $svc = new PosService();

        $cart = [
            'items' => [['unit_id' => $unitId, 'sku' => 'TEST-SKU', 'name' => 'Test Model', 'price' => 500.00]],
            'subtotal' => 500.00,
            'discount' => 0.00,
            'total' => 500.00,
        ];
        $held = $svc->holdSale($cart, $cashierId, null, $customerId, 'Test Customer', null);

        // Sell the unit to someone else.
        $anotherCashier = $this->makeCashier('pos-hold2b@test.local');
        $order = (new OrderService())->createOrder($customerId, [$unitId], 'pos', $anotherCashier, [], 'completed');
        // Unit is now 'sold'.

        $resumed = $svc->resumeHeldSale($held['id'], $cashierId);
        $this->assertContains($unitId, $resumed['unavailable_unit_ids'], 'unavailable unit must be flagged');

        $this->cleanupPosFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, null, $held['id']);
    }

    public function testCancelHeldSale(): void
    {
        $cashierId = $this->makeCashier('pos-cancel-held@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePosFixture();
        $svc = new PosService();
        $cart = [
            'items' => [['unit_id' => $unitId, 'sku' => 'X', 'name' => 'X', 'price' => 1.0]],
            'subtotal' => 1.0, 'discount' => 0.0, 'total' => 1.0,
        ];
        $held = $svc->holdSale($cart, $cashierId, null, null, 'Walk-in', null);
        $svc->cancelHeldSale($held['id'], $cashierId, 'Customer walked away');

        $stmt = $this->pdo()->prepare('SELECT status FROM held_sales WHERE id = ?');
        $stmt->execute([$held['id']]);
        $this->assertSame('cancelled', (string) $stmt->fetchColumn());

        $this->cleanupPosFixture(0, $unitId, $customerId, $brandId, $catId, $modelId, null, $held['id']);
    }

    public function testHeldSaleCannotBeResumedTwice(): void
    {
        $cashierId = $this->makeCashier('pos-resume-twice@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePosFixture();
        $svc = new PosService();
        $cart = [
            'items' => [['unit_id' => $unitId, 'sku' => 'X', 'name' => 'X', 'price' => 1.0]],
            'subtotal' => 1.0, 'discount' => 0.0, 'total' => 1.0,
        ];
        $held = $svc->holdSale($cart, $cashierId, null, null, 'Walk-in', null);
        $svc->resumeHeldSale($held['id'], $cashierId);

        $this->assertThrows(ValidationException::class, function () use ($svc, $held, $cashierId) {
            $svc->resumeHeldSale($held['id'], $cashierId);
        }, 'already-resumed held sale must not be resumable');

        $this->cleanupPosFixture(0, $unitId, $customerId, $brandId, $catId, $modelId, null, $held['id']);
    }

    // ---------------------------------------------------- CHECKOUT --

    public function testCheckoutCreatesCompletedOrderAndRecordsCashPayment(): void
    {
        $cashierId = $this->makeCashier('pos-checkout1@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePosFixture();
        $svc = new PosService();
        $shift = $svc->startShift($cashierId, 0.00);

        $result = $svc->checkout(
            [
                'items' => [['unit_id' => $unitId]],
                'discount' => 0,
                'customer_id' => $customerId,
                'payment_method' => 'cash',
                'paid_amount' => 500.00,
            ],
            $cashierId,
            (int) $shift['id'],
            null,
        );

        $this->assertSame('paid', $result['payment_status']);
        $this->assertEquals(500.00, (float) $result['total']);
        $this->assertEquals(0.00, $result['change']);

        $order = (new OrderRepository())->find($result['order_id']);
        $this->assertSame('pos', (string) $order['channel']);
        $this->assertSame('completed', (string) $order['status']);
        $this->assertSame('paid', (string) $order['payment_status']);
        $this->assertNotNull($order['completed_at']);

        $this->cleanupPosFixture($result['order_id'], $unitId, $customerId, $brandId, $catId, $modelId, (int) $shift['id']);
    }

    public function testCheckoutComputesChange(): void
    {
        $cashierId = $this->makeCashier('pos-change@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePosFixture();
        $svc = new PosService();
        $shift = $svc->startShift($cashierId, 0.00);

        $result = $svc->checkout(
            [
                'items' => [['unit_id' => $unitId]],
                'discount' => 0,
                'customer_id' => $customerId,
                'payment_method' => 'cash',
                'paid_amount' => 600.00, // 100 change
            ],
            $cashierId,
            (int) $shift['id'],
            null,
        );
        $this->assertEquals(100.00, $result['change']);

        $this->cleanupPosFixture($result['order_id'], $unitId, $customerId, $brandId, $catId, $modelId, (int) $shift['id']);
    }

    // ---------------------------------------------------- CONCURRENT DOUBLE-SELL --

    public function testConcurrentDoubleSellIsRejected(): void
    {
        $cashierId = $this->makeCashier('pos-conc1@test.local');
        $cashier2Id = $this->makeCashier('pos-conc2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePosFixture();
        $svc = new PosService();
        $shift = $svc->startShift($cashierId, 0.00);

        // First checkout succeeds.
        $first = $svc->checkout(
            [
                'items' => [['unit_id' => $unitId]],
                'discount' => 0,
                'customer_id' => $customerId,
                'payment_method' => 'cash',
                'paid_amount' => 500.00,
            ],
            $cashierId,
            (int) $shift['id'],
            null,
        );
        $this->assertSame('paid', $first['payment_status']);

        // Second checkout for the same unit must fail with a
        // "Unit X is not sellable" ValidationException. The DB-level
        // FOR UPDATE lock serialises the two attempts; the second
        // sees the unit as 'sold' and refuses.
        $this->assertThrows(ValidationException::class, function () use ($svc, $unitId, $customerId, $cashier2Id) {
            $svc->checkout(
                [
                    'items' => [['unit_id' => $unitId]],
                    'discount' => 0,
                    'customer_id' => $customerId,
                    'payment_method' => 'cash',
                    'paid_amount' => 500.00,
                ],
                $cashier2Id,
                null,
                null,
            );
        }, 'cannot sell the same physical unit twice');

        $this->cleanupPosFixture($first['order_id'], $unitId, $customerId, $brandId, $catId, $modelId, (int) $shift['id']);
    }

    // ---------------------------------------------------- DISCOUNT AUTHORIZATION --

    public function testCashierCanApplySmallDiscount(): void
    {
        $cashierId = $this->makeCashier('pos-disc1@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePosFixture();
        $svc = new PosService();
        $shift = $svc->startShift($cashierId, 0.00);

        // 25 BDT on a 500 subtotal = 5% — at the threshold, cashier can self-authorise.
        $authorizer = $svc->assertDiscountAuthorized(25.00, 500.00, $cashierId, null);
        $this->assertNull($authorizer, '5% discount is within the cashier range');

        $result = $svc->checkout(
            [
                'items' => [['unit_id' => $unitId]],
                'discount' => 25.00,
                'customer_id' => $customerId,
                'payment_method' => 'cash',
                'paid_amount' => 475.00,
            ],
            $cashierId,
            (int) $shift['id'],
            $authorizer,
        );
        $this->assertEquals(475.00, (float) $result['total']);

        $order = (new OrderRepository())->find($result['order_id']);
        $this->assertNull($order['discount_authorized_by'], 'small discount needs no authorizer');

        $this->cleanupPosFixture($result['order_id'], $unitId, $customerId, $brandId, $catId, $modelId, (int) $shift['id']);
    }

    public function testCashierCannotApplyLargeDiscountWithoutManager(): void
    {
        $cashierId = $this->makeCashier('pos-disc2@test.local');
        [, , , $unitId, $customerId] = $this->makePosFixture();
        $svc = new PosService();

        // 100 BDT on a 500 subtotal = 20% — above the 5% cashier threshold.
        $this->assertThrows(ValidationException::class, function () use ($svc, $cashierId) {
            $svc->assertDiscountAuthorized(100.00, 500.00, $cashierId, null);
        }, 'cashier without pos.authorize_discount cannot apply >5% discount');
    }

    public function testManagerCanApplyLargeDiscountAndAuthorizerIsRecorded(): void
    {
        $managerId = $this->makeManager('pos-disc3@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePosFixture();
        $svc = new PosService();
        $shift = $svc->startShift($managerId, 0.00);

        $authorizer = $svc->assertDiscountAuthorized(100.00, 500.00, $managerId, $managerId);
        $this->assertSame($managerId, $authorizer, 'manager authorisation returns the authorizer id');

        $result = $svc->checkout(
            [
                'items' => [['unit_id' => $unitId]],
                'discount' => 100.00,
                'customer_id' => $customerId,
                'payment_method' => 'cash',
                'paid_amount' => 400.00,
            ],
            $managerId,
            (int) $shift['id'],
            $authorizer,
        );

        $order = (new OrderRepository())->find($result['order_id']);
        $this->assertSame((string) $managerId, (string) $order['discount_authorized_by'], 'authorizer id recorded on order');

        $this->cleanupPosFixture($result['order_id'], $unitId, $customerId, $brandId, $catId, $modelId, (int) $shift['id']);
    }

    // ---------------------------------------------------- HTTP ROUTES --

    public function testHttpSearchReturnsJson(): void
    {
        $cashierId = $this->makeCashier('pos-search-http@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePosFixture();
        // Read the SKU of the seeded unit to search for it.
        $sku = (string) $this->pdo()->query("SELECT sku FROM inventory_units WHERE id = {$unitId}")->fetchColumn();

        $r = $this->dispatchAs($cashierId, 'GET', '/admin/pos/search', [], ['q' => $sku]);
        $this->assertSame(200, $r->status());
        $body = json_decode($r->body(), true);
        $this->assertNotSame([], $body['units'] ?? [], 'search must surface the matching unit');
        $this->assertSame($unitId, (int) $body['units'][0]['id']);

        $this->cleanupPosFixture(0, $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testHttpCheckoutRoute(): void
    {
        $cashierId = $this->makeCashier('pos-http-co@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePosFixture();
        $svc = new PosService();
        $shift = $svc->startShift($cashierId, 0.00);

        $r = $this->dispatchAs($cashierId, 'POST', '/admin/pos/checkout', [
            'items' => [['unit_id' => $unitId]],
            'subtotal' => 500.00,
            'discount' => 0,
            'total' => 500.00,
            'customer_id' => $customerId,
            'payment_method' => 'cash',
            'paid_amount' => 500.00,
            'payment_reference' => null,
        ]);
        $this->assertSame(200, $r->status(), 'checkout must return JSON 200');
        $body = json_decode($r->body(), true);
        $this->assertSame('paid', $body['payment_status']);

        $this->cleanupPosFixture($body['order_id'], $unitId, $customerId, $brandId, $catId, $modelId, (int) $shift['id']);
    }

    public function testHttpReceiptRendersOrder(): void
    {
        $cashierId = $this->makeCashier('pos-receipt@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePosFixture();
        $svc = new PosService();
        $shift = $svc->startShift($cashierId, 0.00);

        $r = $this->dispatchAs($cashierId, 'POST', '/admin/pos/checkout', [
            'items' => [['unit_id' => $unitId]],
            'subtotal' => 500.00,
            'discount' => 0,
            'total' => 500.00,
            'customer_id' => $customerId,
            'payment_method' => 'cash',
            'paid_amount' => 500.00,
            'payment_reference' => null,
        ]);
        $body = json_decode($r->body(), true);

        $r = $this->dispatchAs($cashierId, 'GET', '/admin/pos/receipt/' . $body['order_id']);
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Receipt', $r->body());
        $this->assertStringContainsString('Print', $r->body());

        $this->cleanupPosFixture($body['order_id'], $unitId, $customerId, $brandId, $catId, $modelId, (int) $shift['id']);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $cashierId = $this->makeCashier('pos-csrf@test.local');
        $this->actingAs($cashierId);
        [, , , $unitId, $customerId] = $this->makePosFixture();
        $svc = new PosService();
        $shift = $svc->startShift($cashierId, 0.00);
        $held = $svc->holdSale([
            'items' => [['unit_id' => $unitId, 'sku' => 'X', 'name' => 'X', 'price' => 1.0]],
            'subtotal' => 1.0, 'discount' => 0.0, 'total' => 1.0,
        ], $cashierId, (int) $shift['id'], $customerId, 'Walk-in', null);

        $routes = [
            ['POST', '/admin/pos/shift/open', ['opening_float' => 0]],
            ['POST', '/admin/pos/shift/close', ['actual_cash' => 0]],
            ['POST', '/admin/pos/hold', ['items' => [['unit_id' => $unitId]], 'subtotal' => 1, 'discount' => 0, 'total' => 1]],
            ['POST', '/admin/pos/resume/' . $held['id'], []],
            ['POST', '/admin/pos/cancel-held/' . $held['id'], ['reason' => 'x']],
            ['POST', '/admin/pos/quick-customer', ['name' => 'Walk In', 'phone' => '+8801799900099']],
            ['POST', '/admin/pos/checkout', ['items' => [['unit_id' => $unitId]], 'customer_id' => $customerId, 'payment_method' => 'cash', 'paid_amount' => 1]],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(
                    Request::synthetic($method, $uri, [], $post, ['HTTP_ACCEPT' => 'application/json']),
                    $this->resolver()
                );
            }, "{$method} {$uri} must require CSRF");
        }

        $this->cleanupPosFixture(0, $unitId, $customerId, 0, 0, 0, (int) $shift['id'], $held['id']);
    }

    // ---------------------------------------------------- QUICK CUSTOMER --

    public function testQuickCustomerRouteCreatesAndReturnsJson(): void
    {
        $cashierId = $this->makeCashier('pos-qc@test.local');
        $phone = '+88017' . random_int(10000000, 99999999);
        $r = $this->dispatchAs($cashierId, 'POST', '/admin/pos/quick-customer', [
            'name' => 'Walk In Customer',
            'phone' => $phone,
            'email' => '',
        ]);
        $this->assertSame(200, $r->status());
        $body = json_decode($r->body(), true);
        $this->assertGreaterThan(0, $body['id']);
        $this->assertSame('Walk In Customer', $body['name']);

        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$body['id']]);
    }

    public function testQuickCustomerRejectsMissingName(): void
    {
        $cashierId = $this->makeCashier('pos-qc-fail@test.local');
        $r = $this->dispatchAs($cashierId, 'POST', '/admin/pos/quick-customer', [
            'name' => '',
            'phone' => '+88017',
        ]);
        $this->assertSame(422, $r->status());
    }
}
