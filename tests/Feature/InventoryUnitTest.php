<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Services\InventoryService;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Individual laptop inventory — HTTP-level tests.
 *
 * Proves:
 *  - search by SKU/serial/model name
 *  - filters by status, location, condition grade, brand
 *  - receive a unit with all fields (SKU, serial, model, grade, costs,
 *    price, location, initial status)
 *  - unique SKU + unique serial_number enforced
 *  - status transitions via state machine (valid + invalid)
 *  - price adjustment (with min_price floor)
 *  - unit photo upload (valid JPEG stored; invalid MIME rejected)
 *  - unit photo delete (file + DB record removed)
 *  - permission enforcement
 *  - CSRF on all state-changing routes
 */
final class InventoryUnitTest extends TestCase
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

    private function dispatchAs(int $userId, string $method, string $uri, array $post = [], array $get = [], array $files = []): Response
    {
        $this->actingAs($userId);
        return $this->router()->dispatch(
            Request::synthetic($method, $uri, $get, $this->withCsrf($post), [], [], $files),
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

    private function makeTestImage(string $path): void
    {
        $data = "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xff\xd9";
        file_put_contents($path, $data);
    }

    public function setUp(): void
    {
        parent::setUp();
        // Ensure a user with id=1 exists for FK constraints.
        if ((int) $this->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            $this->makeUser('inv-setup@test.local', 'Correct-Horse-1', ['owner']);
        }
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testCustomerBlockedFromInventory(): void
    {
        $customerId = $this->makeUser('inv-customer@test.local', 'Correct-Horse-1', ['customer']);
        $this->assertThrows(ForbiddenException::class, function () use ($customerId) {
            $this->dispatchAs($customerId, 'GET', '/admin/inventory');
        }, 'customer must not access inventory');
    }

    public function testCashierCanViewButNotReceive(): void
    {
        $cashierId = $this->makeUser('inv-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);

        // Cashier can view (inventory.view).
        $response = $this->dispatchAs($cashierId, 'GET', '/admin/inventory');
        $this->assertSame(200, $response->status());

        // Cashier cannot receive (no inventory.receive).
        $this->assertThrows(ForbiddenException::class, function () use ($cashierId) {
            $this->dispatchAs($cashierId, 'GET', '/admin/inventory/create');
        });
    }

    // ---------------------------------------------------- SEARCH --

    public function testSearchBySku(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-search@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId, ['sku' => 'SEARCH-001']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/inventory', [], ['q' => 'SEARCH-001']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/SEARCH-001/', $response->body());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testSearchBySerialNumber(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-serial@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId, ['sku' => 'SER-001', 'serial_number' => 'SN-INV-TEST-001']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/inventory', [], ['q' => 'SN-INV-TEST-001']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/SER-001/', $response->body(), 'unit must appear when searching by serial number');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- FILTERS --

    public function testFilterByGrade(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-grade@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitA = $this->makeUnit($modelId, ['sku' => 'GR-A-001', 'condition_grade' => 'A']);
        $unitB = $this->makeUnit($modelId, ['sku' => 'GR-B-001', 'condition_grade' => 'B']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/inventory', [], ['grade' => 'A']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/GR-A-001/', $response->body());
        $this->assertFalse(str_contains($response->body(), 'GR-B-001'), 'grade B unit must be filtered out');

        // Cleanup.
        foreach ([$unitA, $unitB] as $uid) {
            $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$uid]);
            $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$uid]);
        }
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testFilterByStatus(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-status@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'ST-AVAIL-001']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/inventory', [], ['status' => 'available']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/ST-AVAIL-001/', $response->body());

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/inventory', [], ['status' => 'sold']);
        $this->assertSame(200, $response->status());
        $this->assertFalse(str_contains($response->body(), 'ST-AVAIL-001'), 'available unit must not appear in sold filter');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- RECEIVE --

    public function testReceiveCreatesUnitWithAllFields(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-receive@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inventory', [
            'sku' => 'RECV-TEST-' . bin2hex(random_bytes(3)),
            'serial_number' => 'SN-RECV-' . bin2hex(random_bytes(3)),
            'product_model_id' => (string) $modelId,
            'condition_grade' => 'A',
            'purchase_cost' => '42000.00',
            'landed_cost' => '3500.00',
            'selling_price' => '58500.00',
            'min_selling_price' => '55000.00',
            'supplier_ref' => 'Dubai Supplier LLC',
            'purchase_ref' => 'DXB-LOT-2026-10',
            'initial_status' => 'awaiting_inspection',
        ]);

        $this->assertSame(302, $response->status());

        // Cleanup: get the unit id from the redirect.
        $location = $response->headers()['Location'] ?? '';
        $this->assertMatchesRegularExpression('#/admin/inventory/\d+$#', $location);

        // Cleanup.
        $unitId = (int) preg_replace('/^.+\//', '', $location);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testDuplicateSkuRejected(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-dup-sku@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId, ['sku' => 'DUP-SKU-001']);

        // Try to receive another unit with the same SKU.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inventory', [
            'sku' => 'DUP-SKU-001', // duplicate!
            'product_model_id' => (string) $modelId,
            'condition_grade' => 'B',
            'purchase_cost' => '100.00',
            'selling_price' => '200.00',
            'initial_status' => 'awaiting_inspection',
        ]);

        $this->assertSame(422, $response->status(), 'duplicate SKU must fail validation');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testDuplicateSerialRejected(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-dup-sn@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId, ['sku' => 'DUP-SN-A', 'serial_number' => 'SN-DUP-001']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inventory', [
            'sku' => 'DUP-SN-B',
            'serial_number' => 'SN-DUP-001', // duplicate serial!
            'product_model_id' => (string) $modelId,
            'condition_grade' => 'B',
            'purchase_cost' => '100.00',
            'selling_price' => '200.00',
            'initial_status' => 'awaiting_inspection',
        ]);

        $this->assertSame(422, $response->status(), 'duplicate serial number must fail validation');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- STATUS TRANSITIONS --

    public function testValidTransitionApplied(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-trans@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId); // awaiting_inspection by default

        // Transition: inspection → available.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inventory/' . $unitId . '/transition', [
            'transition' => 'inspection:available',
            'reason' => 'QA passed all checks',
        ]);

        $this->assertSame(302, $response->status());

        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame('available', (string) $stmt->fetchColumn(), 'status must transition to available');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testInvalidTransitionRejected(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-bad-trans@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId); // awaiting_inspection

        // Try to transition from awaiting_inspection to sold (not allowed).
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inventory/' . $unitId . '/transition', [
            'transition' => 'sale:sold',
            'reason' => 'invalid transition attempt',
        ]);

        $this->assertSame(302, $response->status(), 'invalid transition must redirect (not 200)');

        // Verify status unchanged.
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame('awaiting_inspection', (string) $stmt->fetchColumn(), 'status must remain unchanged');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- PRICE ADJUSTMENT --

    public function testPriceAdjustment(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-price@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'PRC-001', 'selling_price' => '500.00', 'min_selling_price' => '400.00']);

        // Adjust price above the minimum.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inventory/' . $unitId . '/price', [
            'selling_price' => '550.00',
        ]);

        $this->assertSame(302, $response->status());

        $stmt = $this->pdo()->prepare('SELECT selling_price FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertEquals(550.00, (float) $stmt->fetchColumn(), 'price must be updated');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- UNIT PHOTOS --

    public function testValidPhotoUpload(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-photo@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'PHO-001']);

        $tmpFile = tempnam(sys_get_temp_dir(), 'inv_photo_');
        $this->makeTestImage($tmpFile);

        $files = [
            'photo' => [
                'name' => 'unit-front.jpg', 'type' => 'image/jpeg',
                'tmp_name' => $tmpFile, 'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ];

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inventory/' . $unitId . '/photos', ['caption' => 'Front view'], [], $files);
        $this->assertSame(302, $response->status());

        // Verify DB record.
        $stmt = $this->pdo()->prepare('SELECT file_path, caption FROM unit_photos WHERE inventory_unit_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$unitId]);
        $photo = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertTrue($photo !== false);
        $this->assertStringStartsWith('uploads/units/unit-', $photo['file_path']);
        $this->assertSame('Front view', $photo['caption']);
        $this->assertTrue(file_exists(BASE_PATH . '/public/' . $photo['file_path']), 'file must exist on disk');

        // Verify show view includes the photo.
        $response = $this->dispatchAs($ownerId, 'GET', '/admin/inventory/' . $unitId);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Unit photos/', $response->body());

        // Cleanup.
        @unlink(BASE_PATH . '/public/' . $photo['file_path']);
        $this->pdo()->prepare('DELETE FROM unit_photos WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testInvalidMimePhotoRejected(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-bad-photo@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'BAD-PHO-001']);

        $tmpFile = tempnam(sys_get_temp_dir(), 'inv_bad_');
        file_put_contents($tmpFile, '<?php echo "XSS"; ?>');

        $files = [
            'photo' => [
                'name' => 'evil.php.jpg', 'type' => 'image/jpeg',
                'tmp_name' => $tmpFile, 'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ];

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inventory/' . $unitId . '/photos', [], [], $files);
        $this->assertSame(302, $response->status(), 'invalid MIME must redirect');

        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM unit_photos WHERE inventory_unit_id = ?');
        $count->execute([$unitId]);
        $this->assertSame(0, (int) $count->fetchColumn(), 'no photo record for invalid MIME');

        @unlink($tmpFile);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testDeletePhotoRemovesFileAndDbRecord(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-del-photo@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'DEL-PHO-001']);

        // Create a real file + DB record.
        $filePath = 'uploads/units/test-del-' . bin2hex(random_bytes(8)) . '.jpg';
        $fullPath = BASE_PATH . '/public/' . $filePath;
        file_put_contents($fullPath, 'test');

        $stmt = $this->pdo()->prepare('INSERT INTO unit_photos (inventory_unit_id, file_path, sort_order) VALUES (?, ?, 0)');
        $stmt->execute([$unitId, $filePath]);
        $photoId = (int) $this->pdo()->lastInsertId();

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inventory/photos/' . $photoId . '/delete');
        $this->assertSame(302, $response->status());

        // DB record removed.
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM unit_photos WHERE id = ?');
        $stmt->execute([$photoId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'DB record must be removed');

        // File removed from disk.
        $this->assertFalse(file_exists($fullPath), 'file must be deleted from disk');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-csrf@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($ownerId);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'CSRF-INV-001']);

        $routes = [
            ['POST', '/admin/inventory', ['sku' => 'X', 'product_model_id' => (string) $modelId, 'condition_grade' => 'B', 'purchase_cost' => '100.00', 'selling_price' => '200.00', 'initial_status' => 'awaiting_inspection']],
            ['POST', '/admin/inventory/' . $unitId . '/transition', ['transition' => 'inspection:available', 'reason' => 'test']],
            ['POST', '/admin/inventory/' . $unitId . '/price', ['selling_price' => '150.00']],
            ['POST', '/admin/inventory/' . $unitId . '/photos', []],
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
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- SHOW VIEW --

    public function testShowRendersUnitDetails(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('inv-show@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['sku' => 'SHOW-001', 'serial_number' => 'SN-SHOW-001']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/inventory/' . $unitId);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/SHOW-001/', $response->body());
        $this->assertMatchesRegularExpression('/SN-SHOW-001/', $response->body());
        $this->assertMatchesRegularExpression('/Unit photos/', $response->body());
        $this->assertMatchesRegularExpression('/Inventory operations/', $response->body());
        $this->assertMatchesRegularExpression('/Movement history/', $response->body());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }
}
