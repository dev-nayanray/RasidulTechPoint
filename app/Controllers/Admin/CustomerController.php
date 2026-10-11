<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\CustomerRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

/**
 * Customer management: profiles, contact details, account status,
 * order history, notes, search, pagination, duplicate detection.
 *
 * Permission map:
 *  - customers.view   : list + show (financial fields are gated to
 *                      finance.view inside the view template)
 *  - customers.create : create new customer (cashier role)
 *  - customers.manage : full edit, status change, soft-delete, notes
 *
 * Soft-delete: a customer row is never hard-deleted. The foreign key
 * on orders.customer_id is ON DELETE RESTRICT, and the repository
 * refuses to soft-delete if the customer has ever placed an order —
 * historical order linkage must remain intact.
 */
final class CustomerController
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 20));
        $repo = new CustomerRepository();
        $result = $repo->search(
            trim((string) $request->query('q', '')),
            (string) $request->query('status', ''),
            $page,
            $perPage,
        );

        return Response::html(View::render('admin/customers/index', [
            'pageTitle' => 'Customers',
            'customers' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'statuses' => CustomerRepository::ACCOUNT_STATUSES,
            'canSeeFinance' => AuthService::can('finance.view') || AuthService::can('payments.view'),
        ]));
    }

    public function duplicates(Request $request): Response
    {
        $repo = new CustomerRepository();
        $pairs = $repo->findDuplicates();

        return Response::html(View::render('admin/customers/duplicates', [
            'pageTitle' => 'Duplicate contacts',
            'pairs' => $pairs,
        ]));
    }

    public function show(string $id): Response
    {
        $repo = new CustomerRepository();
        // Soft-deleted customers are still viewable (history preserved),
        // so use findWithTrashed() and surface the soft-delete banner.
        $customer = $repo->findWithTrashed((int) $id);
        if ($customer === null) {
            throw new NotFoundException('Customer not found.');
        }

        return Response::html(View::render('admin/customers/show', [
            'pageTitle' => $customer['name'],
            'customer' => $customer,
            'canManage' => AuthService::can('customers.manage'),
            'canSeeFinance' => AuthService::can('finance.view') || AuthService::can('payments.view'),
        ]));
    }

    public function create(): Response
    {
        return Response::html(View::render('admin/customers/form', [
            'pageTitle' => 'Add customer',
            'customer' => null,
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        $errors = $this->validateInput($request, null);

        if ($errors !== []) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/customers/form', [
                'pageTitle' => 'Add customer',
                'customer' => null,
                'errors' => $errors,
            ]), 422);
        }

        $data = $request->post();
        $repo = new CustomerRepository();

        // Catch DB-level duplicate constraint violations as a defence-in-depth
        // check. The validator already rejects duplicates with deleted_at IS
        // NULL filtered out, but a race between validate and insert could
        // otherwise produce a 500. We do not rely on a UNIQUE index any more
        // (migration 016 downgraded it to a regular index so soft-deleted
        // phones can be reused), so this branch is mostly for safety if a
        // future migration re-introduces strict uniqueness.
        try {
            $id = $repo->create($data);
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            $errors = [];
            if (str_contains($msg, "uq_customers_phone") || str_contains($msg, "'phone'")) {
                $errors['phone'] = 'This phone number is already in use by another customer.';
            } elseif (str_contains($msg, "uq_customers_email") || str_contains($msg, "'email'")) {
                $errors['email'] = 'This email is already in use by another customer.';
            } else {
                $errors['name'] = 'Could not save the customer: ' . $msg;
            }
            Session::flashInput($data);
            return Response::html(View::render('admin/customers/form', [
                'pageTitle' => 'Add customer',
                'customer' => null,
                'errors' => $errors,
            ]), 422);
        }

        AuditService::record('customer.created', 'customer', $id, [
            'name' => $data['name'],
            'phone' => $data['phone'],
            'account_status' => $data['account_status'] ?? 'active',
        ], $request);

        flash_success('Customer created.');
        return Response::redirect(url('/admin/customers/' . $id));
    }

    public function edit(string $id): Response
    {
        $repo = new CustomerRepository();
        $customer = $repo->find((int) $id);
        if ($customer === null) {
            throw new NotFoundException('Customer not found.');
        }

        return Response::html(View::render('admin/customers/form', [
            'pageTitle' => 'Edit: ' . $customer['name'],
            'customer' => $customer,
            'errors' => [],
        ]));
    }

    public function update(string $id, Request $request): Response
    {
        $repo = new CustomerRepository();
        $customer = $repo->find((int) $id);
        if ($customer === null) {
            throw new NotFoundException('Customer not found.');
        }

        $errors = $this->validateInput($request, (int) $id);
        if ($errors !== []) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/customers/form', [
                'pageTitle' => 'Edit: ' . $customer['name'],
                'customer' => array_merge($customer, $request->post()),
                'errors' => $errors,
            ]), 422);
        }

        $data = $request->post();
        try {
            $repo->update((int) $id, $data);
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            $errors = [];
            if (str_contains($msg, "uq_customers_phone") || str_contains($msg, "'phone'")) {
                $errors['phone'] = 'This phone number is already in use by another customer.';
            } elseif (str_contains($msg, "uq_customers_email") || str_contains($msg, "'email'")) {
                $errors['email'] = 'This email is already in use by another customer.';
            } else {
                $errors['name'] = 'Could not save the customer: ' . $msg;
            }
            Session::flashInput($data);
            return Response::html(View::render('admin/customers/form', [
                'pageTitle' => 'Edit: ' . $customer['name'],
                'customer' => array_merge($customer, $data),
                'errors' => $errors,
            ]), 422);
        }

        AuditService::record('customer.updated', 'customer', (int) $id, [
            'name' => $data['name'],
        ], $request);

        flash_success('Customer updated.');
        return Response::redirect(url('/admin/customers/' . $id));
    }

    public function updateStatus(string $id, Request $request): Response
    {
        $repo = new CustomerRepository();
        $customer = $repo->find((int) $id);
        if ($customer === null) {
            throw new NotFoundException('Customer not found.');
        }

        $status = (string) $request->input('account_status', '');
        if (!in_array($status, CustomerRepository::ACCOUNT_STATUSES, true)) {
            flash_error('Invalid account status.');
            return Response::redirect(url('/admin/customers/' . $id));
        }

        $repo->updateStatus((int) $id, $status);
        AuditService::record('customer.status_changed', 'customer', (int) $id, [
            'from' => $customer['account_status'],
            'to' => $status,
        ], $request);

        flash_success('Account status set to ' . $status . '.');
        return Response::redirect(url('/admin/customers/' . $id));
    }

    public function addNote(string $id, Request $request): Response
    {
        $repo = new CustomerRepository();
        $customer = $repo->find((int) $id);
        if ($customer === null) {
            throw new NotFoundException('Customer not found.');
        }

        $body = trim((string) $request->input('body', ''));
        if ($body === '') {
            flash_error('Note body cannot be empty.');
            return Response::redirect(url('/admin/customers/' . $id));
        }

        $author = AuthService::id();
        if ($author === null) {
            flash_error('You must be signed in to add a note.');
            return Response::redirect(url('/admin/customers/' . $id));
        }

        $repo->addNote((int) $id, $author, $body);
        AuditService::record('customer.note_added', 'customer', (int) $id, [
            'body_preview' => mb_substr($body, 0, 80),
        ], $request);

        flash_success('Note added.');
        return Response::redirect(url('/admin/customers/' . $id) . '#notes');
    }

    public function destroy(string $id, Request $request): Response
    {
        $repo = new CustomerRepository();
        $customer = $repo->find((int) $id);
        if ($customer === null) {
            throw new NotFoundException('Customer not found.');
        }

        if ($repo->hasOrders((int) $id)) {
            flash_error(
                'This customer has order history and cannot be deleted. '
                . 'Suspend the account instead — historical orders must remain linked.'
            );
            return Response::redirect(url('/admin/customers/' . $id));
        }

        $repo->softDelete((int) $id);
        AuditService::record('customer.deleted', 'customer', (int) $id, [
            'name' => $customer['name'],
            'phone' => $customer['phone'],
        ], $request);

        flash_success('Customer deleted (soft delete — history preserved).');
        return Response::redirect(url('/admin/customers'));
    }

    public function restore(string $id, Request $request): Response
    {
        $repo = new CustomerRepository();
        // Allow restoring a previously soft-deleted customer.
        $customer = $repo->findWithTrashed((int) $id);
        if ($customer === null) {
            throw new NotFoundException('Customer not found.');
        }
        if (!$repo->isDeleted((int) $id)) {
            flash_error('That customer is not deleted.');
            return Response::redirect(url('/admin/customers/' . $id));
        }

        $repo->restore((int) $id);
        AuditService::record('customer.restored', 'customer', (int) $id, [
            'name' => $customer['name'],
        ], $request);

        flash_success('Customer restored.');
        return Response::redirect(url('/admin/customers/' . $id));
    }

    /**
     * Validate create/update input. Phone and email uniqueness is checked
     * against the customers table excluding soft-deleted rows and
     * excluding the current row (when editing).
     *
     * @return array<string,string>
     */
    private function validateInput(Request $request, ?int $excludeId): array
    {
        $errors = [];
        $repo = new CustomerRepository();

        $name = trim((string) $request->input('name', ''));
        $phone = trim((string) $request->input('phone', ''));
        $email = trim((string) $request->input('email', ''));
        $altPhone = trim((string) $request->input('alt_phone', ''));

        if ($name === '' || mb_strlen($name) < 2) {
            $errors['name'] = 'Name is required (min 2 characters).';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'Name may not exceed 120 characters.';
        }

        if ($phone === '' || mb_strlen($phone) < 6) {
            $errors['phone'] = 'A phone number is required (min 6 digits).';
        } elseif (mb_strlen($phone) > 30) {
            $errors['phone'] = 'Phone may not exceed 30 characters.';
        } elseif ($repo->phoneTaken($phone, $excludeId)) {
            $errors['phone'] = 'This phone number is already in use by another customer.';
        }

        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'A valid email address is required.';
            } elseif (mb_strlen($email) > 190) {
                $errors['email'] = 'Email may not exceed 190 characters.';
            } elseif ($repo->emailTaken($email, $excludeId)) {
                $errors['email'] = 'This email is already in use by another customer.';
            }
        }

        if ($altPhone !== '' && mb_strlen($altPhone) > 30) {
            $errors['alt_phone'] = 'Alternate phone may not exceed 30 characters.';
        }

        return $errors;
    }
}
