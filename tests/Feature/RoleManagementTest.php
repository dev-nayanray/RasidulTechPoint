<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\RoleRepository;
use App\Services\AuthService;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Role & permission management — HTTP-level tests.
 *
 * Proves:
 *  - role creation (store): valid input → 302 + audit row; duplicate slug → 422
 *  - role show: renders member list + permission badges
 *  - role delete: non-system empty role → 302 + audit row; system role → rejected;
 *    role with members → rejected
 *  - permission sync: owner role untouchable; non-owner role gets the exact set
 *  - audit diff: added/removed arrays are correct
 *  - permission enforcement: manager (no roles.manage) blocked from every route
 *  - CSRF on all state-changing routes
 */
final class RoleManagementTest extends TestCase
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

    public function testManagerBlockedFromAllRoleRoutes(): void
    {
        $managerId = $this->makeUser('role-mgr-block@test.local', 'Correct-Horse-1', ['manager']);
        $techRoleId = $this->roleId('technician');

        // Manager does NOT have roles.manage → blocked from every role route.
        $this->assertThrows(ForbiddenException::class, fn () => $this->dispatchAs($managerId, 'GET', '/admin/roles'));
        $this->assertThrows(ForbiddenException::class, fn () => $this->dispatchAs($managerId, 'GET', '/admin/roles/create'));
        $this->assertThrows(ForbiddenException::class, fn () => $this->dispatchAs($managerId, 'POST', '/admin/roles', ['name' => 'X', 'slug' => 'x']));
        $this->assertThrows(ForbiddenException::class, fn () => $this->dispatchAs($managerId, 'GET', '/admin/roles/' . $techRoleId));
        $this->assertThrows(ForbiddenException::class, fn () => $this->dispatchAs($managerId, 'GET', '/admin/roles/' . $techRoleId . '/edit'));
        $this->assertThrows(ForbiddenException::class, fn () => $this->dispatchAs($managerId, 'POST', '/admin/roles/' . $techRoleId . '/permissions', ['permissions' => []]));
        $this->assertThrows(ForbiddenException::class, fn () => $this->dispatchAs($managerId, 'POST', '/admin/roles/' . $techRoleId . '/delete'));
    }

    // ---------------------------------------------------- CREATE --

    public function testOwnerCanCreateCustomRole(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('role-create-owner@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/roles', [
            'name' => 'Shop Supervisor',
            'slug' => 'shop-supervisor',
            'description' => 'Oversees shop floor operations',
        ]);

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/admin/roles/\d+/edit$#', $response->headers()['Location'] ?? '');

        // Verify the role was created.
        $repo = new RoleRepository();
        $this->assertTrue($repo->slugExists('shop-supervisor'));

        $stmt = $this->pdo()->prepare('SELECT id, name, description, is_system FROM roles WHERE slug = ?');
        $stmt->execute(['shop-supervisor']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Shop Supervisor', $row['name']);
        $this->assertSame('Oversees shop floor operations', $row['description']);
        $this->assertSame(0, (int) $row['is_system'], 'custom role must have is_system=0');

        // Verify audit trail.
        $audit = $this->pdo()->prepare("SELECT action, metadata FROM audit_logs WHERE action = 'role.created' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $log = $audit->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('role.created', $log['action']);
        $meta = json_decode((string) $log['metadata'], true);
        $this->assertSame('Shop Supervisor', $meta['name']);
        $this->assertSame('shop-supervisor', $meta['slug']);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([(int) $row['id']]);
        $this->pdo()->prepare('DELETE FROM roles WHERE id = ?')->execute([(int) $row['id']]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testCreateRejectsDuplicateSlug(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('role-dup-owner@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/roles', [
            'name' => 'Technician',
            'slug' => 'technician', // already exists
            'description' => '',
        ]);

        $this->assertSame(422, $response->status(), 'duplicate slug must fail validation');

        // Verify no new role was created.
        $count = $this->pdo()->query("SELECT COUNT(*) FROM roles WHERE slug = 'technician'")->fetchColumn();
        $this->assertSame(1, (int) $count, 'only the original technician role should exist');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testCreateRejectsInvalidSlug(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('role-invalid-owner@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/roles', [
            'name' => 'Bad',
            'slug' => 'has spaces and !@#',
            'description' => '',
        ]);

        $this->assertSame(422, $response->status(), 'invalid slug must fail validation');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- SHOW --

    public function testShowRendersMembersAndPermissions(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('role-show-owner@test.local', 'Correct-Horse-1', ['owner']);

        // Create a custom role with known permissions (don't rely on the
        // seeded technician role — other test classes may have modified it).
        $repo = new RoleRepository();
        $roleId = $repo->create('Show Test Role', 'show-test-' . bin2hex(random_bytes(3)), 'For show test');

        // Assign specific permissions.
        $repo->syncPermissions($roleId, ['dashboard.view', 'inspections.manage', 'notifications.view']);

        // Add a member.
        $memberId = $this->makeUser('role-show-member@test.local', 'Correct-Horse-1', []);
        $this->pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')
            ->execute([$memberId, $roleId]);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/roles/' . $roleId);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Show Test Role/', $response->body());
        $this->assertMatchesRegularExpression('/role-show-member@test\.local/', $response->body(), 'member must appear');
        $this->assertMatchesRegularExpression('/inspections\.manage/', $response->body(), 'permission badge must appear');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$memberId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$memberId]);
        $this->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
        $this->pdo()->prepare('DELETE FROM roles WHERE id = ?')->execute([$roleId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testShowForMemberlessRoleShowsEmptyState(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('role-empty-owner@test.local', 'Correct-Horse-1', ['owner']);

        // Create a custom role with no members.
        $repo = new RoleRepository();
        $roleId = $repo->create('Test Empty Role', 'test-empty-role', 'No members');

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/roles/' . $roleId);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/No users currently hold this role/', $response->body());

        // Cleanup.
        $repo->delete($roleId);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- PERMISSION SYNC --

    public function testPermissionSyncRecordsAuditDiff(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('role-sync-owner@test.local', 'Correct-Horse-1', ['owner']);
        $techRoleId = $this->roleId('technician');

        $repo = new RoleRepository();
        $before = $repo->find($techRoleId)['permission_names'];

        $newSet = ['dashboard.view', 'products.view', 'inspections.manage', 'service.manage', 'notifications.view'];
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/roles/' . $techRoleId . '/permissions', [
            'permissions' => $newSet,
        ]);
        $this->assertSame(302, $response->status());

        $after = $repo->find($techRoleId)['permission_names'];
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        // Verify audit row.
        $audit = $this->pdo()->prepare("SELECT metadata FROM audit_logs WHERE action = 'roles.permissions_updated' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $audit->execute([$techRoleId]);
        $meta = json_decode((string) $audit->fetchColumn(), true);
        $this->assertSame($added, $meta['added']);
        $this->assertSame($removed, $meta['removed']);

        // Cleanup: restore original permissions.
        $repo->syncPermissions($techRoleId, $before);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testOwnerRolePermissionsAreUntouchable(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('role-untouchable-owner@test.local', 'Correct-Horse-1', ['owner']);
        $ownerRoleId = $this->roleId('owner');

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/roles/' . $ownerRoleId . '/permissions', [
            'permissions' => [], // try to strip all permissions
        ]);
        $this->assertSame(302, $response->status(), 'syncPermissions on owner is a no-op but still redirects');

        // A fresh owner must still have full access.
        $freshOwnerId = $this->makeUser('role-untouchable-fresh@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($freshOwnerId);
        $this->assertTrue(AuthService::can('settings.manage'));
        $this->assertTrue(AuthService::can('roles.manage'));

        // Cleanup.
        foreach ([$ownerId, $freshOwnerId] as $id) {
            $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
            $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
    }

    // ---------------------------------------------------- DELETE --

    public function testCanDeleteEmptyCustomRole(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('role-delete-owner@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new RoleRepository();
        $roleId = $repo->create('Temp Role', 'temp-role-' . bin2hex(random_bytes(3)), 'To be deleted');

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/roles/' . $roleId . '/delete');
        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/admin/roles$#', $response->headers()['Location'] ?? '');

        // Verify the role is gone.
        $this->assertNull($repo->find($roleId));

        // Verify audit trail.
        $audit = $this->pdo()->prepare("SELECT action, metadata FROM audit_logs WHERE action = 'role.deleted' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $audit->execute([$roleId]);
        $log = $audit->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('role.deleted', $log['action']);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testCannotDeleteSystemRole(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('role-sys-del@test.local', 'Correct-Horse-1', ['owner']);
        $techRoleId = $this->roleId('technician'); // system role

        $this->assertThrows(ValidationException::class, function () use ($ownerId, $techRoleId) {
            $this->dispatchAs($ownerId, 'POST', '/admin/roles/' . $techRoleId . '/delete');
        }, 'system roles must not be deletable');

        // Verify the role still exists.
        $repo = new RoleRepository();
        $this->assertNotNull($repo->find($techRoleId));

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testCannotDeleteRoleWithMembers(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('role-members-del@test.local', 'Correct-Horse-1', ['owner']);

        $repo = new RoleRepository();
        $roleId = $repo->create('Populated Role', 'populated-role-' . bin2hex(random_bytes(3)), 'Has members');

        // Add a member to the role.
        $memberId = $this->makeUser('role-member@test.local', 'Correct-Horse-1', []);
        $this->pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')
            ->execute([$memberId, $roleId]);

        $this->assertThrows(ValidationException::class, function () use ($ownerId, $roleId) {
            $this->dispatchAs($ownerId, 'POST', '/admin/roles/' . $roleId . '/delete');
        }, 'role with members must not be deletable');

        // Verify the role still exists.
        $this->assertNotNull($repo->find($roleId));

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$memberId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$memberId]);
        $this->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
        $this->pdo()->prepare('DELETE FROM roles WHERE id = ?')->execute([$roleId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('role-csrf@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($ownerId);
        $techRoleId = $this->roleId('technician');

        $routes = [
            ['POST', '/admin/roles', ['name' => 'X', 'slug' => 'x', 'description' => '']],
            ['POST', '/admin/roles/' . $techRoleId . '/permissions', ['permissions' => []]],
            ['POST', '/admin/roles/' . $techRoleId . '/delete', []],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(
                    Request::synthetic($method, $uri, [], $post),
                    $this->resolver()
                );
            }, "{$method} {$uri} must require CSRF token");
        }

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- INDEX --

    public function testIndexRendersCreateButtonAndRoleLinks(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('role-index@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/roles');
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Create role/', $response->body());
        $this->assertMatchesRegularExpression('/href="[^"]*\/admin\/roles\/\d+"/', $response->body(), 'role names must link to show page');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }
}
