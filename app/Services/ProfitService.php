<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database\Connection;
use PDO;

/**
 * COGS and profit calculation.
 *
 * Design principles:
 *  - COGS per unit = inventory_units.purchase_cost + inventory_units.landed_cost
 *    (the actual cost of acquiring the unit, including allocated shipping/
 *    customs/import expenses from the landed-cost allocation module).
 *  - Sales revenue = SUM(orders.subtotal - orders.discount) for completed
 *    orders in the date range. This is what the customer owes — NOT
 *    payments.amount (which is what they actually paid). Payment
 *    collections are cash inflows, not revenue. A customer can owe
 *    1000 (revenue) but only pay 500 (partial payment) — the revenue
 *    is 1000, not 500.
 *  - Cancelled/refunded orders are excluded from revenue AND COGS.
 *    A cancelled order never generated revenue. A refunded order's
 *    revenue was reversed — the customer got their money back. The
 *    unit may be back in stock (return_to_stock) or scrapped (damaged).
 *    For simplicity, refunded orders are excluded from both revenue
 *    and COGS. If a unit was scrapped after a return, its cost is
 *    captured as an inventory write-off (a separate expense category),
 *    not as COGS.
 *  - Operating expenses = SUM(expense_entries.amount) WHERE status IN
 *    ('approved','paid') for the date range. Separate from COGS.
 *  - Gross profit = Net revenue - COGS
 *  - Net profit = Gross profit - Operating expenses
 *
 * Financial integrity:
 *  - All calculations are read-only (SELECT queries). No writes.
 *  - The service never touches orders, payments, expenses, or
 *    inventory tables — it only reads from them.
 *  - Results are returned as structured arrays, not stored. Each
 *    report is computed fresh on demand.
 */
final class ProfitService
{
    /**
     * Calculate COGS for completed orders in a date range.
     *
     * For each order_item in a completed order, look up the
     * inventory_unit's (purchase_cost + landed_cost). Sum all
     * unit costs = order COGS. Sum across all orders = total COGS.
     *
     * @return array{
     *   total_revenue: float,
     *   total_discount: float,
     *   net_revenue: float,
     *   total_cogs: float,
     *   gross_profit: float,
     *   gross_margin: float,
     *   order_count: int,
     *   unit_count: int,
     *   orders: list<array<string,mixed>>
     * }
     */
    public function cogsReport(string $from, string $to): array
    {
        $pdo = Connection::pdo();

        // Fetch completed orders with their line items and unit costs.
        $stmt = $pdo->prepare(
            "SELECT o.id, o.reference, o.subtotal, o.discount, o.total,
                    o.completed_at, o.customer_id,
                    c.name AS customer_name
             FROM orders o
             JOIN customers c ON c.id = o.customer_id
             WHERE o.status = 'completed'
               AND o.completed_at >= ? AND o.completed_at <= ?
             ORDER BY o.completed_at DESC"
        );
        $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalRevenue = 0.0;
        $totalDiscount = 0.0;
        $totalCogs = 0.0;
        $unitCount = 0;

        foreach ($orders as &$order) {
            // Fetch line items with inventory unit costs.
            $stmt = $pdo->prepare(
                'SELECT oi.id, oi.product_name, oi.sku, oi.unit_price, oi.line_total,
                        iu.purchase_cost, iu.landed_cost,
                        (iu.purchase_cost + iu.landed_cost) AS unit_cogs
                 FROM order_items oi
                 JOIN inventory_units iu ON iu.id = oi.inventory_unit_id
                 WHERE oi.order_id = ?'
            );
            $stmt->execute([$order['id']]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $orderCogs = 0.0;
            foreach ($items as $item) {
                $orderCogs += (float) $item['unit_cogs'];
            }

            $netRevenue = (float) $order['subtotal'] - (float) $order['discount'];
            $order['items'] = $items;
            $order['cogs'] = $orderCogs;
            $order['net_revenue'] = $netRevenue;
            $order['gross_profit'] = $netRevenue - $orderCogs;
            $order['gross_margin'] = $netRevenue > 0 ? round(($order['gross_profit'] / $netRevenue) * 100, 2) : 0.0;

            $totalRevenue += (float) $order['subtotal'];
            $totalDiscount += (float) $order['discount'];
            $totalCogs += $orderCogs;
            $unitCount += count($items);
        }
        unset($order);

        $netRevenue = $totalRevenue - $totalDiscount;
        $grossProfit = $netRevenue - $totalCogs;
        $grossMargin = $netRevenue > 0 ? round(($grossProfit / $netRevenue) * 100, 2) : 0.0;

        return [
            'total_revenue' => $totalRevenue,
            'total_discount' => $totalDiscount,
            'net_revenue' => $netRevenue,
            'total_cogs' => $totalCogs,
            'gross_profit' => $grossProfit,
            'gross_margin' => $grossMargin,
            'order_count' => count($orders),
            'unit_count' => $unitCount,
            'orders' => $orders,
        ];
    }

    /**
     * Full profit & loss report: revenue - COGS - operating expenses.
     *
     * @return array{
     *   from: string,
     *   to: string,
     *   total_revenue: float,
     *   total_discount: float,
     *   net_revenue: float,
     *   total_cogs: float,
     *   gross_profit: float,
     *   gross_margin: float,
     *   operating_expenses: float,
     *   net_profit: float,
     *   net_margin: float,
     *   expenses_by_category: list<array<string,mixed>>,
     *   order_count: int,
     * }
     */
    public function profitAndLoss(string $from, string $to): array
    {
        $cogs = $this->cogsReport($from, $to);

        // Operating expenses (approved + paid, NOT pending/rejected).
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM expense_entries
             WHERE status IN ('approved', 'paid')
               AND expense_date >= ? AND expense_date <= ?"
        );
        $stmt->execute([$from, $to]);
        $operatingExpenses = (float) $stmt->fetchColumn();

        // Expenses by category.
        $stmt = $pdo->prepare(
            "SELECT ec.name, COALESCE(SUM(ee.amount), 0) AS total, COUNT(ee.id) AS count
             FROM expense_entries ee
             JOIN expense_categories ec ON ec.id = ee.category_id
             WHERE ee.status IN ('approved', 'paid')
               AND ee.expense_date >= ? AND ee.expense_date <= ?
             GROUP BY ec.id, ec.name
             ORDER BY total DESC"
        );
        $stmt->execute([$from, $to]);
        $expensesByCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $netProfit = $cogs['gross_profit'] - $operatingExpenses;
        $netMargin = $cogs['net_revenue'] > 0 ? round(($netProfit / $cogs['net_revenue']) * 100, 2) : 0.0;

        return [
            'from' => $from,
            'to' => $to,
            'total_revenue' => $cogs['total_revenue'],
            'total_discount' => $cogs['total_discount'],
            'net_revenue' => $cogs['net_revenue'],
            'total_cogs' => $cogs['total_cogs'],
            'gross_profit' => $cogs['gross_profit'],
            'gross_margin' => $cogs['gross_margin'],
            'operating_expenses' => $operatingExpenses,
            'net_profit' => $netProfit,
            'net_margin' => $netMargin,
            'expenses_by_category' => $expensesByCategory,
            'order_count' => $cogs['order_count'],
        ];
    }

    /**
     * Summary dashboard: current month P&L + key metrics.
     */
    public function dashboard(): array
    {
        $monthStart = gmdate('Y-m-01');
        $today = gmdate('Y-m-d');
        return $this->profitAndLoss($monthStart, $today);
    }

    /**
     * Per-unit COGS breakdown for a specific order.
     * @return list<array<string,mixed>>
     */
    public function orderCogsBreakdown(int $orderId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT oi.id, oi.product_name, oi.sku, oi.unit_price, oi.line_total,
                    iu.purchase_cost, iu.landed_cost,
                    (iu.purchase_cost + iu.landed_cost) AS unit_cogs,
                    (oi.unit_price - (iu.purchase_cost + iu.landed_cost)) AS unit_profit,
                    CASE
                        WHEN oi.unit_price > 0 THEN ROUND(((oi.unit_price - (iu.purchase_cost + iu.landed_cost)) / oi.unit_price) * 100, 2)
                        ELSE 0
                    END AS unit_margin
             FROM order_items oi
             JOIN inventory_units iu ON iu.id = oi.inventory_unit_id
             WHERE oi.order_id = ?
             ORDER BY oi.id'
        );
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
