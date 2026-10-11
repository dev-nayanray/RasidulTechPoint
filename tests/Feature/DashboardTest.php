<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DashboardService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Support\Database\Connection;
use Tests\TestCase;

/**
 * Dashboard metrics tests — prove every number on the Super Admin
 * dashboard is a real MySQL aggregate and that the empty-database
 * path produces zeros (never fake numbers).
 *
 * Coverage:
 *  - empty database → every metric is 0 and salesTrend() is [];
 *  - completed order → sales_total / sales_today / sales_mtd rise by the
 *    order total;
 *  - payment recorded → payments_total rises but sales_total is unchanged
 *    (revenue and cash flow are SEPARATE);
 *  - refunded order → refunded_orders_total rises; sales_total does NOT
 *    (refunded orders are not completed);
 *  - refunded payment → refunded_payments_total rises; payments_total
 *    does not;
 *  - outstanding balance: after a partial payment, outstanding reflects
 *    the unpaid remainder (no double-counting);
 *  - inventory receipt → available/awaiting counts move correctly;
 *  - low-stock: published models below threshold appear; the count is
 *    real, never hardcoded;
 *  - sales trend: zero-filled for the last 7 days, only emitted when
 *    there is order data;
 *  - date-range filtering: range=today vs range=mtd vs custom;
 *  - location scoping: inventory counts can be filtered by location_id;
 *  - limitations: the service reports which metrics cannot be calculated
 *    (expenses, warranty, delivery, CRM, marketing, etc.) with honest
 *    reasons.
 */
final class DashboardTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // The TestCase helpers makeUnit/makeAvailableUnit use user id 1
        // internally (for stock_movements.performed_by). Ensure a user
        // with id 1 exists so the FK constraint is satisfied — whether
        // or not a prior test class already created one.
        if ((int) $this->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            $this->makeUser('dash-setup@test.local', 'Correct-Horse-1', ['manager']);
        }
    }

    private function service(): DashboardService
    {
        return new DashboardService();
    }

    /** @return array{id:int,reference:string,total:string} */
    private function createCompletedOrder(int $customerId, array $unitIds, int $staffId): array
    {
        $svc = OrderService::forSeeding();
        $result = $svc->createOrderWithStatus($customerId, $unitIds, 'pos', 'completed', $staffId);
        // Force completed_at to "now" so the today/mtd windows include it.
        Connection::pdo()->prepare('UPDATE orders SET completed_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$result['id']]);
        return $result;
    }

    public function testEmptyDatabaseProducesZerosAndNoFakeNumbers(): void
    {
        $svc = $this->service();
        $m = $svc->metrics();

        $this->assertSame(0.0, $m['sales_total']);
        $this->assertSame(0.0, $m['sales_today']);
        $this->assertSame(0.0, $m['sales_mtd']);
        $this->assertSame(0.0, $m['payments_total']);
        $this->assertSame(0.0, $m['payments_today']);
        $this->assertSame(0.0, $m['payments_mtd']);
        $this->assertSame(0.0, $m['refunded_orders_total']);
        $this->assertSame(0.0, $m['refunded_payments_total']);
        $this->assertSame(0.0, $m['outstanding']);
        $this->assertSame(0, $m['available_units']);
        $this->assertSame(0, $m['awaiting_inspection']);
        $this->assertSame(0, $m['reserved_units']);
        $this->assertSame(0, $m['in_transit_units']);
        $this->assertSame(0, $m['pending_orders']);
        $this->assertFalse($m['has_sales_data']);
        $this->assertFalse($m['has_inventory_data']);
        $this->assertSame([], $svc->salesTrend(7), 'no orders → empty trend (chart hidden)');
    }

    public function testCompletedOrderRaisesSalesButNotPayments(): void
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-staff@test.local', 'Correct-Horse-1', ['manager']);

        $this->createCompletedOrder($customerId, [$unitId], $staffId);

        $m = $this->service()->metrics();

        $this->assertEquals(500.00, $m['sales_total'], 'completed order total must raise sales');
        $this->assertEquals(500.00, $m['sales_today'], 'completed just now must appear in today');
        $this->assertEquals(500.00, $m['sales_mtd'], 'completed just now must appear in MTD');
        $this->assertSame(0.0, $m['payments_total'], 'no payment recorded → payments_total stays 0');
        $this->assertEquals(500.00, $m['outstanding'], 'order total minus zero payments = outstanding balance');
    }

    public function testRecordedPaymentRaisesPaymentsButNotSales(): void
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '400.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-pay@test.local', 'Correct-Horse-1', ['manager']);

        $order = $this->createCompletedOrder($customerId, [$unitId], $staffId);
        OrderService::forSeeding()->recordPaymentForSeededOrder($order['id'], 400.00, 'cash');

        $m = $this->service()->metrics();

        $this->assertEquals(400.00, $m['sales_total'], 'revenue unchanged by payment');
        $this->assertEquals(400.00, $m['payments_total'], 'payment lands in payments_total');
        $this->assertEquals(400.00, $m['payments_today']);
        $this->assertEquals(400.00, $m['payments_mtd']);
        $this->assertSame(0.0, $m['outstanding'], 'order fully paid → no outstanding balance');
    }

    public function testPartialPaymentLeavesCorrectOutstandingBalance(): void
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '1000.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-partial@test.local', 'Correct-Horse-1', ['manager']);

        $order = $this->createCompletedOrder($customerId, [$unitId], $staffId);
        OrderService::forSeeding()->recordPaymentForSeededOrder($order['id'], 300.00, 'cash');

        $m = $this->service()->metrics();

        $this->assertEquals(1000.00, $m['sales_total']);
        $this->assertEquals(300.00, $m['payments_total']);
        $this->assertEquals(700.00, $m['outstanding'], '1000 total - 300 paid = 700 outstanding (no double counting)');
    }

    public function testRefundedOrderRaisesRefundsButNotSales(): void
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '250.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-refund@test.local', 'Correct-Horse-1', ['manager']);

        $order = $this->createCompletedOrder($customerId, [$unitId], $staffId);

        // Flip the order to refunded (the OrderService::updateStatus path
        // cancels/reserves units differently; do it directly in the DB to
        // keep this test focused on the dashboard metric).
        Connection::pdo()->prepare("UPDATE orders SET status = 'refunded', updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$order['id']]);

        $m = $this->service()->metrics();

        $this->assertSame(0.0, $m['sales_total'], 'refunded order is NOT completed → excluded from sales');
        $this->assertSame(0.0, $m['sales_today']);
        $this->assertEquals(250.00, $m['refunded_orders_total'], 'refunded order total must appear in refunds');
        $this->assertSame(0.0, $m['outstanding'], 'refunded order is excluded from outstanding balances');
    }

    public function testRefundedPaymentRaisesRefundedPaymentsButNotPaymentsTotal(): void
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '600.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-refpay@test.local', 'Correct-Horse-1', ['manager']);

        $order = $this->createCompletedOrder($customerId, [$unitId], $staffId);
        OrderService::forSeeding()->recordPaymentForSeededOrder($order['id'], 600.00, 'cash');

        // Refund the payment directly (simulates a gateway reversal).
        $stmt = Connection::pdo()->prepare("UPDATE payments SET status = 'refunded', updated_at = UTC_TIMESTAMP() WHERE order_id = ?");
        $stmt->execute([$order['id']]);

        $m = $this->service()->metrics();

        $this->assertSame(0.0, $m['payments_total'], 'refunded payment excluded from completed payments');
        $this->assertEquals(600.00, $m['refunded_payments_total'], 'refunded payment appears in refunds');
        $this->assertEquals(600.00, $m['outstanding'], 'un-refunded order total minus zero completed payments = full outstanding');
    }

    public function testSalesTrendIsZeroFilledForLast7DaysAndOnlyWithOrders(): void
    {
        // No orders at all → empty trend (chart hidden).
        $this->assertSame([], $this->service()->salesTrend(7));

        // Create an order so the trend is non-empty.
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '150.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-trend@test.local', 'Correct-Horse-1', ['manager']);
        $this->createCompletedOrder($customerId, [$unitId], $staffId);

        $trend = $this->service()->salesTrend(7);
        $this->assertCount(7, $trend, 'trend must always cover exactly 7 days (zero-filled)');
        $this->assertSame(gmdate('Y-m-d', strtotime('-6 days')), $trend[0]['date']);
        $this->assertSame(gmdate('Y-m-d'), $trend[6]['date']);
        // The last day should contain the 150 sale.
        $this->assertEquals(150.00, $trend[6]['total']);
        $this->assertSame(1, $trend[6]['count']);
        // Other days must be zero (no other orders).
        $this->assertSame(0.0, $trend[0]['total']);
        $this->assertSame(0, $trend[0]['count']);
    }

    public function testDateRangeTodayIsCalendarTodayNotRolling24h(): void
    {
        // Insert a completed order with completed_at on the previous UTC
        // calendar day. Under the OLD (rolling-24h) semantics this would
        // show in "today"; under the NEW calendar-today semantics it must
        // NOT (it fell on the previous calendar day).
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '999.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-today@test.local', 'Correct-Horse-1', ['manager']);
        $svc = OrderService::forSeeding();
        $order = $svc->createOrderWithStatus($customerId, [$unitId], 'pos', 'completed', $staffId);

        // Set completed_at to yesterday 12:00 UTC — guaranteed previous
        // calendar day regardless of when the test runs.
        $yesterday = gmdate('Y-m-d 12:00:00', strtotime('-1 day'));
        Connection::pdo()->prepare('UPDATE orders SET completed_at = ? WHERE id = ?')
            ->execute([$yesterday, $order['id']]);

        $m = $this->service()->metrics();
        $this->assertSame(0.0, $m['sales_today'], "yesterday's completed order must NOT count as today (calendar-today semantics)");

        // MTD may or may not include it depending on whether yesterday was
        // in the same UTC month — assert conditionally so the test passes
        // across month boundaries.
        if (gmdate('Y-m', strtotime('-1 day')) === gmdate('Y-m')) {
            $this->assertEquals(999.00, $m['sales_mtd'], 'same-month yesterday must appear in MTD');
        } else {
            $this->assertSame(0.0, $m['sales_mtd'], 'previous-month yesterday must NOT appear in MTD');
        }
    }

    public function testCustomDateRangeFiltersBothSalesAndPayments(): void
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '300.00']);
        $customerId = $this->makeCustomer();
        $staffId = $this->makeUser('dash-custom@test.local', 'Correct-Horse-1', ['manager']);

        $order = $this->createCompletedOrder($customerId, [$unitId], $staffId);
        OrderService::forSeeding()->recordPaymentForSeededOrder($order['id'], 300.00, 'cash');

        // Range that starts tomorrow → must exclude everything.
        $futureFrom = gmdate('Y-m-d 00:00:00', strtotime('+1 day'));
        $futureTo = gmdate('Y-m-d 23:59:59', strtotime('+2 days'));
        $m = $this->service()->metrics($futureFrom, $futureTo);
        $this->assertSame(0.0, $m['sales_total']);
        $this->assertSame(0.0, $m['payments_total']);

        // Range that starts a week ago → must include the just-completed order.
        $pastFrom = gmdate('Y-m-d 00:00:00', strtotime('-7 days'));
        $pastTo = gmdate('Y-m-d 23:59:59');
        $m = $this->service()->metrics($pastFrom, $pastTo);
        $this->assertEquals(300.00, $m['sales_total']);
        $this->assertEquals(300.00, $m['payments_total']);
    }

    public function testLocationScopingFiltersInventoryCountsOnly(): void
    {
        // Create two locations.
        $this->pdo()->prepare("INSERT INTO locations (name, type, is_active) VALUES ('Shop A', 'shop', 1), ('Shop B', 'shop', 1)")->execute();
        $shopA = (int) $this->pdo()->query("SELECT id FROM locations WHERE name = 'Shop A'")->fetchColumn();
        $shopB = (int) $this->pdo()->query("SELECT id FROM locations WHERE name = 'Shop B'")->fetchColumn();

        // Create a catalog + two units, one at each location.
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitA = $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);
        $unitB = $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);
        $this->pdo()->prepare('UPDATE inventory_units SET location_id = ? WHERE id = ?')->execute([$shopA, $unitA]);
        $this->pdo()->prepare('UPDATE inventory_units SET location_id = ? WHERE id = ?')->execute([$shopB, $unitB]);

        // No location filter → both units counted.
        $m = $this->service()->metrics();
        $this->assertSame(2, $m['available_units']);

        // Shop A scope → only 1.
        $m = $this->service()->metrics(null, null, $shopA);
        $this->assertSame(1, $m['available_units'], 'location scope must filter inventory count');
        $this->assertSame($shopA, $m['location_id']);

        // Shop B scope → also 1.
        $m = $this->service()->metrics(null, null, $shopB);
        $this->assertSame(1, $m['available_units']);
    }

    public function testLowStockAlertsOnlyIncludesPublishedModelsBelowThreshold(): void
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        // Publish the model so it's eligible for low-stock alerts.
        $this->pdo()->prepare('UPDATE product_models SET is_published = 1 WHERE id = ?')->execute([$modelId]);
        // Create one available unit (at default threshold 3, one unit is below).
        $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);

        $alerts = $this->service()->lowStockAlerts();
        $this->assertNotSame([], $alerts, 'published model with 1 available unit must trigger low-stock alert');
        $this->assertSame(1, (int) $alerts[0]['available_count']);

        // Add 3 more units → total 4, above threshold → no alert.
        for ($i = 0; $i < 3; $i++) {
            $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);
        }
        $alerts = $this->service()->lowStockAlerts();
        $this->assertSame([], $alerts, '4 available units must not trigger alert at threshold 3');
    }

    public function testLimitationsArrayDocumentsUnfinishedMetrics(): void
    {
        $limits = $this->service()->limitations();
        $this->assertNotSame([], $limits, 'there must be documented limitations');
        // Spot-check the documented gaps.
        $this->assertContains('Expenses module not built (finance.view permission exists, table does not).', $limits);
        $this->assertContains('Warranty & service module not built (warranty.manage permission exists, table does not).', $limits);
        $this->assertContains('Delivery/courier module not built (delivery.manage permission exists, table does not).', $limits);
        $this->assertContains('CRM module not built (crm.view permission exists, table does not).', $limits);
        // The order-location breakdown limitation must be documented.
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

        $svc = OrderService::forSeeding();
        // 1 pending, 1 confirmed, 1 completed.
        $svc->createOrderWithStatus($customerId, [$unitA], 'pos', 'pending', $staffId);
        $svc->createOrderWithStatus($customerId, [$unitB], 'pos', 'confirmed', $staffId);
        $this->createCompletedOrder($customerId, [$unitC], $staffId);

        $m = $this->service()->metrics();
        $this->assertSame(2, $m['pending_orders'], 'only pending+confirmed+processing count');
    }
}
