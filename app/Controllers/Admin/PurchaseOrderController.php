<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Repositories\CatalogRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\SupplierRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\LandedCostService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

/**
 * Purchase orders and Dubai import batches.
 *
 * Status flow: draft → submitted → approved → received (or cancelled).
 * Stock is NOT added when a PO is created/approved. Stock arrives
 * through the existing InventoryService::receive() flow — the PO
 * provides agreed costs, supplier, and batch reference.
 *
 * Permission matrix:
 *   purchases.view  — list + show
 *   purchases.manage — create/edit/submit/cancel
 *   inventory.approve — approve (separation of duties: the person
 *     who creates cannot approve)
 *   inventory.receive — receive stock against an approved PO
 */
final class PurchaseOrderController
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 20));
        $repo = new PurchaseOrderRepository();
        $status = (string) $request->query('status', '');
        $result = $repo->search(trim((string) $request->query('q', '')), $status, $page, $perPage);

        return Response::html(View::render('admin/purchase-orders/index', [
            'pageTitle' => 'Purchase orders',
            'orders' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'statusFilter' => $status,
        ]));
    }

    public function create(): Response
    {
        return Response::html(View::render('admin/purchase-orders/form', [
            'pageTitle' => 'New purchase order',
            'order' => null,
            'suppliers' => (new SupplierRepository())->all(),
            'models' => (new CatalogRepository())->searchModels('', 0, 0, 1, 500)['rows'],
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        $errors = $this->validate($request);
        if ($errors !== []) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/purchase-orders/form', [
                'pageTitle' => 'New purchase order',
                'order' => $request->post(),
                'suppliers' => (new SupplierRepository())->all(),
                'models' => (new CatalogRepository())->searchModels('', 0, 0, 1, 500)['rows'],
                'errors' => $errors,
            ]), 422);
        }

        $data = $request->post();
        $items = $this->extractItems($data);

        $repo = new PurchaseOrderRepository();
        $id = $repo->create(
            (int) $data['supplier_id'],
            $data['import_batch_ref'] ?? null ?: null,
            $data['notes'] ?? null ?: null,
            current_user_id(),
            $items,
        );

        AuditService::record('po.created', 'purchase_order', $id, [
            'supplier_id' => (int) $data['supplier_id'],
            'item_count' => count($items),
        ], $request);

        flash_success('Purchase order created (draft).');
        return Response::redirect(url('/admin/purchase-orders/' . $id));
    }

    public function show(string $id): Response
    {
        $order = (new PurchaseOrderRepository())->find((int) $id);
        if ($order === null) {
            throw new NotFoundException('Purchase order not found.');
        }

        $landedCostService = new LandedCostService();
        $allocations = $landedCostService->allocationsFor((int) $id);

        // Get units received against this PO.
        $units = \App\Support\Database\Connection::pdo()->prepare(
            'SELECT iu.id, iu.sku, iu.condition_grade, iu.status, iu.purchase_cost, iu.landed_cost,
                    iu.selling_price, iu.created_at
             FROM inventory_units iu
             WHERE iu.purchase_order_id = ?
             ORDER BY iu.id ASC'
        );
        $units->execute([$id]);

        return Response::html(View::render('admin/purchase-orders/show', [
            'pageTitle' => $order['reference'],
            'order' => $order,
            'allocations' => $allocations,
            'units' => $units->fetchAll(\PDO::FETCH_ASSOC),
        ]));
    }

    public function allocateLandedCost(string $id, Request $request): Response
    {
        $repo = new PurchaseOrderRepository();
        $order = $repo->find((int) $id);
        if ($order === null) {
            throw new NotFoundException('Purchase order not found.');
        }

        $expenseType = (string) $request->input('expense_type', 'other');
        $totalAmount = (float) $request->input('total_amount', 0);
        $method = (string) $request->input('method', 'equal');
        $description = trim((string) $request->input('description', ''));

        if ($totalAmount <= 0) {
            flash_error('Total amount must be positive.');
            return Response::redirect(url('/admin/purchase-orders/' . $id));
        }

        try {
            $allocationId = (new LandedCostService())->allocate(
                (int) $id, $expenseType, $totalAmount, $method,
                current_user_id(), $description ?: null,
            );
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/purchase-orders/' . $id));
        }

        AuditService::record('po.landed_cost_allocated', 'purchase_order', (int) $id, [
            'allocation_id' => $allocationId,
            'expense_type' => $expenseType,
            'total_amount' => $totalAmount,
            'method' => $method,
        ], $request);

        flash_success('Landed cost allocated across received units.');
        return Response::redirect(url('/admin/purchase-orders/' . $id));
    }

    public function edit(string $id): Response
    {
        $repo = new PurchaseOrderRepository();
        $order = $repo->find((int) $id);
        if ($order === null) {
            throw new NotFoundException('Purchase order not found.');
        }
        if ($order['status'] !== 'draft') {
            flash_error('Only draft purchase orders can be edited.');
            return Response::redirect(url('/admin/purchase-orders/' . $id));
        }

        return Response::html(View::render('admin/purchase-orders/form', [
            'pageTitle' => 'Edit: ' . $order['reference'],
            'order' => $order,
            'suppliers' => (new SupplierRepository())->all(),
            'models' => (new CatalogRepository())->searchModels('', 0, 0, 1, 500)['rows'],
            'errors' => [],
        ]));
    }

    public function update(string $id, Request $request): Response
    {
        $repo = new PurchaseOrderRepository();
        $order = $repo->find((int) $id);
        if ($order === null) {
            throw new NotFoundException('Purchase order not found.');
        }
        if ($order['status'] !== 'draft') {
            flash_error('Only draft purchase orders can be edited.');
            return Response::redirect(url('/admin/purchase-orders/' . $id));
        }

        $errors = $this->validate($request);
        if ($errors !== []) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/purchase-orders/form', [
                'pageTitle' => 'Edit: ' . $order['reference'],
                'order' => array_merge($order, $request->post()),
                'suppliers' => (new SupplierRepository())->all(),
                'models' => (new CatalogRepository())->searchModels('', 0, 0, 1, 500)['rows'],
                'errors' => $errors,
            ]), 422);
        }

        $data = $request->post();
        $items = $this->extractItems($data);
        $repo->update((int) $id, (int) $data['supplier_id'], $data['import_batch_ref'] ?? null ?: null, $data['notes'] ?? null ?: null, $items);

        AuditService::record('po.updated', 'purchase_order', (int) $id, [
            'item_count' => count($items),
        ], $request);

        flash_success('Purchase order updated.');
        return Response::redirect(url('/admin/purchase-orders/' . $id));
    }

    public function changeStatus(string $id, Request $request): Response
    {
        $repo = new PurchaseOrderRepository();
        $order = $repo->find((int) $id);
        if ($order === null) {
            throw new NotFoundException('Purchase order not found.');
        }

        $action = (string) $request->input('action', '');

        // Validate status transition + permission.
        $allowedTransitions = [
            'submit' => ['from' => 'draft', 'to' => 'submitted', 'perm' => 'purchases.manage'],
            'approve' => ['from' => 'submitted', 'to' => 'approved', 'perm' => 'inventory.approve'],
            'receive' => ['from' => 'approved', 'to' => 'received', 'perm' => 'inventory.receive'],
            'cancel' => ['from' => null, 'to' => 'cancelled', 'perm' => 'purchases.manage'],
        ];

        if (!isset($allowedTransitions[$action])) {
            flash_error('Unknown action.');
            return Response::redirect(url('/admin/purchase-orders/' . $id));
        }

        $transition = $allowedTransitions[$action];
        if ($transition['from'] !== null && $order['status'] !== $transition['from']) {
            flash_error("Cannot {$action} a {$order['status']} purchase order. Expected {$transition['from']}.}");
            return Response::redirect(url('/admin/purchase-orders/' . $id));
        }

        // For cancel, allow from any non-received status.
        if ($action === 'cancel' && in_array($order['status'], ['received', 'cancelled'], true)) {
            flash_error("Cannot cancel a {$order['status']} purchase order.");
            return Response::redirect(url('/admin/purchase-orders/' . $id));
        }

        // Server-side permission re-check.
        if (!AuthService::can($transition['perm'])) {
            throw new ForbiddenException("Missing permission: {$transition['perm']}");
        }

        $repo->updateStatus((int) $id, $transition['to'], current_user_id());

        AuditService::record('po.' . $action, 'purchase_order', (int) $id, [
            'from' => $order['status'],
            'to' => $transition['to'],
        ], $request);

        flash_success("Purchase order {$transition['to']}.");
        return Response::redirect(url('/admin/purchase-orders/' . $id));
    }

    /**
     * @return array<string,string>
     */
    private function validate(Request $request): array
    {
        $errors = [];
        $supplierId = (int) $request->input('supplier_id', 0);
        if ($supplierId <= 0) {
            $errors['supplier_id'] = 'A supplier is required.';
        }

        $items = $this->extractItems($request->post());
        if ($items === []) {
            $errors['items'] = 'At least one line item is required.';
        }

        foreach ($items as $i => $item) {
            if ((int) $item['quantity'] < 1) {
                $errors["items_{$i}_quantity"] = 'Quantity must be at least 1.';
            }
            if ((float) $item['unit_cost'] < 0) {
                $errors["items_{$i}_unit_cost"] = 'Unit cost cannot be negative.';
            }
            if ((int) $item['product_model_id'] <= 0) {
                $errors["items_{$i}_model"] = 'A product model is required.';
            }
        }

        return $errors;
    }

    /**
     * @return list<array{product_model_id:int,quantity:int,unit_cost:float,description:?string}>
     */
    private function extractItems(array $post): array
    {
        $modelIds = $post['item_model'] ?? [];
        $quantities = $post['item_qty'] ?? [];
        $costs = $post['item_cost'] ?? [];
        $descriptions = $post['item_desc'] ?? [];

        if (!is_array($modelIds)) {
            return [];
        }

        $items = [];
        foreach ($modelIds as $i => $modelId) {
            if ((int) $modelId <= 0) {
                continue;
            }
            $items[] = [
                'product_model_id' => (int) $modelId,
                'quantity' => max(1, (int) ($quantities[$i] ?? 1)),
                'unit_cost' => max(0, (float) ($costs[$i] ?? 0)),
                'description' => $descriptions[$i] ?? null,
            ];
        }
        return $items;
    }
}
