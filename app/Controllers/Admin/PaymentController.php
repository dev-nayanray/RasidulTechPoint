<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\PaymentRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\PaymentService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Payment records management.
 *
 * Permission map:
 *  - payments.view    : list + detail + reconciliation batches
 *  - payments.manage  : record manual payments + verify pending
 *  - payments.refund  : issue refunds
 *
 * Critical rule: this controller never accepts card security codes
 * (CVV/CVC) or banking PINs in any field. PaymentService::
 * assertNoCardSecurityData() runs on every state-changing endpoint as
 * defence-in-depth, in addition to the per-field validation.
 */
final class PaymentController
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 25));
        $repo = new PaymentRepository();
        $result = $repo->search(
            trim((string) $request->query('q', '')),
            (string) $request->query('status', ''),
            (string) $request->query('method', ''),
            (string) $request->query('gateway', ''),
            (string) $request->query('from', ''),
            (string) $request->query('to', ''),
            (string) $request->query('verified', ''),
            (int) $request->query('shift', 0),
            $page,
            $perPage,
        );

        return Response::html(View::render('admin/payments/index', [
            'pageTitle' => 'Payments',
            'payments' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'method' => (string) $request->query('method', ''),
            'gateway' => (string) $request->query('gateway', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'verified' => (string) $request->query('verified', ''),
            'methods' => $repo->methodsCatalog(),
        ]));
    }

    public function pendingQueue(): Response
    {
        $repo = new PaymentRepository();
        $pending = $repo->pendingVerification(100);

        return Response::html(View::render('admin/payments/pending', [
            'pageTitle' => 'Payment verification queue',
            'pending' => $pending,
            'canManage' => AuthService::can('payments.manage'),
        ]));
    }

    public function show(string $id): Response
    {
        $payment = (new PaymentRepository())->find((int) $id);
        if ($payment === null) {
            throw new NotFoundException('Payment not found.');
        }
        return Response::html(View::render('admin/payments/show', [
            'pageTitle' => 'Payment #' . $payment['id'],
            'payment' => $payment,
            'canManage' => AuthService::can('payments.manage'),
            'canRefund' => AuthService::can('payments.refund'),
        ]));
    }

    public function verify(string $id, Request $request): Response
    {
        $evidenceRef = trim((string) $request->input('evidence_ref', ''));
        if ($evidenceRef === '') {
            flash_error('Evidence reference is required to verify a payment.');
            return Response::redirect(url('/admin/payments/' . $id));
        }

        try {
            $svc = new PaymentService();
            // Defence-in-depth: scan the payload for forbidden card
            // security fields before any DB write.
            $svc->assertNoCardSecurityData($request->post());
            $result = $svc->verify((int) $id, $evidenceRef, current_user_id());
            flash_success('Payment #' . $result['payment_id'] . ' verified. Status: ' . $result['status'] . '.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }

        return Response::redirect(url('/admin/payments/' . $id));
    }

    public function refund(string $id, Request $request): Response
    {
        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            flash_error('A refund reason is required.');
            return Response::redirect(url('/admin/payments/' . $id));
        }

        try {
            $svc = new PaymentService();
            $svc->assertNoCardSecurityData($request->post());
            $result = $svc->refund((int) $id, $reason, current_user_id());
            flash_success('Payment #' . $result['payment_id'] . ' refunded. Order payment status recomputed.');
            AuditService::record('payment.refunded', 'payment', (int) $id, [
                'reason' => mb_substr($reason, 0, 80),
            ], $request);
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }

        return Response::redirect(url('/admin/payments/' . $id));
    }

    // ---------------------------------------------------- METHODS CATALOG --

    public function methodsIndex(): Response
    {
        $repo = new PaymentRepository();
        return Response::html(View::render('admin/payments/methods', [
            'pageTitle' => 'Payment methods',
            'methods' => $repo->methodsCatalog(),
        ]));
    }

    public function updateMethod(string $id, Request $request): Response
    {
        $repo = new PaymentRepository();
        $method = $repo->findMethod((int) $id);
        if ($method === null) {
            throw new NotFoundException('Payment method not found.');
        }

        $label = trim((string) $request->input('label', ''));
        $instructions = trim((string) $request->input('instructions', ''));
        $isActiveRaw = $request->input('is_active');
        $displayOrderRaw = $request->input('display_order');
        $isActive = $isActiveRaw !== null ? (int) !empty($isActiveRaw) : null;
        $displayOrder = $displayOrderRaw !== null ? (int) $displayOrderRaw : null;

        $svc = new PaymentService();
        $svc->assertNoCardSecurityData($request->post());

        $repo->updateMethod(
            (int) $id,
            $label !== '' ? $label : null,
            $instructions !== '' ? $instructions : null,
            $isActive,
            $displayOrder,
        );

        AuditService::record('payment.method_updated', 'payment_method', (int) $id, [
            'label' => $label ?: $method['label'],
            'is_active' => $isActive,
        ], $request);

        flash_success('Payment method updated.');
        return Response::redirect(url('/admin/payments/methods'));
    }

    // ---------------------------------------------------- RECONCILIATION --

    public function reconciliationIndex(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 25));
        $repo = new PaymentRepository();
        $result = $repo->batches($page, $perPage);
        return Response::html(View::render('admin/payments/reconciliation', [
            'pageTitle' => 'Reconciliation batches',
            'batches' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
        ]));
    }

    public function createReconciliationBatch(Request $request): Response
    {
        $date = trim((string) $request->input('batch_date', ''));
        $notes = trim((string) $request->input('notes', ''));
        try {
            $svc = new PaymentService();
            $svc->assertNoCardSecurityData($request->post());
            $result = $svc->createReconciliationBatch($date, current_user_id(), $notes !== '' ? $notes : null);
            flash_success("Batch created for {$result['batch_date']}. Expected: {$result['expected_total']} BDT, {$result['payment_count']} payment(s).");
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/payments/reconciliation'));
    }

    public function confirmReconciliationBatch(string $id, Request $request): Response
    {
        $actual = (float) $request->input('actual_total', 0);
        $notes = trim((string) $request->input('notes', ''));
        try {
            $svc = new PaymentService();
            $svc->assertNoCardSecurityData($request->post());
            $result = $svc->confirmReconciliationBatch((int) $id, $actual, current_user_id(), $notes !== '' ? $notes : null);
            if ($result['status'] === 'confirmed') {
                flash_success('Batch confirmed. Reconciled perfectly.');
            } else {
                flash_error('Batch confirmed with discrepancy of ' . $result['discrepancy'] . ' BDT. Please review.');
            }
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/payments/reconciliation'));
    }
}
