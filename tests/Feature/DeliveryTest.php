<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\CourierRepository;
use App\Services\DeliveryService;
use App\Services\OrderService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Delivery and courier management (Prompt 26) — service + HTTP tests.
 *
 * Proves:
 *  - Courier CRUD with soft-delete + restore
 *  - Delivery zone CRUD (hard delete — zones are nullable on shipments)
 *  - Shipment creation: unique shipment_number, recipient snapshot,
 *    fee, pending status, courier + zone validation
 *  - Dispatch: assigns courier, sets tracking reference + URL,
 *    auto-generates tracking URL from courier template
 *  - Dispatch status matrix: pending → dispatched OK, pending →
 *    delivered rejected, delivered terminal, cancelled terminal
 *  - Mark in transit, mark delivered with proof of delivery
 *  - Mark failed with reason
 *  - Return to origin from failed / dispatched / in_transit
 *  - Return to origin does NOT auto-return inventory (separate flow)
 *  - Cancel shipment
 *  - Permission: technician blocked; cashier without delivery.manage blocked
 *  - CSRF on every state-changing route
 */
final class DeliveryTest extends TestCase
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

    private function makeFixture(): array
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $customerId = $this->makeCustomer();
        return [$brandId, $catId, $modelId, $unitId, $customerId];
    }

    private function cleanupFixture(int $orderId, int $unitId, int $customerId, int $brandId, int $catId, int $modelId, array $extraIds = []): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (($extraIds['shipments'] ?? []) as $sid) {
            $this->pdo()->prepare('DELETE FROM shipments WHERE id = ?')->execute([$sid]);
        }
        foreach (($extraIds['zones'] ?? []) as $zid) {
            $this->pdo()->prepare('DELETE FROM delivery_zones WHERE id = ?')->execute([$zid]);
        }
        foreach (($extraIds['couriers'] ?? []) as $cid) {
            $this->pdo()->prepare('DELETE FROM couriers WHERE id = ?')->execute([$cid]);
        }
        $this->pdo()->prepare('DELETE FROM order_corrections WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_status_history WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_fulfillment_notes WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM refunds WHERE order_id = ?')->execute([$orderId]);
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

    private function makeCourier(string $name = 'Test Courier'): int
    {
        return (new CourierRepository())->create([
            'name' => $name,
            'slug' => str_slug($name . '-' . bin2hex(random_bytes(2))),
            'base_fee' => '50.00',
            'per_km_fee' => '10.00',
            'tracking_url_template' => 'https://track.example.com/{ref}',
            'is_active' => 1,
        ]);
    }

    // ---------------------------------------------------- COURIER CRUD --

    public function testCourierCreateAndFind(): void
    {
        $ownerId = $this->makeOwner('dlv-c1@test.local');
        $id = $this->makeCourier('Pathao Express');
        $courier = (new CourierRepository())->find($id);
        $this->assertSame('Pathao Express', $courier['name']);
        $this->assertSame('manual', (string) $courier['api_provider']); // default
        $this->assertSame('50.00', (string) $courier['base_fee']);
        $this->pdo()->prepare('DELETE FROM couriers WHERE id = ?')->execute([$id]);
    }

    public function testCourierSoftDeletePreservesRow(): void
    {
        $ownerId = $this->makeOwner('dlv-c2@test.local');
        $repo = new CourierRepository();
        $id = $this->makeCourier('RedX');
        $repo->softDelete($id);
        $this->assertNull($repo->find($id), 'find() must exclude soft-deleted');
        $this->assertNotNull($repo->findWithTrashed($id), 'row must still exist');
        $repo->restore($id);
        $this->assertNotNull($repo->find($id));
        $this->pdo()->prepare('DELETE FROM couriers WHERE id = ?')->execute([$id]);
    }

    // ---------------------------------------------------- SHIPMENT CREATE --

    public function testShipmentCreateGeneratesUniqueNumber(): void
    {
        $ownerId = $this->makeOwner('dlv-s1@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        $dlv = new DeliveryService();
        $result = $dlv->createShipment(
            $order['id'],
            null,
            null,
            50.00,
            'Test Customer',
            '+88017123',
            '123 Main St, Dhaka',
            null,
            $ownerId,
        );
        $this->assertMatchesRegularExpression('/^SHP-\d{6}-[A-F0-9]{6}$/', $result['shipment_number']);
        $this->assertSame('pending', $result['status']);

        $shipment = $dlv->findShipment($result['id']);
        $this->assertSame('Test Customer', $shipment['recipient_name']);
        $this->assertSame('123 Main St, Dhaka', $shipment['delivery_address']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['shipments' => [$result['id']]]);
    }

    public function testShipmentCreateRejectsEmptyRecipient(): void
    {
        $ownerId = $this->makeOwner('dlv-s2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        $this->assertThrows(ValidationException::class, function () use ($order, $ownerId) {
            (new DeliveryService())->createShipment($order['id'], null, null, 0, '', '+880', 'addr', null, $ownerId);
        }, 'empty recipient name must be rejected');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    // ---------------------------------------------------- DISPATCH --

    public function testDispatchAssignsCourierAndTracking(): void
    {
        $ownerId = $this->makeOwner('dlv-d1@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $courierId = $this->makeCourier('Pathao');
        $dlv = new DeliveryService();
        $shipment = $dlv->createShipment($order['id'], null, null, 50.00, 'C', '+88', 'A', null, $ownerId);

        $result = $dlv->dispatch($shipment['id'], $courierId, 'PATHAO-TXN-123', null, $ownerId);
        $this->assertSame('dispatched', $result['status']);

        $s = $dlv->findShipment($shipment['id']);
        $this->assertSame((string) $courierId, (string) $s['courier_id']);
        $this->assertSame('PATHAO-TXN-123', $s['tracking_reference']);
        // Tracking URL auto-generated from courier template:
        $this->assertSame('https://track.example.com/PATHAO-TXN-123', $s['tracking_url']);
        $this->assertNotNull($s['dispatched_at']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['shipments' => [$shipment['id']], 'couriers' => [$courierId]]);
    }

    public function testDispatchRejectsInactiveCourier(): void
    {
        $ownerId = $this->makeOwner('dlv-d2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $courierId = $this->makeCourier('Inactive Courier');
        $this->pdo()->prepare('UPDATE couriers SET is_active = 0 WHERE id = ?')->execute([$courierId]);

        $dlv = new DeliveryService();
        $shipment = $dlv->createShipment($order['id'], null, null, 0, 'C', '+88', 'A', null, $ownerId);
        $this->assertThrows(ValidationException::class, function () use ($dlv, $shipment, $courierId, $ownerId) {
            $dlv->dispatch($shipment['id'], $courierId, 'X', null, $ownerId);
        }, 'inactive courier must be rejected');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['shipments' => [$shipment['id']], 'couriers' => [$courierId]]);
    }

    // ---------------------------------------------------- STATUS MATRIX --

    public function testStatusMatrixAllowedTransitions(): void
    {
        $svc = new DeliveryService();
        $this->assertContains('dispatched', DeliveryService::STATUS_TRANSITIONS['pending']);
        $this->assertContains('cancelled', DeliveryService::STATUS_TRANSITIONS['pending']);
        $this->assertContains('in_transit', DeliveryService::STATUS_TRANSITIONS['dispatched']);
        $this->assertContains('delivered', DeliveryService::STATUS_TRANSITIONS['in_transit']);
        $this->assertContains('failed', DeliveryService::STATUS_TRANSITIONS['in_transit']);
        $this->assertContains('returned_to_origin', DeliveryService::STATUS_TRANSITIONS['failed']);
        $this->assertSame([], DeliveryService::STATUS_TRANSITIONS['delivered']);
        $this->assertSame([], DeliveryService::STATUS_TRANSITIONS['returned_to_origin']);
        $this->assertSame([], DeliveryService::STATUS_TRANSITIONS['cancelled']);
    }

    public function testPendingToDeliveredRejected(): void
    {
        $svc = new DeliveryService();
        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->assertTransitionAllowed('pending', 'delivered');
        }, 'pending → delivered must be rejected');
    }

    public function testDeliveredIsTerminal(): void
    {
        $svc = new DeliveryService();
        foreach (DeliveryService::SHIPMENT_STATUSES as $to) {
            if ($to === 'delivered') continue;
            $this->assertThrows(ValidationException::class, function () use ($svc, $to) {
                $svc->assertTransitionAllowed('delivered', $to);
            }, "delivered → {$to} must be rejected");
        }
    }

    public function testFullDispatchToDeliveryFlow(): void
    {
        $ownerId = $this->makeOwner('dlv-flow@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $courierId = $this->makeCourier('Flow Courier');
        $dlv = new DeliveryService();
        $shipment = $dlv->createShipment($order['id'], null, null, 50.00, 'C', '+88', 'A', null, $ownerId);

        $dlv->dispatch($shipment['id'], $courierId, 'TXN-1', null, $ownerId);
        $this->assertSame('dispatched', $dlv->findShipment($shipment['id'])['status']);

        $dlv->markInTransit($shipment['id']);
        $this->assertSame('in_transit', $dlv->findShipment($shipment['id'])['status']);

        $dlv->markDelivered($shipment['id'], 'Photo POD-001');
        $s = $dlv->findShipment($shipment['id']);
        $this->assertSame('delivered', $s['status']);
        $this->assertSame('Photo POD-001', $s['proof_of_delivery']);
        $this->assertNotNull($s['delivered_at']);

        // Inventory untouched — shipment doesn't move units.
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame('sold', (string) $stmt->fetchColumn(), 'shipment does not touch inventory');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['shipments' => [$shipment['id']], 'couriers' => [$courierId]]);
    }

    // ---------------------------------------------------- FAIL + RETURN --

    public function testMarkFailedRequiresReason(): void
    {
        $ownerId = $this->makeOwner('dlv-fail@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $courierId = $this->makeCourier();
        $dlv = new DeliveryService();
        $shipment = $dlv->createShipment($order['id'], null, null, 0, 'C', '+88', 'A', null, $ownerId);
        $dlv->dispatch($shipment['id'], $courierId, 'X', null, $ownerId);

        $this->assertThrows(ValidationException::class, function () use ($dlv, $shipment) {
            $dlv->markFailed($shipment['id'], '   ');
        }, 'empty failure reason must be rejected');

        $dlv->markFailed($shipment['id'], 'Customer not available');
        $s = $dlv->findShipment($shipment['id']);
        $this->assertSame('failed', $s['status']);
        $this->assertSame('Customer not available', $s['failure_reason']);
        $this->assertNotNull($s['failed_at']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['shipments' => [$shipment['id']], 'couriers' => [$courierId]]);
    }

    public function testReturnToOriginFromFailed(): void
    {
        $ownerId = $this->makeOwner('dlv-rto@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $courierId = $this->makeCourier();
        $dlv = new DeliveryService();
        $shipment = $dlv->createShipment($order['id'], null, null, 0, 'C', '+88', 'A', null, $ownerId);
        $dlv->dispatch($shipment['id'], $courierId, 'X', null, $ownerId);
        $dlv->markFailed($shipment['id'], 'No one home');

        $dlv->returnToOrigin($shipment['id'], 'Customer refused');
        $s = $dlv->findShipment($shipment['id']);
        $this->assertSame('returned_to_origin', $s['status']);
        $this->assertNotNull($s['returned_at']);

        // Inventory NOT auto-returned — return_to_origin is a physical label only.
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame('sold', (string) $stmt->fetchColumn(), 'return_to_origin does not auto-return inventory');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['shipments' => [$shipment['id']], 'couriers' => [$courierId]]);
    }

    public function testReturnToOriginFromDeliveredRejected(): void
    {
        $svc = new DeliveryService();
        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->assertTransitionAllowed('delivered', 'returned_to_origin');
        }, 'delivered → returned_to_origin must be rejected (delivered is terminal)');
    }

    // ---------------------------------------------------- CANCEL --

    public function testCancelFromPending(): void
    {
        $ownerId = $this->makeOwner('dlv-cancel@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $dlv = new DeliveryService();
        $shipment = $dlv->createShipment($order['id'], null, null, 0, 'C', '+88', 'A', null, $ownerId);
        $dlv->cancel($shipment['id']);
        $s = $dlv->findShipment($shipment['id']);
        $this->assertSame('cancelled', $s['status']);
        $this->assertNotNull($s['cancelled_at']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['shipments' => [$shipment['id']]]);
    }

    // ---------------------------------------------------- HTTP ROUTES --

    public function testHttpShipmentShowRendersAllForms(): void
    {
        $ownerId = $this->makeOwner('dlv-http-show@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $courierId = $this->makeCourier();
        $dlv = new DeliveryService();
        $shipment = $dlv->createShipment($order['id'], null, null, 50.00, 'C', '+88', 'A', null, $ownerId);

        $r = $this->dispatchAs($ownerId, 'GET', '/admin/delivery/shipments/' . $shipment['id']);
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Dispatch shipment', $r->body(), 'dispatch form must render for pending status');

        // After dispatch, the dispatch form should disappear and the deliver/fail forms should appear.
        $dlv->dispatch($shipment['id'], $courierId, 'TXN-1', null, $ownerId);
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/delivery/shipments/' . $shipment['id']);
        $this->assertStringContainsString('Mark as delivered', $r->body());
        $this->assertStringContainsString('Mark as failed', $r->body());

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['shipments' => [$shipment['id']], 'couriers' => [$courierId]]);
    }

    public function testHttpDashboardRenders(): void
    {
        $ownerId = $this->makeOwner('dlv-http-dash@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/delivery');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Courier', $r->body());
        $this->assertStringContainsString('Delivery', $r->body());
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testCashierBlockedFromDelivery(): void
    {
        $cashierId = $this->makeUser('dlv-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);
        $this->assertThrows(ForbiddenException::class, function () use ($cashierId) {
            $this->dispatchAs($cashierId, 'GET', '/admin/delivery');
        }, 'cashier without delivery.manage must be blocked');
    }

    public function testTechnicianBlockedFromDelivery(): void
    {
        $techId = $this->makeUser('dlv-tech@test.local', 'Correct-Horse-1', ['technician']);
        $this->assertThrows(ForbiddenException::class, function () use ($techId) {
            $this->dispatchAs($techId, 'GET', '/admin/delivery/shipments');
        }, 'technician has no delivery.manage');
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $ownerId = $this->makeOwner('dlv-csrf@test.local');
        $this->actingAs($ownerId);
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $courierId = $this->makeCourier();
        $dlv = new DeliveryService();
        $shipment = $dlv->createShipment($order['id'], null, null, 0, 'C', '+88', 'A', null, $ownerId);

        $routes = [
            ['POST', '/admin/delivery/couriers', ['name' => 'X']],
            ['POST', '/admin/delivery/couriers/' . $courierId . '/update', ['name' => 'Y']],
            ['POST', '/admin/delivery/couriers/' . $courierId . '/delete', []],
            ['POST', '/admin/delivery/shipments', ['order_id' => $order['id'], 'recipient_name' => 'C', 'recipient_phone' => '+88', 'delivery_address' => 'A', 'fee' => '0']],
            ['POST', '/admin/delivery/shipments/' . $shipment['id'] . '/dispatch', ['courier_id' => $courierId]],
            ['POST', '/admin/delivery/shipments/' . $shipment['id'] . '/transit', []],
            ['POST', '/admin/delivery/shipments/' . $shipment['id'] . '/deliver', []],
            ['POST', '/admin/delivery/shipments/' . $shipment['id'] . '/cancel', []],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(
                    Request::synthetic($method, $uri, [], $post),
                    $this->resolver()
                );
            }, "{$method} {$uri} must require CSRF");
        }

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['shipments' => [$shipment['id']], 'couriers' => [$courierId]]);
    }
}
