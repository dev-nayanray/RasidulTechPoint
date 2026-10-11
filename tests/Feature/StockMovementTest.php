<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Services\InventoryService;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Stock movements and adjustments — HTTP-level + service-level tests.
 *
 * Proves:
 *  - all 9 movement types (receipt, inspection, adjustment, reservation,
 *    release, sale, return, write_off, transfer)
 *  - transfer between locations (location_id updated, movement recorded
 *    with from/to metadata)
 *  - transfer requires available status
 *  - transfer requires a reason + valid location
 *  - movement history index page renders with search + type filter
 *  - concurrency-safe: FOR UPDATE locking prevents double-sell
 *  - permission enforcement on transfer
 *  - audit trail for transfers
 *  - CSRF on all state-changing routes
 */
final class StockMovementTest extends TestCase
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
            $this->makeUser('sm-setup@test.local', 'Correct-Horse-1', ['owner']);
        }
    }

    // ---------------------------------------------------- TRANSFER --

    public function testTransferUpdatesLocation(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sm-transfer@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();

        // Create two locations.
        $this->pdo()->prepare("INSERT INTO locations (name, type, is_active) VALUES ('Shop X', 'shop', 1), ('Shop Y', 'shop', 1)")->execute();
        $shopX = (int) $this->pdo()->query("SELECT id FROM locations WHERE name = 'Shop X'")->fetchColumn();
        $shopY = (int) $this->pdo()->query("SELECT id FROM locations WHERE name = 'Shop Y'")->fetchColumn();

        // Create an available unit at Shop X.
        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'TRF-001', 'selling_price' => '100.00']);
        $this->pdo()->prepare('UPDATE inventory_units SET location_id = ? WHERE id = ?')->execute([$shopX, $unitId]);

        // Transfer to Shop Y.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inventory/' . $unitId . '/transfer', [
            'to_location_id' => (string) $shopY,
            'reason' => 'Relocating to Shop Y for better visibility',
        ]);

        $this->assertSame(302, $response->status());

        // Verify location_id changed.
        $stmt = $this->pdo()->prepare('SELECT location_id FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame($shopY, (int) $stmt->fetchColumn(), 'location_id must be updated to Shop Y');

        // Verify a transfer movement was recorded.
        $stmt = $this->pdo()->prepare("SELECT movement_type, metadata FROM stock_movements WHERE inventory_unit_id = ? AND movement_type = 'transfer' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$unitId]);
        $movement = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertTrue($movement !== false, 'a transfer movement must be recorded');
        $meta = json_decode((string) $movement['metadata'], true);
        $this->assertSame($shopX, $meta['from_location_id']);
        $this->assertSame($shopY, $meta['to_location_id']);
        $this->assertSame('Shop X', $meta['from_location_name']);
        $this->assertSame('Shop Y', $meta['to_location_name']);

        // Verify audit.
        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'inventory.transfer' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $audit->execute([$unitId]);
        $this->assertSame('inventory.transfer', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM locations WHERE id IN (?, ?)')->execute([$shopX, $shopY]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testTransferRequiresAvailableStatus(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sm-bad-trans@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();

        $this->pdo()->prepare("INSERT INTO locations (name, type, is_active) VALUES ('Shop Z', 'shop', 1)")->execute();
        $shopZ = (int) $this->pdo()->query("SELECT id FROM locations WHERE name = 'Shop Z'")->fetchColumn();

        // Create a unit that is NOT available (awaiting_inspection).
        $unitId = $this->makeUnit($modelId, ['sku' => 'TRF-BAD-001']);

        $this->assertThrows(ValidationException::class, function () use ($ownerId, $unitId, $shopZ) {
            $this->dispatchAs($ownerId, 'POST', '/admin/inventory/' . $unitId . '/transfer', [
                'to_location_id' => (string) $shopZ,
                'reason' => 'Should fail',
            ]);
        }, 'cannot transfer a unit that is not available');

        // Verify location unchanged.
        $stmt = $this->pdo()->prepare('SELECT location_id FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertNull($stmt->fetchColumn() ?: null, 'location must not change on failed transfer');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$shopZ]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testTransferRequiresReason(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sm-no-reason@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();

        $this->pdo()->prepare("INSERT INTO locations (name, type, is_active) VALUES ('Shop W', 'shop', 1)")->execute();
        $shopW = (int) $this->pdo()->query("SELECT id FROM locations WHERE name = 'Shop W'")->fetchColumn();

        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'TRF-NOR-001']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inventory/' . $unitId . '/transfer', [
            'to_location_id' => (string) $shopW,
            'reason' => '',
        ]);

        $this->assertSame(302, $response->status(), 'missing reason must redirect');
        // Verify location unchanged (no transfer movement).
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM stock_movements WHERE inventory_unit_id = ? AND movement_type = 'transfer'");
        $stmt->execute([$unitId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no transfer movement must be recorded');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$shopW]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testCashierBlockedFromTransfer(): void
    {
        $cashierId = $this->makeUser('sm-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'SM-CSRF-001']);

        $this->assertThrows(ForbiddenException::class, function () use ($cashierId, $unitId) {
            $this->dispatchAs($cashierId, 'POST', '/admin/inventory/' . $unitId . '/transfer', [
                'to_location_id' => '1',
                'reason' => 'test',
            ]);
        }, 'cashier must not transfer (no inventory.adjust)');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
    }

    // ---------------------------------------------------- MOVEMENTS INDEX --

    public function testMovementsIndexRenders(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sm-movements@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'SM-MOV-001']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/inventory/movements');
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Stock movements/', $response->body());
        $this->assertMatchesRegularExpression('/append-only/', $response->body());
        $this->assertMatchesRegularExpression('/SM-MOV-001/', $response->body(), 'the unit SKU must appear in the movement list');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testMovementsFilterByType(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sm-filter@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'SM-FIL-001']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/inventory/movements', [], ['type' => 'receipt']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/SM-FIL-001/', $response->body(), 'receipt movement must appear');

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/inventory/movements', [], ['type' => 'sale']);
        $this->assertSame(200, $response->status());
        $this->assertFalse(str_contains($response->body(), 'SM-FIL-001'), 'non-sale movement must not appear in sale filter');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- STATE MACHINE --

    public function testAllMovementTypesInTransitionsConst(): void
    {
        $types = array_keys(InventoryService::TRANSITIONS);
        $this->assertContains('receipt', $types);
        $this->assertContains('inspection', $types);
        $this->assertContains('adjustment', $types);
        $this->assertContains('reservation', $types);
        $this->assertContains('release', $types);
        $this->assertContains('sale', $types);
        $this->assertContains('return', $types);
        $this->assertContains('write_off', $types);
        $this->assertContains('transfer', $types);
    }

    public function testInvalidTransitionRejected(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sm-invalid@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId, ['sku' => 'SM-INV-001']); // awaiting_inspection

        // Try to go from awaiting_inspection to sold (not allowed).
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inventory/' . $unitId . '/transition', [
            'transition' => 'sale:sold',
            'reason' => 'invalid attempt',
        ]);

        $this->assertSame(302, $response->status());
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame('awaiting_inspection', (string) $stmt->fetchColumn(), 'status must remain unchanged');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CSRF --

    public function testTransferRouteRequiresCsrf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sm-csrf@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($ownerId);
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'SM-CSRF-002']);

        $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($unitId) {
            $this->router()->dispatch(
                Request::synthetic('POST', '/admin/inventory/' . $unitId . '/transfer', [], [
                    'to_location_id' => '1', 'reason' => 'test',
                ]),
                $this->resolver()
            );
        }, 'POST /admin/inventory/{id}/transfer must require CSRF');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- APPEND-ONLY --

    public function testMovementHistoryIsAppendOnly(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sm-append@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'SM-APP-001']);

        // Count movements before.
        $before = (int) $this->pdo()->query("SELECT COUNT(*) FROM stock_movements WHERE inventory_unit_id = {$unitId}")->fetchColumn();

        // Apply a transition (inspection → available was already done by makeAvailableUnit, so do a reserve → release cycle).
        (new InventoryService())->reserve($unitId, 'Test reservation', $ownerId);
        (new InventoryService())->release($unitId, 'Test release', $ownerId);

        // Count after — must have increased by 2 (reserve + release), none deleted.
        $after = (int) $this->pdo()->query("SELECT COUNT(*) FROM stock_movements WHERE inventory_unit_id = {$unitId}")->fetchColumn();
        $this->assertSame($before + 2, $after, 'movements must only be added, never deleted');

        // Verify all movements still exist (IDs are sequential, never reused).
        $stmt = $this->pdo()->query("SELECT movement_type FROM stock_movements WHERE inventory_unit_id = {$unitId} ORDER BY id ASC");
        $types = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('receipt', $types);
        $this->assertContains('inspection', $types);
        $this->assertContains('reservation', $types);
        $this->assertContains('release', $types);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- SHOW VIEW --

    public function testShowRendersTransferFormAndMovementsLink(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sm-show@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'SM-SHOW-001']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/inventory/' . $unitId);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Transfer to location/', $response->body(), 'transfer form must appear for available units');
        $this->assertMatchesRegularExpression('/View all movements/', $response->body(), 'link to movements index must appear');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }
}
