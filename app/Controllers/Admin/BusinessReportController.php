<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\BusinessReportService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Business reports: inventory valuation, stock aging, purchasing,
 * supplier, customer, staff, inspection, warranty, repair.
 * All routes require reports.view. CSV export via ?export=csv.
 */
final class BusinessReportController
{
    private function maybeExport(Request $request, BusinessReportService $svc, array $report, array $columns, string $filename): ?Response
    {
        if ($request->query('export') !== 'csv') {
            return null;
        }
        $csv = $svc->toCsv($report['rows'] ?? [], $columns);
        return Response::text($csv, 200)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '.csv"');
    }

    // ---------------------------------------------------- INVENTORY VALUATION --

    public function inventoryValuation(Request $request): Response
    {
        $svc = new BusinessReportService();
        $status = (string) $request->query('status', '');
        $report = $svc->inventoryValuation($status !== '' ? $status : null);

        if ($r = $this->maybeExport($request, $svc, $report, ['status', 'unit_count', 'total_purchase', 'total_landed', 'total_cost', 'total_retail', 'potential_profit'], 'inventory_valuation')) {
            return $r;
        }

        return Response::html(View::render('admin/reports/business/inventory_valuation', [
            'pageTitle' => 'Inventory Valuation',
            'report' => $report,
            'status' => $status,
        ]));
    }

    // ---------------------------------------------------- STOCK AGING --

    public function stockAging(Request $request): Response
    {
        $svc = new BusinessReportService();
        $report = $svc->stockAging();

        if ($r = $this->maybeExport($request, $svc, $report, ['age_bucket', 'unit_count', 'total_cost', 'total_retail'], 'stock_aging')) {
            return $r;
        }

        return Response::html(View::render('admin/reports/business/stock_aging', [
            'pageTitle' => 'Stock Aging',
            'report' => $report,
        ]));
    }

    // ---------------------------------------------------- PURCHASING --

    public function purchasing(Request $request): Response
    {
        $from = (string) $request->query('from', gmdate('Y-m-01'));
        $to = (string) $request->query('to', gmdate('Y-m-d'));
        $svc = new BusinessReportService();
        $report = $svc->purchasingReport($from, $to);

        if ($r = $this->maybeExport($request, $svc, $report, ['status', 'po_count', 'total_value'], 'purchasing_' . $from . '_' . $to)) {
            return $r;
        }

        return Response::html(View::render('admin/reports/business/purchasing', [
            'pageTitle' => 'Purchasing Report',
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ]));
    }

    // ---------------------------------------------------- SUPPLIER --

    public function supplier(Request $request): Response
    {
        $svc = new BusinessReportService();
        $report = $svc->supplierReport();

        if ($r = $this->maybeExport($request, $svc, $report, ['name', 'contact_name', 'phone', 'outstanding_balance', 'unit_count', 'po_count', 'is_active'], 'supplier')) {
            return $r;
        }

        return Response::html(View::render('admin/reports/business/supplier', [
            'pageTitle' => 'Supplier Report',
            'report' => $report,
        ]));
    }

    // ---------------------------------------------------- CUSTOMER --

    public function customer(Request $request): Response
    {
        $svc = new BusinessReportService();
        $report = $svc->customerReport();

        if ($r = $this->maybeExport($request, $svc, $report, ['name', 'phone', 'email', 'account_status', 'orders_count', 'lifetime_value', 'last_order_at'], 'customer')) {
            return $r;
        }

        return Response::html(View::render('admin/reports/business/customer', [
            'pageTitle' => 'Customer Report',
            'report' => $report,
        ]));
    }

    // ---------------------------------------------------- STAFF --

    public function staff(Request $request): Response
    {
        $svc = new BusinessReportService();
        $report = $svc->staffReport();

        if ($r = $this->maybeExport($request, $svc, $report, ['name', 'email', 'phone', 'is_active', 'roles', 'orders_placed', 'payments_recorded', 'service_jobs', 'last_login_at'], 'staff')) {
            return $r;
        }

        return Response::html(View::render('admin/reports/business/staff', [
            'pageTitle' => 'Staff Report',
            'report' => $report,
        ]));
    }

    // ---------------------------------------------------- INSPECTION --

    public function inspection(Request $request): Response
    {
        $from = (string) $request->query('from', gmdate('Y-m-01'));
        $to = (string) $request->query('to', gmdate('Y-m-d'));
        $svc = new BusinessReportService();
        $report = $svc->inspectionReport($from, $to);

        if ($r = $this->maybeExport($request, $svc, $report, ['outcome', 'technician_name', 'count'], 'inspection_' . $from . '_' . $to)) {
            return $r;
        }

        return Response::html(View::render('admin/reports/business/inspection', [
            'pageTitle' => 'Inspection Report',
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ]));
    }

    // ---------------------------------------------------- WARRANTY --

    public function warranty(Request $request): Response
    {
        $svc = new BusinessReportService();
        $report = $svc->warrantyReport();

        if ($r = $this->maybeExport($request, $svc, $report, ['status', 'claim_type', 'count'], 'warranty')) {
            return $r;
        }

        return Response::html(View::render('admin/reports/business/warranty', [
            'pageTitle' => 'Warranty Report',
            'report' => $report,
        ]));
    }

    // ---------------------------------------------------- REPAIR --

    public function repair(Request $request): Response
    {
        $from = (string) $request->query('from', gmdate('Y-m-01'));
        $to = (string) $request->query('to', gmdate('Y-m-d'));
        $svc = new BusinessReportService();
        $report = $svc->repairReport($from, $to);

        if ($r = $this->maybeExport($request, $svc, $report, ['status', 'service_type', 'count', 'total_charge'], 'repair_' . $from . '_' . $to)) {
            return $r;
        }

        return Response::html(View::render('admin/reports/business/repair', [
            'pageTitle' => 'Repair Report',
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ]));
    }
}
