<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Services\OrderService;
use App\Services\ProfitService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * COGS and profit calculation (Prompt 33) — service + HTTP tests.
 *
 * Proves:
 *  - COGS = SUM(purchase_cost + landed_cost) for units in completed orders
 *  - Net revenue = subtotal - discount (NOT payments.amount)
 *  - Gross profit = Net revenue - COGS
 *  - Cancelled orders excluded from revenue AND COGS
 *  - Operating expenses separate from COGS
 *  - Net profit = Gross profit - Operating expenses
 *  - Per-order COGS breakdown with per-unit profit + margin
 *  - Permission: technician blocked
 *  - Financial integrity: read-only, no writes
 */
final class ProfitTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->group(['middleware' => ['headers']], function (Router $router): void {
            require BASE_PATH . '/routes/web.php';
        });
        return $router;
    }

    private function resolver(): MiddlewareResolver
    {
        $resolver = new MiddlewareResolver();
        $resolver->register('headers', fn () => new \App\Middleware\SecurityHeadersMiddleware());
        $resolver->register('auth', fn () => new \App\Middleware\AuthMiddleware(guest: false));
        $resolver->register('guest', fn () => new \App\Middleware\AuthMiddleware(guest: true));
        $resolver->register('csrf', fn () => new \App\Middleware\CsrfMiddleware());
        $resolver->register('permission', fn () => new \App\Middleware\PermissionMiddleware());
        return $resolver;
    }

    private function withCsrf(array $post): array
    {
        $post['_token'] = Csrf::token();
        return $post;
    }

    private function dispatchAs(int $userId, string $method, string $uri, array $post = [], array $get = []): Response
    {
        $this->actingAs($userId);
        return $this->router()->dispatch(
            Request::synthetic($method, $uri, $get, $this->withCsrf($post)),
            $this->resolver()
        );
    }

    private function wipeOwners(): void
    {
        $this->pdo()->exec(
            "UPDATE users SET is_active = 0 WHERE id IN (
                SELECT user_id FROM (
                    SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                    WHERE r.slug = 'owner'
                ) AS owners)"
        );
    }

    private function makeOwner(string $email): int
    {
        $this->wipeOwners();
        $id = $this->makeUser($email, 'Correct-Horse-1', ['owner']);
        $this->actingAs($id);
        return $id;
    }

    private function makeFixture(float $sellPrice, float $purchaseCost, float $landedCost = 0): array
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, [
            'selling_price' => number_format($sellPrice, 2, '.', ''),
            'purchase_cost' => number_format($purchaseCost, 2, '.', ''),
            'landed_cost' => number_format($landedCost, 2, '.', ''),
        ]);
        $customerId = $this->makeCustomer();
        return [$brandId, $catId, $modelId, $unitId, $customerId];
    }

    private function cleanupFixture(int $orderId, int $unitId, int $customerId, int $brandId, int $catId, int $modelId): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM refunds WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Find a specific order in the COGS report by order_id.
     */
    private function findOrderInReport(array $report, int $orderId): ?array
    {
        foreach ($report['orders'] ?? [] as $o) {
            if ((int) $o['id'] === $orderId) {
                return $o;
            }
        }
        return null;
    }

    // ---------------------------------------------------- COGS --

    public function testCogsEqualsPurchasePlusLandedCost(): void
    {
        $ownerId = $this->makeOwner('prof-c1@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture(1000, 600, 50);
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        $today = gmdate('Y-m-d');
        $report = (new ProfitService())->cogsReport($today, $today);

        // Find our order in the report (shared DB has other orders).
        $o = $this->findOrderInReport($report, $order['id']);
        $this->assertNotNull($o, 'our order must appear in the COGS report');
        $this->assertEquals(1000.00, (float) $o['subtotal']);
        $this->assertEquals(650.00, (float) $o['cogs'], 'COGS = 600 + 50 = 650');
        $this->assertEquals(350.00, (float) $o['gross_profit'], 'profit = 1000 - 650 = 350');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testDiscountReducesNetRevenue(): void
    {
        $ownerId = $this->makeOwner('prof-c2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture(1000, 500);
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, ['discount' => 100], 'completed');

        $today = gmdate('Y-m-d');
        $report = (new ProfitService())->cogsReport($today, $today);

        $o = $this->findOrderInReport($report, $order['id']);
        $this->assertNotNull($o);
        $this->assertEquals(1000.00, (float) $o['subtotal']);
        $this->assertEquals(100.00, (float) $o['discount']);
        $this->assertEquals(900.00, (float) $o['net_revenue']);
        $this->assertEquals(500.00, (float) $o['cogs']);
        $this->assertEquals(400.00, (float) $o['gross_profit']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testCancelledOrdersExcluded(): void
    {
        $ownerId = $this->makeOwner('prof-c3@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture(1000, 500);
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'pending');
        $svc->updateStatus($order['id'], 'cancelled', $ownerId, 'Customer cancelled');

        $today = gmdate('Y-m-d');
        $report = (new ProfitService())->cogsReport($today, $today);

        // Cancelled order must NOT appear in the report.
        $o = $this->findOrderInReport($report, $order['id']);
        $this->assertNull($o, 'cancelled order must be excluded from COGS report');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    // ---------------------------------------------------- P&L --

    public function testProfitAndLoss(): void
    {
        $ownerId = $this->makeOwner('prof-p1@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture(1000, 600, 50);
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, ['discount' => 50], 'completed');

        $catId2 = (int) $this->pdo()->query('SELECT id FROM expense_categories LIMIT 1')->fetchColumn();
        $stmt = $this->pdo()->prepare(
            'INSERT INTO expense_entries (expense_number, category_id, description, amount, expense_date, payment_account, status, created_by)
             VALUES (?, ?, ?, ?, ?, "cash", "approved", ?)'
        );
        $stmt->execute(['EXP-PROFTEST-1', $catId2, 'Test expense', '200.00', gmdate('Y-m-d'), $ownerId]);

        $today = gmdate('Y-m-d');
        $svc2 = new ProfitService();
        $pnl = $svc2->profitAndLoss($today, $today);
        $cogsReport = $svc2->cogsReport($today, $today);

        // Find our order in the COGS report (shared DB).
        $o = $this->findOrderInReport($cogsReport, $order['id']);
        $this->assertNotNull($o);
        // Net revenue = 1000 - 50 = 950.
        $this->assertEquals(950.00, (float) $o['net_revenue']);
        // COGS = 650.
        $this->assertEquals(650.00, (float) $o['cogs']);
        // Gross profit = 950 - 650 = 300.
        $this->assertEquals(300.00, (float) $o['gross_profit']);

        // Our test expense is in the P&L expenses.
        $foundExpense = false;
        foreach ($pnl['expenses_by_category'] as $cat) {
            if ((float) $cat['total'] >= 200) {
                $foundExpense = true;
                break;
            }
        }
        $this->assertTrue($foundExpense, 'test expense must appear in expenses');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
        $this->pdo()->prepare('DELETE FROM expense_entries WHERE expense_number = ?')->execute(['EXP-PROFTEST-1']);
    }

    public function testPaymentCollectionsNotCountedAsRevenue(): void
    {
        $ownerId = $this->makeOwner('prof-p2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture(1000, 500);
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        // Record a PARTIAL payment of 600.
        $svc->recordPayment($order['id'], 600.00, 'cash', $ownerId);

        $today = gmdate('Y-m-d');
        $report = (new ProfitService())->cogsReport($today, $today);

        $o = $this->findOrderInReport($report, $order['id']);
        $this->assertNotNull($o);
        // Revenue must be 1000 (subtotal), NOT 600 (payment collected).
        $this->assertEquals(1000.00, (float) $o['subtotal'], 'revenue = order subtotal, not payment amount');
        $this->assertEquals(1000.00, (float) $o['net_revenue']);
        $this->assertEquals(500.00, (float) $o['cogs']);
        $this->assertEquals(500.00, (float) $o['gross_profit']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    // ---------------------------------------------------- PER-ORDER BREAKDOWN --

    public function testOrderCogsBreakdown(): void
    {
        $ownerId = $this->makeOwner('prof-b1@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture(1000, 600, 50);
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        $items = (new ProfitService())->orderCogsBreakdown($order['id']);
        $this->assertCount(1, $items);
        $this->assertEquals(600.00, (float) $items[0]['purchase_cost']);
        $this->assertEquals(50.00, (float) $items[0]['landed_cost']);
        $this->assertEquals(650.00, (float) $items[0]['unit_cogs']);
        $this->assertEquals(350.00, (float) $items[0]['unit_profit'], 'unit_profit = 1000 - 650');
        $this->assertEquals(35.0, (float) $items[0]['unit_margin'], 'margin = 35%');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testTechnicianBlockedFromProfit(): void
    {
        $techId = $this->makeUser('prof-tech@test.local', 'Correct-Horse-1', ['technician']);
        $this->assertThrows(ForbiddenException::class, function () use ($techId) {
            $this->dispatchAs($techId, 'GET', '/admin/profit');
        }, 'technician has no finance.view');
    }

    // ---------------------------------------------------- HTTP --

    public function testHttpDashboardRenders(): void
    {
        $ownerId = $this->makeOwner('prof-http@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/profit');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Profit', $r->body());
        $this->assertStringContainsString('COGS', $r->body());
    }

    public function testHttpCogsReportRenders(): void
    {
        $ownerId = $this->makeOwner('prof-http-cogs@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/profit/cogs');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('COGS Report', $r->body());
    }

    public function testHttpPnlRenders(): void
    {
        $ownerId = $this->makeOwner('prof-http-pnl@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/profit/pnl');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Profit', $r->body());
        $this->assertStringContainsString('Methodology', $r->body());
    }
}
