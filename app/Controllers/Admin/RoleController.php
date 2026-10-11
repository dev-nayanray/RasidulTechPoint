<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\RoleRepository;
use App\Services\AuditService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

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
        return Response::redirect(url('/admin/roles'));
    }
}
