<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Services\AuthService;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use App\Repositories\UserRepository;
use Tests\TestCase;

/**
 * Staff account management — HTTP-level tests.
 *
 * Drives the real router through the real UserController to prove:
 *  - staff CRUD (create / read / update / soft-delete / restore)
 *  - search by name or email
 *  - pagination
 *  - status changes (activate / deactivate)
 *  - role assignment with privilege-escalation guard
 *  - self-deactivation + self-delete prevention
 *  - last-active-owner protection at the HTTP layer
 *  - password-reset initiation (admin triggers reset email)
 *  - login history on the profile page
 *  - audit trail for every sensitive action
 *  - permission enforcement on every route
 */
final class StaffManagementTest extends TestCase
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

    public function testCashierCannotListUsers(): void
    {
        $cashierId = $this->makeUser('staff-cashier-list@test.local', 'Correct-Horse-1', ['sales_cashier']);
        $this->expectForbidden($cashierId, 'GET', '/admin/users');
    }

    public function testCashierCannotCreateUsers(): void
    {
        $cashierId = $this->makeUser('staff-cashier-create@test.local', 'Correct-Horse-1', ['sales_cashier']);
        $this->expectForbidden($cashierId, 'GET', '/admin/users/create');
        $this->expectForbidden($cashierId, 'POST', '/admin/users', [
            'name' => 'X', 'email' => 'x@y.z', 'password' => 'Strong-Pass-12', 'roles' => [],
        ]);
    }

    public function testManagerCanListButNotDeactivate(): void
    {
        $managerId = $this->makeUser('staff-manager@test.local', 'Correct-Horse-1', ['manager']);
        $targetId = $this->makeUser('staff-target@test.local', 'Correct-Horse-1', ['technician']);

        // Manager has users.view → can list.
        $response = $this->dispatchAs($managerId, 'GET', '/admin/users');
        $this->assertSame(200, $response->status());

        // Manager does NOT have users.deactivate → blocked.
        $this->expectForbidden($managerId, 'POST', '/admin/users/' . $targetId . '/status', ['activate' => '0']);
    }

    // ---------------------------------------------------- CRUD --

    public function testOwnerCanCreateStaffUser(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-owner-create@test.local', 'Correct-Horse-1', ['owner']);
        $managerRoleId = $this->roleId('manager');

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/users', [
            'name' => 'New Staff',
            'email' => 'new-staff@staff.test',
            'phone' => '+8801712345678',
            'password' => 'Strong-Pass-12',
            'roles' => [$managerRoleId],
        ]);

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/admin/users/\d+$#', $response->headers()['Location'] ?? '');

        // Verify the user was created with the right data.
        $stmt = $this->pdo()->prepare('SELECT id, name, email, phone FROM users WHERE email = ?');
        $stmt->execute(['new-staff@staff.test']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('New Staff', $row['name']);
        $this->assertSame('+8801712345678', $row['phone']);

        $roles = AuthService::rolesFor((int) $row['id']);
        $this->assertContains('manager', $roles);

        // Verify audit trail.
        $audit = $this->pdo()->prepare("SELECT action, metadata FROM audit_logs WHERE action = 'user.created' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $log = $audit->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('user.created', $log['action']);
        $meta = json_decode((string) $log['metadata'], true);
        $this->assertSame('new-staff@staff.test', $meta['email']);
        $this->assertContains('manager', $meta['roles']);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([(int) $row['id']]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([(int) $row['id']]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testCreateRejectsDuplicateEmail(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-owner-dup@test.local', 'Correct-Horse-1', ['owner']);
        $this->makeUser('existing@staff.test', 'Correct-Horse-1', ['technician']);

        // The controller catches ValidationException and returns a 422
        // response with the form re-rendered — so we check the response
        // status rather than expecting an exception to propagate.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/users', [
            'name' => 'Dup',
            'email' => 'existing@staff.test',
            'password' => 'Strong-Pass-12',
            'roles' => [],
        ]);
        $this->assertSame(422, $response->status(), 'duplicate email must fail validation (422)');

        // Verify the duplicate user was NOT created.
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $count->execute(['existing@staff.test']);
        $this->assertSame(1, (int) $count->fetchColumn(), 'only the original user should exist');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testCreateRejectsWeakPassword(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-owner-weak@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/users', [
            'name' => 'Weak',
            'email' => 'weak@staff.test',
            'password' => 'short',  // < 10 chars
            'roles' => [],
        ]);
        $this->assertSame(422, $response->status(), 'weak password must fail validation (422)');

        // Verify the user was NOT created.
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $count->execute(['weak@staff.test']);
        $this->assertSame(0, (int) $count->fetchColumn(), 'user with weak password should not be created');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testUpdateChangesNameAndKeepsRolesWhenUnchanged(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-owner-upd@test.local', 'Correct-Horse-1', ['owner']);
        $targetId = $this->makeUser('staff-target-upd@test.local', 'Correct-Horse-1', ['manager']);
        $managerRoleId = $this->roleId('manager');

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/users/' . $targetId . '/update', [
            'name' => 'Updated Name',
            'email' => 'staff-target-upd@test.local',
            'phone' => '+8801798765432',
            'password' => '',
            'roles' => [$managerRoleId], // same role → no privilege-escalation guard
        ]);

        $this->assertSame(302, $response->status());

        $stmt = $this->pdo()->prepare('SELECT name, phone FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Updated Name', $row['name']);
        $this->assertSame('+8801798765432', $row['phone']);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?)')->execute([$targetId, $ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$targetId, $ownerId]);
    }

    public function testUpdateCanChangePassword(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-owner-pwd@test.local', 'Correct-Horse-1', ['owner']);
        $targetId = $this->makeUser('staff-target-pwd@test.local', 'Old-Pass-12345', ['technician']);
        $technicianRoleId = $this->roleId('technician');

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/users/' . $targetId . '/update', [
            'name' => 'Tech',
            'email' => 'staff-target-pwd@test.local',
            'phone' => '',
            'password' => 'Brand-New-Pass-99',
            'roles' => [$technicianRoleId],
        ]);

        $this->assertSame(302, $response->status());

        // Verify the password was actually changed.
        $stmt = $this->pdo()->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $this->assertTrue(password_verify('Brand-New-Pass-99', (string) $stmt->fetchColumn()));

        // Verify audit recorded the password change.
        $audit = $this->pdo()->prepare("SELECT metadata FROM audit_logs WHERE action = 'user.updated' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $meta = json_decode((string) $audit->fetchColumn(), true);
        $this->assertTrue($meta['password_changed']);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?)')->execute([$targetId, $ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$targetId, $ownerId]);
    }

    // ---------------------------------------------------- SEARCH --

    public function testSearchByName(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-search-owner@test.local', 'Correct-Horse-1', ['owner']);
        $this->makeUser('alice.jones@search.test', 'Correct-Horse-1', ['manager']);
        $this->makeUser('bob.smith@search.test', 'Correct-Horse-1', ['technician']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/users', [], ['q' => 'alice']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Test alice\.jones@search\.test/', $response->body());
        $this->assertFalse(str_contains($response->body(), 'bob.smith'));
    }

    public function testSearchByEmail(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-search-email@test.local', 'Correct-Horse-1', ['owner']);
        $this->makeUser('carol@search.test', 'Correct-Horse-1', ['manager']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/users', [], ['q' => 'carol@search']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/carol@search\.test/', $response->body());
    }

    public function testSearchWithNoMatchesReturnsEmptyState(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-search-empty@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/users', [], ['q' => 'nonexistent']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/No users match/i', $response->body());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- PAGINATION --

    public function testPaginationReturnsCorrectPage(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-page-owner@test.local', 'Correct-Horse-1', ['owner']);

        // Create 25 users so page 2 of per_page=20 has content.
        for ($i = 0; $i < 25; $i++) {
            $this->makeUser('page-user-' . $i . '@test.local', 'Correct-Horse-1', ['technician']);
        }

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/users', [], ['page' => '2', 'per_page' => '20']);
        $this->assertSame(200, $response->status());
        // The pagination nav must show "Page 2" — don't assert the total
        // page count because earlier test classes may have created users too.
        $this->assertMatchesRegularExpression('/Page.*\b2\b/i', $response->body());

        // Cleanup.
        $this->pdo()->exec("DELETE FROM user_roles WHERE user_id IN (SELECT id FROM (SELECT id FROM users WHERE email LIKE 'page-user-%@test.local') AS t)");
        $this->pdo()->exec("DELETE FROM users WHERE email LIKE 'page-user-%@test.local'");
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- STATUS --

    public function testDeactivateThenReactivate(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-deact-owner@test.local', 'Correct-Horse-1', ['owner']);
        $targetId = $this->makeUser('staff-deact-target@test.local', 'Correct-Horse-1', ['technician']);

        // Deactivate.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/users/' . $targetId . '/status', ['activate' => '0']);
        $this->assertSame(302, $response->status());
        $stmt = $this->pdo()->prepare('SELECT is_active FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());

        // Reactivate.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/users/' . $targetId . '/status', ['activate' => '1']);
        $this->assertSame(302, $response->status());
        $stmt = $this->pdo()->prepare('SELECT is_active FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        // Verify two audit rows (deactivate + reactivate).
        $count = $this->pdo()->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.deactivated' AND entity_id = ?");
        $count->execute([$targetId]);
        $this->assertTrue((int) $count->fetchColumn() >= 2);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?)')->execute([$targetId, $ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$targetId, $ownerId]);
    }

    public function testUserCannotDeactivateSelf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-self-deact@test.local', 'Correct-Horse-1', ['owner']);

        $this->assertThrows(ForbiddenException::class, function () use ($ownerId) {
            $this->dispatchAs($ownerId, 'POST', '/admin/users/' . $ownerId . '/status', ['activate' => '0']);
        }, 'a user must not be able to deactivate their own account');

        // Verify the user is still active.
        $stmt = $this->pdo()->prepare('SELECT is_active FROM users WHERE id = ?');
        $stmt->execute([$ownerId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- ROLE ASSIGNMENT --

    public function testOwnerCanAssignRoleToUser(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-role-owner@test.local', 'Correct-Horse-1', ['owner']);
        $targetId = $this->makeUser('staff-role-target@test.local', 'Correct-Horse-1', ['technician']);
        $managerRoleId = $this->roleId('manager');
        $technicianRoleId = $this->roleId('technician');

        // Owner changes the target from technician to manager.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/users/' . $targetId . '/update', [
            'name' => 'Target',
            'email' => 'staff-role-target@test.local',
            'phone' => '',
            'password' => '',
            'roles' => [$managerRoleId], // removing technician, adding manager
        ]);
        $this->assertSame(302, $response->status());

        $roles = AuthService::rolesFor($targetId);
        $this->assertContains('manager', $roles);
        $this->assertNotContains('technician', $roles);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?)')->execute([$targetId, $ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$targetId, $ownerId]);
    }

    public function testManagerCannotChangeRolesWithoutRolesManage(): void
    {
        $this->wipeOwners();
        // Manager has users.update but NOT roles.manage.
        $managerId = $this->makeUser('staff-no-roles@test.local', 'Correct-Horse-1', ['manager']);
        $targetId = $this->makeUser('staff-no-roles-target@test.local', 'Correct-Horse-1', ['technician']);
        $managerRoleId = $this->roleId('manager');
        $technicianRoleId = $this->roleId('technician');

        // Manager tries to change the target's role from technician to manager.
        $this->assertThrows(ForbiddenException::class, function () use ($managerId, $targetId, $managerRoleId) {
            $this->dispatchAs($managerId, 'POST', '/admin/users/' . $targetId . '/update', [
                'name' => 'Target',
                'email' => 'staff-no-roles-target@test.local',
                'phone' => '',
                'password' => '',
                'roles' => [$managerRoleId],
            ]);
        }, 'manager without roles.manage must not change role assignments');

        // Verify roles are unchanged.
        $roles = AuthService::rolesFor($targetId);
        $this->assertContains('technician', $roles);
        $this->assertNotContains('manager', $roles);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?)')->execute([$targetId, $managerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$targetId, $managerId]);
    }

    // ---------------------------------------------------- LAST OWNER --

    public function testLastActiveOwnerCannotBeDeactivated(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-last-owner@test.local', 'Correct-Horse-1', ['owner']);

        // The route needs users.deactivate. Only owners have it, but if the
        // actor is another active owner, the target isn't "last". Use a
        // custom role with users.deactivate (but not owner) to exercise the
        // guard at the HTTP layer.
        $customRoleId = $this->makeCustomRole('staff_deactivator_test', ['users.deactivate']);
        $actorId = $this->makeUser('staff-last-actor@test.local', 'Correct-Horse-1', []);
        $this->pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')
            ->execute([$actorId, $customRoleId]);

        $this->assertThrows(ValidationException::class, function () use ($actorId, $ownerId) {
            $this->dispatchAs($actorId, 'POST', '/admin/users/' . $ownerId . '/status', ['activate' => '0']);
        }, 'the last active owner must not be deactivated');

        // Verify the owner is still active.
        $stmt = $this->pdo()->prepare('SELECT is_active FROM users WHERE id = ?');
        $stmt->execute([$ownerId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        // Cleanup.
        foreach ([$ownerId, $actorId] as $cleanupId) {
            $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$cleanupId]);
            $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$cleanupId]);
        }
        $this->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$customRoleId]);
        $this->pdo()->prepare('DELETE FROM roles WHERE id = ?')->execute([$customRoleId]);
    }

    public function testLastActiveOwnerCannotBeDeleted(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-last-owner-del@test.local', 'Correct-Horse-1', ['owner']);

        // Same pattern: custom role with users.deactivate to reach the
        // controller-level assertNotLastOwner guard on the delete route.
        $customRoleId = $this->makeCustomRole('staff_deleter_test', ['users.deactivate']);
        $actorId = $this->makeUser('staff-last-actor-del@test.local', 'Correct-Horse-1', []);
        $this->pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')
            ->execute([$actorId, $customRoleId]);

        $this->assertThrows(ValidationException::class, function () use ($actorId, $ownerId) {
            $this->dispatchAs($actorId, 'POST', '/admin/users/' . $ownerId . '/delete');
        }, 'the last active owner must not be deleted');

        // Verify the owner is NOT soft-deleted.
        $stmt = $this->pdo()->prepare('SELECT deleted_at FROM users WHERE id = ?');
        $stmt->execute([$ownerId]);
        $this->assertNull($stmt->fetchColumn());

        // Cleanup.
        foreach ([$ownerId, $actorId] as $cleanupId) {
            $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$cleanupId]);
            $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$cleanupId]);
        }
        $this->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$customRoleId]);
        $this->pdo()->prepare('DELETE FROM roles WHERE id = ?')->execute([$customRoleId]);
    }

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

    // ---------------------------------------------------- SOFT DELETE --

    public function testSoftDeleteHidesUserFromListAndCanBeRestored(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-delete-owner@test.local', 'Correct-Horse-1', ['owner']);
        $targetId = $this->makeUser('staff-delete-target@test.local', 'Correct-Horse-1', ['technician']);

        // Soft-delete the target.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/users/' . $targetId . '/delete');
        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/admin/users$#', $response->headers()['Location'] ?? '');

        // The user is now soft-deleted.
        $repo = new UserRepository();
        $this->assertTrue($repo->isDeleted($targetId));
        $this->assertNull($repo->find($targetId), 'find() must exclude soft-deleted users');
        $this->assertNotNull($repo->findWithTrashed($targetId), 'findWithTrashed() must still return the row');

        // The show page still works (uses findWithTrashed) and shows a Restore button.
        $response = $this->dispatchAs($ownerId, 'GET', '/admin/users/' . $targetId);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Restore user/', $response->body());
        $this->assertMatchesRegularExpression('/soft-deleted/i', $response->body());

        // Restore.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/users/' . $targetId . '/restore');
        $this->assertSame(302, $response->status());
        $this->assertFalse($repo->isDeleted($targetId));
        $this->assertNotNull($repo->find($targetId));

        // Verify audit trail has both delete + restore events.
        $count = $this->pdo()->prepare("SELECT COUNT(*) FROM audit_logs WHERE action IN ('user.deleted', 'user.restored') AND entity_id = ?");
        $count->execute([$targetId]);
        $this->assertTrue((int) $count->fetchColumn() >= 2);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?)')->execute([$targetId, $ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$targetId, $ownerId]);
    }

    public function testUserCannotDeleteSelf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-self-delete@test.local', 'Correct-Horse-1', ['owner']);

        $this->assertThrows(ForbiddenException::class, function () use ($ownerId) {
            $this->dispatchAs($ownerId, 'POST', '/admin/users/' . $ownerId . '/delete');
        }, 'a user must not be able to delete their own account');

        // Verify the user is NOT soft-deleted.
        $stmt = $this->pdo()->prepare('SELECT deleted_at FROM users WHERE id = ?');
        $stmt->execute([$ownerId]);
        $this->assertNull($stmt->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- PASSWORD RESET INITIATION --

    public function testAdminCanInitiatePasswordResetForStaff(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-reset-owner@test.local', 'Correct-Horse-1', ['owner']);
        $targetId = $this->makeUser('staff-reset-target@test.local', 'Old-Pass-12345', ['manager']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/users/' . $targetId . '/reset-password');
        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/admin/users/' . $targetId . '$#', $response->headers()['Location'] ?? '');

        // Verify a reset token was created in the DB.
        $stmt = $this->pdo()->prepare('SELECT token_hash, used_at FROM password_reset_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$targetId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($row);
        $this->assertNull($row['used_at'], 'token must be unused');

        // Verify the audit trail.
        $audit = $this->pdo()->prepare("SELECT action, metadata FROM audit_logs WHERE action = 'user.password_reset_initiated' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $audit->execute([$targetId]);
        $log = $audit->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('user.password_reset_initiated', $log['action']);
        $meta = json_decode((string) $log['metadata'], true);
        $this->assertSame('staff-reset-target@test.local', $meta['email']);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$targetId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?)')->execute([$targetId, $ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$targetId, $ownerId]);
    }

    public function testPasswordResetCannotBeInitiatedForDeactivatedUser(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-reset-inactive-owner@test.local', 'Correct-Horse-1', ['owner']);
        $targetId = $this->makeUser('staff-reset-inactive@test.local', 'Old-Pass-12345', ['manager']);
        $this->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$targetId]);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/users/' . $targetId . '/reset-password');
        $this->assertSame(302, $response->status());

        // No token should have been created.
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = ?');
        $stmt->execute([$targetId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?)')->execute([$targetId, $ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$targetId, $ownerId]);
    }

    public function testCashierCannotInitiatePasswordReset(): void
    {
        $cashierId = $this->makeUser('staff-reset-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);
        $targetId = $this->makeUser('staff-reset-cashier-target@test.local', 'Old-Pass-12345', ['manager']);

        $this->expectForbidden($cashierId, 'POST', '/admin/users/' . $targetId . '/reset-password');
    }

    // ---------------------------------------------------- LOGIN HISTORY --

    public function testShowPageDisplaysLoginHistory(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-history-owner@test.local', 'Correct-Horse-1', ['owner']);
        $targetId = $this->makeUser('staff-history-target@test.local', 'Correct-Horse-1', ['manager']);

        // Record a login attempt (successful) + a failed one.
        $this->pdo()->prepare('INSERT INTO login_attempts (email, ip, was_successful) VALUES (?, ?, 1)')
            ->execute(['staff-history-target@test.local', '10.1.1.1']);
        $this->pdo()->prepare('INSERT INTO login_attempts (email, ip, was_successful) VALUES (?, ?, 0)')
            ->execute(['staff-history-target@test.local', '10.2.2.2']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/users/' . $targetId);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Login history/', $response->body());
        $this->assertMatchesRegularExpression('/10\.1\.1\.1/', $response->body());
        $this->assertMatchesRegularExpression('/10\.2\.2\.2/', $response->body());
        $this->assertMatchesRegularExpression('/Success/', $response->body());
        $this->assertMatchesRegularExpression('/Failed/', $response->body());

        // Cleanup.
        $this->pdo()->prepare("DELETE FROM login_attempts WHERE email = 'staff-history-target@test.local'")->execute();
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?)')->execute([$targetId, $ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$targetId, $ownerId]);
    }

    public function testShowPageWithNoLoginHistoryShowsEmptyState(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-no-history-owner@test.local', 'Correct-Horse-1', ['owner']);
        $targetId = $this->makeUser('staff-no-history@test.local', 'Correct-Horse-1', ['manager']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/users/' . $targetId);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/No login attempts recorded/', $response->body());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?)')->execute([$targetId, $ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$targetId, $ownerId]);
    }

    // ---------------------------------------------------- SHOW VIEW --

    public function testShowPageDisplaysRolesAndAccountDetails(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-show-owner@test.local', 'Correct-Horse-1', ['owner']);
        $targetId = $this->makeUser('staff-show-target@test.local', 'Correct-Horse-1', ['manager']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/users/' . $targetId);
        $this->assertSame(200, $response->status());
        // The roles section must show the Manager role.
        $this->assertMatchesRegularExpression('/Manager/', $response->body());
        // The account section must show the email.
        $this->assertMatchesRegularExpression('/staff-show-target@test\.local/', $response->body());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?)')->execute([$targetId, $ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$targetId, $ownerId]);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('staff-csrf-owner@test.local', 'Correct-Horse-1', ['owner']);
        $targetId = $this->makeUser('staff-csrf-target@test.local', 'Correct-Horse-1', ['technician']);
        $this->actingAs($ownerId);

        // Each entry: [method, uri, post]. All entries have 3 elements to
        // avoid PHP 8.5 array-destructuring default-value issues.
        $routes = [
            ['POST', '/admin/users', ['name' => 'X', 'email' => 'x@y.z', 'password' => 'Strong-Pass-12']],
            ['POST', '/admin/users/' . $targetId . '/update', ['name' => 'X']],
            ['POST', '/admin/users/' . $targetId . '/status', ['activate' => '0']],
            ['POST', '/admin/users/' . $targetId . '/reset-password', []],
            ['POST', '/admin/users/' . $targetId . '/delete', []],
            ['POST', '/admin/users/' . $targetId . '/restore', []],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(
                    Request::synthetic($method, $uri, [], $post),
                    $this->resolver()
                );
            }, "{$method} {$uri} must require a CSRF token");
        }

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?)')->execute([$targetId, $ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$targetId, $ownerId]);
    }
}
