<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\CustomerRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\OrderRepository;
use App\Services\AuditService;
use App\Services\OrderService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

final class OrderController
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 20));
        $result = (new OrderRepository())->search(
            trim((string) $request->query('q', '')),
            (string) $request->query('status', ''),
            (string) $request->query('payment', ''),
            $page,
            $perPage
        );

        return Response::html(View::render('admin/orders/index', [
            'pageTitle' => 'Orders',
            'orders' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'payment' => (string) $request->query('payment', ''),
            'orderStatuses' => OrderService::ORDER_STATUSES,
            'paymentStatuses' => OrderService::PAYMENT_STATUSES,
        ]));
    }

    public function show(string $id): Response
    {
        $order = (new OrderRepository())->find((int) $id);
        if ($order === null) {
            throw new NotFoundException('Order not found.');
        }

        return Response::html(View::render('admin/orders/show', [
            'pageTitle' => 'Order ' . $order['reference'],
            'order' => $order,
            'methods' => OrderService::PAYMENT_METHODS,
        ]));
    }

    public function create(): Response
    {
        return Response::html(View::render('admin/orders/create', [
            'pageTitle' => 'New order',
            'customers' => (new CustomerRepository())->search('', 1, 500)['rows'],
            'availableUnits' => (new InventoryRepository())->search('', 'available', 0, 1, 500)['rows'],
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        try {
            $data = Validator::make($request->post(), [
                'customer_id' => 'required|integer|exists:customers,id',
                'channel' => 'required|in:online,pos',
                'status' => 'required|in:pending,confirmed,processing,completed',
                'discount' => 'nullable|decimal:2',
                'notes' => 'nullable|string|max:500',
                'units' => 'required|array',
            ], [
                'customer_id' => 'Customer',
                'units' => 'Units',
            ])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/orders/create', [
                'pageTitle' => 'New order',
                'customers' => (new CustomerRepository())->search('', 1, 500)['rows'],
                'availableUnits' => (new InventoryRepository())->search('', 'available', 0, 1, 500)['rows'],
                'errors' => $e->errors,
            ]), 422);
        }

        $unitIds = array_values(array_filter(array_map('intval', (array) $data['units']), fn ($v) => $v > 0));
        if ($unitIds === []) {
            flash_error('Select at least one available unit.');
            return Response::redirect(url('/admin/orders/create'));
        }

        try {
            $order = (new OrderService())->createOrder(
                (int) $data['customer_id'],
                $unitIds,
                $data['channel'],
                current_user_id(),
                ['discount' => (float) ($data['discount'] ?? 0), 'notes' => $data['notes'] ?? null],
                $data['status']
            );
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/orders/create'));
        }

        flash_success('Order ' . $order['reference'] . ' created.');
        return Response::redirect(url('/admin/orders/' . $order['id']));
    }

    public function updateStatus(string $id, Request $request): Response
    {
        $status = (string) $request->input('status', '');
        if (!in_array($status, OrderService::ORDER_STATUSES, true)) {
            flash_error('Invalid order status.');
            return Response::redirect(url('/admin/orders/' . $id));
        }

        try {
            (new OrderService())->updateStatus((int) $id, $status, current_user_id());
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }

        return Response::redirect(url('/admin/orders/' . $id));
    }

    public function recordPayment(string $id, Request $request): Response
    {
        try {
            $data = Validator::make($request->post(), [
                'amount' => 'required|decimal:2',
                'method' => 'required|in:cash,card,bank_transfer,bkash,nagad,other',
                'reference' => 'nullable|string|max:120',
            ], ['amount' => 'Amount', 'method' => 'Payment method'])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/orders/' . $id));
        }

        try {
            $result = (new OrderService())->recordPayment(
                (int) $id,
                (float) $data['amount'],
                $data['method'],
                current_user_id(),
                $data['reference'] ?? null
            );
            flash_success('Payment recorded. Order payment status: ' . $result['payment_status'] . '.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }

        return Response::redirect(url('/admin/orders/' . $id));
    }
}
