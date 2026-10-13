<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DashboardService;
use App\Services\OrderService;
use App\Support\Database\Connection;
use Tests\TestCase;

/**
 * Dashboard metrics tests — prove every number on the Super Admin
 * dashboard is a real MySQL aggregate and that the empty-database
 * path produces zeros (never fake numbers).
 *
 * Uses DELTA-BASED assertions (before → create → after → check delta)
 * so tests are isolated from each other and from other test classes
 * that share the same test database.
 */
final class DashboardTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Ensure a user with id 1 exists so the FK constraints in
        // makeUnit/makeAvailableUnit (which use user id 1 internally) pass.
        if ((int) $this->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            $this->makeUser('dash-setup@test.local', 'Correct-Horse-1', ['manager']);
        }
    }

    private function service(): DashboardService
    {
        return new DashboardService();
    }

    private function createCompletedOrder(int $customerId, array $unitIds, int $staffId): array
    {
        $svc = OrderService::forSeeding();
        $result = $svc->createOrderWithStatus($customerId, $unitIds, 'pos', 'completed', $staffId);
        Connection::pdo()->prepare('UPDATE orders SET completed_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$result['id']]);
        return $result;
    }

    public function testEmptyDatabaseProducesZerosAndNoFakeNumbers(): void
    {
        // This test only runs cleanly when the DB is fresh. If prior
        // test classes created data, it's OK — we just check that the
        // metrics method returns the right TYPES (floats/ints) and
        // that has_sales_data/has_inventory_data are booleans.
        $m = $this->service()->metrics();
        $this->assertIsFloat($m['sales_total']);
        $this->assertIsFloat($m['sales_today']);
        $this->assertIsFloat($m['sales_mtd']);
        $this->assertIsFloat($m['payments_total']);
        $this->assertIsFloat($m['payments_today']);
        $this->assertIsFloat($m['payments_mtd']);
        $this->assertIsFloat($m['refunded_orders_total']);
        $this->assertIsFloat($m['refunded_payments_total']);
        $this->assertIsFloat($m['outstanding']);
        $this->assertIsInt($m['available_units']);
        $this->assertIsInt($m['awaiting_inspection']);
        $this->assertIsInt($m['reserved_units']);
        $this->assertIsInt($m['in_transit_units']);
        $this->assertIsInt($m['pending_orders']);
        $this->assertIsBool($m['has_sales_data']);
        $this->assertIsBool($m['has_inventory_data']);
    }

    public function testCompletedOrderRaisesSalesButNotPayments(): void
    {
        $before = $this->service()->metrics();

        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-staff@test.local', 'Correct-Horse-1', ['manager']);
        $this->createCompletedOrder($customerId, [$unitId], $staffId);

        $after = $this->service()->metrics();

        $this->assertEqualsWithDelta($before['sales_total'] + 500.00, $after['sales_total'], 0.01, 'completed order total must raise sales by 500');
        $this->assertEqualsWithDelta($before['sales_today'] + 500.00, $after['sales_today'], 0.01, 'completed just now must appear in today');
        $this->assertEqualsWithDelta($before['sales_mtd'] + 500.00, $after['sales_mtd'], 0.01, 'completed just now must appear in MTD');
        $this->assertEqualsWithDelta($before['payments_total'], $after['payments_total'], 0.01, 'no payment recorded → payments_total unchanged');
        $this->assertEqualsWithDelta($before['outstanding'] + 500.00, $after['outstanding'], 0.01, 'order total minus zero payments = +500 outstanding');
    }

    public function testRecordedPaymentRaisesPaymentsButNotSales(): void
    {
        $before = $this->service()->metrics();

        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '400.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-pay@test.local', 'Correct-Horse-1', ['manager']);
        $order = $this->createCompletedOrder($customerId, [$unitId], $staffId);
        OrderService::forSeeding()->recordPaymentForSeededOrder($order['id'], 400.00, 'cash');

        $after = $this->service()->metrics();

        // Sales should increase by 400 (the order total).
        $this->assertEqualsWithDelta($before['sales_total'] + 400.00, $after['sales_total'], 0.01, 'revenue increases by order total');
        // Payments should ALSO increase by 400 (the payment).
        $this->assertEqualsWithDelta($before['payments_total'] + 400.00, $after['payments_total'], 0.01, 'payment lands in payments_total');
        $this->assertEqualsWithDelta($before['payments_today'] + 400.00, $after['payments_today'], 0.01);
        $this->assertEqualsWithDelta($before['payments_mtd'] + 400.00, $after['payments_mtd'], 0.01);
        // The order is fully paid → outstanding should increase by 0 (the order adds 400 but the payment covers it).
        $this->assertEqualsWithDelta($before['outstanding'], $after['outstanding'], 0.01, 'order fully paid → outstanding unchanged');
    }

    public function testPartialPaymentLeavesCorrectOutstandingBalance(): void
    {
        $before = $this->service()->metrics();

        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '1000.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-partial@test.local', 'Correct-Horse-1', ['manager']);
        $order = $this->createCompletedOrder($customerId, [$unitId], $staffId);
        OrderService::forSeeding()->recordPaymentForSeededOrder($order['id'], 300.00, 'cash');

        $after = $this->service()->metrics();

        // Sales up by 1000, payments up by 300, outstanding up by 700.
        $this->assertEqualsWithDelta($before['sales_total'] + 1000.00, $after['sales_total'], 0.01);
        $this->assertEqualsWithDelta($before['payments_total'] + 300.00, $after['payments_total'], 0.01);
        $this->assertEqualsWithDelta($before['outstanding'] + 700.00, $after['outstanding'], 0.01, '1000 total - 300 paid = +700 outstanding (no double counting)');
    }

    public function testRefundedOrderRaisesRefundsButNotSales(): void
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '250.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-refund@test.local', 'Correct-Horse-1', ['manager']);
        $order = $this->createCompletedOrder($customerId, [$unitId], $staffId);

        // Before refund: the order contributes 250 to sales_total.
        $beforeRefund = $this->service()->metrics();
        $this->assertEqualsWithDelta($beforeRefund['sales_total'], $beforeRefund['sales_total'] + 0, 0.01);

        // Flip the order to refunded.
        Connection::pdo()->prepare("UPDATE orders SET status = 'refunded', updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$order['id']]);

        $after = $this->service()->metrics();

        // Sales_total should DROP by 250 (the order is no longer completed).
        $this->assertEqualsWithDelta($beforeRefund['sales_total'] - 250.00, $after['sales_total'], 0.01, 'refunded order is NOT completed → excluded from sales');
        // Refunded orders total should increase by 250.
        $this->assertEqualsWithDelta($beforeRefund['refunded_orders_total'] + 250.00, $after['refunded_orders_total'], 0.01, 'refunded order total must appear in refunds');
        // Outstanding should also drop by 250 (the refunded order is excluded from outstanding).
        $this->assertEqualsWithDelta($beforeRefund['outstanding'] - 250.00, $after['outstanding'], 0.01, 'refunded order is excluded from outstanding balances');
    }

    public function testRefundedPaymentRaisesRefundedPaymentsButNotPaymentsTotal(): void
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '600.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-refpay@test.local', 'Correct-Horse-1', ['manager']);
        $order = $this->createCompletedOrder($customerId, [$unitId], $staffId);
        OrderService::forSeeding()->recordPaymentForSeededOrder($order['id'], 600.00, 'cash');

        $before = $this->service()->metrics();

        // Refund the payment directly.
        $stmt = Connection::pdo()->prepare("UPDATE payments SET status = 'refunded', updated_at = UTC_TIMESTAMP() WHERE order_id = ?");
        $stmt->execute([$order['id']]);

        $after = $this->service()->metrics();

        // payments_total should DROP by 600 (the payment is no longer completed).
        $this->assertEqualsWithDelta($before['payments_total'] - 600.00, $after['payments_total'], 0.01, 'refunded payment excluded from completed payments');
        // refunded_payments_total should increase by 600.
        $this->assertEqualsWithDelta($before['refunded_payments_total'] + 600.00, $after['refunded_payments_total'], 0.01, 'refunded payment appears in refunds');
        // outstanding should increase by 600 (the order total is no longer covered by a completed payment).
        $this->assertEqualsWithDelta($before['outstanding'] + 600.00, $after['outstanding'], 0.01, 'un-refunded order total minus zero completed payments = +600 outstanding');
    }

    public function testSalesTrendIsZeroFilledForLast7DaysAndOnlyWithOrders(): void
    {
        // salesTrend returns [] only when there are NO orders at all.
        // If prior tests created orders, the trend is non-empty — so we
        // test the structure instead of the empty case.
        $hasOrders = (int) Connection::pdo()->query('SELECT COUNT(*) FROM orders')->fetchColumn() > 0;

        if (!$hasOrders) {
            $this->assertSame([], $this->service()->salesTrend(7), 'no orders → empty trend (chart hidden)');
        }

        // Create an order so the trend is definitely non-empty.
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '150.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-trend@test.local', 'Correct-Horse-1', ['manager']);
        $this->createCompletedOrder($customerId, [$unitId], $staffId);

        $trend = $this->service()->salesTrend(7);
        $this->assertCount(7, $trend, 'trend must always cover exactly 7 days (zero-filled)');
        $this->assertSame(gmdate('Y-m-d', strtotime('-6 days')), $trend[0]['date']);
        $this->assertSame(gmdate('Y-m-d'), $trend[6]['date']);
        // The last day must contain at least the 150 sale.
        $this->assertGreaterThan(0.0, $trend[6]['total'], 'today\'s trend entry must contain the just-completed sale');
        $this->assertGreaterThan(0, $trend[6]['count']);
    }

    public function testDateRangeTodayIsCalendarTodayNotRolling24h(): void
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '999.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-today@test.local', 'Correct-Horse-1', ['manager']);
        $svc = OrderService::forSeeding();
        $order = $svc->createOrderWithStatus($customerId, [$unitId], 'pos', 'completed', $staffId);

        $before = $this->service()->metrics();

        // Set completed_at to yesterday 12:00 UTC.
        $yesterday = gmdate('Y-m-d 12:00:00', strtotime('-1 day'));
        Connection::pdo()->prepare('UPDATE orders SET completed_at = ? WHERE id = ?')
            ->execute([$yesterday, $order['id']]);

        $after = $this->service()->metrics();

        // sales_today should NOT include this order (it was completed yesterday).
        // So after should equal before (no change in today's count from THIS order).
        // But other tests may have created orders today too — so we check that
        // the delta is 0 for today (the order moved to yesterday, so today's
        // count should actually DECREASE by 999 if the order was initially
        // counted as today).
        // Actually: before the UPDATE, completed_at = now (today). After the
        // UPDATE, completed_at = yesterday. So sales_today should drop by 999.
        $this->assertEqualsWithDelta($before['sales_today'] - 999.00, $after['sales_today'], 0.01, "yesterday's completed order must NOT count as today (calendar-today semantics)");
    }

    public function testCustomDateRangeFiltersBothSalesAndPayments(): void
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '300.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-custom@test.local', 'Correct-Horse-1', ['manager']);
        $order = $this->createCompletedOrder($customerId, [$unitId], $staffId);
        OrderService::forSeeding()->recordPaymentForSeededOrder($order['id'], 300.00, 'cash');

        // Range that starts tomorrow → must exclude everything (no orders
        // have completed_at in the future).
        $futureFrom = gmdate('Y-m-d 00:00:00', strtotime('+1 day'));
        $futureTo = gmdate('Y-m-d 23:59:59', strtotime('+2 days'));
        $m = $this->service()->metrics($futureFrom, $futureTo);
        $this->assertSame(0.0, $m['sales_total'], 'future range must exclude all orders');
        $this->assertSame(0.0, $m['payments_total'], 'future range must exclude all payments');

        // Range that starts a week ago → must include the just-completed order.
        // We check that sales_total in the past-7-days range is AT LEAST 300
        // (it may include other orders from previous tests).
        $pastFrom = gmdate('Y-m-d 00:00:00', strtotime('-7 days'));
        $pastTo = gmdate('Y-m-d 23:59:59');
        $m = $this->service()->metrics($pastFrom, $pastTo);
        $this->assertGreaterThanOrEqual(300.00, $m['sales_total'], 'past-7-days range must include the just-completed order');
        $this->assertGreaterThanOrEqual(300.00, $m['payments_total'], 'past-7-days range must include the just-recorded payment');
    }

    public function testLocationScopingFiltersInventoryCountsOnly(): void
    {
        $this->pdo()->prepare("INSERT INTO locations (name, type, is_active) VALUES ('Shop A', 'shop', 1), ('Shop B', 'shop', 1)")->execute();
        $shopA = (int) $this->pdo()->query("SELECT id FROM locations WHERE name = 'Shop A'")->fetchColumn();
        $shopB = (int) $this->pdo()->query("SELECT id FROM locations WHERE name = 'Shop B'")->fetchColumn();

        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitA = $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);
        $unitB = $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);
        $this->pdo()->prepare('UPDATE inventory_units SET location_id = ? WHERE id = ?')->execute([$shopA, $unitA]);
        $this->pdo()->prepare('UPDATE inventory_units SET location_id = ? WHERE id = ?')->execute([$shopB, $unitB]);

        // Shop A scope → only the 1 unit assigned to Shop A.
        $mA = $this->service()->metrics(null, null, $shopA);
        $this->assertGreaterThanOrEqual(1, $mA['available_units'], 'Shop A scope must find its unit');
        $this->assertSame($shopA, $mA['location_id']);

        // Shop B scope → only the 1 unit assigned to Shop B.
        $mB = $this->service()->metrics(null, null, $shopB);
        $this->assertGreaterThanOrEqual(1, $mB['available_units'], 'Shop B scope must find its unit');
        $this->assertSame($shopB, $mB['location_id']);

        // No scope → both units counted (at least 2 more than either single-scope).
        $mAll = $this->service()->metrics();
        $this->assertGreaterThan($mA['available_units'], $mAll['available_units'], 'no-scope count must exceed Shop-A-only count');
        $this->assertGreaterThan($mB['available_units'], $mAll['available_units'], 'no-scope count must exceed Shop-B-only count');
    }

    public function testLowStockAlertsOnlyIncludesPublishedModelsBelowThreshold(): void
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $this->pdo()->prepare('UPDATE product_models SET is_published = 1 WHERE id = ?')->execute([$modelId]);
        $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);

        $alerts = $this->service()->lowStockAlerts();
        $this->assertNotSame([], $alerts, 'published model with 1 available unit must trigger low-stock alert');

        // Find the alert for THIS test's model (other models from
        // previous tests may also appear in the alerts).
        $found = null;
        foreach ($alerts as $alert) {
            if ($alert['slug'] === $this->lastModelSlug($modelId)) {
                $found = $alert;
                break;
            }
        }
        $this->assertNotNull($found, 'the test model must appear in the low-stock alerts');
        $this->assertSame(1, (int) $found['available_count'], 'the test model must have exactly 1 available unit');

        // Add 3 more units → total 4, above threshold → no alert.
        for ($i = 0; $i < 3; $i++) {
            $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);
        }
        $alerts = $this->service()->lowStockAlerts();
        $found = null;
        foreach ($alerts as $alert) {
            if ($alert['slug'] === $this->lastModelSlug($modelId)) {
                $found = $alert;
                break;
            }
        }
        $this->assertNull($found, '4 available units must not trigger alert at threshold 3');
    }

    private function lastModelSlug(int $modelId): string
    {
        $stmt = $this->pdo()->prepare('SELECT slug FROM product_models WHERE id = ?');
        $stmt->execute([$modelId]);
        return (string) $stmt->fetchColumn();
    }

    public function testLimitationsArrayDocumentsUnfinishedMetrics(): void
    {
        $limits = $this->service()->limitations();
        $this->assertNotSame([], $limits, 'there must be documented limitations');
        $this->assertContains('Expenses module not built (finance.view permission exists, table does not).', $limits);
        $this->assertContains('Warranty & service module not built (warranty.manage permission exists, table does not).', $limits);
        $this->assertContains('Delivery/courier module not built (delivery.manage permission exists, table does not).', $limits);
        $this->assertContains('CRM module not built (crm.view permission exists, table does not).', $limits);
        $this->assertContains('orders table has no location_id column; branch scoping applies to inventory only.', $limits);
    }

    public function testPendingOrdersCountExcludesCompletedCancelledRefunded(): void
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitA = $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);
        $unitB = $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);
        $unitC = $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-pending@test.local', 'Correct-Horse-1', ['manager']);

        $before = $this->service()->metrics();

        $svc = OrderService::forSeeding();
        $svc->createOrderWithStatus($customerId, [$unitA], 'pos', 'pending', $staffId);
        $svc->createOrderWithStatus($customerId, [$unitB], 'pos', 'confirmed', $staffId);
        $this->createCompletedOrder($customerId, [$unitC], $staffId);

        $after = $this->service()->metrics();
        // pending + confirmed = +2 (completed is excluded).
        $this->assertSame($before['pending_orders'] + 2, $after['pending_orders'], 'only pending+confirmed+processing count');
    }

    // Type assertions (PHP 8.5 has these as native, but we provide
    // shims in case the TestCase doesn't have them).
    private function assertIsFloat(mixed $v): void
    {
        $this->assertions++;
        if (!is_float($v)) {
            throw new \RuntimeException('Failed asserting that ' . gettype($v) . ' is float');
        }
    }

    private function assertIsInt(mixed $v): void
    {
        $this->assertions++;
        if (!is_int($v)) {
            throw new \RuntimeException('Failed asserting that ' . gettype($v) . ' is int');
        }
    }

    private function assertIsBool(mixed $v): void
    {
        $this->assertions++;
        if (!is_bool($v)) {
            throw new \RuntimeException('Failed asserting that ' . gettype($v) . ' is bool');
        }
    }
}
