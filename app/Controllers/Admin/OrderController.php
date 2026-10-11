<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\CustomerRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\OrderRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\OrderService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

/**
 * Order management: list with filters, detail view (customer info,
 * item snapshots, totals, payment status, fulfillment notes, status
 * history), status transitions, payment recording + confirmation.
 *
 * Permission map:
 *  - orders.view     : list + show
 *  - orders.manage   : create / update status / add fulfillment note
 *  - payments.manage : record + confirm payments
 *
 * Status transitions are enforced server-side by OrderService::
 * STATUS_TRANSITIONS via assertTransitionAllowed(). The UI only
 * offers the next legal status from the current one — invalid jumps
 * like pending → completed are rejected before they hit the DB.
 *
 * Payment evidence rule (Prompt 22): non-cash payments require a
 * gateway transaction id or bank slip number before they count
 * toward order.payment_status. Cash is verified at the till.
 */
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
            $perPage,
            (string) $request->query('channel', ''),
            (string) $request->query('from', ''),
            (string) $request->query('to', ''),
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
            'channel' => (string) $request->query('channel', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
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

        $refundSvc = new \App\Services\RefundService();
        $invoiceSvc = new \App\Services\InvoiceService();
        $correctionSvc = new \App\Services\OrderCorrectionService();

        return Response::html(View::render('admin/orders/show', [
            'pageTitle' => 'Order ' . $order['reference'],
            'order' => $order,
            'methods' => OrderService::PAYMENT_METHODS,
            'canManage' => AuthService::can('orders.manage'),
            'canManagePayments' => AuthService::can('payments.manage'),
            'canRefund' => AuthService::can('payments.refund'),
            'canCorrect' => AuthService::can('pos.authorize_discount') || AuthService::can('payments.refund') || AuthService::can('settings.manage'),
            'refunds' => $refundSvc->forOrder((int) $order['id']),
            'refundableAmount' => $refundSvc->refundableAmount(null, (int) $order['id']),
            'activeInvoice' => $invoiceSvc->findByOrder((int) $order['id']),
            'corrections' => $correctionSvc->listForOrder((int) $order['id']),
            'correctableFields' => \App\Services\OrderCorrectionService::CORRECTABLE_FIELDS,
            'inventoryActions' => \App\Services\RefundService::INVENTORY_ACTIONS,
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
        $reason = trim((string) $request->input('reason', ''));
        if (!in_array($status, OrderService::ORDER_STATUSES, true)) {
            flash_error('Invalid order status.');
            return Response::redirect(url('/admin/orders/' . $id));
        }

        try {
            (new OrderService())->updateStatus((int) $id, $status, current_user_id(), $reason !== '' ? $reason : null);
            flash_success('Order status updated to ' . $status . '.');
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
                'evidence_ref' => 'nullable|string|max:120',
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
                $data['reference'] ?? null,
                $data['evidence_ref'] ?? null,
            );
            $msg = 'Payment recorded. Order payment status: ' . $result['payment_status'] . '.';
            if ($result['payment_state'] !== 'completed') {
                $msg .= ' Payment is pending evidence — confirm with a gateway slip to mark paid.';
            }
            flash_success($msg);
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }

        return Response::redirect(url('/admin/orders/' . $id));
    }

    /**
     * Confirm a pending non-cash payment with verified evidence.
     * Promotes the payment to 'completed' and re-computes the order
     * payment_status. Without this step, the payment stays 'pending'
     * and the order cannot be marked paid.
     */
    public function confirmPayment(string $id, string $paymentId, Request $request): Response
    {
        try {
            $data = Validator::make($request->post(), [
                'evidence_ref' => 'required|string|max:120',
            ], ['evidence_ref' => 'Evidence reference'])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/orders/' . $id));
        }

        try {
            $result = (new OrderService())->confirmPayment(
                (int) $paymentId,
                (string) $data['evidence_ref'],
                current_user_id(),
            );
            flash_success('Payment confirmed. Order payment status: ' . $result['payment_status'] . '.');
            AuditService::record('payment.confirmed', 'order', (int) $id, [
                'payment_id' => (int) $paymentId,
                'evidence_ref' => mb_substr((string) $data['evidence_ref'], 0, 80),
            ], $request);
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }

        return Response::redirect(url('/admin/orders/' . $id));
    }

    /**
     * Append a fulfillment note (operator-facing; not shown to the
     * customer). The order_fulfillment_notes table is append-only.
     */
    public function addFulfillmentNote(string $id, Request $request): Response
    {
        $body = trim((string) $request->input('body', ''));
        if ($body === '') {
            flash_error('Fulfillment note body cannot be empty.');
            return Response::redirect(url('/admin/orders/' . $id) . '#fulfillment-notes');
        }

        try {
            (new OrderService())->addFulfillmentNote((int) $id, current_user_id(), $body);
            flash_success('Fulfillment note added.');
            AuditService::record('order.fulfillment_note_added', 'order', (int) $id, [
                'body_preview' => mb_substr($body, 0, 80),
            ], $request);
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }

        return Response::redirect(url('/admin/orders/' . $id) . '#fulfillment-notes');
    }
}
