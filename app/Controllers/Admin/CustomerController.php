<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\CustomerRepository;
use App\Services\AuditService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

final class CustomerController
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 20));
        $result = (new CustomerRepository())->search(trim((string) $request->query('q', '')), $page, $perPage);

        return Response::html(View::render('admin/customers/index', [
            'pageTitle' => 'Customers',
            'customers' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
        ]));
    }

    public function show(string $id): Response
    {
        $customer = (new CustomerRepository())->find((int) $id);
        if ($customer === null) {
            throw new NotFoundException('Customer not found.');
        }

        return Response::html(View::render('admin/customers/show', [
            'pageTitle' => $customer['name'],
            'customer' => $customer,
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
        try {
            $data = Validator::make($request->post(), [
                'name' => 'required|string|min:2|max:120',
                'phone' => 'required|string|min:6|max:30|unique:customers,phone',
                'email' => 'nullable|email|max:190',
                'address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'notes' => 'nullable|string|max:500',
            ], ['phone' => 'Phone number'])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/customers/form', [
                'pageTitle' => 'Add customer',
                'customer' => null,
                'errors' => $e->errors,
            ]), 422);
        }

        $id = (new CustomerRepository())->create($data);
        AuditService::record('customer.created', 'customer', $id, ['name' => $data['name']], $request);

        flash_success('Customer created.');
        return Response::redirect(url('/admin/customers/' . $id));
    }
}
