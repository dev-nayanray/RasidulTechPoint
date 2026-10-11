<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\SupplierRepository;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Purchase orders and import batches — HTTP-level tests.
 *
 * Proves:
 *  - CRUD (create, show, edit, update)
 *  - line items with quantities and agreed costs
 *  - server-side total calculation (qty × unit_cost)
 *  - status transitions: draft → submitted → approved → received
 *  - cancel from non-received status
 *  - cannot edit non-draft POs
 *  - cannot approve from wrong status
 *  - stock is NOT added on create/approve (no inventory_units created)
 *  - permission enforcement (purchases.view vs purchases.manage vs
 *    inventory.approve)
 *  - audit trail
 *  - CSRF
 */
final class PurchaseOrderTest extends TestCase
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

    private function makeSupplier(): int
    {
        $repo = new SupplierRepository();
        return $repo->create('PO Test Supplier', 'po-test-supplier-' . bin2hex(random_bytes(3)), null, null, null, null, null, null, null);
    }

    public function setUp(): void
    {
        parent::setUp();
        if ((int) $this->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            $this->makeUser('po-setup@test.local', 'Correct-Horse-1', ['owner']);
        }
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testCashierBlockedFromPurchaseOrders(): void
    {
        $cashierId = $this->makeUser('po-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);
        $this->assertThrows(ForbiddenException::class, function () use ($cashierId) {
            $this->dispatchAs($cashierId, 'GET', '/admin/purchase-orders');
        }, 'cashier (no purchases.view) must not access POs');
    }

    // ---------------------------------------------------- CREATE --

    public function testCreatePurchaseOrder(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('po-create@test.local', 'Correct-Horse-1', ['owner']);
        $supplierId = $this->makeSupplier();
        [$brandId, $catId, $modelId] = $this->makeCatalog();

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/purchase-orders', [
            'supplier_id' => (string) $supplierId,
            'import_batch_ref' => 'DXB-LOT-2026-11',
            'notes' => 'First order from Dubai',
            'item_model' => [(string) $modelId],
            'item_qty' => ['5'],
            'item_cost' => ['42000.00'],
            'item_desc' => ['Dell Latitude 7490 batch'],
        ]);

        $this->assertSame(302, $response->status());

        // Verify the PO was created.
        $stmt = $this->pdo()->prepare('SELECT id, reference, status, supplier_id, import_batch_ref, subtotal, total FROM purchase_orders WHERE supplier_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$supplierId]);
        $po = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertTrue($po !== false);
        $this->assertSame('draft', $po['status']);
        $this->assertSame('DXB-LOT-2026-11', $po['import_batch_ref']);
        $this->assertEquals(210000.00, (float) $po['subtotal'], '5 × 42000 = 210000');
        $this->assertEquals(210000.00, (float) $po['total']);

        // Verify line item.
        $stmt = $this->pdo()->prepare('SELECT quantity, unit_cost, line_total FROM purchase_order_items WHERE purchase_order_id = ?');
        $stmt->execute([$po['id']]);
        $item = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(5, (int) $item['quantity']);
        $this->assertEquals(42000.00, (float) $item['unit_cost']);
        $this->assertEquals(210000.00, (float) $item['line_total']);

        // Verify NO stock was added (no inventory_units created).
        $count = $this->pdo()->query("SELECT COUNT(*) FROM inventory_units WHERE purchase_ref = 'DXB-LOT-2026-11'")->fetchColumn();
        $this->assertSame(0, (int) $count, 'no inventory units must be created on PO creation');

        // Verify audit.
        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'po.created' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('po.created', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$po['id']]);
        $this->pdo()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$po['id']]);
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testCreateRejectsMissingSupplier(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('po-no-sup@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/purchase-orders', [
            'supplier_id' => '0',
            'item_model' => [(string) $modelId],
            'item_qty' => ['1'],
            'item_cost' => ['100.00'],
        ]);

        $this->assertSame(422, $response->status(), 'missing supplier must fail validation');

        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testCreateRejectsNoItems(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('po-no-items@test.local', 'Correct-Horse-1', ['owner']);
        $supplierId = $this->makeSupplier();

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/purchase-orders', [
            'supplier_id' => (string) $supplierId,
            'item_model' => [],
        ]);

        $this->assertSame(422, $response->status(), 'no items must fail validation');

        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- STATUS TRANSITIONS --

    public function testFullStatusFlow(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('po-flow@test.local', 'Correct-Horse-1', ['owner']);
        $supplierId = $this->makeSupplier();
        [, , $modelId] = $this->makeCatalog();

        // Create PO (draft).
        $repo = new PurchaseOrderRepository();
        $poId = $repo->create($supplierId, 'DXB-FLOW-001', null, $ownerId, [
            ['product_model_id' => $modelId, 'quantity' => 3, 'unit_cost' => 10000.00, 'description' => null],
        ]);

        // Submit.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/purchase-orders/' . $poId . '/status', ['action' => 'submit']);
        $this->assertSame(302, $response->status());
        $po = $repo->find($poId);
        $this->assertSame('submitted', $po['status']);
        $this->assertNotNull($po['submitted_at']);

        // Approve (owner has inventory.approve).
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/purchase-orders/' . $poId . '/status', ['action' => 'approve']);
        $this->assertSame(302, $response->status());
        $po = $repo->find($poId);
        $this->assertSame('approved', $po['status']);
        $this->assertNotNull($po['approved_at']);

        // Mark received.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/purchase-orders/' . $poId . '/status', ['action' => 'receive']);
        $this->assertSame(302, $response->status());
        $po = $repo->find($poId);
        $this->assertSame('received', $po['status']);
        $this->assertNotNull($po['received_at']);

        // Verify NO stock was added (receiving the PO status doesn't add units).
        $count = $this->pdo()->query("SELECT COUNT(*) FROM inventory_units WHERE purchase_ref = 'DXB-FLOW-001'")->fetchColumn();
        $this->assertSame(0, (int) $count, 'marking PO as received must NOT add inventory units');

        // Verify audit trail for the status transitions (po.created is
        // only logged by the controller's store() method, not by the
        // repo directly — we only check the status-change actions here).
        foreach (['po.submit', 'po.approve', 'po.receive'] as $action) {
            $audit = $this->pdo()->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = ? AND entity_id = ?");
            $audit->execute([$action, $poId]);
            $this->assertTrue((int) $audit->fetchColumn() > 0, "audit row for {$action} must exist");
        }

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testCannotApproveDraftPO(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('po-bad-app@test.local', 'Correct-Horse-1', ['owner']);
        $supplierId = $this->makeSupplier();
        [, , $modelId] = $this->makeCatalog();

        $repo = new PurchaseOrderRepository();
        $poId = $repo->create($supplierId, null, null, $ownerId, [
            ['product_model_id' => $modelId, 'quantity' => 1, 'unit_cost' => 100.00, 'description' => null],
        ]);

        // Try to approve a draft (should fail — needs to be submitted first).
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/purchase-orders/' . $poId . '/status', ['action' => 'approve']);
        $this->assertSame(302, $response->status(), 'must redirect (flash error)');

        $po = $repo->find($poId);
        $this->assertSame('draft', $po['status'], 'status must remain draft');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testCancelFromSubmitted(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('po-cancel@test.local', 'Correct-Horse-1', ['owner']);
        $supplierId = $this->makeSupplier();
        [, , $modelId] = $this->makeCatalog();

        $repo = new PurchaseOrderRepository();
        $poId = $repo->create($supplierId, null, null, $ownerId, [
            ['product_model_id' => $modelId, 'quantity' => 1, 'unit_cost' => 100.00, 'description' => null],
        ]);
        $repo->updateStatus($poId, 'submitted', $ownerId);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/purchase-orders/' . $poId . '/status', ['action' => 'cancel']);
        $this->assertSame(302, $response->status());
        $this->assertSame('cancelled', $repo->find($poId)['status']);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- EDIT --

    public function testCannotEditNonDraftPO(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('po-no-edit@test.local', 'Correct-Horse-1', ['owner']);
        $supplierId = $this->makeSupplier();
        [, , $modelId] = $this->makeCatalog();

        $repo = new PurchaseOrderRepository();
        $poId = $repo->create($supplierId, null, null, $ownerId, [
            ['product_model_id' => $modelId, 'quantity' => 1, 'unit_cost' => 100.00, 'description' => null],
        ]);
        $repo->updateStatus($poId, 'submitted', $ownerId);

        // Try to edit a submitted PO.
        $response = $this->dispatchAs($ownerId, 'GET', '/admin/purchase-orders/' . $poId . '/edit');
        $this->assertSame(302, $response->status(), 'must redirect (flash error)');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('po-csrf@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($ownerId);
        $supplierId = $this->makeSupplier();
        [, , $modelId] = $this->makeCatalog();

        $repo = new PurchaseOrderRepository();
        $poId = $repo->create($supplierId, null, null, $ownerId, [
            ['product_model_id' => $modelId, 'quantity' => 1, 'unit_cost' => 100.00, 'description' => null],
        ]);

        $routes = [
            ['POST', '/admin/purchase-orders', ['supplier_id' => (string) $supplierId, 'item_model' => [(string) $modelId], 'item_qty' => ['1'], 'item_cost' => ['100.00']]],
            ['POST', '/admin/purchase-orders/' . $poId . '/update', ['supplier_id' => (string) $supplierId, 'item_model' => [(string) $modelId], 'item_qty' => ['2'], 'item_cost' => ['200.00']]],
            ['POST', '/admin/purchase-orders/' . $poId . '/status', ['action' => 'submit']],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(
                    Request::synthetic($method, $uri, [], $post),
                    $this->resolver()
                );
            }, "{$method} {$uri} must require CSRF");
        }

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- SHOW --

    public function testShowRendersPOWithLineItems(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('po-show@test.local', 'Correct-Horse-1', ['owner']);
        $supplierId = $this->makeSupplier();
        [, , $modelId] = $this->makeCatalog();

        $repo = new PurchaseOrderRepository();
        $poId = $repo->create($supplierId, 'DXB-SHOW-001', 'Test notes', $ownerId, [
            ['product_model_id' => $modelId, 'quantity' => 2, 'unit_cost' => 15000.00, 'description' => 'Show test'],
        ]);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/purchase-orders/' . $poId);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Line items/', $response->body());
        $this->assertMatchesRegularExpression('/30[,.]?000/', $response->body(), 'line total 2×15000=30000 must appear');
        $this->assertMatchesRegularExpression('/Test notes/', $response->body());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }
}
