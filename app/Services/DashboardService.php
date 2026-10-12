<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database\Connection;
use PDO;

/**
 * Admin dashboard metrics — every number is a real aggregate query.
 *
 * ------------------------------------------------------------------
 * METRIC DEFINITIONS (all timestamps stored in UTC; presentation
 * converts through APP_TIMEZONE via local_datetime())
 * ------------------------------------------------------------------
 *
 * Revenue (order-completion based, NEVER overlaps with payments):
 *   sales_total        SUM(orders.total) WHERE status='completed'
 *                      AND completed_at IN [from, to]
 *   sales_today        same, restricted to completed_at >= UTC_DATE()
 *                      (calendar today, NOT a rolling 24h window)
 *   sales_mtd          same, restricted to completed_at >= first day of
 *                      the current UTC month
 *
 * Payments (cash-flow based, separate from revenue):
 *   payments_total     SUM(payments.amount) WHERE status='completed'
 *                      AND paid_at IN [from, to]
 *   payments_today     same, paid_at >= UTC_DATE()
 *   payments_mtd       same, paid_at >= first day of current UTC month
 *
 * Refunds (separated from BOTH revenue and collected payments):
 *   refunded_orders_total   SUM(orders.total) WHERE status='refunded'
 *                           AND updated_at IN [from, to]
 *   refunded_payments_total SUM(payments.amount) WHERE status='refunded'
 *                           AND updated_at IN [from, to]
 *   A refunded order's total is the gross amount returned; a refunded
 *   payment is the cash actually reversed. They measure different things
 *   and are reported as separate sub-totals.
 *
 * Outstanding balances (snapshot, not date-scoped):
 *   outstanding        SUM(orders.total - COALESCE(paid, 0)) over
 *                      non-cancelled/non-refunded orders where balance > 0.
 *                      Paid subquery groups by order_id — no double-counting.
 *
 * Inventory (snapshot, optionally location-scoped):
 *   available_units    COUNT(inventory_units) WHERE status='available'
 *   awaiting_inspection COUNT(inventory_units) WHERE status='awaiting_inspection'
 *   reserved_units     COUNT(inventory_units) WHERE status='reserved'
 *   in_transit_units   COUNT(inventory_units) WHERE status='in_transit'
 *
 * Orders (snapshot):
 *   pending_orders     COUNT(orders) WHERE status IN (pending, confirmed, processing)
 *
 * Sales trend (for the 7-day bar chart, only emitted when real data exists):
 *   sales_trend        [{date, total, count}, ...] for the last 7 UTC days.
 *                      Zero-filled so the chart never has gaps.
 *
 * ------------------------------------------------------------------
 * METRICS THAT CANNOT YET BE CALCULATED (unfinished modules)
 * ------------------------------------------------------------------
 * See limitations() — the UI shows an honest "not yet available" badge.
 */
final class DashboardService
{
    /** @return array<string,mixed> */
    public function metrics(?string $from = null, ?string $to = null, ?int $locationId = null): array
    {
        $pdo = Connection::pdo();

        [$salesTotal, $salesCount] = $this->completedSalesInRange($from, $to);

        $todayStart = gmdate('Y-m-d 00:00:00');
        $mtdStart = gmdate('Y-m-01 00:00:00');

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total), 0) FROM orders
             WHERE status = 'completed' AND completed_at >= ?"
        );
        $stmt->execute([$todayStart]);
        $salesToday = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total), 0) FROM orders
             WHERE status = 'completed' AND completed_at >= ?"
        );
        $stmt->execute([$mtdStart]);
        $salesMtd = (float) $stmt->fetchColumn();

        $paymentsTotal = $this->completedPaymentsInRange($from, $to);

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE status = 'completed' AND paid_at >= ?"
        );
        $stmt->execute([$todayStart]);
        $paymentsToday = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE status = 'completed' AND paid_at >= ?"
        );
        $stmt->execute([$mtdStart]);
        $paymentsMtd = (float) $stmt->fetchColumn();

        $refundedOrdersTotal = $this->refundedOrdersInRange($from, $to);
        $refundedPaymentsTotal = $this->refundedPaymentsInRange($from, $to);

        $outstanding = (float) $pdo->query(
            "SELECT COALESCE(SUM(o.total - COALESCE(p.paid, 0)), 0)
             FROM orders o
             LEFT JOIN (
                 SELECT order_id, SUM(amount) AS paid
                 FROM payments WHERE status = 'completed' GROUP BY order_id
             ) p ON p.order_id = o.id
             WHERE o.status NOT IN ('cancelled', 'refunded')
               AND (o.total - COALESCE(p.paid, 0)) > 0"
        )->fetchColumn();

        $locationClause = $locationId !== null ? ' AND location_id = ' . (int) $locationId : '';

        $availableUnits = (int) $pdo->query(
            "SELECT COUNT(*) FROM inventory_units WHERE status = 'available'{$locationClause}"
        )->fetchColumn();

        $awaitingInspection = (int) $pdo->query(
            "SELECT COUNT(*) FROM inventory_units WHERE status = 'awaiting_inspection'{$locationClause}"
        )->fetchColumn();

        $reservedUnits = (int) $pdo->query(
            "SELECT COUNT(*) FROM inventory_units WHERE status = 'reserved'{$locationClause}"
        )->fetchColumn();

        $inTransitUnits = (int) $pdo->query(
            "SELECT COUNT(*) FROM inventory_units WHERE status = 'in_transit'{$locationClause}"
        )->fetchColumn();

        $pendingOrders = (int) $pdo->query(
            "SELECT COUNT(*) FROM orders WHERE status IN ('pending','confirmed','processing')"
        )->fetchColumn();

        return [
            'sales_total' => $salesTotal,
            'sales_count' => $salesCount,
            'sales_today' => $salesToday,
            'sales_mtd' => $salesMtd,
            'payments_total' => $paymentsTotal,
            'payments_today' => $paymentsToday,
            'payments_mtd' => $paymentsMtd,
            'refunded_orders_total' => $refundedOrdersTotal,
            'refunded_payments_total' => $refundedPaymentsTotal,
            'outstanding' => $outstanding,
            'available_units' => $availableUnits,
            'awaiting_inspection' => $awaitingInspection,
            'reserved_units' => $reservedUnits,
            'in_transit_units' => $inTransitUnits,
            'pending_orders' => $pendingOrders,
            'has_sales_data' => $this->hasAnyOrders(),
            'has_inventory_data' => $this->hasAnyInventoryUnits(),
            'location_id' => $locationId,
            'date_from' => $from,
            'date_to' => $to,
        ];
    }

    /** @return array{0:float,1:int} */
    private function completedSalesInRange(?string $from, ?string $to): array
    {
        $pdo = Connection::pdo();
        $sql = "SELECT COALESCE(SUM(total), 0), COUNT(*) FROM orders WHERE status = 'completed'";
        $params = [];
        if ($from !== null) {
            $sql .= ' AND completed_at >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND completed_at <= ?';
            $params[] = $to;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return [(float) $row[0], (int) $row[1]];
    }

    private function completedPaymentsInRange(?string $from, ?string $to): float
    {
        $pdo = Connection::pdo();
        $sql = "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed'";
        $params = [];
        if ($from !== null) {
            $sql .= ' AND paid_at >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND paid_at <= ?';
            $params[] = $to;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }

    private function refundedOrdersInRange(?string $from, ?string $to): float
    {
        $pdo = Connection::pdo();
        $sql = "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status = 'refunded'";
        $params = [];
        if ($from !== null) {
            $sql .= ' AND updated_at >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND updated_at <= ?';
            $params[] = $to;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }

    private function refundedPaymentsInRange(?string $from, ?string $to): float
    {
        $pdo = Connection::pdo();
        $sql = "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'refunded'";
        $params = [];
        if ($from !== null) {
            $sql .= ' AND updated_at >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND updated_at <= ?';
            $params[] = $to;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }

    private function hasAnyOrders(): bool
    {
        return (int) Connection::pdo()->query('SELECT COUNT(*) FROM orders')->fetchColumn() > 0;
    }

    private function hasAnyInventoryUnits(): bool
    {
        return (int) Connection::pdo()->query('SELECT COUNT(*) FROM inventory_units')->fetchColumn() > 0;
    }

    /** @return list<array<string,mixed>> */
    public function recentOrders(int $limit = 8): array
    {
        return Connection::pdo()->query(
            'SELECT o.id, o.reference, o.status, o.payment_status, o.total, o.created_at,
                    c.name AS customer_name
             FROM orders o
             JOIN customers c ON c.id = o.customer_id
             ORDER BY o.id DESC LIMIT ' . (int) $limit
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{total:int,new:int} real counts from contact_inquiries */
    public function contactInquiryStats(): array
    {
        $row = Connection::pdo()->query(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'new'), 0) AS new FROM contact_inquiries"
        )->fetch(PDO::FETCH_ASSOC);
        return ['total' => (int) $row['total'], 'new' => (int) $row['new']];
    }

    /** @return list<array<string,mixed>> */
    public function recentMovements(int $limit = 8, ?int $locationId = null): array
    {
        $sql = 'SELECT sm.id, sm.movement_type, sm.from_status, sm.to_status, sm.reason, sm.created_at,
                    iu.sku, u.name AS user_name
             FROM stock_movements sm
             JOIN inventory_units iu ON iu.id = sm.inventory_unit_id
             LEFT JOIN users u ON u.id = sm.performed_by';
        $params = [];
        if ($locationId !== null) {
            $sql .= ' WHERE iu.location_id = ?';
            $params[] = $locationId;
        }
        $sql .= ' ORDER BY sm.id DESC LIMIT ' . (int) $limit;
        $stmt = Connection::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function recentAudit(int $limit = 8): array
    {
        return AuditService::recent($limit);
    }

    /** @return list<array<string,mixed>> */
    public function lowStockAlerts(): array
    {
        $threshold = max(0, (int) SettingService::get('low_stock_alert_threshold', 3));

        return Connection::pdo()->query(
            "SELECT pm.name, pm.slug,
                    SUM(CASE WHEN iu.status = 'available' THEN 1 ELSE 0 END) AS available_count
             FROM product_models pm
             LEFT JOIN inventory_units iu ON iu.product_model_id = pm.id
             WHERE pm.is_published = 1 AND pm.deleted_at IS NULL
             GROUP BY pm.id, pm.name, pm.slug
             HAVING available_count <= " . $threshold . '
             ORDER BY available_count ASC, pm.name ASC
             LIMIT 10'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 7-day sales trend for the bar chart. Each row is one UTC day.
     * Zero-filled so the chart never has gaps. Returns [] when there is
     * no order data at all (the view hides the chart in that case).
     *
     * @return list<array{date:string,total:float,count:int}>
     */
    public function salesTrend(int $days = 7): array
    {
        if (!$this->hasAnyOrders()) {
            return [];
        }

        $pdo = Connection::pdo();
        $from = gmdate('Y-m-d 00:00:00', strtotime("-{$days} days"));
        $stmt = $pdo->prepare(
            "SELECT DATE(completed_at) AS d,
                    COALESCE(SUM(total), 0) AS total,
                    COUNT(*) AS cnt
             FROM orders
             WHERE status = 'completed' AND completed_at >= ?
             GROUP BY DATE(completed_at)
             ORDER BY d ASC"
        );
        $stmt->execute([$from]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[$row['d']] = [(float) $row['total'], (int) $row['cnt']];
        }

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime("-{$i} days"));
            [$total, $count] = $map[$day] ?? [0.0, 0];
            $trend[] = [
                'date' => $day,
                'total' => $total,
                'count' => $count,
            ];
        }
        return $trend;
    }

    /** @return list<array{id:int,name:string}> */
    public function locations(): array
    {
        return Connection::pdo()->query(
            'SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Static documentation of metrics that cannot yet be calculated
     * because their backing business module has not been built.
     *
     * @return array<string,string>
     */
    public function limitations(): array
    {
        return [
            'expenses_total' => 'Expenses module not built (finance.view permission exists, table does not).',
            'net_profit' => 'Requires expenses + COGS aggregation; finance module pending.',
            'warranty_claims_open' => 'Warranty & service module not built (warranty.manage permission exists, table does not).',
            'delivery_pending' => 'Delivery/courier module not built (delivery.manage permission exists, table does not).',
            'crm_pipeline_value' => 'CRM module not built (crm.view permission exists, table does not).',
            'marketing_campaigns_active' => 'Marketing module not built (marketing.manage permission exists, table does not).',
            'pos_session_open' => 'POS terminal UI not built; order service already supports channel=pos.',
            'supplier_purchases_mtd' => 'Supplier/purchase module not built (purchases.view permission exists, table does not).',
            'order_location_breakdown' => 'orders table has no location_id column; branch scoping applies to inventory only.',
        ];
    }
}
