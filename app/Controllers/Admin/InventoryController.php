<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\CatalogRepository;
use App\Repositories\InventoryRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\InventoryService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

final class InventoryController
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 20));
        $repo = new InventoryRepository();

        $status = (string) $request->query('status', '');
        $result = $repo->search(trim((string) $request->query('q', '')), $status, (int) $request->query('model', 0), $page, $perPage);

        return Response::html(View::render('admin/inventory/index', [
            'pageTitle' => 'Inventory units',
            'units' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => $status,
            'statusCounts' => $repo->statusCounts(),
            'statuses' => InventoryService::STATUSES,
        ]));
    }

    public function show(string $id): Response
    {
        $unit = (new InventoryRepository())->find((int) $id);
        if ($unit === null) {
            throw new NotFoundException('Inventory unit not found.');
        }

        return Response::html(View::render('admin/inventory/show', [
            'pageTitle' => 'Unit ' . $unit['sku'],
            'unit' => $unit,
            'transitions' => InventoryService::TRANSITIONS,
        ]));
    }

    public function create(): Response
    {
        return Response::html(View::render('admin/inventory/receive', [
            'pageTitle' => 'Receive stock',
            'models' => (new CatalogRepository())->searchModels('', 0, 0, 1, 500)['rows'],
            'locations' => $this->locations(),
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        try {
            $data = Validator::make($request->post(), [
                'sku' => 'required|string|min:3|max:40|unique:inventory_units,sku',
                'serial_number' => 'nullable|string|max:80|unique:inventory_units,serial_number',
                'product_model_id' => 'required|integer|exists:product_models,id',
                'condition_grade' => 'required|in:A,B,C,D',
                'purchase_cost' => 'required|decimal:2',
                'landed_cost' => 'nullable|decimal:2',
                'selling_price' => 'required|decimal:2',
                'min_selling_price' => 'nullable|decimal:2',
                'location_id' => 'nullable|integer|exists:locations,id',
                'supplier_ref' => 'nullable|string|max:120',
                'purchase_ref' => 'nullable|string|max:120',
                'notes' => 'nullable|string|max:500',
                'initial_status' => 'required|in:awaiting_inspection,in_transit',
            ], [
                'sku' => 'SKU',
                'product_model_id' => 'Laptop model',
                'condition_grade' => 'Condition grade',
            ])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/inventory/receive', [
                'pageTitle' => 'Receive stock',
                'models' => (new CatalogRepository())->searchModels('', 0, 0, 1, 500)['rows'],
                'locations' => $this->locations(),
                'errors' => $e->errors,
            ]), 422);
        }

        $unitId = (new InventoryService())->receive($data, current_user_id(), $data['initial_status']);

        AuditService::record('inventory.received', 'inventory_unit', $unitId, [
            'sku' => $data['sku'],
            'model' => (int) $data['product_model_id'],
            'initial_status' => $data['initial_status'],
        ], $request);

        flash_success('Unit ' . $data['sku'] . ' received into inventory.');
        return Response::redirect(url('/admin/inventory/' . $unitId));
    }

    /**
     * Apply a state-machine transition from the unit detail page.
     */
    public function transition(string $id, Request $request): Response
    {
        $id = (int) $id;
        $repo = new InventoryRepository();
        $unit = $repo->find($id);
        if ($unit === null) {
            throw new NotFoundException('Inventory unit not found.');
        }

        $transition = (string) $request->input('transition', '');
        $reason = trim((string) $request->input('reason', ''));

        // transition format: "movement_type:to_status" from the radio group.
        if (!preg_match('/^([a-z_]+):([a-z_]+)$/', $transition, $parts)) {
            flash_error('Choose an inventory operation.');
            return Response::redirect(url('/admin/inventory/' . $id));
        }
        [, $movementType, $toStatus] = $parts;

        $allowed = InventoryService::TRANSITIONS[$movementType][$unit['status']] ?? [];
        if (!in_array($toStatus, $allowed, true)) {
            flash_error("Invalid transition {$movementType} → {$toStatus} for status {$unit['status']}.");
            return Response::redirect(url('/admin/inventory/' . $id));
        }
        if ($reason === '') {
            flash_error('A reason is required for every inventory operation.');
            return Response::redirect(url('/admin/inventory/' . $id));
        }

        // Server-side permission re-check for sensitive transitions.
        $needed = match ($movementType) {
            'reservation', 'release' => 'orders.manage',
            'sale' => 'orders.manage',
            'inspection' => 'inspections.manage|inventory.approve',
            'adjustment', 'write_off' => 'inventory.adjust',
            default => 'inventory.adjust',
        };
        $granted = false;
        foreach (explode('|', $needed) as $permission) {
            if (AuthService::can($permission)) {
                $granted = true;
                break;
            }
        }
        if (!$granted) {
            throw new \App\Exceptions\ForbiddenException("Missing permission: {$needed}");
        }

        (new InventoryService())->transition($id, $movementType, $toStatus, $reason, current_user_id());

        AuditService::record('inventory.' . $movementType, 'inventory_unit', $id, [
            'to_status' => $toStatus,
            'reason' => mb_substr($reason, 0, 200),
        ], $request);

        flash_success('Inventory updated.');
        return Response::redirect(url('/admin/inventory/' . $id));
    }

    public function adjustPrice(string $id, Request $request): Response
    {
        $id = (int) $id;
        $repo = new InventoryRepository();
        $unit = $repo->find($id);
        if ($unit === null) {
            throw new NotFoundException('Inventory unit not found.');
        }

        try {
            $data = Validator::make($request->post(), [
                'selling_price' => 'required|decimal:2',
            ], ['selling_price' => 'Selling price'])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/inventory/' . $id));
        }

        (new InventoryService())->changePrice($id, (float) $data['selling_price'], current_user_id());
        AuditService::record('inventory.price_changed', 'inventory_unit', $id, [
            'new_price' => $data['selling_price'],
        ], $request);

        flash_success('Selling price updated.');
        return Response::redirect(url('/admin/inventory/' . $id));
    }

    private function locations(): array
    {
        return \App\Support\Database\Connection::pdo()
            ->query('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name')
            ->fetchAll(\PDO::FETCH_ASSOC);
    }
}
