<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\DashboardService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Super Admin dashboard.
 *
 * All numbers are real MySQL aggregates — there are no hardcoded or fake
 * figures anywhere on the page. When the database is empty, the page
 * shows the empty-state panel instead of zeros dressed up as data.
 *
 * Query parameters (all optional, all GET):
 *   range         today | 7d | 30d | mtd | custom   (default: mtd)
 *   from          YYYY-MM-DD  (used only when range=custom)
 *   to            YYYY-MM-DD  (used only when range=custom)
 *   location_id   int         (branch scoping for inventory metrics; 0 = all)
 */
final class DashboardController
{
    public function index(Request $request): Response
    {
        $service = new DashboardService();

        [$from, $to, $rangeLabel] = $this->resolveRange($request);
        $locationId = $this->resolveLocation($request);

        $metrics = $service->metrics($from, $to, $locationId);
        $salesTrend = $service->salesTrend(7);
        $locations = $service->locations();

        return Response::html(View::render('admin/dashboard', [
            'pageTitle' => 'Dashboard',
            'metrics' => $metrics,
            'salesTrend' => $salesTrend,
            'recentOrders' => $service->recentOrders(),
            'recentMovements' => $service->recentMovements(8, $locationId),
            'recentAudit' => $service->recentAudit(),
            'lowStock' => $service->lowStockAlerts(),
            'limitations' => $service->limitations(),
            'range' => $request->query('range', 'mtd'),
            'rangeLabel' => $rangeLabel,
            'from' => $request->query('from', ''),
            'to' => $request->query('to', ''),
            'locationId' => $locationId,
            'locations' => $locations,
            'metricDefinitions' => $this->metricDefinitions(),
        ]));
    }

    /** @return array{0:?string,1:?string,2:string} */
    private function resolveRange(Request $request): array
    {
        $range = (string) $request->query('range', 'mtd');

        switch ($range) {
            case 'today':
                return [gmdate('Y-m-d 00:00:00'), gmdate('Y-m-d 23:59:59'), 'Today'];
            case '7d':
                return [gmdate('Y-m-d 00:00:00', strtotime('-6 days')), gmdate('Y-m-d 23:59:59'), 'Last 7 days'];
            case '30d':
                return [gmdate('Y-m-d 00:00:00', strtotime('-29 days')), gmdate('Y-m-d 23:59:59'), 'Last 30 days'];
            case 'custom':
                $fromRaw = (string) $request->query('from', '');
                $toRaw = (string) $request->query('to', '');
                $from = $this->isValidDate($fromRaw) ? $fromRaw . ' 00:00:00' : null;
                $to = $this->isValidDate($toRaw) ? $toRaw . ' 23:59:59' : null;
                $label = ($from !== null && $to !== null)
                    ? ($fromRaw === $toRaw ? $fromRaw : "{$fromRaw} → {$toRaw}")
                    : 'Custom (invalid)';
                return [$from, $to, $label];
            case 'mtd':
            default:
                return [gmdate('Y-m-01 00:00:00'), gmdate('Y-m-d 23:59:59'), 'Month to date'];
        }
    }

    private function resolveLocation(Request $request): ?int
    {
        $raw = $request->query('location_id');
        if ($raw === null || $raw === '' || $raw === '0') {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $parts = explode('-', $date);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    /** @return array<string,string> */
    private function metricDefinitions(): array
    {
        return [
            'sales_total' => 'Sum of orders.total where status=completed and completed_at falls inside the selected range. This is recognized revenue — it does not include pending, cancelled, or refunded orders.',
            'sales_today' => 'Same as sales_total but restricted to completed_at >= UTC midnight today (calendar today, not a rolling 24h window).',
            'sales_mtd' => 'Same as sales_total but restricted to completed_at >= the 1st of the current UTC month.',
            'payments_total' => 'Sum of payments.amount where status=completed and paid_at falls inside the selected range. This is cash collected, NOT revenue — an order can be completed before payment lands, or paid before completion (POS).',
            'payments_today' => 'Same as payments_total but restricted to paid_at >= UTC midnight today.',
            'payments_mtd' => 'Same as payments_total but restricted to paid_at >= the 1st of the current UTC month.',
            'refunded_orders_total' => 'Sum of orders.total where status=refunded and the status change (updated_at) falls inside the selected range. This is the gross order value returned to the customer.',
            'refunded_payments_total' => 'Sum of payments.amount where status=refunded and updated_at falls inside the selected range. This is cash actually reversed (gateway refund). It is separate from refunded_orders_total because an order can be marked refunded without a payment reversal, and vice versa.',
            'outstanding' => 'Snapshot (not date-scoped): SUM(orders.total - completed payments) over non-cancelled, non-refunded orders where the balance > 0. The paid subquery groups by order_id so there is no double-counting.',
            'available_units' => 'Snapshot: COUNT(inventory_units) where status=available. Optionally scoped by location_id.',
            'awaiting_inspection' => 'Snapshot: COUNT(inventory_units) where status=awaiting_inspection. Optionally scoped by location_id.',
            'reserved_units' => 'Snapshot: COUNT(inventory_units) where status=reserved. Optionally scoped by location_id.',
            'in_transit_units' => 'Snapshot: COUNT(inventory_units) where status=in_transit. Optionally scoped by location_id.',
            'pending_orders' => 'Snapshot: COUNT(orders) where status IN (pending, confirmed, processing). NOT location-scoped (orders table has no location_id).',
            'low_stock' => 'Published product_models whose available-unit count <= settings.low_stock_alert_threshold (default 3).',
            'sales_trend' => 'Last 7 UTC days, each day\'s SUM(orders.total) where status=completed. Zero-filled so the chart never has gaps. Hidden entirely when there are no orders at all.',
        ];
    }
}
