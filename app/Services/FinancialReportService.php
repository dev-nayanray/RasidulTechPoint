<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database\Connection;
use PDO;

/**
 * Financial reporting: date-filtered reports across all financial modules.
 *
 * All reports:
 *  - Use consistent date boundaries: from 00:00:00 to 23:59:59 UTC.
 *  - Read-only (SELECT queries only, no writes).
 *  - Clearly label estimates or unavailable measures.
 *  - Available for CSV export (requires reports.export permission).
 *
 * Report types:
 *  1. Sales — completed orders in date range, grouped by day.
 *  2. Payments — completed payments in date range, grouped by method.
 *  3. Refunds — completed refunds in date range.
 *  4. Expenses — approved+paid expenses, grouped by category.
 *  5. Gross profit — delegates to ProfitService.cogsReport.
 *  6. Receivables — orders with unpaid/partial payment_status.
 *  7. Payables — suppliers with outstanding balance + approved POs.
 *  8. Reconciliation — reconciliation batches in date range.
 */
final class FinancialReportService
{
    private ProfitService $profit;

    public function __construct()
    {
        $this->profit = new ProfitService();
    }

    // ---------------------------------------------------- SALES --

    /** @return array{rows:list<array<string,mixed>>,total_revenue:float,total_discount:float,total_net:float,order_count:int} */
    public function salesReport(string $from, string $to): array
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare(
            "SELECT DATE(o.completed_at) AS sale_date,
                    COUNT(*) AS order_count,
                    SUM(o.subtotal) AS gross_revenue,
                    SUM(o.discount) AS total_discount,
                    SUM(o.total) AS net_revenue
             FROM orders o
             WHERE o.status = 'completed'
               AND o.completed_at >= ? AND o.completed_at <= ?
             GROUP BY DATE(o.completed_at)
             ORDER BY sale_date DESC"
        );
        $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalRevenue = 0.0; $totalDiscount = 0.0; $totalNet = 0.0; $orderCount = 0;
        foreach ($rows as $r) {
            $totalRevenue += (float) $r['gross_revenue'];
            $totalDiscount += (float) $r['total_discount'];
            $totalNet += (float) $r['net_revenue'];
            $orderCount += (int) $r['order_count'];
        }

        return [
            'rows' => $rows,
            'total_revenue' => $totalRevenue,
            'total_discount' => $totalDiscount,
            'total_net' => $totalNet,
            'order_count' => $orderCount,
        ];
    }

    // ---------------------------------------------------- PAYMENTS --

    /** @return array{rows:list<array<string,mixed>>,total_collected:float,count:int} */
    public function paymentReport(string $from, string $to): array
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare(
            "SELECT p.method,
                    COUNT(*) AS count,
                    SUM(p.amount) AS total
             FROM payments p
             WHERE p.status = 'completed'
               AND p.paid_at >= ? AND p.paid_at <= ?
             GROUP BY p.method
             ORDER BY total DESC"
        );
        $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0.0; $count = 0;
        foreach ($rows as $r) {
            $total += (float) $r['total'];
            $count += (int) $r['count'];
        }

        return ['rows' => $rows, 'total_collected' => $total, 'count' => $count];
    }

    // ---------------------------------------------------- REFUNDS --

    /** @return array{rows:list<array<string,mixed>>,total_refunded:float,count:int} */
    public function refundReport(string $from, string $to): array
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare(
            "SELECT r.refund_number, r.order_id, r.amount, r.reason,
                    r.inventory_action, r.status, r.processed_at,
                    o.reference AS order_reference,
                    c.name AS customer_name
             FROM refunds r
             JOIN orders o ON o.id = r.order_id
             JOIN customers c ON c.id = o.customer_id
             WHERE r.status = 'completed'
               AND r.processed_at >= ? AND r.processed_at <= ?
             ORDER BY r.id DESC"
        );
        $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0.0; $count = 0;
        foreach ($rows as $r) {
            $total += (float) $r['amount'];
            $count++;
        }

        return ['rows' => $rows, 'total_refunded' => $total, 'count' => $count];
    }

    // ---------------------------------------------------- EXPENSES --

    /** @return array{rows:list<array<string,mixed>>,total:float,count:int} */
    public function expenseReport(string $from, string $to): array
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare(
            "SELECT ec.name AS category,
                    COUNT(ee.id) AS count,
                    SUM(ee.amount) AS total
             FROM expense_entries ee
             JOIN expense_categories ec ON ec.id = ee.category_id
             WHERE ee.status IN ('approved', 'paid')
               AND ee.expense_date >= ? AND ee.expense_date <= ?
             GROUP BY ec.id, ec.name
             ORDER BY total DESC"
        );
        $stmt->execute([$from, $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0.0; $count = 0;
        foreach ($rows as $r) {
            $total += (float) $r['total'];
            $count += (int) $r['count'];
        }

        return ['rows' => $rows, 'total' => $total, 'count' => $count];
    }

    // ---------------------------------------------------- GROSS PROFIT --

    /** Delegates to ProfitService.cogsReport. */
    public function grossProfitReport(string $from, string $to): array
    {
        return $this->profit->cogsReport($from, $to);
    }

    // ---------------------------------------------------- RECEIVABLES --

    /**
     * Orders with unpaid or partial payment_status.
     * Outstanding = total - SUM(completed payments).
     * @return array{rows:list<array<string,mixed>>,total_outstanding:float,count:int}
     */
    public function receivablesReport(): array
    {
        $pdo = Connection::pdo();
        // Find orders with unpaid/partial payment_status that are
        // not cancelled (they still owe money).
        $stmt = $pdo->query(
            "SELECT o.id, o.reference, o.total, o.created_at,
                    o.payment_status,
                    c.name AS customer_name, c.phone AS customer_phone,
                    COALESCE((
                        SELECT SUM(p.amount) FROM payments p
                        WHERE p.order_id = o.id AND p.status = 'completed'
                    ), 0) AS paid_amount
             FROM orders o
             JOIN customers c ON c.id = o.customer_id
             WHERE o.payment_status IN ('unpaid', 'partial')
               AND o.status NOT IN ('cancelled')
             ORDER BY o.created_at DESC
             LIMIT 500"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalOutstanding = 0.0; $count = 0;
        foreach ($rows as &$r) {
            $outstanding = (float) $r['total'] - (float) $r['paid_amount'];
            $r['outstanding'] = number_format($outstanding, 2, '.', '');
            $totalOutstanding += $outstanding;
            $count++;
        }
        unset($r);

        return ['rows' => $rows, 'total_outstanding' => $totalOutstanding, 'count' => $count];
    }

    // ---------------------------------------------------- PAYABLES --

    /**
     * Suppliers with outstanding_balance > 0 + approved-but-unreceived POs.
     * @return array{rows:list<array<string,mixed>>,total_outstanding:float,count:int}
     */
    public function payablesReport(): array
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->query(
            "SELECT s.id, s.name, s.contact_name, s.phone,
                    s.outstanding_balance,
                    (SELECT COUNT(*) FROM purchase_orders po
                     WHERE po.supplier_id = s.id
                       AND po.status IN ('approved', 'submitted')) AS pending_po_count
             FROM suppliers s
             WHERE s.deleted_at IS NULL
               AND s.outstanding_balance > 0
             ORDER BY s.outstanding_balance DESC
             LIMIT 500"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0.0; $count = 0;
        foreach ($rows as $r) {
            $total += (float) $r['outstanding_balance'];
            $count++;
        }

        return ['rows' => $rows, 'total_outstanding' => $total, 'count' => $count];
    }

    // ---------------------------------------------------- RECONCILIATION --

    /**
     * Reconciliation batches in date range.
     * @return array{rows:list<array<string,mixed>>,total_expected:float,total_actual:float,total_discrepancy:float,count:int}
     */
    public function reconciliationReport(string $from, string $to): array
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare(
            "SELECT rb.id, rb.batch_date, rb.status, rb.expected_total,
                    rb.actual_total, rb.discrepancy, rb.payment_count,
                    u.name AS prepared_by_name,
                    c.name AS confirmed_by_name
             FROM reconciliation_batches rb
             LEFT JOIN users u ON u.id = rb.prepared_by
             LEFT JOIN users c ON c.id = rb.confirmed_by
             WHERE rb.batch_date >= ? AND rb.batch_date <= ?
             ORDER BY rb.batch_date DESC"
        );
        $stmt->execute([$from, $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $expected = 0.0; $actual = 0.0; $discrepancy = 0.0; $count = 0;
        foreach ($rows as $r) {
            $expected += (float) $r['expected_total'];
            $actual += (float) ($r['actual_total'] ?? 0);
            $discrepancy += (float) ($r['discrepancy'] ?? 0);
            $count++;
        }

        return [
            'rows' => $rows,
            'total_expected' => $expected,
            'total_actual' => $actual,
            'total_discrepancy' => $discrepancy,
            'count' => $count,
        ];
    }

    // ---------------------------------------------------- CSV EXPORT --

    /**
     * Export a report to CSV format.
     * @param list<array<string,mixed>> $rows
     * @param list<string> $columns Column names to include.
     * @return string CSV content.
     */
    public function toCsv(array $rows, array $columns): string
    {
        $csv = implode(',', array_map(fn ($c) => '"' . str_replace('"', '""', $c) . '"', $columns)) . "\n";
        foreach ($rows as $row) {
            $vals = [];
            foreach ($columns as $col) {
                $val = $row[$col] ?? '';
                $vals[] = '"' . str_replace('"', '""', (string) $val) . '"';
            }
            $csv .= implode(',', $vals) . "\n";
        }
        return $csv;
    }

    // ---------------------------------------------------- DASHBOARD --

    /**
     * Summary dashboard: current month figures across all report types.
     * Clearly labels estimates or unavailable measures.
     */
    public function dashboard(): array
    {
        $monthStart = gmdate('Y-m-01');
        $today = gmdate('Y-m-d');

        $sales = $this->salesReport($monthStart, $today);
        $payments = $this->paymentReport($monthStart, $today);
        $refunds = $this->refundReport($monthStart, $today);
        $expenses = $this->expenseReport($monthStart, $today);
        $receivables = $this->receivablesReport();
        $payables = $this->payablesReport();

        return [
            'month_start' => $monthStart,
            'today' => $today,
            'sales' => $sales,
            'payments' => $payments,
            'refunds' => $refunds,
            'expenses' => $expenses,
            'receivables' => $receivables,
            'payables' => $payables,
            'notes' => [
                'sales_note' => 'Revenue = subtotal - discount for completed orders only. Payment collections are NOT counted as revenue.',
                'receivables_note' => 'Outstanding = order total - completed payments. Excludes cancelled orders.',
                'payables_note' => 'Based on supplier.outstanding_balance (manual field). Approved purchase orders are listed but not yet automatically reflected in the balance.',
                'reconciliation_note' => 'See the reconciliation batches report for cash vs expected comparison.',
            ],
        ];
    }
}
