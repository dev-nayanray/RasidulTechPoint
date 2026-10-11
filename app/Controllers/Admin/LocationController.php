<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\LocationRepository;
use App\Services\AuditService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

/**
 * Location & branch management.
 *
 * Creates, edits, and deletes locations (shops, warehouses, service
 * centers). Each location has a name, short code, type, address, phone,
 * and is_active flag. One location can be the primary shop.
 *
 * Stock-location relationships are read-only here — the inventory
 * receive form already lets you assign units to a location, and the
 * dashboard already filters metrics by location_id. This controller
 * adds the CRUD layer on top of the existing locations table.
 *
 * Per-user branch assignment (user_locations table) is NOT implemented
 * — the limitation is documented in the dashboard's limitations()
 * array. The infrastructure supports location filtering on inventory,
 * but staff are not yet restricted to a specific branch.
 */
final class LocationController
{
    public function index(): Response
    {
        $repo = new LocationRepository();
        return Response::html(View::render('admin/locations/index', [
            'pageTitle' => 'Locations',
            'locations' => $repo->allWithStockCounts(),
        ]));
    }

    public function create(): Response
    {
        return Response::html(View::render('admin/locations/form', [
            'pageTitle' => 'Create location',
            'location' => null,
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        $repo = new LocationRepository();
        $errors = $this->validate($request, $repo, null);

        if ($errors !== []) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/locations/form', [
                'pageTitle' => 'Create location',
                'location' => $request->post(),
                'errors' => $errors,
            ]), 422);
        }

        $data = $request->post();
        $isPrimary = !empty($data['is_primary']);
        $id = $repo->create(
            $data['name'],
            $data['code'] ?? null,
            $data['type'] ?? 'shop',
            $data['address'] ?? null,
            $data['phone'] ?? null,
            $isPrimary,
        );

        if ($isPrimary) {
            $repo->setPrimary($id);
        }

        AuditService::record('location.created', 'location', $id, [
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'type' => $data['type'] ?? 'shop',
            'is_primary' => $isPrimary,
        ], $request);

        flash_success('Location created.');
        return Response::redirect(url('/admin/locations'));
    }

    public function edit(string $id): Response
    {
        $location = (new LocationRepository())->find((int) $id);
        if ($location === null) {
            throw new NotFoundException('Location not found.');
        }

        return Response::html(View::render('admin/locations/form', [
            'pageTitle' => 'Edit: ' . $location['name'],
            'location' => $location,
            'errors' => [],
        ]));
    }

    public function update(string $id, Request $request): Response
    {
        $repo = new LocationRepository();
        $location = $repo->find((int) $id);
        if ($location === null) {
            throw new NotFoundException('Location not found.');
        }

        $errors = $this->validate($request, $repo, (int) $id);

        if ($errors !== []) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/locations/form', [
                'pageTitle' => 'Edit: ' . $location['name'],
                'location' => array_merge($location, $request->post()),
                'errors' => $errors,
            ]), 422);
        }

        $data = $request->post();
        $isActive = !empty($data['is_active']);
        $isPrimary = !empty($data['is_primary']);
        $repo->update(
            (int) $id,
            $data['name'],
            $data['code'] ?? null,
            $data['type'] ?? 'shop',
            $data['address'] ?? null,
            $data['phone'] ?? null,
            $isActive,
        );

        if ($isPrimary) {
            $repo->setPrimary((int) $id);
        }

        AuditService::record('location.updated', 'location', (int) $id, [
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'type' => $data['type'] ?? 'shop',
            'is_active' => $isActive,
            'is_primary' => $isPrimary,
        ], $request);

        flash_success('Location updated.');
        return Response::redirect(url('/admin/locations'));
    }

    public function destroy(string $id, Request $request): Response
    {
        $repo = new LocationRepository();
        $location = $repo->find((int) $id);
        if ($location === null) {
            throw new NotFoundException('Location not found.');
        }

        $repo->delete((int) $id);

        AuditService::record('location.deleted', 'location', (int) $id, [
            'name' => $location['name'],
            'code' => $location['code'] ?? null,
        ], $request);

        flash_success('Location deleted.');
        return Response::redirect(url('/admin/locations'));
    }

    /**
     * @return array<string,string> field => error message
     */
    private function validate(Request $request, LocationRepository $repo, ?int $excludeId): array
    {
        $errors = [];
        $name = trim((string) $request->input('name', ''));
        $code = trim((string) $request->input('code', ''));
        $type = (string) $request->input('type', 'shop');

        if ($name === '' || mb_strlen($name) < 2) {
            $errors['name'] = 'Name is required (min 2 characters).';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'Name must be at most 120 characters.';
        } elseif ($repo->nameExists($name, $excludeId)) {
            $errors['name'] = 'A location with this name already exists.';
        }

        if ($code !== '' && mb_strlen($code) > 10) {
            $errors['code'] = 'Code must be at most 10 characters.';
        } elseif ($code !== '' && !preg_match('/^[A-Z0-9\-]+$/', strtoupper($code))) {
            $errors['code'] = 'Code must contain only uppercase letters, numbers, and hyphens.';
        } elseif ($code !== '' && $repo->codeExists(strtoupper($code), $excludeId)) {
            $errors['code'] = 'A location with this code already exists.';
        }

        if (!in_array($type, ['shop', 'warehouse', 'service_center'], true)) {
            $errors['type'] = 'Type must be shop, warehouse, or service_center.';
        }

        return $errors;
    }
}
