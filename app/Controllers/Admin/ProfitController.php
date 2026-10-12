<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\ProfitService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * COGS and profit calculation controller.
 * All routes require finance.view permission (read-only financial reports).
 */
final class ProfitController
{
    public function dashboard(): Response
    {
        $svc = new ProfitService();
        $pnl = $svc->dashboard();

        return Response::html(View::render('admin/profit/dashboard', [
            'pageTitle' => 'Profit & Loss',
            'pnl' => $pnl,
        ]));
    }

    public function cogsReport(Request $request): Response
    {
        $from = (string) $request->query('from', gmdate('Y-m-01'));
        $to = (string) $request->query('to', gmdate('Y-m-d'));
        $svc = new ProfitService();
        $report = $svc->cogsReport($from, $to);

        return Response::html(View::render('admin/profit/cogs', [
            'pageTitle' => 'COGS Report',
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ]));
    }

    public function profitAndLoss(Request $request): Response
    {
        $from = (string) $request->query('from', gmdate('Y-m-01'));
        $to = (string) $request->query('to', gmdate('Y-m-d'));
        $svc = new ProfitService();
        $pnl = $svc->profitAndLoss($from, $to);

        return Response::html(View::render('admin/profit/pnl', [
            'pageTitle' => 'Profit & Loss Statement',
            'pnl' => $pnl,
        ]));
    }
}
