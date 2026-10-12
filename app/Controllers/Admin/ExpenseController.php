<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Services\AuditService;
use App\Services\ExpenseService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Expense management controller.
 */
final class ExpenseController
{
    public function index(): Response
    {
        $svc = new ExpenseService();
        $pdo = \App\Support\Database\Connection::pdo();
        $pending = (int) $pdo->query("SELECT COUNT(*) FROM expense_entries WHERE status = 'pending'")->fetchColumn();
        $approved = (int) $pdo->query("SELECT COUNT(*) FROM expense_entries WHERE status = 'approved'")->fetchColumn();
        $paid = (int) $pdo->query("SELECT COUNT(*) FROM expense_entries WHERE status = 'paid'")->fetchColumn();
        $pendingTotal = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expense_entries WHERE status = 'pending'")->fetchColumn();
        $paidTotal = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expense_entries WHERE status = 'paid'")->fetchColumn();
        $monthStart = gmdate('Y-m-01');
        $recent = $svc->search('', '', null, $monthStart, '', 1, 10)['rows'];

        return Response::html(View::render('admin/expenses/dashboard', [
            'pageTitle' => 'Expenses',
            'pending' => $pending,
            'approved' => $approved,
            'paid' => $paid,
            'pendingTotal' => $pendingTotal,
            'paidTotal' => $paidTotal,
            'recent' => $recent,
            'categories' => $svc->allCategories(true),
        ]));
    }

    // ---------------------------------------------------- ENTRIES --

    public function entryIndex(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 25));
        $svc = new ExpenseService();
        $catId = $request->query('category', 0) !== null ? (int) $request->query('category', 0) : null;
        $result = $svc->search(
            trim((string) $request->query('q', '')),
            (string) $request->query('status', ''),
            $catId,
            (string) $request->query('from', ''),
            (string) $request->query('to', ''),
            $page,
            $perPage,
        );
        return Response::html(View::render('admin/expenses/entries/index', [
            'pageTitle' => 'Expense Entries',
            'entries' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'categoryId' => $catId ?? 0,
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'statuses' => ExpenseService::ENTRY_STATUSES,
            'paymentAccounts' => ExpenseService::PAYMENT_ACCOUNTS,
            'categories' => $svc->allCategories(true),
        ]));
    }

    public function entryShow(string $id): Response
    {
        $entry = (new ExpenseService())->findEntry((int) $id);
        if ($entry === null) {
            throw new NotFoundException('Expense entry not found.');
        }
        return Response::html(View::render('admin/expenses/entries/show', [
            'pageTitle' => 'Expense ' . $entry['expense_number'],
            'entry' => $entry,
        ]));
    }

    public function entryCreateForm(): Response
    {
        return Response::html(View::render('admin/expenses/entries/create', [
            'pageTitle' => 'New Expense',
            'categories' => (new ExpenseService())->allCategories(true),
            'paymentAccounts' => ExpenseService::PAYMENT_ACCOUNTS,
            'errors' => [],
        ]));
    }

    public function entryStore(Request $request): Response
    {
        $svc = new ExpenseService();
        $data = $request->post();
        $file = $request->file('receipt');
        try {
            $receiptPath = $svc->saveReceipt($file);
            if ($receiptPath !== null) {
                $data['receipt_path'] = $receiptPath;
            }
            $result = $svc->createEntry($data, current_user_id());
            flash_success('Expense ' . $result['expense_number'] . ' created.');
            return Response::redirect(url('/admin/expenses/entries/' . $result['id']));
        } catch (\App\Exceptions\ValidationException $e) {
            return Response::html(View::render('admin/expenses/entries/create', [
                'pageTitle' => 'New Expense',
                'categories' => $svc->allCategories(true),
                'paymentAccounts' => ExpenseService::PAYMENT_ACCOUNTS,
                'errors' => $e->errors,
            ]), 422);
        }
    }

    public function entryApprove(string $id): Response
    {
        try {
            (new ExpenseService())->approveEntry((int) $id, current_user_id());
            flash_success('Expense approved. Entry is now locked — no further edits allowed.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/expenses/entries/' . $id));
    }

    public function entryReject(string $id, Request $request): Response
    {
        try {
            (new ExpenseService())->rejectEntry((int) $id, current_user_id(), (string) $request->input('reason', ''));
            flash_success('Expense rejected.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/expenses/entries/' . $id));
    }

    public function entryMarkPaid(string $id): Response
    {
        try {
            (new ExpenseService())->markPaid((int) $id);
            flash_success('Expense marked as paid.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/expenses/entries/' . $id));
    }

    // ---------------------------------------------------- CATEGORIES --

    public function categoryIndex(): Response
    {
        return Response::html(View::render('admin/expenses/categories/index', [
            'pageTitle' => 'Expense Categories',
            'categories' => (new ExpenseService())->allCategories(false),
        ]));
    }

    public function categoryStore(Request $request): Response
    {
        try {
            (new ExpenseService())->createCategory(
                (string) $request->input('name', ''),
                $request->input('description') ?: null,
                (int) $request->input('display_order', 0),
            );
            flash_success('Category created.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/expenses/categories'));
    }

    public function categoryUpdate(string $id, Request $request): Response
    {
        try {
            (new ExpenseService())->updateCategory(
                (int) $id,
                (string) $request->input('name', ''),
                $request->input('description') ?: null,
                (int) $request->input('display_order', 0),
                !empty($request->input('is_active')),
            );
            flash_success('Category updated.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/expenses/categories'));
    }

    // ---------------------------------------------------- REPORTS --

    public function report(Request $request): Response
    {
        $from = (string) $request->query('from', gmdate('Y-m-01'));
        $to = (string) $request->query('to', gmdate('Y-m-d'));
        $report = (new ExpenseService())->reportByCategory($from, $to);
        return Response::html(View::render('admin/expenses/report', [
            'pageTitle' => 'Expense Report',
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ]));
    }
}
