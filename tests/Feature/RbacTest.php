<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\PermissionMiddleware;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Support\Request;
use App\Support\Response;
use Tests\TestCase;

final class RbacTest extends TestCase
{
    public function testOwnerCanEverything(): void
    {
        $id = $this->makeUser('owner@rbac.test', roleSlugs: ['owner']);
        $this->actingAs($id);
        $this->assertTrue(AuthService::can('settings.manage'));
        $this->assertTrue(AuthService::can('roles.manage'));
        $this->assertTrue(AuthService::can('anything.not.defined'));
    }

    public function testManagerCannotManageRolesOrSettings(): void
    {
        $id = $this->makeUser('manager@rbac.test', roleSlugs: ['manager']);
        $this->actingAs($id);

        $this->assertTrue(AuthService::can('orders.manage'));
        $this->assertTrue(AuthService::can('inventory.adjust'));
        $this->assertFalse(AuthService::can('roles.manage'));
        $this->assertFalse(AuthService::can('settings.manage'));
        $this->assertFalse(AuthService::can('audit_logs.view'));
    }

    public function testCashierPermissionsComeOnlyFromMatrix(): void
    {
        $id = $this->makeUser('cashier@rbac.test', roleSlugs: ['sales_cashier']);
        $this->actingAs($id);

        $this->assertTrue(AuthService::can('pos.access'));
        $this->assertTrue(AuthService::can('orders.manage'));
        $this->assertFalse(AuthService::can('inventory.adjust'));
        $this->assertFalse(AuthService::can('users.view'));
        $this->assertFalse(AuthService::can('finance.view'));
    }

    public function testCustomerRoleHasNoBackOfficePermissions(): void
    {
        $id = $this->makeUser('customer@rbac.test', roleSlugs: ['customer']);
        $this->actingAs($id);
        $this->assertFalse(AuthService::can('dashboard.view'));
        $this->assertFalse(AuthService::can('orders.view'));
        $this->assertFalse(AuthService::can('products.view'));
    }

    public function testUnauthenticatedUserHasNoPermissions(): void
    {
        $this->assertFalse(AuthService::check());
        $this->assertFalse(AuthService::can('dashboard.view'));
    }

    public function testPermissionMiddlewareBlocksAndAllows(): void
    {
        $cashier = $this->makeUser('cashier2@rbac.test', roleSlugs: ['sales_cashier']);
        $this->actingAs($cashier);

        $middleware = new PermissionMiddleware();
        $middleware->setArgument('users.view');

        $blocked = false;
        try {
            $middleware->handle($this->syntheticRequest(), fn () => Response::html('never'));
        } catch (\App\Exceptions\ForbiddenException) {
            $blocked = true;
        }
        $this->assertTrue($blocked, 'cashier must be blocked from users.view');

        $middleware2 = new PermissionMiddleware();
        $middleware2->setArgument('orders.view|orders.manage');
        $passed = false;
        $response = $middleware2->handle($this->syntheticRequest(), function () use (&$passed) {
            $passed = true;
            return Response::html('ok');
        });
        $this->assertTrue($passed, 'cashier with orders.manage passes the OR group');
        $this->assertSame('ok', $response->body());
    }

    public function testLastActiveOwnerCannotBeDeactivated(): void
    {
        // Isolate: deactivate any owners created by earlier test methods.
        $this->pdo()->exec(
            "UPDATE users SET is_active = 0 WHERE id IN (
                SELECT user_id FROM (
                    SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                    WHERE r.slug = 'owner'
                ) AS owners)"
        );

        $owner = $this->makeUser('solo-owner@rbac.test', roleSlugs: ['owner']);
        $this->assertThrows(\App\Exceptions\ValidationException::class,
            fn () => UserRepository::assertNotLastOwner($owner),
            'the only active owner must be protected');

        // Cleanup so later tests start from a neutral state.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$owner]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$owner]);
    }

    public function testLastOwnerProtectedEvenWhenOthersHoldOwnerInactive(): void
    {
        $this->pdo()->exec(
            "UPDATE users SET is_active = 0 WHERE id IN (
                SELECT user_id FROM (
                    SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                    WHERE r.slug = 'owner'
                ) AS owners)"
        );

        $owner = $this->makeUser('solo2@rbac.test', roleSlugs: ['owner']);
        $inactiveOwner = $this->makeUser('sleeping@rbac.test', roleSlugs: ['owner']);
        $this->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$inactiveOwner]);

        $this->assertThrows(\App\Exceptions\ValidationException::class,
            fn () => UserRepository::assertNotLastOwner($owner));

        $this->pdo()->prepare('DELETE FROM users WHERE email IN (?, ?)')
            ->execute(['solo2@rbac.test', 'sleeping@rbac.test']);
    }

    public function testOwnerCanBeDeactivatedWhenAnotherActiveOwnerExists(): void
    {
        $ownerA = $this->makeUser('owner-a@rbac.test', roleSlugs: ['owner']);
        $ownerB = $this->makeUser('owner-b@rbac.test', roleSlugs: ['owner']);

        UserRepository::assertNotLastOwner($ownerA); // must not throw
        UserRepository::assertNotLastOwner($ownerB);
        $this->assertTrue(true);
    }

    public function testNonOwnerIsNotAffectedByOwnerGuard(): void
    {
        $manager = $this->makeUser('plain@rbac.test', roleSlugs: ['manager']);
        UserRepository::assertNotLastOwner($manager); // not an owner: no exception
        $this->assertTrue(true);
    }

    public function testRolePermissionSyncUpdatesAuthorizationImmediately(): void
    {
        $id = $this->makeUser('sync@rbac.test', roleSlugs: ['technician']);
        $this->actingAs($id);
        $this->assertFalse(AuthService::can('orders.manage'));

        $repo = new \App\Repositories\RoleRepository();
        $repo->syncPermissions($this->roleId('technician'), ['dashboard.view', 'orders.manage']);

        // Re-resolve permissions (next request would do this via boot).
        $permissions = AuthService::permissionsFor($id);
        $this->assertContains('orders.manage', $permissions);
    }

    public function testOwnerRolePermissionsAreUntouchable(): void
    {
        $repo = new \App\Repositories\RoleRepository();
        $repo->syncPermissions($this->roleId('owner'), []); // attempt to strip

        $owner = $this->makeUser('stillowner@rbac.test', roleSlugs: ['owner']);
        $this->actingAs($owner);
        $this->assertTrue(AuthService::can('settings.manage'), 'owner keeps implicit full access');
    }

    public function testDeactivatedUserSessionIsInvalidatedOnNextRequest(): void
    {
        $id = $this->makeUser('mid-session@rbac.test', roleSlugs: ['manager']);
        $this->actingAs($id);
        $this->assertTrue(AuthService::check());

        $this->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
        AuthService::refreshUser();

        $this->assertFalse(AuthService::check(), 'deactivated user must be signed out');
    }
}
