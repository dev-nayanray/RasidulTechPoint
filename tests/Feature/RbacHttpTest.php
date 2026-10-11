<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Services\AuthService;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * HTTP-level RBAC tests.
 *
 * The existing RbacTest verifies AuthService::can(), the permission
 * matrix, UserRepository::assertNotLastOwner(), and PermissionMiddleware
 * in isolation. This file complements it by driving the real application
 * router (routes/web.php) through real controllers with different role
 * slugs, proving that:
 *
 *  - every role can only reach the routes its matrix allows;
 *  - privilege-escalation attempts (cashier self-promoting to owner,
 *    manager editing role permissions) are blocked server-side;
 *  - the last active super admin cannot be deactivated or stripped of
 *    the owner role through the HTTP layer;
 *  - a user cannot deactivate their own account;
 *  - every permission denial and every role-permission change is written
 *    to the audit_logs table with the right action + metadata.
 *
 * "Hiding navigation is never security" — these tests prove the server
 * enforces permissions even when the client knows the URL.
 */
final class RbacHttpTest extends TestCase
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

    private function expectForbidden(int $userId, string $method, string $uri, array $post = [], array $get = []): void
    {
        $this->assertThrows(ForbiddenException::class, function () use ($userId, $method, $uri, $post, $get) {
            $this->dispatchAs($userId, $method, $uri, $post, $get);
        }, "user {$userId} must be forbidden from {$method} {$uri}");
    }

    private function expectOk(int $userId, string $method, string $uri, array $post = [], array $get = []): Response
    {
        $response = $this->dispatchAs($userId, $method, $uri, $post, $get);
        $this->assertSame(200, $response->status(), "user {$userId} must reach {$method} {$uri} (got {$response->status()})");
        return $response;
    }

    /**
     * Create a non-system role with exactly the listed permission names.
     * Used when a test needs an actor who holds a specific permission
     * (e.g. users.deactivate) without being an owner — otherwise the
     * last-active-owner guard cannot be exercised at the HTTP layer.
     */
    private function makeCustomRole(string $slug, array $permissionNames): int
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare('INSERT INTO roles (name, slug, description, is_system) VALUES (?, ?, ?, 0)');
        $stmt->execute([ucwords(str_replace('_', ' ', $slug)), $slug, 'Custom test role']);
        $roleId = (int) $pdo->lastInsertId();

        $link = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
        foreach ($permissionNames as $name) {
            $permStmt = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
            $permStmt->execute([$name]);
            $permId = $permStmt->fetchColumn();
            if ($permId !== false) {
                $link->execute([$roleId, (int) $permId]);
            }
        }
        return $roleId;
    }

    private function wipeOwners(): void
    {
        // Deactivate any owners left over from earlier test methods so the
        // last-active-owner guard has a clean slate.
        $this->pdo()->exec(
            "UPDATE users SET is_active = 0 WHERE id IN (
                SELECT user_id FROM (
                    SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                    WHERE r.slug = 'owner'
                ) AS owners)"
        );
    }

    // ------------------------------------------------------ ROLE MATRIX --

    public function testManagerCannotReachRolesOrAuditLogs(): void
    {
        $id = $this->makeUser('matrix-manager@rbac.test', 'Correct-Horse-1', ['manager']);

        // Manager CAN reach the dashboard (dashboard.view).
        $this->expectOk($id, 'GET', '/admin/dashboard');

        // Manager CANNOT reach roles (needs roles.manage).
        $this->expectForbidden($id, 'GET', '/admin/roles');
        $this->expectForbidden($id, 'GET', '/admin/roles/1/edit');
        $this->expectForbidden($id, 'POST', '/admin/roles/1/permissions', ['permissions' => []]);

        // Manager CANNOT reach audit logs (needs audit_logs.view).
        $this->expectForbidden($id, 'GET', '/admin/audit-logs');

        // Manager CANNOT reach settings (needs settings.manage).
        $this->expectForbidden($id, 'GET', '/admin/settings');
        $this->expectForbidden($id, 'POST', '/admin/settings', ['settings' => []]);
    }

    public function testCashierCanReachPosRelatedRoutesButNotInventoryAdjustment(): void
    {
        $id = $this->makeUser('matrix-cashier@rbac.test', 'Correct-Horse-1', ['sales_cashier']);

        // Cashier CAN reach orders (orders.view) and customers (customers.view).
        $this->expectOk($id, 'GET', '/admin/orders');
        $this->expectOk($id, 'GET', '/admin/customers');

        // Cashier CANNOT create users (needs users.create).
        $this->expectForbidden($id, 'GET', '/admin/users/create');
        $this->expectForbidden($id, 'POST', '/admin/users', [
            'name' => 'X', 'email' => 'x@y.z', 'password' => 'Strong-Pass-12', 'roles' => [],
        ]);

        // Cashier CANNOT manage inventory adjustments (needs inventory.adjust).
        $this->expectForbidden($id, 'POST', '/admin/inventory/1/price', ['selling_price' => '200.00']);

        // Cashier CANNOT reach audit logs or settings.
        $this->expectForbidden($id, 'GET', '/admin/audit-logs');
        $this->expectForbidden($id, 'GET', '/admin/settings');
    }

    public function testInventoryOfficerCanManageInventoryButNotOrders(): void
    {
        $id = $this->makeUser('matrix-inventory@rbac.test', 'Correct-Horse-1', ['inventory_officer']);

        // Inventory officer CAN reach inventory.
        $this->expectOk($id, 'GET', '/admin/inventory');
        $this->expectOk($id, 'GET', '/admin/inventory/create');

        // Inventory officer CANNOT manage orders (no orders.view).
        $this->expectForbidden($id, 'GET', '/admin/orders');
        $this->expectForbidden($id, 'GET', '/admin/orders/create');

        // Inventory officer CANNOT reach customers (no customers.view).
        $this->expectForbidden($id, 'GET', '/admin/customers');

        // Inventory officer CANNOT reach audit logs.
        $this->expectForbidden($id, 'GET', '/admin/audit-logs');
    }

    public function testTechnicianCanViewProductsButNotAdjustInventory(): void
    {
        $id = $this->makeUser('matrix-tech@rbac.test', 'Correct-Horse-1', ['technician']);

        // Technician CAN view products (products.view) and inventory (inventory.view).
        $this->expectOk($id, 'GET', '/admin/products');
        $this->expectOk($id, 'GET', '/admin/inventory');

        // Technician CANNOT create products (no products.create).
        $this->expectForbidden($id, 'GET', '/admin/products/create');
        $this->expectForbidden($id, 'POST', '/admin/products', ['name' => 'X']);

        // Technician CANNOT adjust inventory (no inventory.adjust).
        $this->expectForbidden($id, 'POST', '/admin/inventory/1/price', ['selling_price' => '200.00']);

        // Technician CANNOT reach audit logs.
        $this->expectForbidden($id, 'GET', '/admin/audit-logs');
    }

    public function testAccountantCanSeeOrdersAndReportsButNotInventoryReceive(): void
    {
        $id = $this->makeUser('matrix-accountant@rbac.test', 'Correct-Horse-1', ['accountant']);

        // Accountant CAN view orders and customers.
        $this->expectOk($id, 'GET', '/admin/orders');
        $this->expectOk($id, 'GET', '/admin/customers');

        // Accountant CANNOT receive inventory (no inventory.receive).
        $this->expectForbidden($id, 'GET', '/admin/inventory/create');
        $this->expectForbidden($id, 'POST', '/admin/inventory', ['sku' => 'X']);

        // Accountant CANNOT manage products (no products.update).
        $this->expectForbidden($id, 'POST', '/admin/products/1/update', ['name' => 'X']);
    }

    public function testContentManagerCanManageProductsButNotSeeOrders(): void
    {
        $id = $this->makeUser('matrix-content@rbac.test', 'Correct-Horse-1', ['content_manager']);

        // Content manager CAN reach products and brands.
        $this->expectOk($id, 'GET', '/admin/products');
        $this->expectOk($id, 'GET', '/admin/brands');

        // Content manager CANNOT see orders or customers (no orders.view, no customers.view).
        $this->expectForbidden($id, 'GET', '/admin/orders');
        $this->expectForbidden($id, 'GET', '/admin/customers');

        // Content manager CANNOT reach audit logs or settings.
        $this->expectForbidden($id, 'GET', '/admin/audit-logs');
        $this->expectForbidden($id, 'GET', '/admin/settings');
    }

    public function testCustomerRoleHasNoBackOfficeAccess(): void
    {
        $id = $this->makeUser('matrix-customer@rbac.test', 'Correct-Horse-1', ['customer']);

        // Customer is blocked from EVERY /admin/* route.
        foreach ([
            ['GET', '/admin/dashboard'],
            ['GET', '/admin/users'],
            ['GET', '/admin/roles'],
            ['GET', '/admin/products'],
            ['GET', '/admin/inventory'],
            ['GET', '/admin/orders'],
            ['GET', '/admin/customers'],
            ['GET', '/admin/notifications'],
            ['GET', '/admin/settings'],
            ['GET', '/admin/audit-logs'],
        ] as [$method, $uri]) {
            $this->expectForbidden($id, $method, $uri);
        }
    }

    public function testOwnerCanReachEveryAdminRoute(): void
    {
        $id = $this->makeUser('matrix-owner@rbac.test', 'Correct-Horse-1', ['owner']);

        // Owner short-circuits to true for every permission.
        $this->expectOk($id, 'GET', '/admin/dashboard');
        $this->expectOk($id, 'GET', '/admin/users');
        $this->expectOk($id, 'GET', '/admin/roles');
        $this->expectOk($id, 'GET', '/admin/products');
        $this->expectOk($id, 'GET', '/admin/inventory');
        $this->expectOk($id, 'GET', '/admin/orders');
        $this->expectOk($id, 'GET', '/admin/customers');
        $this->expectOk($id, 'GET', '/admin/settings');
        $this->expectOk($id, 'GET', '/admin/audit-logs');

        // Cleanup the extra owner so it doesn't interfere with later owner-protection tests.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }

    // ------------------------------------------ PRIVILEGE ESCALATION --

    public function testCashierCannotSelfPromoteToOwnerViaUserUpdate(): void
    {
        // A cashier who somehow knows the user-update endpoint and submits the
        // owner role id must be rejected at the middleware (permission:users.update)
        // — the controller-level sameRoleSet guard is defense-in-depth and is
        // exercised separately below.
        $cashierId = $this->makeUser('escalation-cashier@rbac.test', 'Correct-Horse-1', ['sales_cashier']);
        $ownerRoleId = $this->roleId('owner');

        $this->expectForbidden($cashierId, 'POST', '/admin/users/' . $cashierId . '/update', [
            'name' => 'Cashier',
            'email' => 'escalation-cashier@rbac.test',
            'phone' => '',
            'password' => '',
            'roles' => [$ownerRoleId], // attempting to grab owner
        ]);

        // Verify the cashier's role assignments are unchanged in the DB.
        $roles = AuthService::rolesFor($cashierId);
        $this->assertContains('sales_cashier', $roles, 'cashier must still hold the sales_cashier role');
        $this->assertNotContains('owner', $roles, 'cashier must NOT have been promoted to owner');
    }

    public function testUserWithUsersUpdateButNotRolesManageCannotChangeRoles(): void
    {
        // Defense-in-depth: even if someone grants users.update to a non-owner
        // role without roles.manage, the controller-level sameRoleSet guard
        // must block any attempt to change role assignments.
        $customRoleId = $this->makeCustomRole('users_editor_test', ['users.update', 'users.view']);
        $actorId = $this->makeUser('escalation-editor@rbac.test', 'Correct-Horse-1', []);
        $this->pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')
            ->execute([$actorId, $customRoleId]);

        $victimId = $this->makeUser('escalation-victim@rbac.test', 'Correct-Horse-1', ['manager']);
        $ownerRoleId = $this->roleId('owner');

        // The actor can pass the middleware (they have users.update) but the
        // controller-level guard must throw ForbiddenException because they
        // lack roles.manage and are trying to change role assignments.
        $this->assertThrows(ForbiddenException::class, function () use ($actorId, $victimId, $ownerRoleId) {
            $this->dispatchAs($actorId, 'POST', '/admin/users/' . $victimId . '/update', [
                'name' => 'Victim',
                'email' => 'escalation-victim@rbac.test',
                'phone' => '',
                'password' => '',
                'roles' => [$ownerRoleId], // trying to add owner to the victim
            ]);
        }, 'a user with users.update but not roles.manage must be blocked from changing roles');

        // Verify the victim's roles are unchanged.
        $roles = AuthService::rolesFor($victimId);
        $this->assertContains('manager', $roles);
        $this->assertNotContains('owner', $roles, 'the victim must not have been promoted to owner');

        // Cleanup.
        foreach ([$actorId, $victimId] as $cleanupId) {
            $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$cleanupId]);
            $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$cleanupId]);
        }
        $this->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$customRoleId]);
        $this->pdo()->prepare('DELETE FROM roles WHERE id = ?')->execute([$customRoleId]);
    }

    public function testManagerCannotEditRolePermissions(): void
    {
        $managerId = $this->makeUser('escalation-manager@rbac.test', 'Correct-Horse-1', ['manager']);
        $technicianRoleId = $this->roleId('technician');

        // Manager hits POST /admin/roles/{id}/permissions — needs roles.manage.
        $this->expectForbidden($managerId, 'POST', '/admin/roles/' . $technicianRoleId . '/permissions', [
            'permissions' => ['dashboard.view', 'orders.manage'], // trying to grant orders.manage to technicians
        ]);

        // Verify the technician role's permissions are unchanged.
        $repo = new \App\Repositories\RoleRepository();
        $role = $repo->find($technicianRoleId);
        $this->assertNotContains('orders.manage', $role['permission_names'], 'technician must NOT have been granted orders.manage');
    }

    public function testRolesManageHolderCanUpdateRolePermissions(): void
    {
        // Use an owner (who has roles.manage implicitly) to update the technician role.
        $ownerId = $this->makeUser('escalation-owner@rbac.test', 'Correct-Horse-1', ['owner']);
        $technicianRoleId = $this->roleId('technician');

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/roles/' . $technicianRoleId . '/permissions', [
            'permissions' => ['dashboard.view', 'products.view', 'inspections.manage', 'service.manage', 'notifications.view'],
        ]);

        $this->assertSame(302, $response->status(), 'roles.manage holder must be able to update role permissions');

        // Verify the changes took effect.
        $repo = new \App\Repositories\RoleRepository();
        $role = $repo->find($technicianRoleId);
        $this->assertContains('inspections.manage', $role['permission_names']);
        $this->assertNotContains('orders.manage', $role['permission_names']);

        // Cleanup the extra owner.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ------------------------------------------ LAST OWNER PROTECTION --

    public function testLastActiveOwnerCannotBeDeactivatedViaHttp(): void
    {
        $this->wipeOwners();

        $ownerId = $this->makeUser('last-owner@rbac.test', 'Correct-Horse-1', ['owner']);

        // The actor needs users.deactivate to reach the controller. Only owners
        // have it via the matrix — but if the actor is an active owner, the
        // target isn't "last". Use a custom role with users.deactivate only.
        $customRoleId = $this->makeCustomRole('deactivator_test', ['users.deactivate']);
        $actorId = $this->makeUser('last-owner-actor@rbac.test', 'Correct-Horse-1', []);
        $this->pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')
            ->execute([$actorId, $customRoleId]);

        // The actor (who has users.deactivate) tries to deactivate the last owner.
        // assertNotLastOwner must throw ValidationException.
        $this->assertThrows(\App\Exceptions\ValidationException::class, function () use ($actorId, $ownerId) {
            $this->dispatchAs($actorId, 'POST', '/admin/users/' . $ownerId . '/status', ['activate' => '0']);
        }, 'deactivating the last active owner must be rejected');

        // Verify the owner is still active in the DB.
        $stmt = $this->pdo()->prepare('SELECT is_active FROM users WHERE id = ?');
        $stmt->execute([$ownerId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'the last owner must remain active');

        // Cleanup.
        foreach ([$ownerId, $actorId] as $cleanupId) {
            $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$cleanupId]);
            $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$cleanupId]);
        }
        $this->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$customRoleId]);
        $this->pdo()->prepare('DELETE FROM roles WHERE id = ?')->execute([$customRoleId]);
    }

    public function testOwnerRoleCannotBeStrippedFromLastOwnerViaUserUpdate(): void
    {
        $this->wipeOwners();

        $ownerId = $this->makeUser('strip-owner@rbac.test', 'Correct-Horse-1', ['owner']);

        // To reach the assertNotLastOwner guard inside UserController::update,
        // the actor must pass BOTH the privilege-escalation guard (needs
        // roles.manage) AND the route middleware (needs users.update). Only
        // an owner normally has both — but then the target wouldn't be "last".
        // So we create a custom role with exactly those two permissions and
        // no owner slug, making the last-owner guard actually exercisable.
        $customRoleId = $this->makeCustomRole('users_updater_test', ['users.update', 'roles.manage']);
        $actorId = $this->makeUser('strip-owner-actor@rbac.test', 'Correct-Horse-1', []);
        $this->pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')
            ->execute([$actorId, $customRoleId]);

        // The actor tries to strip the owner role from the last active owner.
        // The privilege-escalation guard passes (actor has roles.manage), then
        // assertNotLastOwner must throw ValidationException.
        $this->assertThrows(\App\Exceptions\ValidationException::class, function () use ($actorId, $ownerId) {
            $this->dispatchAs($actorId, 'POST', '/admin/users/' . $ownerId . '/update', [
                'name' => 'Last Owner',
                'email' => 'strip-owner@rbac.test',
                'phone' => '',
                'password' => '',
                'roles' => [], // stripping all roles including owner
            ]);
        }, 'stripping the owner role from the last active owner must be rejected');

        // Verify the owner role is still attached.
        $roles = AuthService::rolesFor($ownerId);
        $this->assertContains('owner', $roles, 'the last owner must keep the owner role');

        // Cleanup.
        foreach ([$ownerId, $actorId] as $cleanupId) {
            $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$cleanupId]);
            $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$cleanupId]);
        }
        $this->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$customRoleId]);
        $this->pdo()->prepare('DELETE FROM roles WHERE id = ?')->execute([$customRoleId]);
    }

    public function testUserCannotDeactivateThemself(): void
    {
        // Use an owner as the actor — they have users.deactivate, so the
        // middleware passes; the controller-level self-deactivation guard
        // then throws ForbiddenException.
        $ownerId = $this->makeUser('self-deactivate@rbac.test', 'Correct-Horse-1', ['owner']);

        $this->assertThrows(ForbiddenException::class, function () use ($ownerId) {
            $this->dispatchAs($ownerId, 'POST', '/admin/users/' . $ownerId . '/status', ['activate' => '0']);
        }, 'a user must not be able to deactivate their own account');

        // Verify the user is still active.
        $stmt = $this->pdo()->prepare('SELECT is_active FROM users WHERE id = ?');
        $stmt->execute([$ownerId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'self-deactivation must not change the user row');

        // Cleanup the extra owner.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // -------------------------------------------------- AUDIT TRAIL --

    public function testPermissionDeniedEventIsRecordedInAuditLog(): void
    {
        $id = $this->makeUser('audit-denied@rbac.test', 'Correct-Horse-1', ['customer']);
        $beforeCount = (int) $this->pdo()->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'permission.denied'")->fetchColumn();

        try {
            $this->dispatchAs($id, 'GET', '/admin/dashboard');
        } catch (ForbiddenException $e) {
            // expected
        }

        $afterCount = (int) $this->pdo()->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'permission.denied'")->fetchColumn();
        $this->assertTrue($afterCount > $beforeCount, 'a permission.denied audit row must be written when middleware blocks');

        // Verify the audit row has the right shape.
        $stmt = $this->pdo()->prepare(
            "SELECT user_id, entity_type, metadata FROM audit_logs
             WHERE action = 'permission.denied' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($id, (int) $row['user_id'], 'the denied user must be the audit actor');
        $this->assertSame('route', $row['entity_type']);
        $meta = json_decode((string) $row['metadata'], true);
        $this->assertSame('dashboard.view', $meta['required'], 'the denied permission must be in the metadata');
        $this->assertSame('/admin/dashboard', $meta['path'], 'the denied route must be in the metadata');
    }

    public function testRolePermissionUpdateIsRecordedInAuditLogWithDiff(): void
    {
        $this->wipeOwners();

        $ownerId = $this->makeUser('audit-roles@rbac.test', 'Correct-Horse-1', ['owner']);
        $technicianRoleId = $this->roleId('technician');

        // Snapshot the technician role's permissions before.
        $repo = new \App\Repositories\RoleRepository();
        $before = $repo->find($technicianRoleId)['permission_names'];

        // Grant + revoke in one operation.
        $newSet = ['dashboard.view', 'products.view', 'inspections.manage', 'service.manage', 'notifications.view'];
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/roles/' . $technicianRoleId . '/permissions', [
            'permissions' => $newSet,
        ]);
        $this->assertSame(302, $response->status());

        // Verify the audit row records the diff.
        $stmt = $this->pdo()->prepare(
            "SELECT user_id, entity_type, entity_id, metadata FROM audit_logs
             WHERE action = 'roles.permissions_updated' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame($ownerId, (int) $row['user_id'], 'the actor must be the user who updated the role');
        $this->assertSame('role', $row['entity_type']);
        $this->assertSame($technicianRoleId, (int) $row['entity_id']);

        $meta = json_decode((string) $row['metadata'], true);
        $added = array_values(array_diff($newSet, $before));
        $removed = array_values(array_diff($before, $newSet));
        $this->assertSame($added, $meta['added'] ?? [], 'audit diff must list newly granted permissions');
        $this->assertSame($removed, $meta['removed'] ?? [], 'audit diff must list newly revoked permissions');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testUserCreationIsRecordedInAuditLog(): void
    {
        $this->wipeOwners();

        $ownerId = $this->makeUser('audit-create@rbac.test', 'Correct-Horse-1', ['owner']);
        $managerRoleId = $this->roleId('manager');

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/users', [
            'name' => 'New Staff',
            'email' => 'new-staff-audit@rbac.test',
            'phone' => '',
            'password' => 'Strong-Password-12',
            'roles' => [$managerRoleId],
        ]);

        $this->assertSame(302, $response->status());

        $stmt = $this->pdo()->prepare(
            "SELECT user_id, entity_type, metadata FROM audit_logs
             WHERE action = 'user.created' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame($ownerId, (int) $row['user_id'], 'the creator must be the audit actor');
        $this->assertSame('user', $row['entity_type']);
        $meta = json_decode((string) $row['metadata'], true);
        $this->assertSame('new-staff-audit@rbac.test', $meta['email']);
        $this->assertContains('manager', $meta['roles']);

        // Cleanup the created user + the owner.
        $lookupStmt = $this->pdo()->prepare('SELECT id FROM users WHERE email = ?');
        $lookupStmt->execute(['new-staff-audit@rbac.test']);
        $newId = (int) $lookupStmt->fetchColumn();
        if ($newId > 0) {
            $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$newId]);
            $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$newId]);
        }
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // -------------------------------------------------- OR-GROUPS --

    public function testPermissionOrGroupAllowsWhenAnyMatches(): void
    {
        // Route /admin/users/create has middleware permission:users.create|users.update.
        // An owner (full perms) must pass — verifies the OR-group logic doesn't
        // accidentally block a valid permutation.
        $ownerId = $this->makeUser('or-group@rbac.test', 'Correct-Horse-1', ['owner']);
        $this->expectOk($ownerId, 'GET', '/admin/users/create');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testPermissionOrGroupBlocksWhenNeitherMatches(): void
    {
        // Cashier has neither users.create nor users.update.
        $cashierId = $this->makeUser('or-group-block@rbac.test', 'Correct-Horse-1', ['sales_cashier']);
        $this->expectForbidden($cashierId, 'GET', '/admin/users/create');
    }

    // -------------------------------------------------- DEFENSE IN DEPTH --

    public function testOwnerRolePermissionsAreUntouchableViaHttp(): void
    {
        // Even an owner posting to /admin/roles/{ownerRoleId}/permissions
        // with an empty set must NOT strip the owner role's permissions —
        // RoleRepository::syncPermissions short-circuits for the owner slug.
        $ownerId = $this->makeUser('owner-untouchable@rbac.test', 'Correct-Horse-1', ['owner']);
        $ownerRoleId = $this->roleId('owner');

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/roles/' . $ownerRoleId . '/permissions', [
            'permissions' => [], // attempt to strip all permissions
        ]);
        $this->assertSame(302, $response->status());

        // The owner role still implicitly has every permission (verified by
        // creating a fresh owner and checking can() against a sample perm).
        $freshOwnerId = $this->makeUser('owner-untouchable-2@rbac.test', 'Correct-Horse-1', ['owner']);
        $this->actingAs($freshOwnerId);
        $this->assertTrue(AuthService::can('settings.manage'), 'owner must keep implicit full access');
        $this->assertTrue(AuthService::can('roles.manage'), 'owner must keep implicit full access');
        $this->assertTrue(AuthService::can('audit_logs.view'), 'owner must keep implicit full access');

        // Cleanup.
        foreach ([$ownerId, $freshOwnerId] as $cleanupId) {
            $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$cleanupId]);
            $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$cleanupId]);
        }
    }
}
