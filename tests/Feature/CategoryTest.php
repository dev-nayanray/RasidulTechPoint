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
 * Product categories — HTTP-level + service-level tests.
 *
 * Proves:
 *  - CRUD with all fields (name, slug, parent, description, sort_order,
 *    seo_title, seo_description, image_path, is_active)
 *  - slug uniqueness
 *  - hierarchy validation (self-parent, circular A→B→A)
 *  - delete with reassignment (models moved to target, children reparented)
 *  - delete without reassignment when no models → succeeds
 *  - delete with models but no target → rejected
 *  - sort order persistence
 *  - SEO fields persistence
 *  - audit trail
 *  - permission enforcement
 *  - CSRF on state-changing routes
 */
final class CategoryTest extends TestCase
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

    // ---------------------------------------------------- PERMISSIONS --

    public function testCashierBlockedFromCreatingCategories(): void
    {
        $cashierId = $this->makeUser('cat-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);

        // Cashier can view (products.view).
        $response = $this->dispatchAs($cashierId, 'GET', '/admin/categories');
        $this->assertSame(200, $response->status());

        // Cashier cannot create (no products.create).
        $this->assertThrows(ForbiddenException::class, function () use ($cashierId) {
            $this->dispatchAs($cashierId, 'POST', '/admin/categories', [
                'name' => 'X', 'slug' => 'x',
            ]);
        });
    }

    // ---------------------------------------------------- CREATE --

    public function testCreateCategoryWithAllFields(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('cat-create@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/categories', [
            'name' => 'Business Laptops',
            'slug' => 'business-laptops',
            'parent_id' => '',
            'description' => 'Enterprise-grade laptops',
            'seo_title' => 'Buy Business Laptops | RTP',
            'seo_description' => 'Quality business laptops from Dubai.',
            'sort_order' => 5,
            'is_active' => '1',
        ]);

        $this->assertSame(302, $response->status());

        $repo = new CatalogRepository();
        $cat = $repo->findCategoryBySlug('business-laptops');
        $this->assertNotNull($cat);
        $this->assertSame('Business Laptops', $cat['name']);
        $this->assertSame('Enterprise-grade laptops', $cat['description']);
        $this->assertSame('Buy Business Laptops | RTP', $cat['seo_title']);
        $this->assertSame('Quality business laptops from Dubai.', $cat['seo_description']);
        $this->assertSame(5, (int) $cat['sort_order']);
        $this->assertSame(1, (int) $cat['is_active']);

        // Verify audit.
        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'category.created' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('category.created', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$cat['id']]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testCreateRejectsDuplicateSlug(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('cat-dup@test.local', 'Correct-Horse-1', ['owner']);

        // First create succeeds.
        $this->dispatchAs($ownerId, 'POST', '/admin/categories', [
            'name' => 'First', 'slug' => 'dup-slug',
        ]);

        // Second create with the same slug → should fail at the DB level
        // (unique key). The Validator's unique rule catches it first →
        // redirect with flash error (not a 422 because the controller
        // redirects on validation error).
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/categories', [
            'name' => 'Second', 'slug' => 'dup-slug',
        ]);
        $this->assertSame(302, $response->status(), 'duplicate slug must redirect (not 200)');

        // Verify only one category with that slug exists.
        $count = $this->pdo()->query("SELECT COUNT(*) FROM categories WHERE slug = 'dup-slug'")->fetchColumn();
        $this->assertSame(1, (int) $count);

        // Cleanup.
        $this->pdo()->prepare("DELETE FROM categories WHERE slug = 'dup-slug'")->execute();
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- HIERARCHY --

    public function testCategoryCannotBeItsOwnParent(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('cat-self@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new CatalogRepository();
        $id = $repo->createCategory(['name' => 'Self Test', 'slug' => 'self-test-' . bin2hex(random_bytes(3))]);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/categories/' . $id . '/update', [
            'name' => 'Self Test', 'slug' => 'self-test-' . bin2hex(random_bytes(3)),
            'parent_id' => (string) $id, // self-parent!
            'is_active' => '1',
        ]);

        $this->assertSame(302, $response->status(), 'self-parent must redirect (not 200)');

        // Verify parent_id was NOT set to self.
        $cat = $repo->findCategory($id);
        $this->assertNotSame($id, (int) ($cat['parent_id'] ?? 0));

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testCircularReferencePrevented(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('cat-cycle@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new CatalogRepository();
        $aId = $repo->createCategory(['name' => 'Parent Cat', 'slug' => 'parent-cat-' . bin2hex(random_bytes(3))]);
        $bId = $repo->createCategory(['name' => 'Child Cat', 'slug' => 'child-cat-' . bin2hex(random_bytes(3)), 'parent_id' => $aId]);

        // Try to set A's parent to B → A→B→A circular.
        $this->assertTrue($repo->wouldCreateCycle($aId, $bId), 'A→B→A must be detected as circular');
        $this->assertFalse($repo->wouldCreateCycle($aId, null), 'no parent is never circular');
        $this->assertFalse($repo->wouldCreateCycle($aId, 0), 'parent_id=0 is never circular');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM categories WHERE id IN (?, ?)')->execute([$bId, $aId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- SORT ORDER --

    public function testSortOrderPersistsAndAffectsListing(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('cat-sort@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new CatalogRepository();
        $aId = $repo->createCategory(['name' => 'Z-Sort Cat', 'slug' => 'z-sort-' . bin2hex(random_bytes(3)), 'sort_order' => 1]);
        $bId = $repo->createCategory(['name' => 'A-Sort Cat', 'slug' => 'a-sort-' . bin2hex(random_bytes(3)), 'sort_order' => 10]);

        // Categories are ordered by sort_order ASC, then name ASC.
        // A-Sort (sort=10) should come AFTER Z-Sort (sort=1) despite alphabetical.
        $all = $repo->categories();
        $posA = -1; $posB = -1;
        foreach ($all as $i => $cat) {
            if ((int) $cat['id'] === $aId) $posA = $i;
            if ((int) $cat['id'] === $bId) $posB = $i;
        }
        $this->assertGreaterThan($posA, $posB, 'sort_order=1 must sort before sort_order=10');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM categories WHERE id IN (?, ?)')->execute([$aId, $bId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- DELETE --

    public function testDeleteEmptyCategorySucceeds(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('cat-del-empty@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new CatalogRepository();
        $id = $repo->createCategory(['name' => 'Temp Cat', 'slug' => 'temp-cat-' . bin2hex(random_bytes(3))]);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/categories/' . $id . '/delete');
        $this->assertSame(302, $response->status());
        $this->assertNull($repo->findCategory($id));

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testDeleteCategoryWithModelsRequiresReassignment(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('cat-del-models@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new CatalogRepository();
        $sourceId = $repo->createCategory(['name' => 'Source Cat', 'slug' => 'source-cat-' . bin2hex(random_bytes(3))]);
        $targetId = $repo->createCategory(['name' => 'Target Cat', 'slug' => 'target-cat-' . bin2hex(random_bytes(3))]);

        // Create a product model in the source category.
        [$brandId, , $modelId] = $this->makeCatalog();
        $this->pdo()->prepare('UPDATE product_models SET category_id = ? WHERE id = ?')->execute([$sourceId, $modelId]);

        // Try to delete without reassignment → must fail.
        $this->assertThrows(ValidationException::class, function () use ($ownerId, $sourceId) {
            $this->dispatchAs($ownerId, 'POST', '/admin/categories/' . $sourceId . '/delete');
        }, 'cannot delete a category with models without reassignment');

        // Verify the category still exists.
        $this->assertNotNull($repo->findCategory($sourceId));

        // Delete WITH reassignment.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/categories/' . $sourceId . '/delete', [
            'reassign_to' => (string) $targetId,
        ]);
        $this->assertSame(302, $response->status());

        // Verify the source category is gone.
        $this->assertNull($repo->findCategory($sourceId));

        // Verify the model was reassigned to the target.
        $stmt = $this->pdo()->prepare('SELECT category_id FROM product_models WHERE id = ?');
        $stmt->execute([$modelId]);
        $this->assertSame($targetId, (int) $stmt->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$targetId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testDeleteReassignsChildrenToGrandparent(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('cat-del-child@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new CatalogRepository();
        $grandId = $repo->createCategory(['name' => 'Grandparent', 'slug' => 'gp-' . bin2hex(random_bytes(3))]);
        $parentId = $repo->createCategory(['name' => 'Parent', 'slug' => 'p-' . bin2hex(random_bytes(3)), 'parent_id' => $grandId]);
        $childId = $repo->createCategory(['name' => 'Child', 'slug' => 'c-' . bin2hex(random_bytes(3)), 'parent_id' => $parentId]);

        // Delete the parent → child should be reparented to grandparent.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/categories/' . $parentId . '/delete');
        $this->assertSame(302, $response->status());

        // Verify the child's parent is now the grandparent.
        $child = $repo->findCategory($childId);
        $this->assertSame($grandId, (int) $child['parent_id']);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM categories WHERE id IN (?, ?)')->execute([$childId, $grandId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testDeleteCannotReassignToSelf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('cat-del-self@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new CatalogRepository();
        $id = $repo->createCategory(['name' => 'Self Reassign', 'slug' => 'sr-' . bin2hex(random_bytes(3))]);

        // Create a model so reassignment is required.
        [$brandId, , $modelId] = $this->makeCatalog();
        $this->pdo()->prepare('UPDATE product_models SET category_id = ? WHERE id = ?')->execute([$id, $modelId]);

        // Try to reassign to self.
        $this->assertThrows(ValidationException::class, function () use ($ownerId, $id) {
            $this->dispatchAs($ownerId, 'POST', '/admin/categories/' . $id . '/delete', [
                'reassign_to' => (string) $id,
            ]);
        }, 'cannot reassign models to the category being deleted');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- SEO FIELDS --

    public function testSeoFieldsPersist(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('cat-seo@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new CatalogRepository();
        $id = $repo->createCategory([
            'name' => 'SEO Test',
            'slug' => 'seo-test-' . bin2hex(random_bytes(3)),
            'seo_title' => 'Buy Laptops Online',
            'seo_description' => 'Browse our collection of quality laptops.',
        ]);

        $cat = $repo->findCategory($id);
        $this->assertSame('Buy Laptops Online', $cat['seo_title']);
        $this->assertSame('Browse our collection of quality laptops.', $cat['seo_description']);

        // Update SEO fields.
        $repo->updateCategoryFull($id, [
            'name' => 'SEO Test Updated',
            'slug' => $cat['slug'],
            'seo_title' => 'Updated SEO Title',
            'seo_description' => 'Updated meta description.',
            'is_active' => true,
        ]);

        $cat = $repo->findCategory($id);
        $this->assertSame('Updated SEO Title', $cat['seo_title']);
        $this->assertSame('Updated meta description.', $cat['seo_description']);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('cat-csrf@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($ownerId);

        $repo = new CatalogRepository();
        $id = $repo->createCategory(['name' => 'CSRF Cat', 'slug' => 'csrf-' . bin2hex(random_bytes(3))]);

        $routes = [
            ['POST', '/admin/categories', ['name' => 'X', 'slug' => 'x']],
            ['POST', '/admin/categories/' . $id . '/update', ['name' => 'Y', 'slug' => 'y']],
            ['POST', '/admin/categories/' . $id . '/delete', []],
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
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }
}
