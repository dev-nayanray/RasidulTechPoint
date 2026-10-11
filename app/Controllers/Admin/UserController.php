<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\MailService;
use App\Services\PasswordResetService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;
use App\Support\View;

/**
 * Staff user management: CRUD, search, pagination, status changes,
 * role assignment, profile details, password-reset initiation, login
 * history, and soft delete.
 *
 * Permission matrix (enforced by route middleware AND re-checked
 * inside sensitive methods — defense in depth):
 *   users.view        list + show
 *   users.create      create + store
 *   users.update      edit + update + initiatePasswordReset
 *   users.deactivate  deactivate + destroy + restore
 *   roles.manage      required to change role assignments
 */
final class UserController
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 20));
        $result = (new UserRepository())->search(trim((string) $request->query('q', '')), $page, $perPage);

        return Response::html(View::render('admin/users/index', [
            'pageTitle' => 'Staff users',
            'users' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
        ]));
    }

    public function create(): Response
    {
        return Response::html(View::render('admin/users/form', [
            'pageTitle' => 'Create user',
            'user' => null,
            'roles' => (new RoleRepository())->allWithCounts(),
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        try {
            $data = Validator::make($request->post(), [
                'name' => 'required|string|min:2|max:120',
                'email' => 'required|email|max:190|unique:users,email',
                'phone' => 'nullable|string|max:30',
                'password' => 'required|string|min:10|max:500',
                'roles' => 'array',
            ], ['email' => 'Email address'])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            return $this->formError($request, null, $e->errors, 'admin/users/form', '/admin/users/create');
        }

        $repo = new UserRepository();
        $id = $repo->create($data['name'], $data['email'], $data['password'], $this->safeRoleIds((array) ($data['roles'] ?? [])), $data['phone'] ?? null);

        AuditService::record('user.created', 'user', $id, [
            'email' => $data['email'],
            'roles' => $this->roleSlugs((array) ($data['roles'] ?? [])),
        ], $request);

        flash_success('User created.');
        return Response::redirect(url('/admin/users/' . $id));
    }

    public function show(string $id): Response
    {
        $repo = new UserRepository();
        // Use findWithTrashed so an admin can view a soft-deleted user's
        // profile (with a Restore button) instead of getting a 404.
        $user = $repo->findWithTrashed((int) $id);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }

        return Response::html(View::render('admin/users/show', [
            'pageTitle' => $user['name'],
            'user' => $user,
            'roles' => (new RoleRepository())->allWithCounts(),
            'audit' => AuditService::recent(15, (int) $id),
            'loginHistory' => $repo->loginHistory((int) $id, 20),
        ]));
    }

    public function edit(string $id): Response
    {
        $user = (new UserRepository())->find((int) $id);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }

        return Response::html(View::render('admin/users/form', [
            'pageTitle' => 'Edit user',
            'user' => $user,
            'roles' => (new RoleRepository())->allWithCounts(),
            'errors' => [],
        ]));
    }

    public function update(string $id, Request $request): Response
    {
        $repo = new UserRepository();
        $user = $repo->find((int) $id);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }

        try {
            $data = Validator::make($request->post(), [
                'name' => 'required|string|min:2|max:120',
                'email' => 'required|email|max:190|unique:users,email,' . (int) $id,
                'phone' => 'nullable|string|max:30',
                'password' => 'nullable|string|min:10|max:500',
                'roles' => 'array',
            ], ['email' => 'Email address'])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            return $this->formError($request, $user, $e->errors, 'admin/users/form', '/admin/users/' . $id . '/edit');
        }

        $newRoleIds = $this->safeRoleIds((array) ($data['roles'] ?? []));

        // Privilege-escalation guard: only roles.manage holders may change roles.
        if (!$this->sameRoleSet($user['role_ids'], $newRoleIds) && !AuthService::can('roles.manage')) {
            throw new ForbiddenException('Only roles.manage holders may change role assignments.');
        }

        // Guard: nobody may strip the last active owner of the owner role.
        if (in_array('owner', $this->roleSlugs($newRoleIds), true) === false
            && in_array('owner', $this->roleSlugs($user['role_ids']), true)) {
            UserRepository::assertNotLastOwner((int) $id);
        }

        $repo->update((int) $id, $data['name'], $data['phone'] ?? null, $newRoleIds);

        if (!empty($data['password'])) {
            $pdo = \App\Support\Database\Connection::pdo();
            $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                ->execute([password_hash($data['password'], PASSWORD_DEFAULT), (int) $id]);
        }

        AuditService::record('user.updated', 'user', (int) $id, [
            'roles' => $this->roleSlugs($newRoleIds),
            'password_changed' => !empty($data['password']),
            'email_changed' => strtolower(trim($data['email'])) !== strtolower((string) $user['email']),
        ], $request);

        flash_success('User updated.');
        return Response::redirect(url('/admin/users/' . $id));
    }

    public function deactivate(string $id, Request $request): Response
    {
        $id = (int) $id;
        $repo = new UserRepository();
        $user = $repo->find($id);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }
        if ($id === current_user_id()) {
            throw new ForbiddenException('You cannot deactivate your own account.');
        }
        if (in_array('owner', $this->roleSlugs($user['role_ids']), true)) {
            UserRepository::assertNotLastOwner($id);
        }

        $reactivate = (string) $request->input('activate', '0') === '1';
        $repo->setActive($id, $reactivate);
        AuditService::record('user.deactivated', 'user', $id, [
            'reactivated' => $reactivate,
        ], $request);

        flash_success($reactivate ? 'User reactivated.' : 'User deactivated.');
        return Response::redirect(url('/admin/users/' . $id));
    }

    /**
     * Admin-initiated password reset: generates a single-use token via
     * the existing PasswordResetService and emails the user a reset link.
     * The admin never sees the token or the new password — the user
     * chooses a new password themselves through the normal reset flow.
     */
    public function initiatePasswordReset(string $id, Request $request): Response
    {
        $repo = new UserRepository();
        $user = $repo->find((int) $id);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }
        if ((int) $user['is_active'] !== 1) {
            flash_error('Cannot send a reset link to a deactivated account. Reactivate it first.');
            return Response::redirect(url('/admin/users/' . $id));
        }

        $result = (new PasswordResetService())->createToken($user['email']);

        if ($result['token'] !== null) {
            $link = url('/reset-password?token=' . urlencode($result['token']));
            MailService::send(
                $user['email'],
                'Reset your Rasidul Tech Point password',
                "Hello {$user['name']},\n\nAn administrator has initiated a password reset for your account. "
                . "Use the link below to choose a new password. It expires in 60 minutes and can be used once:\n\n"
                . "{$link}\n\nIf you did not expect this, contact your administrator.",
                $link,
            );
            AuditService::record('user.password_reset_initiated', 'user', (int) $id, [
                'email' => $user['email'],
                'initiated_by' => AuthService::id(),
            ], $request);
            flash_success('A password reset link has been sent to ' . $user['email'] . '.');
        } else {
            // createToken returns null token for unknown emails — but we
            // already verified the user exists above. This branch is a
            // safety net; flash a generic message (no disclosure).
            flash_error('Could not send a reset link. Please try again.');
        }

        return Response::redirect(url('/admin/users/' . $id));
    }

    /**
     * Soft-delete a user (sets deleted_at). The row stays for audit
     * integrity but disappears from lists. Can be restored.
     */
    public function destroy(string $id, Request $request): Response
    {
        $id = (int) $id;
        $repo = new UserRepository();
        $user = $repo->find($id);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }
        if ($id === current_user_id()) {
            throw new ForbiddenException('You cannot delete your own account.');
        }
        if (in_array('owner', $this->roleSlugs($user['role_ids']), true)) {
            UserRepository::assertNotLastOwner($id);
        }

        $repo->softDelete($id);
        AuditService::record('user.deleted', 'user', $id, [
            'email' => $user['email'],
        ], $request);

        flash_success('User deleted. The account is suspended but retained for audit history.');
        return Response::redirect(url('/admin/users'));
    }

    /** Restore a soft-deleted user. */
    public function restore(string $id, Request $request): Response
    {
        $id = (int) $id;
        $repo = new UserRepository();
        if (!$repo->isDeleted($id)) {
            throw new NotFoundException('User is not deleted.');
        }

        $repo->restore($id);
        AuditService::record('user.restored', 'user', $id, [], $request);

        flash_success('User restored.');
        return Response::redirect(url('/admin/users/' . $id));
    }

    /** Only assignable roles (never invent ids). */
    private function safeRoleIds(array $submitted): array
    {
        $valid = array_keys((new RoleRepository())->idSlugMap());
        return array_values(array_intersect(array_map('intval', $submitted), $valid));
    }

    /** @param list<int> $ids @return list<string> */
    private function roleSlugs(array $ids): array
    {
        $map = (new RoleRepository())->idSlugMap();
        $slugs = [];
        foreach ($ids as $id) {
            if (isset($map[(int) $id])) {
                $slugs[] = $map[(int) $id];
            }
        }
        return $slugs;
    }

    private function sameRoleSet(array $old, array $new): bool
    {
        $old = array_map('intval', $old);
        $new = array_map('intval', $new);
        sort($old);
        sort($new);
        return $old === $new;
    }

    private function formError(Request $request, ?array $user, array $errors, string $view, string $path): Response
    {
        \App\Support\Session::flashInput($request->post());
        return Response::html(View::render($view, [
            'pageTitle' => $user !== null ? 'Edit user' : 'Create user',
            'user' => array_merge($user ?? [], ['form_error' => true] + $request->post()),
            'roles' => (new RoleRepository())->allWithCounts(),
            'errors' => $errors,
            'postPath' => $path,
        ]), 422);
    }
}
