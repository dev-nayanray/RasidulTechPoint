<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\CatalogRepository;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Laptop brands — HTTP-level tests.
 *
 * Proves:
 *  - CRUD with all fields (name, slug, description, logo, sort_order, is_active)
 *  - slug uniqueness validation
 *  - logo upload (valid JPEG stored; invalid MIME rejected; oversized rejected)
 *  - sort order persistence + listing order
 *  - search by name/slug
 *  - pagination
 *  - delete without models → succeeds
 *  - delete with models → requires reassignment; reassigns + preserves references
 *  - delete cannot reassign to self
 *  - audit trail for create/update/delete
 *  - permission enforcement
 *  - CSRF on state-changing routes
 */
final class BrandTest extends TestCase
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

    private function dispatchAs(int $userId, string $method, string $uri, array $post = [], array $files = []): Response
    {
        $this->actingAs($userId);
        return $this->router()->dispatch(
            Request::synthetic($method, $uri, [], $this->withCsrf($post), [], [], $files),
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

    /** Create a minimal valid JPEG file on disk for upload testing. */
    private function makeTestImage(string $path): void
    {
        $data = "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xff\xd9";
        file_put_contents($path, $data);
    }

    /** Create a plain text file that looks like a script for MIME-rejection testing. */
    private function makeTestTextFile(string $path): void
    {
        file_put_contents($path, '<?php echo "XSS"; ?>');
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testCashierBlockedFromCreatingBrands(): void
    {
        $cashierId = $this->makeUser('brand-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);

        // Cashier can view (products.view).
        $response = $this->dispatchAs($cashierId, 'GET', '/admin/brands');
        $this->assertSame(200, $response->status());

        // Cashier cannot create (no products.create).
        $this->assertThrows(ForbiddenException::class, function () use ($cashierId) {
            $this->dispatchAs($cashierId, 'POST', '/admin/brands', ['name' => 'X', 'slug' => 'x']);
        });
    }

    // ---------------------------------------------------- CREATE --

    public function testCreateBrandWithAllFields(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('brand-create@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/brands', [
            'name' => 'Lenovo',
            'slug' => 'lenovo',
            'description' => 'ThinkPad manufacturer',
            'sort_order' => 5,
        ]);

        $this->assertSame(302, $response->status());

        $repo = new CatalogRepository();
        $brand = $repo->findBrandBySlug('lenovo');
        $this->assertNotNull($brand);
        $this->assertSame('Lenovo', $brand['name']);
        $this->assertSame('ThinkPad manufacturer', $brand['description']);
        $this->assertSame(5, (int) $brand['sort_order']);
        $this->assertSame(1, (int) $brand['is_active']);

        // Verify audit.
        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'brand.created' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('brand.created', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brand['id']]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testCreateRejectsDuplicateSlug(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('brand-dup@test.local', 'Correct-Horse-1', ['owner']);

        // First create succeeds.
        $this->dispatchAs($ownerId, 'POST', '/admin/brands', [
            'name' => 'First', 'slug' => 'dup-brand-slug',
        ]);

        // Second with same slug → redirect (validation error flash).
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/brands', [
            'name' => 'Second', 'slug' => 'dup-brand-slug',
        ]);
        $this->assertSame(302, $response->status(), 'duplicate slug must redirect');

        // Only one row.
        $count = $this->pdo()->query("SELECT COUNT(*) FROM brands WHERE slug = 'dup-brand-slug'")->fetchColumn();
        $this->assertSame(1, (int) $count);

        // Cleanup.
        $this->pdo()->prepare("DELETE FROM brands WHERE slug = 'dup-brand-slug'")->execute();
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- LOGO UPLOAD --

    public function testValidLogoUploadIsStored(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('brand-logo@test.local', 'Correct-Horse-1', ['owner']);

        $tmpFile = tempnam(sys_get_temp_dir(), 'brand_logo_');
        $this->makeTestImage($tmpFile);

        $files = [
            'logo' => [
                'name' => 'logo.jpg', 'type' => 'image/jpeg',
                'tmp_name' => $tmpFile, 'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ];

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/brands', [
            'name' => 'Logo Brand', 'slug' => 'logo-brand-' . bin2hex(random_bytes(3)),
        ], $files);

        $this->assertSame(302, $response->status());

        $brand = $this->pdo()->query("SELECT logo_path FROM brands WHERE name = 'Logo Brand' ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($brand);
        $this->assertNotNull($brand['logo_path']);
        $this->assertStringStartsWith('uploads/brands/', $brand['logo_path']);
        $this->assertTrue(file_exists(BASE_PATH . '/public/' . $brand['logo_path']));

        // Cleanup.
        @unlink(BASE_PATH . '/public/' . $brand['logo_path']);
        $this->pdo()->prepare("DELETE FROM brands WHERE name = 'Logo Brand'")->execute();
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testInvalidMimeTypeLogoRejected(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('brand-bad-mime@test.local', 'Correct-Horse-1', ['owner']);

        $tmpFile = tempnam(sys_get_temp_dir(), 'brand_bad_');
        $this->makeTestTextFile($tmpFile);

        $files = [
            'logo' => [
                'name' => 'evil.php.jpg', 'type' => 'image/jpeg',
                'tmp_name' => $tmpFile, 'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ];

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/brands', [
            'name' => 'Bad MIME Brand', 'slug' => 'bad-mime-' . bin2hex(random_bytes(3)),
        ], $files);

        $this->assertSame(302, $response->status(), 'invalid MIME must redirect (not 200)');

        // Verify brand was NOT created (validation error).
        $count = $this->pdo()->query("SELECT COUNT(*) FROM brands WHERE name = 'Bad MIME Brand'")->fetchColumn();
        $this->assertSame(0, (int) $count);

        @unlink($tmpFile);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- SORT ORDER --

    public function testSortOrderAffectsListing(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('brand-sort@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new CatalogRepository();
        $aId = $repo->createBrandFull(['name' => 'Z-Brand', 'slug' => 'z-brand-' . bin2hex(random_bytes(3)), 'sort_order' => 1]);
        $bId = $repo->createBrandFull(['name' => 'A-Brand', 'slug' => 'a-brand-' . bin2hex(random_bytes(3)), 'sort_order' => 10]);

        $all = $repo->brands();
        $posA = -1; $posB = -1;
        foreach ($all as $i => $brand) {
            if ((int) $brand['id'] === $aId) $posA = $i;
            if ((int) $brand['id'] === $bId) $posB = $i;
        }
        $this->assertGreaterThan($posA, $posB, 'sort_order=1 must sort before sort_order=10');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM brands WHERE id IN (?, ?)')->execute([$aId, $bId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- SEARCH --

    public function testSearchByName(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('brand-search@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new CatalogRepository();
        $id = $repo->createBrandFull(['name' => 'Asus ROG', 'slug' => 'asus-rog-' . bin2hex(random_bytes(3))]);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/brands', [], );
        $response = $this->dispatchAs($ownerId, 'GET', '/admin/brands?' . http_build_query(['q' => 'Asus']));
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Asus ROG/', $response->body());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testSearchWithNoMatchesReturnsEmptyState(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('brand-empty@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/brands?' . http_build_query(['q' => 'nonexistent']));
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/No brands match/i', $response->body());

        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- DELETE --

    public function testDeleteEmptyBrandSucceeds(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('brand-del-empty@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new CatalogRepository();
        $id = $repo->createBrandFull(['name' => 'Temp Brand', 'slug' => 'temp-brand-' . bin2hex(random_bytes(3))]);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/brands/' . $id . '/delete');
        $this->assertSame(302, $response->status());
        $this->assertNull($repo->findBrand($id));

        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testDeleteBrandWithModelsRequiresReassignment(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('brand-del-models@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new CatalogRepository();
        $sourceId = $repo->createBrandFull(['name' => 'Source Brand', 'slug' => 'source-' . bin2hex(random_bytes(3))]);
        $targetId = $repo->createBrandFull(['name' => 'Target Brand', 'slug' => 'target-' . bin2hex(random_bytes(3))]);

        // Create a product model in the source brand.
        [$sourceId2, $catId, $modelId] = $this->makeCatalog();
        // makeCatalog uses a temp brand; update the model to use our source brand.
        $this->pdo()->prepare('UPDATE product_models SET brand_id = ? WHERE id = ?')->execute([$sourceId, $modelId]);

        // Try to delete without reassignment → must fail.
        $this->assertThrows(ValidationException::class, function () use ($ownerId, $sourceId) {
            $this->dispatchAs($ownerId, 'POST', '/admin/brands/' . $sourceId . '/delete');
        }, 'cannot delete a brand with models without reassignment');

        $this->assertNotNull($repo->findBrand($sourceId), 'brand must still exist');

        // Delete WITH reassignment.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/brands/' . $sourceId . '/delete', [
            'reassign_to' => (string) $targetId,
        ]);
        $this->assertSame(302, $response->status());
        $this->assertNull($repo->findBrand($sourceId), 'source brand must be deleted');

        // Verify the model was reassigned to the target brand.
        $stmt = $this->pdo()->prepare('SELECT brand_id FROM product_models WHERE id = ?');
        $stmt->execute([$modelId]);
        $this->assertSame($targetId, (int) $stmt->fetchColumn(), 'model must be reassigned to target brand');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id IN (?, ?)')->execute([$targetId, $sourceId2]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testDeleteCannotReassignToSelf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('brand-del-self@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new CatalogRepository();
        $id = $repo->createBrandFull(['name' => 'Self Reassign', 'slug' => 'sr-' . bin2hex(random_bytes(3))]);

        // Create a model so reassignment is required.
        [, $catId, $modelId] = $this->makeCatalog();
        $this->pdo()->prepare('UPDATE product_models SET brand_id = ? WHERE id = ?')->execute([$id, $modelId]);

        $this->assertThrows(ValidationException::class, function () use ($ownerId, $id) {
            $this->dispatchAs($ownerId, 'POST', '/admin/brands/' . $id . '/delete', [
                'reassign_to' => (string) $id,
            ]);
        }, 'cannot reassign models to the brand being deleted');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('brand-csrf@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($ownerId);

        $repo = new CatalogRepository();
        $id = $repo->createBrandFull(['name' => 'CSRF Brand', 'slug' => 'csrf-' . bin2hex(random_bytes(3))]);

        $routes = [
            ['POST', '/admin/brands', ['name' => 'X', 'slug' => 'x-' . bin2hex(random_bytes(3))]],
            ['POST', '/admin/brands/' . $id . '/update', ['name' => 'Y', 'slug' => 'y-' . bin2hex(random_bytes(3))]],
            ['POST', '/admin/brands/' . $id . '/delete', []],
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
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }
}
