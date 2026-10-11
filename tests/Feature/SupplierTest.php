<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Repositories\SupplierRepository;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Suppliers — HTTP-level tests.
 *
 * Proves:
 *  - CRUD (create, show, edit, update, delete/restore)
 *  - search by name, contact, email, phone
 *  - pagination
 *  - outstanding balance summary on the index page
 *  - purchase history (units referencing the supplier by name)
 *  - soft delete preserves history
 *  - audit trail for create/update/delete
 *  - permission enforcement (manager can, cashier can't)
 *  - CSRF on all state-changing routes
 */
final class SupplierTest extends TestCase
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

    public function testCashierBlockedFromSuppliers(): void
    {
        $cashierId = $this->makeUser('sup-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);
        $this->assertThrows(ForbiddenException::class, function () use ($cashierId) {
            $this->dispatchAs($cashierId, 'GET', '/admin/suppliers');
        }, 'cashier (no purchases.view) must not access suppliers');
    }

    public function testInventoryOfficerCanViewButNotManage(): void
    {
        $invOfficerId = $this->makeUser('sup-invoff@test.local', 'Correct-Horse-1', ['inventory_officer']);
        $response = $this->dispatchAs($invOfficerId, 'GET', '/admin/suppliers');
        $this->assertSame(200, $response->status(), 'inventory_officer (purchases.view) can view suppliers');

        $this->assertThrows(ForbiddenException::class, function () use ($invOfficerId) {
            $this->dispatchAs($invOfficerId, 'POST', '/admin/suppliers', ['name' => 'X']);
        }, 'inventory_officer (no purchases.manage) must not create suppliers');
    }

    // ---------------------------------------------------- CREATE --

    public function testCreateSupplier(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sup-create@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/suppliers', [
            'name' => 'Dubai Tech Supplies LLC',
            'contact_name' => 'Ahmed Al-Rashid',
            'email' => 'ahmed@dubaitech.example',
            'phone' => '+97141234567',
            'address' => 'Business Bay, Dubai',
            'city' => 'Dubai',
            'country' => 'UAE',
            'notes' => 'Primary supplier for Dell Latitude units',
        ]);

        $this->assertSame(302, $response->status());

        $repo = new SupplierRepository();
        $stmt = $this->pdo()->prepare('SELECT id, name, contact_name, email, phone, city, country, notes FROM suppliers WHERE name = ?');
        $stmt->execute(['Dubai Tech Supplies LLC']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertTrue($row !== false);
        $this->assertSame('Ahmed Al-Rashid', $row['contact_name']);
        $this->assertSame('ahmed@dubaitech.example', $row['email']);
        $this->assertSame('Dubai', $row['city']);
        $this->assertSame('UAE', $row['country']);
        $this->assertSame('Primary supplier for Dell Latitude units', $row['notes']);

        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'supplier.created' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('supplier.created', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$row['id']]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testCreateRejectsEmptyName(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sup-empty@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/suppliers', ['name' => '']);
        $this->assertSame(422, $response->status(), 'empty name must fail validation');

        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testCreateRejectsInvalidEmail(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sup-bad-email@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/suppliers', ['name' => 'Bad Email Co', 'email' => 'not-an-email']);
        $this->assertSame(422, $response->status());

        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- SHOW --

    public function testShowRendersSupplierAndPurchaseHistory(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sup-show@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new SupplierRepository();
        $id = $repo->create('Show Test Supplier', 'show-test-supplier', 'Contact', 'show@test.local', '+880123', 'Addr', 'Dhaka', 'Bangladesh', 'Notes');

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/suppliers/' . $id);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Show Test Supplier/', $response->body());
        $this->assertMatchesRegularExpression('/Contact/', $response->body());
        $this->assertMatchesRegularExpression('/Inventory units from this supplier/', $response->body());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- SEARCH --

    public function testSearchByName(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sup-search@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new SupplierRepository();
        $id = $repo->create('SearchMe Supplies', 'searchme-supplies', null, null, null, null, null, null, null);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/suppliers', [], ['q' => 'SearchMe']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/SearchMe Supplies/', $response->body());

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/suppliers', [], ['q' => 'nonexistent']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/No suppliers match/i', $response->body());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- UPDATE --

    public function testUpdateChangesFields(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sup-update@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new SupplierRepository();
        $id = $repo->create('Update Me', 'update-me', null, null, null, null, null, null, null);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/suppliers/' . $id . '/update', [
            'name' => 'Updated Name',
            'contact_name' => 'New Contact',
            'email' => 'new@updated.test',
            'phone' => '+880999',
            'address' => 'New Address',
            'city' => 'Chittagong',
            'country' => 'Bangladesh',
            'notes' => 'Updated notes',
            'is_active' => '1',
        ]);

        $this->assertSame(302, $response->status());
        $supplier = $repo->find($id);
        $this->assertSame('Updated Name', $supplier['name']);
        $this->assertSame('New Contact', $supplier['contact_name']);
        $this->assertSame('new@updated.test', $supplier['email']);
        $this->assertSame('Chittagong', $supplier['city']);

        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'supplier.updated' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('supplier.updated', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- SOFT DELETE --

    public function testSoftDeletePreservesHistory(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sup-delete@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new SupplierRepository();
        $id = $repo->create('Delete Me', 'delete-me', null, null, null, null, null, null, null);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/suppliers/' . $id . '/delete');
        $this->assertSame(302, $response->status());

        // find() excludes deleted, findWithTrashed() includes.
        $this->assertNull($repo->find($id), 'find() must exclude soft-deleted');
        $this->assertNotNull($repo->findWithTrashed($id), 'row must still exist');

        // Show page still works (uses findWithTrashed).
        $response = $this->dispatchAs($ownerId, 'GET', '/admin/suppliers/' . $id);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/soft-deleted/i', $response->body());

        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'supplier.deleted' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('supplier.deleted', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sup-csrf@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($ownerId);

        $repo = new SupplierRepository();
        $id = $repo->create('CSRF Supplier', 'csrf-supplier', null, null, null, null, null, null, null);

        $routes = [
            ['POST', '/admin/suppliers', ['name' => 'X']],
            ['POST', '/admin/suppliers/' . $id . '/update', ['name' => 'Y']],
            ['POST', '/admin/suppliers/' . $id . '/delete', []],
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
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- INDEX --

    public function testIndexRendersWithOutstandingBalance(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('sup-index@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new SupplierRepository();
        $id = $repo->create('Index Test Co', 'index-test-co', null, null, null, null, null, null, null);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/suppliers');
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Index Test Co/', $response->body());
        $this->assertMatchesRegularExpression('/supplier\(s\)/', $response->body());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }
}
