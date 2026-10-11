<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\SupplierRepository;
use App\Services\AuditService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Supplier management: profiles, contact details, addresses, notes,
 * status, search, pagination, and outstanding balance summary.
 *
 * Suppliers are soft-deleted (deleted_at) to preserve audit history.
 * The existing inventory_units.supplier_ref is a free-text VARCHAR(120),
 * so deleting a supplier does NOT break historical references — the
 * text reference survives.
 */
final class SupplierController
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 20));
        $q = trim((string) $request->query('q', ''));
        $result = (new SupplierRepository())->search($q, $page, $perPage);

        // Compute total outstanding balance across all active suppliers.
        $totalOutstanding = (float) \App\Support\Database\Connection::pdo()
            ->query('SELECT COALESCE(SUM(outstanding_balance), 0) FROM suppliers WHERE is_active = 1 AND deleted_at IS NULL')
            ->fetchColumn();

        return Response::html(View::render('admin/suppliers/index', [
            'pageTitle' => 'Suppliers',
            'suppliers' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => $q,
            'totalOutstanding' => $totalOutstanding,
        ]));
    }

    public function create(): Response
    {
        return Response::html(View::render('admin/suppliers/form', [
            'pageTitle' => 'Add supplier',
            'supplier' => null,
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        $errors = $this->validate($request, null);

        if ($errors !== []) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/suppliers/form', [
                'pageTitle' => 'Add supplier',
                'supplier' => $request->post(),
                'errors' => $errors,
            ]), 422);
        }

        $data = $request->post();
        $repo = new SupplierRepository();
        $slug = str_slug($data['name']);

        if ($repo->slugExists($slug)) {
            $slug = $slug . '-' . bin2hex(random_bytes(2));
        }

        $id = $repo->create(
            $data['name'],
            $slug,
            $data['contact_name'] ?? null ?: null,
            $data['email'] ?? null ?: null,
            $data['phone'] ?? null ?: null,
            $data['address'] ?? null ?: null,
            $data['city'] ?? null ?: null,
            $data['country'] ?? null ?: null,
            $data['notes'] ?? null ?: null,
        );

        AuditService::record('supplier.created', 'supplier', $id, [
            'name' => $data['name'],
            'slug' => $slug,
        ], $request);

        flash_success('Supplier created.');
        return Response::redirect(url('/admin/suppliers/' . $id));
    }

    public function show(string $id): Response
    {
        $repo = new SupplierRepository();
        $supplier = $repo->findWithTrashed((int) $id);
        if ($supplier === null) {
            throw new NotFoundException('Supplier not found.');
        }

        // Find inventory units that reference this supplier by name.
        $units = \App\Support\Database\Connection::pdo()->prepare(
            'SELECT iu.id, iu.sku, iu.status, iu.condition_grade, iu.selling_price, iu.purchase_cost,
                    iu.created_at, pm.name AS product_name
             FROM inventory_units iu
             JOIN product_models pm ON pm.id = iu.product_model_id
             WHERE iu.supplier_ref = ?
             ORDER BY iu.id DESC LIMIT 50'
        );
        $units->execute([$supplier['name']]);

        return Response::html(View::render('admin/suppliers/show', [
            'pageTitle' => $supplier['name'],
            'supplier' => $supplier,
            'units' => $units->fetchAll(\PDO::FETCH_ASSOC),
        ]));
    }

    public function edit(string $id): Response
    {
        $supplier = (new SupplierRepository())->find((int) $id);
        if ($supplier === null) {
            throw new NotFoundException('Supplier not found.');
        }

        return Response::html(View::render('admin/suppliers/form', [
            'pageTitle' => 'Edit: ' . $supplier['name'],
            'supplier' => $supplier,
            'errors' => [],
        ]));
    }

    public function update(string $id, Request $request): Response
    {
        $repo = new SupplierRepository();
        $supplier = $repo->find((int) $id);
        if ($supplier === null) {
            throw new NotFoundException('Supplier not found.');
        }

        $errors = $this->validate($request, (int) $id);

        if ($errors !== []) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/suppliers/form', [
                'pageTitle' => 'Edit: ' . $supplier['name'],
                'supplier' => array_merge($supplier, $request->post()),
                'errors' => $errors,
            ]), 422);
        }

        $data = $request->post();
        $repo->update(
            (int) $id,
            $data['name'],
            $data['contact_name'] ?? null ?: null,
            $data['email'] ?? null ?: null,
            $data['phone'] ?? null ?: null,
            $data['address'] ?? null ?: null,
            $data['city'] ?? null ?: null,
            $data['country'] ?? null ?: null,
            $data['notes'] ?? null ?: null,
            !empty($data['is_active']),
        );

        AuditService::record('supplier.updated', 'supplier', (int) $id, [
            'name' => $data['name'],
        ], $request);

        flash_success('Supplier updated.');
        return Response::redirect(url('/admin/suppliers/' . $id));
    }

    public function destroy(string $id, Request $request): Response
    {
        $repo = new SupplierRepository();
        $supplier = $repo->find((int) $id);
        if ($supplier === null) {
            throw new NotFoundException('Supplier not found.');
        }

        $repo->softDelete((int) $id);

        AuditService::record('supplier.deleted', 'supplier', (int) $id, [
            'name' => $supplier['name'],
        ], $request);

        flash_success('Supplier deleted. History preserved (soft delete).');
        return Response::redirect(url('/admin/suppliers'));
    }

    /**
     * @return array<string,string>
     */
    private function validate(Request $request, ?int $excludeId): array
    {
        $errors = [];
        $repo = new SupplierRepository();

        $name = trim((string) $request->input('name', ''));
        $email = trim((string) $request->input('email', ''));

        if ($name === '' || mb_strlen($name) < 2) {
            $errors['name'] = 'Name is required (min 2 characters).';
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }

        return $errors;
    }
}
