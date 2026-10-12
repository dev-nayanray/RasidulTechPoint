<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Services\AuditService;
use App\Services\CashbookService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Cashbook and balances controller.
 */
final class CashbookController
{
    public function index(): Response
    {
        $svc = new CashbookService();
        $accounts = $svc->allAccounts(true);
        $totalBalance = array_sum(array_map(fn ($a) => (float) $a['current_balance'], $accounts));
        $unverified = (int) \App\Support\Database\Connection::pdo()
            ->query("SELECT COUNT(*) FROM cash_movements WHERE settlement_status = 'unverified'")
            ->fetchColumn();
        $disputed = (int) \App\Support\Database\Connection::pdo()
            ->query("SELECT COUNT(*) FROM cash_movements WHERE settlement_status = 'disputed'")
            ->fetchColumn();
        $recent = $svc->searchMovements('', null, '', '', '', '', 1, 10)['rows'];

        return Response::html(View::render('admin/cashbook/dashboard', [
            'pageTitle' => 'Cashbook',
            'accounts' => $accounts,
            'totalBalance' => $totalBalance,
            'unverified' => $unverified,
            'disputed' => $disputed,
            'recent' => $recent,
        ]));
    }

    // ---------------------------------------------------- ACCOUNTS --

    public function accountIndex(): Response
    {
        return Response::html(View::render('admin/cashbook/accounts/index', [
            'pageTitle' => 'Cash & Bank Accounts',
            'accounts' => (new CashbookService())->allAccounts(false),
            'accountTypes' => CashbookService::ACCOUNT_TYPES,
        ]));
    }

    public function accountStore(Request $request): Response
    {
        try {
            $id = (new CashbookService())->createAccount(
                (string) $request->input('name', ''),
                (string) $request->input('account_type', 'cash'),
                (float) $request->input('opening_balance', 0),
                current_user_id(),
                $request->input('account_number') ?: null,
                $request->input('bank_name') ?: null,
                $request->input('branch') ?: null,
            );
            flash_success('Account created.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/cashbook/accounts'));
    }

    public function accountShow(string $id): Response
    {
        $svc = new CashbookService();
        $account = $svc->findAccount((int) $id);
        if ($account === null) {
            throw new NotFoundException('Account not found.');
        }
        return Response::html(View::render('admin/cashbook/accounts/show', [
            'pageTitle' => $account['name'],
            'account' => $account,
        ]));
    }

    public function accountUpdate(string $id, Request $request): Response
    {
        try {
            (new CashbookService())->updateAccount(
                (int) $id,
                (string) $request->input('name', ''),
                !empty($request->input('is_active')),
                $request->input('account_number') ?: null,
                $request->input('bank_name') ?: null,
                $request->input('branch') ?: null,
            );
            flash_success('Account updated.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/cashbook/accounts'));
    }

    // ---------------------------------------------------- MOVEMENTS --

    public function movementIndex(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 25));
        $svc = new CashbookService();
        $accountId = $request->query('account', 0) !== null ? (int) $request->query('account', 0) : null;
        $result = $svc->searchMovements(
            trim((string) $request->query('q', '')),
            $accountId,
            (string) $request->query('type', ''),
            (string) $request->query('settlement', ''),
            (string) $request->query('from', ''),
            (string) $request->query('to', ''),
            $page,
            $perPage,
        );
        return Response::html(View::render('admin/cashbook/movements/index', [
            'pageTitle' => 'Cash Movements',
            'movements' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'accountId' => $accountId ?? 0,
            'type' => (string) $request->query('type', ''),
            'settlement' => (string) $request->query('settlement', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'movementTypes' => CashbookService::MOVEMENT_TYPES,
            'settlementStatuses' => CashbookService::SETTLEMENT_STATUSES,
            'accounts' => $svc->allAccounts(true),
        ]));
    }

    public function movementShow(string $id): Response
    {
        $movement = (new CashbookService())->findMovement((int) $id);
        if ($movement === null) {
            throw new NotFoundException('Movement not found.');
        }
        return Response::html(View::render('admin/cashbook/movements/show', [
            'pageTitle' => 'Movement ' . $movement['movement_number'],
            'movement' => $movement,
        ]));
    }

    public function movementStore(Request $request): Response
    {
        try {
            $svc = new CashbookService();
            $result = $svc->recordMovement(
                (int) $request->input('account_id', 0),
                (string) $request->input('movement_type', 'deposit'),
                (string) $request->input('direction', 'in'),
                (float) $request->input('amount', 0),
                null, null,
                $request->input('external_reference') ?: null,
                $request->input('description') ?: null,
                current_user_id(),
            );
            flash_success('Movement ' . $result['movement_number'] . ' recorded. Balance after: ' . money($result['balance_after']) . '. Settlement: unverified.');
            return Response::redirect(url('/admin/cashbook/movements/' . $result['id']));
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/cashbook/movements'));
        }
    }

    public function movementVerify(string $id): Response
    {
        try {
            (new CashbookService())->verifyMovement((int) $id, current_user_id());
            flash_success('Movement verified — external settlement confirmed.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/cashbook/movements/' . $id));
    }

    public function movementDispute(string $id): Response
    {
        try {
            (new CashbookService())->disputeMovement((int) $id, current_user_id());
            flash_success('Movement flagged as disputed for investigation.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/cashbook/movements/' . $id));
    }

    // ---------------------------------------------------- TRANSFERS --

    public function transferForm(): Response
    {
        return Response::html(View::render('admin/cashbook/transfer', [
            'pageTitle' => 'Transfer Between Accounts',
            'accounts' => (new CashbookService())->allAccounts(true),
            'errors' => [],
        ]));
    }

    public function transferStore(Request $request): Response
    {
        try {
            $result = (new CashbookService())->transfer(
                (int) $request->input('from_account_id', 0),
                (int) $request->input('to_account_id', 0),
                (float) $request->input('amount', 0),
                $request->input('description') ?: null,
                current_user_id(),
            );
            flash_success('Transfer completed.');
            return Response::redirect(url('/admin/cashbook/movements/' . $result['in_movement_id']));
        } catch (\App\Exceptions\ValidationException $e) {
            return Response::html(View::render('admin/cashbook/transfer', [
                'pageTitle' => 'Transfer Between Accounts',
                'accounts' => (new CashbookService())->allAccounts(true),
                'errors' => $e->errors,
            ]), 422);
        }
    }

    // ---------------------------------------------------- RECONCILIATION --

    public function reconcileForm(string $id): Response
    {
        $svc = new CashbookService();
        $account = $svc->findAccount((int) $id);
        if ($account === null) {
            throw new NotFoundException('Account not found.');
        }
        return Response::html(View::render('admin/cashbook/reconcile', [
            'pageTitle' => 'Reconcile ' . $account['name'],
            'account' => $account,
        ]));
    }

    public function reconcileStore(string $id, Request $request): Response
    {
        try {
            $result = (new CashbookService())->reconcileAccount(
                (int) $id,
                (float) $request->input('verified_balance', 0),
                current_user_id(),
            );
            if ($result['status'] === 'balanced') {
                flash_success('Reconciliation: balanced. Recorded = ' . money($result['recorded_balance']) . ', verified = ' . money($result['verified_balance']) . '.');
            } else {
                flash_error('Reconciliation: discrepancy of ' . money($result['discrepancy']) . '. Recorded = ' . money($result['recorded_balance']) . ', verified = ' . money($result['verified_balance']) . '.');
            }
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/cashbook/accounts/' . $id));
    }
}
