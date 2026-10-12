<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database\Connection;
use PDO;

/**
 * Business reports: inventory valuation, stock aging, purchasing,
 * supplier, customer, staff, inspection, warranty, and repair reports.
 *
 * CSV export:
 *  - Formula injection prevention: cell values starting with =, +, -,
 *    @, tab, or CR are prefixed with a single quote to prevent
 *    spreadsheet apps from interpreting them as formulas.
 *
 * All reports:
 *  - Read-only (SELECT queries only, no writes).
 *  - Paginated where appropriate.
 *  - Filtered by date range or status where applicable.
 *  - Available for CSV export (reports.export permission).
 */
final class BusinessReportService
{
    // ---------------------------------------------------- CSV SAFETY --

    /**
     * Sanitize a cell value to prevent spreadsheet formula injection.
     * If the value starts with =, +, -, @, tab, or CR, prefix with
     * a single quote to neutralise it.
     */
    public function sanitizeCsvCell(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $first = $value[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Export rows to CSV with formula injection prevention.
     * @param list<array<string,mixed>> $rows
     * @param list<string> $columns
     */
    public function toCsv(array $rows, array $columns): string
    {
        $header = implode(',', array_map(fn ($c) => '"' . str_replace('"', '""', $c) . '"', $columns)) . "\n";
        $body = '';
        foreach ($rows as $row) {
            $vals = [];
            foreach ($columns as $col) {
                $val = (string) ($row[$col] ?? '');
                $val = $this->sanitizeCsvCell($val);
                $vals[] = '"' . str_replace('"', '""', $val) . '"';
            }
            $body .= implode(',', $vals) . "\n";
        }
        return $header . $body;
    }

    // ---------------------------------------------------- INVENTORY VALUATION --

    /** @return array{rows:list<array<string,mixed>>,total_cost:float,total_retail:float,count:int} */
    public function inventoryValuation(?string $status = null): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($status !== '' && $status !== null) {
            $where .= ' AND iu.status = ?';
            $params[] = $status;
        }
        $stmt = Connection::pdo()->prepare(
            "SELECT iu.status, COUNT(*) AS unit_count,
                    COALESCE(SUM(iu.purchase_cost), 0) AS total_purchase,
                    COALESCE(SUM(iu.landed_cost), 0) AS total_landed,
                    COALESCE(SUM(iu.purchase_cost + iu.landed_cost), 0) AS total_cost,
                    COALESCE(SUM(iu.selling_price), 0) AS total_retail,
                    COALESCE(SUM(iu.selling_price - (iu.purchase_cost + iu.landed_cost)), 0) AS potential_profit
             FROM inventory_units iu
             {$where}
             GROUP BY iu.status
             ORDER BY iu.status"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalCost = 0.0; $totalRetail = 0.0; $count = 0;
        foreach ($rows as $r) {
            $totalCost += (float) $r['total_cost'];
            $totalRetail += (float) $r['total_retail'];
            $count += (int) $r['unit_count'];
        }
        return ['rows' => $rows, 'total_cost' => $totalCost, 'total_retail' => $totalRetail, 'count' => $count];
    }

    // ---------------------------------------------------- STOCK AGING --

    /** @return array{rows:list<array<string,mixed>>,total_cost:float,count:int} */
    public function stockAging(): array
    {
        $stmt = Connection::pdo()->query(
            "SELECT
                CASE
                    WHEN iu.received_at IS NULL THEN 'unknown'
                    WHEN DATEDIFF(UTC_TIMESTAMP(), iu.received_at) <= 30 THEN '0-30 days'
                    WHEN DATEDIFF(UTC_TIMESTAMP(), iu.received_at) <= 90 THEN '31-90 days'
                    WHEN DATEDIFF(UTC_TIMESTAMP(), iu.received_at) <= 180 THEN '91-180 days'
                    ELSE '180+ days'
                END AS age_bucket,
                COUNT(*) AS unit_count,
                COALESCE(SUM(iu.purchase_cost + iu.landed_cost), 0) AS total_cost,
                COALESCE(SUM(iu.selling_price), 0) AS total_retail
             FROM inventory_units iu
             WHERE iu.status IN ('available', 'reserved', 'awaiting_inspection')
             GROUP BY age_bucket
             ORDER BY FIELD(age_bucket, '0-30 days', '31-90 days', '91-180 days', '180+ days', 'unknown')"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalCost = 0.0; $count = 0;
        foreach ($rows as $r) {
            $totalCost += (float) $r['total_cost'];
            $count += (int) $r['unit_count'];
        }
        return ['rows' => $rows, 'total_cost' => $totalCost, 'count' => $count];
    }

    // ---------------------------------------------------- PURCHASING --

    /** @return array{rows:list<array<string,mixed>>,total:float,count:int} */
    public function purchasingReport(string $from, string $to): array
    {
        $stmt = Connection::pdo()->prepare(
            "SELECT po.status, COUNT(*) AS po_count,
                    COALESCE(SUM(po.total), 0) AS total_value
             FROM purchase_orders po
             WHERE po.created_at >= ? AND po.created_at <= ?
             GROUP BY po.status
             ORDER BY po.status"
        );
        $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = 0.0; $count = 0;
        foreach ($rows as $r) {
            $total += (float) $r['total_value'];
            $count += (int) $r['po_count'];
        }
        return ['rows' => $rows, 'total' => $total, 'count' => $count];
    }

    // ---------------------------------------------------- SUPPLIER --

    /** @return array{rows:list<array<string,mixed>>,total_outstanding:float,count:int} */
    public function supplierReport(): array
    {
        $stmt = Connection::pdo()->query(
            "SELECT s.id, s.name, s.contact_name, s.phone, s.outstanding_balance,
                    s.is_active,
                    (SELECT COUNT(*) FROM inventory_units iu WHERE iu.supplier_ref = s.name) AS unit_count,
                    (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id) AS po_count
             FROM suppliers s
             WHERE s.deleted_at IS NULL
             ORDER BY s.name"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = 0.0; $count = 0;
        foreach ($rows as $r) {
            $total += (float) $r['outstanding_balance'];
            $count++;
        }
        return ['rows' => $rows, 'total_outstanding' => $total, 'count' => $count];
    }

    // ---------------------------------------------------- CUSTOMER --

    /** @return array{rows:list<array<string,mixed>>,total_ltv:float,count:int} */
    public function customerReport(): array
    {
        $stmt = Connection::pdo()->query(
            "SELECT c.id, c.name, c.phone, c.email, c.account_status,
                    c.orders_count, c.lifetime_value, c.last_order_at, c.created_at
             FROM customers c
             WHERE c.deleted_at IS NULL
             ORDER BY c.lifetime_value DESC, c.orders_count DESC
             LIMIT 500"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = 0.0; $count = 0;
        foreach ($rows as $r) {
            $total += (float) $r['lifetime_value'];
            $count++;
        }
        return ['rows' => $rows, 'total_ltv' => $total, 'count' => $count];
    }

    // ---------------------------------------------------- STAFF --

    /** @return array{rows:list<array<string,mixed>>} */
    public function staffReport(): array
    {
        $stmt = Connection::pdo()->query(
            "SELECT u.id, u.name, u.email, u.phone, u.is_active,
                    u.last_login_at,
                    GROUP_CONCAT(r.name SEPARATOR ', ') AS roles,
                    (SELECT COUNT(*) FROM orders o WHERE o.placed_by = u.id) AS orders_placed,
                    (SELECT COUNT(*) FROM payments p WHERE p.received_by = u.id) AS payments_recorded,
                    (SELECT COUNT(*) FROM service_jobs sj WHERE sj.assigned_to = u.id) AS service_jobs
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.deleted_at IS NULL
             GROUP BY u.id, u.name, u.email, u.phone, u.is_active, u.last_login_at
             ORDER BY u.name"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['rows' => $rows];
    }

    // ---------------------------------------------------- INSPECTION --

    /** @return array{rows:list<array<string,mixed>>,total:float,count:int} */
    public function inspectionReport(string $from, string $to): array
    {
        $stmt = Connection::pdo()->prepare(
            "SELECT ir.outcome, COUNT(*) AS count,
                    u.name AS technician_name
             FROM inspection_records ir
             LEFT JOIN users u ON u.id = ir.technician_id
             WHERE ir.created_at >= ? AND ir.created_at <= ?
             GROUP BY ir.outcome, u.name
             ORDER BY ir.outcome"
        );
        $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($rows as $r) {
            $count += (int) $r['count'];
        }
        return ['rows' => $rows, 'count' => $count];
    }

    // ---------------------------------------------------- WARRANTY --

    /** @return array{rows:list<array<string,mixed>>,count:int} */
    public function warrantyReport(): array
    {
        $stmt = Connection::pdo()->query(
            "SELECT wc.status, COUNT(*) AS count, wc.claim_type
             FROM warranty_claims wc
             GROUP BY wc.status, wc.claim_type
             ORDER BY wc.status"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($rows as $r) {
            $count += (int) $r['count'];
        }
        return ['rows' => $rows, 'count' => $count];
    }

    // ---------------------------------------------------- REPAIR --

    /** @return array{rows:list<array<string,mixed>>,total_charge:float,count:int} */
    public function repairReport(string $from, string $to): array
    {
        $stmt = Connection::pdo()->prepare(
            "SELECT sj.status, sj.service_type, COUNT(*) AS count,
                    COALESCE(SUM(sj.total_charge), 0) AS total_charge
             FROM service_jobs sj
             WHERE sj.created_at >= ? AND sj.created_at <= ?
             GROUP BY sj.status, sj.service_type
             ORDER BY sj.status, sj.service_type"
        );
        $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = 0.0; $count = 0;
        foreach ($rows as $r) {
            $total += (float) $r['total_charge'];
            $count += (int) $r['count'];
        }
        return ['rows' => $rows, 'total_charge' => $total, 'count' => $count];
    }
}
