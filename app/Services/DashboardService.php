<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database\Connection;
use PDO;

/**
 * Admin dashboard metrics — every number is a real aggregate query.
 *
 * Metric definitions (all timestamps stored in UTC):
 *  - today_sales        SUM(total) of orders with status='completed' AND
 *                       DATE(completed_at) = UTC_DATE()
 *  - mtd_sales          same but completed_at >= first day of current UTC month
 *  - payments_today     SUM(amount) of payments with status='completed' AND DATE(paid_at)=UTC_DATE()
 *  - payments_mtd       same, month-to-date
 *  - outstanding        SUM(total) - SUM(completed payments) over non-cancelled orders (>= 0 rows only)
 *  - available_units    COUNT inventory_units status='available'
 *  - awaiting_inspection COUNT inventory_units status='awaiting_inspection'
 *  - pending_orders     COUNT orders status IN ('pending','confirmed','processing')
 *  - low_stock          published models with fewer available units than the configured threshold
 */
final class DashboardService
{
    /** @return array<string,mixed> */
    public function metrics(): array
    {
        $pdo = Connection::pdo();

        $todaySales = (float) $pdo->query(
            "SELECT COALESCE(SUM(total), 0) FROM orders
             WHERE status = 'completed' AND completed_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR"
        )->fetchColumn();

        $mtdStart = gmdate('Y-m-01 00:00:00');
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total), 0) FROM orders
             WHERE status = 'completed' AND completed_at >= ?"
        );
        $stmt->execute([$mtdStart]);
        $mtdSales = (float) $stmt->fetchColumn();

        $paymentsToday = (float) $pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE status = 'completed' AND paid_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR"
        )->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE status = 'completed' AND paid_at >= ?"
        );
        $stmt->execute([$mtdStart]);
        $paymentsMtd = (float) $stmt->fetchColumn();

        // Outstanding customer balances: orders that are not cancelled/refunded
        // where completed payments do not cover the total.
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

        $availableUnits = (int) $pdo->query(
            "SELECT COUNT(*) FROM inventory_units WHERE status = 'available'"
        )->fetchColumn();

        $awaitingInspection = (int) $pdo->query(
            "SELECT COUNT(*) FROM inventory_units WHERE status = 'awaiting_inspection'"
        )->fetchColumn();

        $pendingOrders = (int) $pdo->query(
            "SELECT COUNT(*) FROM orders WHERE status IN ('pending','confirmed','processing')"
        )->fetchColumn();

        return [
            'today_sales' => $todaySales,
            'mtd_sales' => $mtdSales,
            'payments_today' => $paymentsToday,
            'payments_mtd' => $paymentsMtd,
            'outstanding' => $outstanding,
            'available_units' => $availableUnits,
            'awaiting_inspection' => $awaitingInspection,
            'pending_orders' => $pendingOrders,
            'has_sales_data' => $this->hasAnyOrders(),
        ];
    }

    private function hasAnyOrders(): bool
    {
        return (int) Connection::pdo()->query('SELECT COUNT(*) FROM orders')->fetchColumn() > 0;
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

    /** @return list<array<string,mixed>> */
    public function recentMovements(int $limit = 8): array
    {
        return Connection::pdo()->query(
            'SELECT sm.id, sm.movement_type, sm.from_status, sm.to_status, sm.reason, sm.created_at,
                    iu.sku, u.name AS user_name
             FROM stock_movements sm
             JOIN inventory_units iu ON iu.id = sm.inventory_unit_id
             LEFT JOIN users u ON u.id = sm.performed_by
             ORDER BY sm.id DESC LIMIT ' . (int) $limit
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function recentAudit(int $limit = 8): array
    {
        return AuditService::recent($limit);
    }

    /**
     * Published models whose available-unit count is below the configured
     * alert threshold (settings.low_stock_alert_threshold).
     *
     * @return list<array<string,mixed>>
     */
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
}
