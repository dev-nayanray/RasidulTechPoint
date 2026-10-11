<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\LocationRepository;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Locations & branch management — HTTP-level tests.
 *
 * Proves:
 *  - location CRUD (create / edit / update / delete)
 *  - code uniqueness validation
 *  - name uniqueness validation
 *  - primary shop designation (only one at a time)
 *  - stock counts per location (available / awaiting / total)
 *  - delete prevention when inventory exists at the location
 *  - audit trail for every change
 *  - permission enforcement (manager can view, cashier blocked)
 *  - CSRF on state-changing routes
 */
final class LocationTest extends TestCase
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

    private function dispatchAs(int $userId, string $method, string $uri, array $post = []): Response
    {
        $this->actingAs($userId);
        return $this->router()->dispatch(
            Request::synthetic($method, $uri, [], $this->withCsrf($post)),
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

    // ---------------------------------------------------- PERMISSIONS --

    public function testCashierCanViewButNotCreateLocations(): void
    {
        $cashierId = $this->makeUser('loc-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);

        // Cashier has inventory.view → can list.
        $response = $this->dispatchAs($cashierId, 'GET', '/admin/locations');
        $this->assertSame(200, $response->status());

        // Cashier does NOT have inventory.adjust → blocked from create.
        $this->assertThrows(ForbiddenException::class, function () use ($cashierId) {
            $this->dispatchAs($cashierId, 'GET', '/admin/locations/create');
        });
    }

    // ---------------------------------------------------- INDEX --

    public function testIndexRendersLocationsWithStockCounts(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('loc-index@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/locations');
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Main Shop/', $response->body(), 'seeded Main Shop must appear');
        $this->assertMatchesRegularExpression('/Primary/', $response->body(), 'primary badge must show for the primary shop');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CREATE --

    public function testOwnerCanCreateLocation(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('loc-create@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/locations', [
            'name' => 'Chittagong Branch',
            'code' => 'CTG',
            'type' => 'shop',
            'address' => 'Agrabad, Chittagong',
            'phone' => '+880312345678',
            'is_primary' => '',
        ]);

        $this->assertSame(302, $response->status());

        // Verify the location was created.
        $stmt = $this->pdo()->prepare('SELECT name, code, type, address, phone, is_primary FROM locations WHERE name = ?');
        $stmt->execute(['Chittagong Branch']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('CTG', $row['code']);
        $this->assertSame('shop', $row['type']);
        $this->assertSame(0, (int) $row['is_primary']);

        // Verify audit trail.
        $audit = $this->pdo()->prepare("SELECT action, metadata FROM audit_logs WHERE action = 'location.created' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $log = $audit->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('location.created', $log['action']);
        $meta = json_decode((string) $log['metadata'], true);
        $this->assertSame('Chittagong Branch', $meta['name']);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM locations WHERE name = ?')->execute(['Chittagong Branch']);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testCreateRejectsDuplicateCode(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('loc-dup-code@test.local', 'Correct-Horse-1', ['owner']);

        // Create a location with code 'CTG'.
        $this->dispatchAs($ownerId, 'POST', '/admin/locations', [
            'name' => 'Branch 1',
            'code' => 'CTG',
            'type' => 'shop',
        ]);

        // Try to create another with the same code.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/locations', [
            'name' => 'Branch 2',
            'code' => 'CTG',
            'type' => 'shop',
        ]);

        $this->assertSame(422, $response->status(), 'duplicate code must fail');

        // Verify only one location with code CTG exists.
        $count = $this->pdo()->query("SELECT COUNT(*) FROM locations WHERE code = 'CTG'")->fetchColumn();
        $this->assertSame(1, (int) $count, 'only one location with code CTG should exist');

        // Cleanup.
        $this->pdo()->prepare("DELETE FROM locations WHERE code = 'CTG'")->execute();
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testCreateRejectsDuplicateName(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('loc-dup-name@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/locations', [
            'name' => 'Main Shop', // already seeded
            'code' => 'MS2',
            'type' => 'shop',
        ]);

        $this->assertSame(422, $response->status(), 'duplicate name must fail');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- UPDATE --

    public function testUpdateChangesFields(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('loc-update@test.local', 'Correct-Horse-1', ['owner']);

        // Create a location to update.
        $repo = new LocationRepository();
        $id = $repo->create('Temp Update Loc', 'TMP', 'warehouse', null, null);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/locations/' . $id . '/update', [
            'name' => 'Updated Warehouse',
            'code' => 'UWH',
            'type' => 'warehouse',
            'address' => 'New Address 99',
            'phone' => '+880999999999',
            'is_active' => '1',
        ]);

        $this->assertSame(302, $response->status());

        $loc = $repo->find($id);
        $this->assertSame('Updated Warehouse', $loc['name']);
        $this->assertSame('UWH', $loc['code']);
        $this->assertSame('New Address 99', $loc['address']);

        // Verify audit.
        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'location.updated' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $audit->execute([$id]);
        $this->assertSame('location.updated', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- PRIMARY --

    public function testOnlyOnePrimaryAtATime(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('loc-primary@test.local', 'Correct-Horse-1', ['owner']);

        // Create a new location and mark it primary.
        $repo = new LocationRepository();
        $id = $repo->create('New Primary', 'NP1', 'shop', null, null, true);

        // Verify only one primary exists.
        $count = $this->pdo()->query('SELECT COUNT(*) FROM locations WHERE is_primary = 1')->fetchColumn();
        $this->assertSame(1, (int) $count, 'exactly one location must be primary');

        // The new location must be the primary.
        $stmt = $this->pdo()->prepare('SELECT is_primary FROM locations WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        // Cleanup: restore the original primary.
        $this->pdo()->exec('UPDATE locations SET is_primary = 0 WHERE is_primary = 1');
        $this->pdo()->prepare("UPDATE locations SET is_primary = 1 WHERE name = 'Main Shop'")->execute();
        $this->pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- STOCK COUNTS --

    public function testStockCountsAreReal(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('loc-stock@test.local', 'Correct-Horse-1', ['owner']);

        // Find the Main Shop location id.
        $shopId = (int) $this->pdo()->query("SELECT id FROM locations WHERE name = 'Main Shop'")->fetchColumn();

        // Create inventory at this location.
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);
        $this->pdo()->prepare('UPDATE inventory_units SET location_id = ? WHERE id = ?')->execute([$shopId, $unitId]);

        // Verify the index shows 1 available at this location.
        $repo = new LocationRepository();
        $all = $repo->allWithStockCounts();
        $shop = null;
        foreach ($all as $loc) {
            if ((int) $loc['id'] === $shopId) {
                $shop = $loc;
                break;
            }
        }
        $this->assertNotNull($shop);
        $this->assertSame(1, (int) $shop['available_count'], '1 available unit at Main Shop');
        $this->assertSame(1, (int) $shop['total_units'], '1 total unit at Main Shop');

        // Verify find() returns the stock breakdown.
        $detail = $repo->find($shopId);
        $this->assertSame(1, $detail['stock']['available'] ?? 0);

        // Cleanup.
        $this->pdo()->prepare('UPDATE inventory_units SET location_id = NULL WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- DELETE --

    public function testCanDeleteEmptyLocation(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('loc-delete@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new LocationRepository();
        $id = $repo->create('Temp Delete Loc', 'TDL', 'warehouse', null, null);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/locations/' . $id . '/delete');
        $this->assertSame(302, $response->status());

        $this->assertNull($repo->find($id), 'location must be deleted');

        // Verify audit.
        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'location.deleted' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $audit->execute([$id]);
        $this->assertSame('location.deleted', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testCannotDeleteLocationWithInventory(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('loc-del-inv@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new LocationRepository();
        $id = $repo->create('Inv Loc', 'INV', 'shop', null, null);

        // Assign inventory to this location.
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);
        $this->pdo()->prepare('UPDATE inventory_units SET location_id = ? WHERE id = ?')->execute([$id, $unitId]);

        $this->assertThrows(ValidationException::class, function () use ($ownerId, $id) {
            $this->dispatchAs($ownerId, 'POST', '/admin/locations/' . $id . '/delete');
        }, 'cannot delete a location with inventory');

        // Verify location still exists.
        $this->assertNotNull($repo->find($id));

        // Cleanup.
        $this->pdo()->prepare('UPDATE inventory_units SET location_id = NULL WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('loc-csrf@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($ownerId);

        $repo = new LocationRepository();
        $id = $repo->create('CSRF Loc', 'CSF', 'shop', null, null);

        $routes = [
            ['POST', '/admin/locations', ['name' => 'X', 'code' => 'X', 'type' => 'shop']],
            ['POST', '/admin/locations/' . $id . '/update', ['name' => 'Y', 'code' => 'Y', 'type' => 'shop']],
            ['POST', '/admin/locations/' . $id . '/delete', []],
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
        $this->pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }
}
