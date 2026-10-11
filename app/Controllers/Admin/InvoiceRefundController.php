<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\AuditService;
use App\Services\InvoiceService;
use App\Services\OrderCorrectionService;
use App\Services\RefundService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Invoices, refunds, and authorized corrections (Prompt 25).
 *
 * Routes (under /admin prefix, all auth + CSRF gated):
 *   GET  /admin/invoices                           list
 *   GET  /admin/invoices/{id}                      printable invoice view
 *   POST /admin/orders/{id}/invoice                generate new invoice
 *   POST /admin/invoices/{id}/void                 void an invoice
 *   POST /admin/invoices/{id}/mark-paid            mark invoice as paid
 *   GET  /admin/refunds                            list
 *   GET  /admin/refunds/{id}                       refund detail
 *   POST /admin/orders/{id}/refund                 create pending refund
 *   POST /admin/refunds/{id}/process               process pending refund
 *   POST /admin/refunds/{id}/cancel                cancel pending refund
 *   POST /admin/orders/{id}/corrections            record an authorized correction
 *
 * Permission map:
 *  - orders.view       : view invoices + refunds (read-only)
 *  - payments.refund   : create / process / cancel refunds
 *  - settings.manage   : void invoices
 *  - pos.authorize_discount OR payments.refund OR settings.manage :
 *    record order corrections
 */
final class InvoiceRefundController
{
    // ---------------------------------------------------- INVOICES --

    public function invoiceIndex(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 25));
        $svc = new InvoiceService();
        $result = $svc->search(
            trim((string) $request->query('q', '')),
            (string) $request->query('status', ''),
            $page,
            $perPage,
        );
        return Response::html(View::render('admin/invoices/index', [
            'pageTitle' => 'Invoices',
            'invoices' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'statuses' => InvoiceService::INVOICE_STATUSES,
        ]));
    }

    public function invoiceShow(string $id): Response
    {
        $invoice = (new InvoiceService())->find((int) $id);
        if ($invoice === null) {
            throw new NotFoundException('Invoice not found.');
        }

        $biz = $this->businessProfile();
        return Response::html(View::render('admin/invoices/show', [
            'pageTitle' => 'Invoice ' . $invoice['invoice_number'],
            'invoice' => $invoice,
            'business' => $biz,
            'canVoid' => AuthService::can('settings.manage'),
        ]));
    }

    public function invoiceGenerate(string $id, Request $request): Response
    {
        $billing = [
            'billing_name' => trim((string) $request->input('billing_name', '')),
            'billing_address' => trim((string) $request->input('billing_address', '')) ?: null,
            'billing_phone' => trim((string) $request->input('billing_phone', '')) ?: null,
            'billing_email' => trim((string) $request->input('billing_email', '')) ?: null,
            'tax_name' => trim((string) $request->input('tax_name', '')) ?: null,
            'tax_rate' => (float) $request->input('tax_rate', 0),
            'notes' => trim((string) $request->input('notes', '')) ?: null,
            'due_date' => trim((string) $request->input('due_date', '')) ?: null,
        ];

        try {
            $result = (new InvoiceService())->generateForOrder((int) $id, $billing, current_user_id());
            flash_success('Invoice ' . $result['invoice_number'] . ' issued. Total: ' . money($result['total']) . '.');
            return Response::redirect(url('/admin/invoices/' . $result['id']));
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/orders/' . $id));
        }
    }

    public function invoiceVoid(string $id, Request $request): Response
    {
        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            flash_error('A void reason is required.');
            return Response::redirect(url('/admin/invoices/' . $id));
        }
        try {
            (new InvoiceService())->void((int) $id, current_user_id(), $reason);
            flash_success('Invoice voided. Issue a new invoice with the next sequential number.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/invoices/' . $id));
    }

    public function invoiceMarkPaid(string $id): Response
    {
        try {
            (new InvoiceService())->markPaid((int) $id);
            flash_success('Invoice marked as paid.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/invoices/' . $id));
    }

    // ---------------------------------------------------- REFUNDS --

    public function refundIndex(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 25));
        $svc = new RefundService();
        $result = $svc->search(
            trim((string) $request->query('q', '')),
            (string) $request->query('status', ''),
            $page,
            $perPage,
        );
        return Response::html(View::render('admin/refunds/index', [
            'pageTitle' => 'Refunds',
            'refunds' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'statuses' => RefundService::REFUND_STATUSES,
        ]));
    }

    public function refundShow(string $id): Response
    {
        $refund = (new RefundService())->find((int) $id);
        if ($refund === null) {
            throw new NotFoundException('Refund not found.');
        }
        return Response::html(View::render('admin/refunds/show', [
            'pageTitle' => 'Refund ' . $refund['refund_number'],
            'refund' => $refund,
            'canProcess' => AuthService::can('payments.refund'),
        ]));
    }

    public function refundCreate(string $id, Request $request): Response
    {
        $amount = (float) $request->input('amount', 0);
        $reason = trim((string) $request->input('reason', ''));
        $inventoryAction = (string) $request->input('inventory_action', 'no_action');
        $paymentId = $request->input('payment_id');
        $paymentId = $paymentId !== null && $paymentId !== '' ? (int) $paymentId : null;

        try {
            $svc = new RefundService();
            $result = $svc->create(
                (int) $id,
                $amount,
                $reason,
                $inventoryAction,
                current_user_id(),
                $paymentId,
            );
            flash_success('Refund ' . $result['refund_number'] . ' created. Process it to release payment + inventory.');
            return Response::redirect(url('/admin/refunds/' . $result['id']));
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/orders/' . $id));
        }
    }

    public function refundProcess(string $id): Response
    {
        try {
            $result = (new RefundService())->process((int) $id, current_user_id());
            flash_success('Refund #' . $result['id'] . ' processed. Payment refunded, inventory released per the action.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/refunds/' . $id));
    }

    public function refundCancel(string $id): Response
    {
        try {
            (new RefundService())->cancel((int) $id, current_user_id());
            flash_success('Refund cancelled.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/refunds/' . $id));
    }

    // ---------------------------------------------------- CORRECTIONS --

    public function correctionStore(string $id, Request $request): Response
    {
        $field = trim((string) $request->input('field', ''));
        $oldValue = (string) $request->input('old_value', '');
        $newValue = (string) $request->input('new_value', '');
        $reason = trim((string) $request->input('reason', ''));
        $ipAddress = $request->ip();

        try {
            $svc = new OrderCorrectionService();
            $svc->record(
                (int) $id,
                $field,
                $oldValue,
                $newValue,
                $reason,
                current_user_id(),
                $ipAddress,
            );
            flash_success('Correction recorded. Audit trail updated.');
            AuditService::record('order.correction_recorded', 'order', (int) $id, [
                'field' => $field,
                'reason' => mb_substr($reason, 0, 80),
            ], $request);
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/orders/' . $id));
    }

    // ---------------------------------------------------- HELPERS --

    /** @return array{name:string,address:string,phone:string,email:string} */
    private function businessProfile(): array
    {
        $row = \App\Support\Database\Connection::pdo()->query(
            'SELECT name, address, phone, email FROM business_profiles ORDER BY id LIMIT 1'
        )->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['name' => 'Rasidul Tech Point', 'address' => '', 'phone' => '', 'email' => ''];
        }
        return [
            'name' => (string) ($row['name'] ?? 'Rasidul Tech Point'),
            'address' => (string) ($row['address'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
        ];
    }
}
