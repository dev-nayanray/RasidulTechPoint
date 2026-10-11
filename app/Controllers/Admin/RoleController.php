<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\RoleRepository;
use App\Services\AuditService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;
use App\Support\View;

/**
 * Role & permission management UI.
 *
 * Permission matrix (all routes require roles.manage):
 *   GET  /admin/roles                  index              list all roles + permission catalog
 *   GET  /admin/roles/create           create             show the create-role form
 *   POST /admin/roles                  store              validate + create a custom role
 *   GET  /admin/roles/{id}             show               role details + member list
 *   GET  /admin/roles/{id}/edit        edit               permission checkbox form
 *   POST /admin/roles/{id}/permissions updatePermissions replace the role's permission set
 *   POST /admin/roles/{id}/delete      destroy            delete a non-system, empty role
 *
 * Safety:
 *  - Owner role permissions are untouchable (syncPermissions short-circuits).
 *  - System roles (owner, customer, ...) can never be deleted.
 *  - A role with members can't be deleted (reassign first).
 *  - Every permission change is audited with the added/removed diff.
 *  - Every role creation and deletion is audited.
 */
final class RoleController
{
    public function index(): Response
    {
        return Response::html(View::render('admin/roles/index', [
            'pageTitle' => 'Roles & permissions',
            'roles' => (new RoleRepository())->allWithCounts(),
            'permissionsGrouped' => (new RoleRepository())->permissionsGrouped(),
        ]));
    }

    public function create(): Response
    {
        return Response::html(View::render('admin/roles/create', [
            'pageTitle' => 'Create role',
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        try {
            $data = Validator::make($request->post(), [
                'name' => 'required|string|min:2|max:80',
                'slug' => 'required|string|min:2|max:80|slug',
                'description' => 'nullable|string|max:255',
            ], ['slug' => 'Slug'])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            \App\Support\Session::flashInput($request->post());
            return Response::html(View::render('admin/roles/create', [
                'pageTitle' => 'Create role',
                'errors' => $e->errors,
            ]), 422);
        }

        $repo = new RoleRepository();
        $slug = str_slug($data['slug']);

        if ($repo->slugExists($slug)) {
            \App\Support\Session::flashInput($request->post());
            return Response::html(View::render('admin/roles/create', [
                'pageTitle' => 'Create role',
                'errors' => ['slug' => 'A role with this slug already exists.'],
            ]), 422);
        }

        $id = $repo->create($data['name'], $slug, $data['description'] ?? null);

        AuditService::record('role.created', 'role', $id, [
            'name' => $data['name'],
            'slug' => $slug,
        ], $request);

        flash_success('Role created. Assign permissions from the edit page.');
        return Response::redirect(url('/admin/roles/' . $id . '/edit'));
    }

    public function show(string $id): Response
    {
        $repo = new RoleRepository();
        $role = $repo->find((int) $id);
        if ($role === null) {
            throw new NotFoundException('Role not found.');
        }

        $members = $repo->members((int) $id, 100);

        return Response::html(View::render('admin/roles/show', [
            'pageTitle' => $role['name'],
            'role' => $role,
            'members' => $members,
            'memberCount' => count($members),
        ]));
    }

    public function edit(string $id): Response
    {
        $repo = new RoleRepository();
        $role = $repo->find((int) $id);
        if ($role === null) {
            throw new NotFoundException('Role not found.');
        }

        return Response::html(View::render('admin/roles/edit', [
            'pageTitle' => 'Edit role: ' . $role['name'],
            'role' => $role,
            'permissionsGrouped' => $repo->permissionsGrouped(),
        ]));
    }

    public function updatePermissions(string $id, Request $request): Response
    {
        $repo = new RoleRepository();
        $role = $repo->find((int) $id);
        if ($role === null) {
            throw new NotFoundException('Role not found.');
        }

        $before = $role['permission_names'];
        $selected = array_values(array_filter((array) $request->input('permissions', []), 'is_string'));

        $repo->syncPermissions((int) $id, $selected);

        $after = $repo->find((int) $id)['permission_names'] ?? [];
        AuditService::record('roles.permissions_updated', 'role', (int) $id, [
            'added' => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
        ], $request);

        flash_success('Permissions updated for role ' . $role['name'] . '.');
        return Response::redirect(url('/admin/roles/' . $id));
    }

    public function destroy(string $id, Request $request): Response
    {
        $repo = new RoleRepository();
        $role = $repo->find((int) $id);
        if ($role === null) {
            throw new NotFoundException('Role not found.');
        }

        $repo->delete((int) $id);

        AuditService::record('role.deleted', 'role', (int) $id, [
            'name' => $role['name'],
            'slug' => $role['slug'],
        ], $request);

        flash_success('Role ' . $role['name'] . ' deleted.');
        return Response::redirect(url('/admin/roles'));
    }
}
