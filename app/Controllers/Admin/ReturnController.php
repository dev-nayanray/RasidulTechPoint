<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\OrderRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\ReturnService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Returns and exchanges controller.
 *
 * Routes (under /admin/returns, all auth + CSRF):
 *   GET  /admin/returns                    dashboard
 *   GET  /admin/returns/list              list
 *   GET  /admin/returns/{id}              show
 *   POST /admin/returns                   create
 *   POST /admin/returns/{id}/approve
 *   POST /admin/returns/{id}/reject
 *   POST /admin/returns/{id}/receive
 *   POST /admin/returns/{id}/inspect
 *   POST /admin/returns/{id}/complete
 *   POST /admin/returns/{id}/cancel
 *   GET  /admin/returns/create/{orderId}  create form (from order)
 */
final class ReturnController
{
    public function index(): Response
    {
        $svc = new ReturnService();
        $pdo = \App\Support\Database\Connection::pdo();
        $requested = (int) $pdo->query("SELECT COUNT(*) FROM return_requests WHERE status = 'requested'")->fetchColumn();
        $inProgress = (int) $pdo->query("SELECT COUNT(*) FROM return_requests WHERE status IN ('approved','received','inspected')")->fetchColumn();
        $completed = (int) $pdo->query("SELECT COUNT(*) FROM return_requests WHERE status = 'completed'")->fetchColumn();
        $recent = $svc->search('', '', 1, 10)['rows'];

        return Response::html(View::render('admin/returns/dashboard', [
            'pageTitle' => 'Returns & Exchanges',
            'requested' => $requested,
            'inProgress' => $inProgress,
            'completed' => $completed,
            'recent' => $recent,
        ]));
    }

    public function list(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 25));
        $svc = new ReturnService();
        $result = $svc->search(
            trim((string) $request->query('q', '')),
            (string) $request->query('status', ''),
            $page,
            $perPage,
        );
        return Response::html(View::render('admin/returns/list', [
            'pageTitle' => 'Returns',
            'returns' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'statuses' => ReturnService::RETURN_STATUSES,
        ]));
    }

    public function show(string $id): Response
    {
        $rr = (new ReturnService())->find((int) $id);
        if ($rr === null) {
            throw new NotFoundException('Return request not found.');
        }
        return Response::html(View::render('admin/returns/show', [
            'pageTitle' => 'Return ' . $rr['return_number'],
            'rr' => $rr,
            'inspectionResults' => ReturnService::INSPECTION_RESULTS,
            'dispositions' => ReturnService::INVENTORY_DISPOSITIONS,
        ]));
    }

    public function createForm(string $orderId): Response
    {
        $order = (new OrderRepository())->find((int) $orderId);
        if ($order === null) {
            throw new NotFoundException('Order not found.');
        }
        return Response::html(View::render('admin/returns/create', [
            'pageTitle' => 'Create return for ' . $order['reference'],
            'order' => $order,
            'requestTypes' => ReturnService::REQUEST_TYPES,
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        $data = $request->post();
        $itemIds = array_values(array_filter(array_map('intval', (array) ($data['items'] ?? [])), fn ($v) => $v > 0));
        try {
            $svc = new ReturnService();
            $result = $svc->createRequest(
                (int) $data['order_id'],
                (int) $data['customer_id'],
                (string) ($data['request_type'] ?? 'refund'),
                (string) ($data['reason'] ?? ''),
                $data['customer_reported'] ?? null,
                $itemIds,
                current_user_id(),
            );
            flash_success('Return ' . $result['return_number'] . ' created.');
            return Response::redirect(url('/admin/returns/' . $result['id']));
        } catch (\App\Exceptions\ValidationException $e) {
            $order = (new OrderRepository())->find((int) $data['order_id']);
            return Response::html(View::render('admin/returns/create', [
                'pageTitle' => 'Create return',
                'order' => $order,
                'requestTypes' => ReturnService::REQUEST_TYPES,
                'errors' => $e->errors,
            ]), 422);
        }
    }

    public function approve(string $id, Request $request): Response
    {
        try {
            (new ReturnService())->approve((int) $id, current_user_id(), $request->input('notes') ?: null);
            flash_success('Return approved.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/returns/' . $id));
    }

    public function reject(string $id, Request $request): Response
    {
        try {
            (new ReturnService())->reject((int) $id, current_user_id(), (string) $request->input('reason', ''));
            flash_success('Return rejected.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/returns/' . $id));
    }

    public function receive(string $id): Response
    {
        try {
            (new ReturnService())->receive((int) $id, current_user_id());
            flash_success('Items received. Units marked as returned (not sellable until inspection).');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/returns/' . $id));
    }

    public function inspect(string $id, Request $request): Response
    {
        $data = $request->post();
        $itemResults = [];
        foreach (($data['items'] ?? []) as $itemId => $result) {
            $itemResults[(int) $itemId] = [
                'inspection_result' => $result['inspection_result'] ?? 'pending',
                'inventory_disposition' => $result['inventory_disposition'] ?? null,
                'inspection_notes' => $result['inspection_notes'] ?? null,
            ];
        }
        try {
            (new ReturnService())->inspect(
                (int) $id,
                current_user_id(),
                $itemResults,
                $data['global_notes'] ?? null,
            );
            flash_success('Inspection recorded. Ready to process.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/returns/' . $id));
    }

    public function complete(string $id): Response
    {
        try {
            $result = (new ReturnService())->complete((int) $id, current_user_id());
            flash_success('Return completed. Refund: ' . money($result['refund_amount']) . '. Inventory dispositioned per inspection.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/returns/' . $id));
    }

    public function cancel(string $id, Request $request): Response
    {
        try {
            (new ReturnService())->cancel((int) $id, current_user_id(), $request->input('reason') ?: null);
            flash_success('Return cancelled.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/returns/' . $id));
    }
}
