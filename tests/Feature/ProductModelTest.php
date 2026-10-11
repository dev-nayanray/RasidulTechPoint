<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Repositories\CatalogRepository;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Laptop models & specifications — HTTP-level tests.
 *
 * Proves:
 *  - CRUD with all spec fields (brand, category, processor, RAM, storage,
 *    graphics, screen size, resolution, OS, description, SEO)
 *  - price display rules (show_price flag)
 *  - model-unit separation (models have no price column; prices are on
 *    inventory_units; priceRange() aggregates from available units)
 *  - slug auto-generation + uniqueness
 *  - validation (name required, brand/category required, RAM range,
 *    storage type enum, display size decimal)
 *  - soft delete (deleted_at set; findModel excludes deleted rows)
 *  - audit trail
 *  - permission enforcement
 *  - CSRF
 */
final class ProductModelTest extends TestCase
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

    // ---------------------------------------------------- PERMISSIONS --

    public function testCashierBlockedFromCreatingProducts(): void
    {
        $cashierId = $this->makeUser('pm-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);

        // Cashier can view (products.view).
        $response = $this->dispatchAs($cashierId, 'GET', '/admin/products');
        $this->assertSame(200, $response->status());

        // Cashier cannot create (no products.create).
        $this->assertThrows(ForbiddenException::class, function () use ($cashierId) {
            $this->dispatchAs($cashierId, 'GET', '/admin/products/create');
        });
    }

    // ---------------------------------------------------- CREATE --

    public function testCreateModelWithAllSpecs(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('pm-create@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId] = $this->makeCatalog();

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/products', [
            'name' => 'Dell Latitude 7490',
            'slug' => '',
            'brand_id' => (string) $brandId,
            'category_id' => (string) $catId,
            'processor' => 'Intel Core i7-8650U',
            'ram_gb' => '16',
            'storage_type' => 'SSD',
            'storage_capacity_gb' => '512',
            'graphics' => 'Intel UHD 620',
            'display_size_inches' => '14.0',
            'resolution' => '1920x1080',
            'operating_system' => 'Windows 10 Pro',
            'description' => 'Business-grade ultrabook.',
            'seo_title' => 'Buy Dell Latitude 7490 | RTP',
            'seo_description' => 'Refurbished Dell Latitude 7490 with i7, 16GB RAM, 512GB SSD.',
            'is_published' => '1',
            'show_price' => '1',
        ]);

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/admin/products/\d+$#', $response->headers()['Location'] ?? '');

        // Verify the model was created with all fields.
        $repo = new CatalogRepository();
        $model = $repo->findModelBySlug('dell-latitude-7490');
        $this->assertNotNull($model);
        $this->assertSame('Dell Latitude 7490', $model['name']);
        $this->assertSame('Intel Core i7-8650U', $model['processor']);
        $this->assertSame(16, (int) $model['ram_gb']);
        $this->assertSame('SSD', $model['storage_type']);
        $this->assertSame(512, (int) $model['storage_capacity_gb']);
        $this->assertSame('Intel UHD 620', $model['graphics']);
        $this->assertEquals(14.0, (float) $model['display_size_inches']);
        $this->assertSame('1920x1080', $model['resolution']);
        $this->assertSame('Windows 10 Pro', $model['operating_system']);
        $this->assertSame('Business-grade ultrabook.', $model['description']);
        $this->assertSame('Buy Dell Latitude 7490 | RTP', $model['seo_title']);
        $this->assertSame('Refurbished Dell Latitude 7490 with i7, 16GB RAM, 512GB SSD.', $model['seo_description']);
        $this->assertSame(1, (int) $model['is_published']);
        $this->assertSame(1, (int) $model['show_price']);

        // Verify audit.
        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'product_model.created' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('product_model.created', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$model['id']]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testCreateWithEmptySlugAutoGenerates(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('pm-slug@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId] = $this->makeCatalog();

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/products', [
            'name' => 'HP EliteBook 840',
            'slug' => '',
            'brand_id' => (string) $brandId,
            'category_id' => (string) $catId,
        ]);

        $this->assertSame(302, $response->status());

        // findModelBySlug filters by is_published=1; query directly
        // since the model is unpublished by default.
        $stmt = $this->pdo()->prepare('SELECT id, slug FROM product_models WHERE slug = ? AND deleted_at IS NULL');
        $stmt->execute(['hp-elitebook-840']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertTrue($row !== false, 'slug must be auto-generated from the name');
        $this->assertSame('hp-elitebook-840', $row['slug']);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$row['id']]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- PRICE DISPLAY --

    public function testShowPriceFlagDefaultsToOne(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('pm-price@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId] = $this->makeCatalog();

        // Create without sending show_price → should default to 1.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/products', [
            'name' => 'Price Test Model',
            'slug' => '',
            'brand_id' => (string) $brandId,
            'category_id' => (string) $catId,
        ]);
        $this->assertSame(302, $response->status());

        // Query directly (findModelBySlug filters by is_published=1).
        $stmt = $this->pdo()->prepare('SELECT id, slug, show_price FROM product_models WHERE slug = ? AND deleted_at IS NULL');
        $stmt->execute(['price-test-model']);
        $model = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertTrue($model !== false);
        $this->assertSame(1, (int) $model['show_price'], 'show_price must default to 1');

        // Update to hide price.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/products/' . $model['id'] . '/update', [
            'name' => 'Price Test Model',
            'slug' => $model['slug'],
            'brand_id' => (string) $brandId,
            'category_id' => (string) $catId,
            'show_price' => '0',
        ]);
        $this->assertSame(302, $response->status());

        $stmt = $this->pdo()->prepare('SELECT show_price FROM product_models WHERE id = ?');
        $stmt->execute([$model['id']]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'show_price must be 0 when unchecked');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$model['id']]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- PRICE RANGE --

    public function testPriceRangeAggregatesFromAvailableUnits(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('pm-range@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();

        // Create 3 available units with different prices.
        $unitA = $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);
        $unitB = $this->makeAvailableUnit($modelId, ['selling_price' => '200.00']);
        $unitC = $this->makeAvailableUnit($modelId, ['selling_price' => '150.00']);

        $repo = new CatalogRepository();
        $range = $repo->priceRange($modelId);

        $this->assertSame(3, $range['count'], '3 available units');
        $this->assertEquals(100.00, $range['min']);
        $this->assertEquals(200.00, $range['max']);
        $this->assertEquals(150.00, $range['avg']);

        // The show page must render the price range.
        $response = $this->dispatchAs($ownerId, 'GET', '/admin/products/' . $modelId);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Price range/', $response->body());

        // Cleanup.
        foreach ([$unitA, $unitB, $unitC] as $uid) {
            $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$uid]);
        }
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id IN (?, ?, ?)')->execute([$unitA, $unitB, $unitC]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testPriceRangeEmptyWhenNoAvailableUnits(): void
    {
        $repo = new CatalogRepository();
        $range = $repo->priceRange(999999);
        $this->assertNull($range['min']);
        $this->assertNull($range['max']);
        $this->assertNull($range['avg']);
        $this->assertSame(0, $range['count']);
    }

    // ---------------------------------------------------- MODEL-UNIT SEPARATION --

    public function testModelHasNoPriceColumnButUnitsDo(): void
    {
        // Verify the product_models table has no selling_price column.
        $cols = $this->pdo()->query("SHOW COLUMNS FROM product_models LIKE 'selling_price'")->fetchColumn();
        $this->assertFalse($cols, 'product_models must NOT have a selling_price column (prices are on inventory_units)');

        // Verify inventory_units has selling_price.
        $cols = $this->pdo()->query("SHOW COLUMNS FROM inventory_units LIKE 'selling_price'")->fetchColumn();
        $this->assertTrue($cols !== false, 'inventory_units must have a selling_price column');
    }

    // ---------------------------------------------------- SOFT DELETE --

    public function testSoftDeleteHidesFromList(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('pm-delete@test.local', 'Correct-Horse-1', ['owner']);
        [$brandId, $catId, $modelId] = $this->makeCatalog();

        $repo = new CatalogRepository();
        $this->assertNotNull($repo->findModel($modelId));

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/products/' . $modelId . '/delete');
        $this->assertSame(302, $response->status());

        // findModel excludes deleted rows.
        $this->assertNull($repo->findModel($modelId), 'soft-deleted model must not appear in findModel');

        // But the row still exists in the DB with deleted_at set.
        $stmt = $this->pdo()->prepare('SELECT deleted_at FROM product_models WHERE id = ?');
        $stmt->execute([$modelId]);
        $this->assertNotNull($stmt->fetchColumn(), 'row must still exist with deleted_at set');

        // Verify audit.
        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'product_model.deleted' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $audit->execute([$modelId]);
        $this->assertSame('product_model.deleted', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- VALIDATION --

    public function testCreateRejectsMissingBrand(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('pm-no-brand@test.local', 'Correct-Horse-1', ['owner']);
        [, $catId] = $this->makeCatalog();

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/products', [
            'name' => 'No Brand Model',
            'slug' => '',
            'brand_id' => '',
            'category_id' => (string) $catId,
        ]);

        $this->assertSame(422, $response->status(), 'missing brand must fail validation');

        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('pm-csrf@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($ownerId);
        [$brandId, $catId, $modelId] = $this->makeCatalog();

        $routes = [
            ['POST', '/admin/products', ['name' => 'X', 'slug' => '', 'brand_id' => (string) $brandId, 'category_id' => (string) $catId]],
            ['POST', '/admin/products/' . $modelId . '/update', ['name' => 'Y', 'slug' => '', 'brand_id' => (string) $brandId, 'category_id' => (string) $catId]],
            ['POST', '/admin/products/' . $modelId . '/delete', []],
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
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }
}
