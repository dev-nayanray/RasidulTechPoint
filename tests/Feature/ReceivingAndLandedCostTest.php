<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\SupplierRepository;
use App\Services\InventoryService;
use App\Services\LandedCostService;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Receiving and landed costs — HTTP-level + service-level tests.
 *
 * Proves:
 *  - receive() links a unit to its purchase_order_id
 *  - duplicate SKU receipt prevented (unique constraint)
 *  - landed cost allocation (equal method): each unit gets the same share
 *  - landed cost allocation (by_cost method): proportional to purchase_cost
 *  - multiple allocations accumulate (cost history retained)
 *  - allocation requires units linked to the PO
 *  - allocation with no units → rejected
 *  - per-unit breakdown stored in JSON
 *  - permission enforcement
 *  - CSRF
 */
final class ReceivingAndLandedCostTest extends TestCase
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

    public function setUp(): void
    {
        parent::setUp();
        if ((int) $this->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            $this->makeUser('rlc-setup@test.local', 'Correct-Horse-1', ['owner']);
        }
    }

    private function makeApprovedPO(int $ownerId, int $supplierId, int $modelId): int
    {
        $repo = new PurchaseOrderRepository();
        $poId = $repo->create($supplierId, 'DXB-RLC-' . bin2hex(random_bytes(3)), null, $ownerId, [
            ['product_model_id' => $modelId, 'quantity' => 3, 'unit_cost' => 10000.00, 'description' => null],
        ]);
        $repo->updateStatus($poId, 'submitted', $ownerId);
        $repo->updateStatus($poId, 'approved', $ownerId);
        return $poId;
    }

    // ---------------------------------------------------- RECEIVE WITH PO LINK --

    public function testReceiveLinksUnitToPurchaseOrder(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('rlc-recv@test.local', 'Correct-Horse-1', ['owner']);
        $supplierId = (new SupplierRepository())->create('RLC Supplier', 'rlc-supplier-' . bin2hex(random_bytes(3)), null, null, null, null, null, null, null);
        [, , $modelId] = $this->makeCatalog();
        $poId = $this->makeApprovedPO($ownerId, $supplierId, $modelId);

        // Receive a unit linked to the PO.
        $svc = new InventoryService();
        $unitId = $svc->receive([
            'sku' => 'RLC-001',
            'serial_number' => 'SN-RLC-001',
            'product_model_id' => $modelId,
            'condition_grade' => 'A',
            'purchase_cost' => '10000.00',
            'landed_cost' => '0.00',
            'selling_price' => '15000.00',
            'purchase_order_id' => $poId,
            'supplier_ref' => 'RLC Supplier',
            'purchase_ref' => 'DXB-RLC-001',
        ], $ownerId);

        // Verify the link.
        $stmt = $this->pdo()->prepare('SELECT purchase_order_id FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame($poId, (int) $stmt->fetchColumn(), 'unit must be linked to the PO');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testDuplicateSkuReceiptPrevented(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('rlc-dup@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();

        $svc = new InventoryService();
        $unitId = $svc->receive([
            'sku' => 'RLC-DUP-001',
            'product_model_id' => $modelId,
            'condition_grade' => 'B',
            'purchase_cost' => '100.00',
            'selling_price' => '200.00',
        ], $ownerId);

        // Try to receive another unit with the same SKU.
        $this->assertThrows(\PDOException::class, function () use ($svc, $modelId, $ownerId) {
            $svc->receive([
                'sku' => 'RLC-DUP-001', // duplicate!
                'product_model_id' => $modelId,
                'condition_grade' => 'B',
                'purchase_cost' => '100.00',
                'selling_price' => '200.00',
            ], $ownerId);
        }, 'duplicate SKU must be rejected by the unique constraint');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- LANDED COST ALLOCATION (EQUAL) --

    public function testEqualAllocation(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('rlc-equal@test.local', 'Correct-Horse-1', ['owner']);
        $supplierId = (new SupplierRepository())->create('RLC Equal', 'rlc-equal-' . bin2hex(random_bytes(3)), null, null, null, null, null, null, null);
        [, , $modelId] = $this->makeCatalog();
        $poId = $this->makeApprovedPO($ownerId, $supplierId, $modelId);

        // Receive 3 units against the PO.
        $svc = new InventoryService();
        $unitIds = [];
        for ($i = 0; $i < 3; $i++) {
            $unitIds[] = $svc->receive([
                'sku' => 'RLC-EQ-' . $i,
                'product_model_id' => $modelId,
                'condition_grade' => 'B',
                'purchase_cost' => '10000.00',
                'landed_cost' => '0.00',
                'selling_price' => '15000.00',
                'purchase_order_id' => $poId,
            ], $ownerId);
        }

        // Allocate 3000 BDT shipping equally across 3 units → 1000 each.
        $allocId = (new LandedCostService())->allocate($poId, 'shipping', 3000.00, 'equal', $ownerId, 'Sea freight');

        // Verify each unit's landed_cost is now 1000.
        foreach ($unitIds as $uid) {
            $stmt = $this->pdo()->prepare('SELECT landed_cost FROM inventory_units WHERE id = ?');
            $stmt->execute([$uid]);
            $this->assertEquals(1000.00, (float) $stmt->fetchColumn(), 'each unit must have 1000 landed cost');
        }

        // Verify the allocation record.
        $stmt = $this->pdo()->prepare('SELECT total_amount, allocation_method, allocated_count, per_unit_breakdown FROM landed_cost_allocations WHERE id = ?');
        $stmt->execute([$allocId]);
        $alloc = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(3000.00, (float) $alloc['total_amount']);
        $this->assertSame('equal', $alloc['allocation_method']);
        $this->assertSame(3, (int) $alloc['allocated_count']);
        $breakdown = json_decode((string) $alloc['per_unit_breakdown'], true);
        $this->assertCount(3, $breakdown);
        $this->assertEquals(1000.00, $breakdown[0]['amount']);

        // Cleanup.
        foreach ($unitIds as $uid) {
            $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$uid]);
        }
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM landed_cost_allocations WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- LANDED COST ALLOCATION (BY_COST) --

    public function testByCostAllocation(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('rlc-bycost@test.local', 'Correct-Horse-1', ['owner']);
        $supplierId = (new SupplierRepository())->create('RLC ByCost', 'rlc-bycost-' . bin2hex(random_bytes(3)), null, null, null, null, null, null, null);
        [, , $modelId] = $this->makeCatalog();
        $poId = $this->makeApprovedPO($ownerId, $supplierId, $modelId);

        // Receive 2 units with different purchase costs.
        $svc = new InventoryService();
        $unitA = $svc->receive([
            'sku' => 'RLC-BC-A', 'product_model_id' => $modelId, 'condition_grade' => 'A',
            'purchase_cost' => '20000.00', 'landed_cost' => '0.00', 'selling_price' => '25000.00',
            'purchase_order_id' => $poId,
        ], $ownerId);
        $unitB = $svc->receive([
            'sku' => 'RLC-BC-B', 'product_model_id' => $modelId, 'condition_grade' => 'B',
            'purchase_cost' => '10000.00', 'landed_cost' => '0.00', 'selling_price' => '15000.00',
            'purchase_order_id' => $poId,
        ], $ownerId);

        // Total purchase cost = 30000. Allocate 1500 by_cost.
        // A gets 20000/30000 * 1500 = 1000. B gets 10000/30000 * 1500 = 500.
        $allocId = (new LandedCostService())->allocate($poId, 'customs', 1500.00, 'by_cost', $ownerId, 'Customs duty');

        $stmt = $this->pdo()->prepare('SELECT landed_cost FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitA]);
        $this->assertEquals(1000.00, round((float) $stmt->fetchColumn(), 2), 'A should get 1000 (2/3 of 1500)');
        $stmt->execute([$unitB]);
        $this->assertEquals(500.00, round((float) $stmt->fetchColumn(), 2), 'B should get 500 (1/3 of 1500)');

        // Cleanup.
        foreach ([$unitA, $unitB] as $uid) {
            $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$uid]);
        }
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM landed_cost_allocations WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- MULTIPLE ALLOCATIONS ACCUMULATE --

    public function testMultipleAllocationsAccumulate(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('rlc-multi@test.local', 'Correct-Horse-1', ['owner']);
        $supplierId = (new SupplierRepository())->create('RLC Multi', 'rlc-multi-' . bin2hex(random_bytes(3)), null, null, null, null, null, null, null);
        [, , $modelId] = $this->makeCatalog();
        $poId = $this->makeApprovedPO($ownerId, $supplierId, $modelId);

        $svc = new InventoryService();
        $unitId = $svc->receive([
            'sku' => 'RLC-MULTI-1', 'product_model_id' => $modelId, 'condition_grade' => 'A',
            'purchase_cost' => '10000.00', 'landed_cost' => '0.00', 'selling_price' => '15000.00',
            'purchase_order_id' => $poId,
        ], $ownerId);

        $lcs = new LandedCostService();
        $lcs->allocate($poId, 'shipping', 500.00, 'equal', $ownerId, 'First batch shipping');
        $lcs->allocate($poId, 'customs', 300.00, 'equal', $ownerId, 'Customs');

        $stmt = $this->pdo()->prepare('SELECT landed_cost FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertEquals(800.00, (float) $stmt->fetchColumn(), 'landed_cost must accumulate: 500 + 300 = 800');

        // Verify two allocation rows exist (cost history retained).
        $count = $this->pdo()->query("SELECT COUNT(*) FROM landed_cost_allocations WHERE purchase_order_id = {$poId}")->fetchColumn();
        $this->assertSame(2, (int) $count, 'two allocation rows must exist');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM landed_cost_allocations WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- NO UNITS → REJECTED --

    public function testAllocationWithNoUnitsRejected(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('rlc-nounits@test.local', 'Correct-Horse-1', ['owner']);
        $supplierId = (new SupplierRepository())->create('RLC NoUnits', 'rlc-nounits-' . bin2hex(random_bytes(3)), null, null, null, null, null, null, null);
        [, , $modelId] = $this->makeCatalog();
        $poId = $this->makeApprovedPO($ownerId, $supplierId, $modelId);

        // Don't receive any units. Try to allocate.
        $this->assertThrows(ValidationException::class, function () use ($poId, $ownerId) {
            (new LandedCostService())->allocate($poId, 'shipping', 500.00, 'equal', $ownerId, 'No units');
        }, 'cannot allocate when no units are linked to the PO');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- HTTP: ALLOCATE VIA CONTROLLER --

    public function testHttpAllocateLandedCost(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('rlc-http@test.local', 'Correct-Horse-1', ['owner']);
        $supplierId = (new SupplierRepository())->create('RLC HTTP', 'rlc-http-' . bin2hex(random_bytes(3)), null, null, null, null, null, null, null);
        [, , $modelId] = $this->makeCatalog();
        $poId = $this->makeApprovedPO($ownerId, $supplierId, $modelId);

        // Receive a unit.
        (new InventoryService())->receive([
            'sku' => 'RLC-HTTP-1', 'product_model_id' => $modelId, 'condition_grade' => 'A',
            'purchase_cost' => '10000.00', 'landed_cost' => '0.00', 'selling_price' => '15000.00',
            'purchase_order_id' => $poId,
        ], $ownerId);

        // Allocate via HTTP.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/purchase-orders/' . $poId . '/allocate', [
            'expense_type' => 'shipping',
            'total_amount' => '2000.00',
            'method' => 'equal',
            'description' => 'Sea freight',
        ]);

        $this->assertSame(302, $response->status());

        // Verify unit's landed_cost updated.
        $stmt = $this->pdo()->prepare('SELECT landed_cost FROM inventory_units WHERE purchase_order_id = ?');
        $stmt->execute([$poId]);
        $this->assertEquals(2000.00, (float) $stmt->fetchColumn(), 'landed cost must be 2000');

        // Verify audit.
        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'po.landed_cost_allocated' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('po.landed_cost_allocated', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id IN (SELECT id FROM inventory_units WHERE purchase_order_id = ?)')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM landed_cost_allocations WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllocateRouteRequiresCsrf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('rlc-csrf@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($ownerId);
        $supplierId = (new SupplierRepository())->create('RLC CSRF', 'rlc-csrf-' . bin2hex(random_bytes(3)), null, null, null, null, null, null, null);
        [, , $modelId] = $this->makeCatalog();
        $poId = $this->makeApprovedPO($ownerId, $supplierId, $modelId);

        $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($poId) {
            $this->router()->dispatch(
                Request::synthetic('POST', '/admin/purchase-orders/' . $poId . '/allocate', [], [
                    'expense_type' => 'shipping', 'total_amount' => '100.00', 'method' => 'equal',
                ]),
                $this->resolver()
            );
        }, 'POST /admin/purchase-orders/{id}/allocate must require CSRF');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }
}
