<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\FinancialReportService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Financial reporting controller.
 * reports.view for viewing, reports.export for CSV export.
 */
final class FinancialReportController
{
    public function index(): Response
    {
        $svc = new FinancialReportService();
        $dashboard = $svc->dashboard();

        return Response::html(View::render('admin/reports/dashboard', [
            'pageTitle' => 'Financial Reports',
            'dashboard' => $dashboard,
        ]));
    }

    public function sales(Request $request): Response
    {
        $from = (string) $request->query('from', gmdate('Y-m-01'));
        $to = (string) $request->query('to', gmdate('Y-m-d'));
        $svc = new FinancialReportService();
        $report = $svc->salesReport($from, $to);

        if ($request->query('export') === 'csv') {
            $csv = $svc->toCsv($report['rows'], ['sale_date', 'order_count', 'gross_revenue', 'total_discount', 'net_revenue']);
            return Response::text($csv, 200)->header('Content-Type', 'text/csv')->header('Content-Disposition', 'attachment; filename="sales_report_' . $from . '_' . $to . '.csv"');
        }

        return Response::html(View::render('admin/reports/sales', [
            'pageTitle' => 'Sales Report',
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ]));
    }

    public function payments(Request $request): Response
    {
        $from = (string) $request->query('from', gmdate('Y-m-01'));
        $to = (string) $request->query('to', gmdate('Y-m-d'));
        $svc = new FinancialReportService();
        $report = $svc->paymentReport($from, $to);

        if ($request->query('export') === 'csv') {
            $csv = $svc->toCsv($report['rows'], ['method', 'count', 'total']);
            return Response::text($csv, 200)->header('Content-Type', 'text/csv')->header('Content-Disposition', 'attachment; filename="payment_report_' . $from . '_' . $to . '.csv"');
        }

        return Response::html(View::render('admin/reports/payments', [
            'pageTitle' => 'Payment Report',
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ]));
    }

    public function refunds(Request $request): Response
    {
        $from = (string) $request->query('from', gmdate('Y-m-01'));
        $to = (string) $request->query('to', gmdate('Y-m-d'));
        $svc = new FinancialReportService();
        $report = $svc->refundReport($from, $to);

        if ($request->query('export') === 'csv') {
            $csv = $svc->toCsv($report['rows'], ['refund_number', 'order_reference', 'customer_name', 'amount', 'reason', 'inventory_action', 'status', 'processed_at']);
            return Response::text($csv, 200)->header('Content-Type', 'text/csv')->header('Content-Disposition', 'attachment; filename="refund_report_' . $from . '_' . $to . '.csv"');
        }

        return Response::html(View::render('admin/reports/refunds', [
            'pageTitle' => 'Refund Report',
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ]));
    }

    public function expenses(Request $request): Response
    {
        $from = (string) $request->query('from', gmdate('Y-m-01'));
        $to = (string) $request->query('to', gmdate('Y-m-d'));
        $svc = new FinancialReportService();
        $report = $svc->expenseReport($from, $to);

        if ($request->query('export') === 'csv') {
            $csv = $svc->toCsv($report['rows'], ['category', 'count', 'total']);
            return Response::text($csv, 200)->header('Content-Type', 'text/csv')->header('Content-Disposition', 'attachment; filename="expense_report_' . $from . '_' . $to . '.csv"');
        }

        return Response::html(View::render('admin/reports/expenses', [
            'pageTitle' => 'Expense Report',
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ]));
    }

    public function grossProfit(Request $request): Response
    {
        $from = (string) $request->query('from', gmdate('Y-m-01'));
        $to = (string) $request->query('to', gmdate('Y-m-d'));
        $svc = new FinancialReportService();
        $report = $svc->grossProfitReport($from, $to);

        if ($request->query('export') === 'csv') {
            $csv = $svc->toCsv($report['orders'] ?? [], ['reference', 'customer_name', 'subtotal', 'discount', 'net_revenue', 'cogs', 'gross_profit', 'gross_margin']);
            return Response::text($csv, 200)->header('Content-Type', 'text/csv')->header('Content-Disposition', 'attachment; filename="gross_profit_report_' . $from . '_' . $to . '.csv"');
        }

        return Response::html(View::render('admin/reports/gross_profit', [
            'pageTitle' => 'Gross Profit Report',
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ]));
    }

    public function receivables(Request $request): Response
    {
        $svc = new FinancialReportService();
        $report = $svc->receivablesReport();

        if ($request->query('export') === 'csv') {
            $csv = $svc->toCsv($report['rows'], ['reference', 'customer_name', 'customer_phone', 'total', 'paid_amount', 'outstanding', 'payment_status', 'created_at']);
            return Response::text($csv, 200)->header('Content-Type', 'text/csv')->header('Content-Disposition', 'attachment; filename="receivables_report.csv"');
        }

        return Response::html(View::render('admin/reports/receivables', [
            'pageTitle' => 'Receivables Report',
            'report' => $report,
        ]));
    }

    public function payables(Request $request): Response
    {
        $svc = new FinancialReportService();
        $report = $svc->payablesReport();

        if ($request->query('export') === 'csv') {
            $csv = $svc->toCsv($report['rows'], ['name', 'contact_name', 'phone', 'outstanding_balance', 'pending_po_count']);
            return Response::text($csv, 200)->header('Content-Type', 'text/csv')->header('Content-Disposition', 'attachment; filename="payables_report.csv"');
        }

        return Response::html(View::render('admin/reports/payables', [
            'pageTitle' => 'Payables Report',
            'report' => $report,
        ]));
    }

    public function reconciliation(Request $request): Response
    {
        $from = (string) $request->query('from', gmdate('Y-m-01'));
        $to = (string) $request->query('to', gmdate('Y-m-d'));
        $svc = new FinancialReportService();
        $report = $svc->reconciliationReport($from, $to);

        if ($request->query('export') === 'csv') {
            $csv = $svc->toCsv($report['rows'], ['batch_date', 'status', 'expected_total', 'actual_total', 'discrepancy', 'payment_count', 'prepared_by_name']);
            return Response::text($csv, 200)->header('Content-Type', 'text/csv')->header('Content-Disposition', 'attachment; filename="reconciliation_report_' . $from . '_' . $to . '.csv"');
        }

        return Response::html(View::render('admin/reports/reconciliation', [
            'pageTitle' => 'Reconciliation Report',
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ]));
    }
}
