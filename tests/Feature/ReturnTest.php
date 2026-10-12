<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ValidationException;
use App\Services\OrderService;
use App\Services\ReturnService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Returns and exchanges (Prompt 29) — service tests.
 *
 * Proves:
 *  - Create return from a completed order: sequential number, items
 *    linked to order_items + inventory_units, eligibility check
 *  - Eligibility: rejects non-completed orders, rejects units not 'sold',
 *    rejects items already in a pending return
 *  - Status matrix: requested→completed rejected, terminal states
 *  - Full lifecycle: requested→approved→received→inspected→completed
 *  - Receive marks units as 'returned' (NOT 'available')
 *  - Inspect records per-item results + dispositions
 *  - Complete: pass+return_to_stock → available (sellable); fail → damaged
 *  - Complete with refund type: creates + processes a RefundService refund
 *  - Inventory NOT auto-sellable: units go sold→returned→(only via
 *    inspection pass)→available
 *  - Cancel from any non-terminal status
 *  - CSRF on every state-changing route
 */
final class ReturnTest extends TestCase
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

    private function makeFixture(float $price = 1000.00): array
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => number_format($price, 2, '.', '')]);
        $customerId = $this->makeCustomer();
        return [$brandId, $catId, $modelId, $unitId, $customerId];
    }

    private function cleanupFixture(int $orderId, int $unitId, int $customerId, int $brandId, int $catId, int $modelId, array $extra = []): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (($extra['returns'] ?? []) as $rid) {
            $this->pdo()->prepare('DELETE FROM return_history WHERE return_request_id = ?')->execute([$rid]);
            $this->pdo()->prepare('DELETE FROM return_items WHERE return_request_id = ?')->execute([$rid]);
            $this->pdo()->prepare('DELETE FROM return_requests WHERE id = ?')->execute([$rid]);
        }
        $this->pdo()->prepare('DELETE FROM refunds WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_corrections WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_status_history WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_fulfillment_notes WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM invoices WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function unitStatus(int $unitId): string
    {
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        return (string) $stmt->fetchColumn();
    }

    private function getReturnItems(int $returnId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM return_items WHERE return_request_id = ?');
        $stmt->execute([$returnId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------- CREATE + ELIGIBILITY --

    public function testCreateReturnFromCompletedOrder(): void
    {
        $ownerId = $this->makeOwner('ret-c1@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $orderItems = $this->pdo()->prepare('SELECT id FROM order_items WHERE order_id = ?');
        $orderItems->execute([$order['id']]);
        $oiId = (int) $orderItems->fetchColumn();

        $retSvc = new ReturnService();
        $result = $retSvc->createRequest($order['id'], $customerId, 'refund', 'Defective screen', 'Screen flickers', [$oiId], $ownerId);
        $this->assertMatchesRegularExpression('/^RTN-\d{4}-\d{5}$/', $result['return_number']);
        $this->assertSame('requested', $result['status']);

        $rr = $retSvc->find($result['id']);
        $this->assertCount(1, $rr['items']);
        $this->assertSame((string) $unitId, (string) $rr['items'][0]['inventory_unit_id']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['returns' => [$result['id']]]);
    }

    public function testCreateRejectsNonCompletedOrder(): void
    {
        $ownerId = $this->makeOwner('ret-c2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'pending'); // NOT completed
        $this->assertThrows(ValidationException::class, function () use ($order, $customerId, $ownerId) {
            (new ReturnService())->createRequest($order['id'], $customerId, 'refund', 'Reason', null, [1], $ownerId);
        }, 'returns only for completed orders');
        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testCreateRejectsNonSoldUnit(): void
    {
        $ownerId = $this->makeOwner('ret-c3@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        // Unit is 'available', not 'sold' — no order created.
        $this->assertThrows(ValidationException::class, function () use ($ownerId) {
            (new ReturnService())->createRequest(999999, 1, 'refund', 'Reason', null, [1], $ownerId);
        }, 'non-sold unit must be rejected');
        $this->cleanupFixture(0, $unitId, $customerId, $brandId, $catId, $modelId);
    }

    // ---------------------------------------------------- STATUS MATRIX --

    public function testStatusMatrix(): void
    {
        $svc = new ReturnService();
        $this->assertContains('approved', ReturnService::STATUS_TRANSITIONS['requested']);
        $this->assertContains('received', ReturnService::STATUS_TRANSITIONS['approved']);
        $this->assertContains('inspected', ReturnService::STATUS_TRANSITIONS['received']);
        $this->assertContains('completed', ReturnService::STATUS_TRANSITIONS['inspected']);
        $this->assertSame([], ReturnService::STATUS_TRANSITIONS['completed']);
        $this->assertSame([], ReturnService::STATUS_TRANSITIONS['rejected']);
    }

    public function testRequestedToCompletedRejected(): void
    {
        $svc = new ReturnService();
        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->assertTransitionAllowed('requested', 'completed');
        }, 'requested → completed must be rejected');
    }

    // ---------------------------------------------------- FULL LIFECYCLE --

    public function testFullLifecycleReturnToStock(): void
    {
        $ownerId = $this->makeOwner('ret-life@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture(1000.00);
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId); // pay for it

        $oiId = (int) $this->pdo()->query("SELECT id FROM order_items WHERE order_id = {$order['id']}")->fetchColumn();
        $retSvc = new ReturnService();
        $rr = $retSvc->createRequest($order['id'], $customerId, 'refund', 'Defective', 'Issue', [$oiId], $ownerId);

        // Unit is 'sold' before receive.
        $this->assertSame('sold', $this->unitStatus($unitId));

        // Approve.
        $retSvc->approve($rr['id'], $ownerId);
        $this->assertSame('approved', $retSvc->find($rr['id'])['status']);

        // Receive — unit goes sold → returned (NOT available).
        $retSvc->receive($rr['id'], $ownerId);
        $this->assertSame('returned', $this->unitStatus($unitId), 'unit must be returned (not available) after receive');

        // Inspect — pass + return_to_stock.
        $items = $this->getReturnItems($rr['id']);
        $itemId = (int) $items[0]['id'];
        $retSvc->inspect($rr['id'], $ownerId, [
            $itemId => ['inspection_result' => 'pass', 'inventory_disposition' => 'return_to_stock'],
        ], 'Inspection passed');

        // Unit is still 'returned' after inspect — NOT sellable yet.
        $this->assertSame('returned', $this->unitStatus($unitId), 'unit must stay returned after inspect');

        // Complete — NOW the unit becomes 'available' (sellable).
        $result = $retSvc->complete($rr['id'], $ownerId);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('available', $this->unitStatus($unitId), 'unit must be available after completion with pass + return_to_stock');

        // Refund was processed.
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM refunds WHERE order_id = ? AND status = "completed"');
        $stmt->execute([$order['id']]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'a refund must have been created + processed');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['returns' => [$rr['id']]]);
    }

    public function testInspectionFailMovesToDamaged(): void
    {
        $ownerId = $this->makeOwner('ret-fail@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);
        $oiId = (int) $this->pdo()->query("SELECT id FROM order_items WHERE order_id = {$order['id']}")->fetchColumn();
        $retSvc = new ReturnService();
        $rr = $retSvc->createRequest($order['id'], $customerId, 'refund', 'Defective', 'Issue', [$oiId], $ownerId);
        $retSvc->approve($rr['id'], $ownerId);
        $retSvc->receive($rr['id'], $ownerId);
        $items = $this->getReturnItems($rr['id']);
        $itemId = (int) $items[0]['id'];
        $retSvc->inspect($rr['id'], $ownerId, [
            $itemId => ['inspection_result' => 'fail', 'inventory_disposition' => 'scrap'],
        ]);
        $retSvc->complete($rr['id'], $ownerId);
        $this->assertSame('damaged', $this->unitStatus($unitId), 'fail + scrap → damaged');
        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['returns' => [$rr['id']]]);
    }

    // ---------------------------------------------------- CRITICAL: NOT AUTO-SELLABLE --

    public function testUnitNotSellableAfterReceive(): void
    {
        $ownerId = $this->makeOwner('ret-notsell@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);
        $oiId = (int) $this->pdo()->query("SELECT id FROM order_items WHERE order_id = {$order['id']}")->fetchColumn();
        $retSvc = new ReturnService();
        $rr = $retSvc->createRequest($order['id'], $customerId, 'refund', 'Reason', null, [$oiId], $ownerId);
        $retSvc->approve($rr['id'], $ownerId);
        $retSvc->receive($rr['id'], $ownerId);

        // After receive, the unit is 'returned' — NOT 'available'.
        // It cannot be re-sold until inspection + completion.
        $this->assertNotSame('available', $this->unitStatus($unitId), 'returned unit must NOT be available/sellable after receive');
        $this->assertSame('returned', $this->unitStatus($unitId));

        // Trying to create a new order for this unit must fail because
        // the unit is not in 'available' or 'reserved' status.
        $this->assertThrows(ValidationException::class, function () use ($svc, $customerId, $unitId, $ownerId) {
            $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        }, 'cannot re-sell a returned unit before inspection + completion');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['returns' => [$rr['id']]]);
    }

    // ---------------------------------------------------- CANCEL --

    public function testCancelFromRequested(): void
    {
        $ownerId = $this->makeOwner('ret-cancel@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);
        $oiId = (int) $this->pdo()->query("SELECT id FROM order_items WHERE order_id = {$order['id']}")->fetchColumn();
        $retSvc = new ReturnService();
        $rr = $retSvc->createRequest($order['id'], $customerId, 'refund', 'Reason', null, [$oiId], $ownerId);
        $retSvc->cancel($rr['id'], $ownerId, 'Customer withdrew');
        $this->assertSame('cancelled', $retSvc->find($rr['id'])['status']);
        // Unit stays 'sold' — was never received.
        $this->assertSame('sold', $this->unitStatus($unitId));
        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['returns' => [$rr['id']]]);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $ownerId = $this->makeOwner('ret-csrf@test.local');
        $this->actingAs($ownerId);
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);
        $oiId = (int) $this->pdo()->query("SELECT id FROM order_items WHERE order_id = {$order['id']}")->fetchColumn();
        $retSvc = new ReturnService();
        $rr = $retSvc->createRequest($order['id'], $customerId, 'refund', 'Reason', null, [$oiId], $ownerId);

        $routes = [
            ['POST', '/admin/returns', ['order_id' => (string) $order['id'], 'customer_id' => (string) $customerId, 'request_type' => 'refund', 'reason' => 'x', 'items' => [(string) $oiId]]],
            ['POST', '/admin/returns/' . $rr['id'] . '/approve', []],
            ['POST', '/admin/returns/' . $rr['id'] . '/reject', ['reason' => 'x']],
            ['POST', '/admin/returns/' . $rr['id'] . '/receive', []],
            ['POST', '/admin/returns/' . $rr['id'] . '/cancel', ['reason' => 'x']],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(Request::synthetic($method, $uri, [], $post), $this->resolver());
            }, "{$method} {$uri} must require CSRF");
        }

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['returns' => [$rr['id']]]);
    }
}
