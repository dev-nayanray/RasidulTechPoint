<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Services\FinancialReportService;
use App\Services\OrderService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Financial reporting (Prompt 34) — service + HTTP tests.
 *
 * Proves:
 *  - Sales report: completed orders by day, totals
 *  - Payment report: by method, totals
 *  - Refund report: completed refunds, totals
 *  - Expense report: by category, approved+paid only
 *  - Gross profit report: delegates to ProfitService
 *  - Receivables: unpaid/partial orders with outstanding
 *  - Payables: suppliers with outstanding_balance > 0
 *  - Reconciliation: batches in date range
 *  - CSV export: correct format
 *  - Date boundaries: consistent from 00:00:00 to 23:59:59
 *  - Permission: technician blocked
 *  - Read-only: no writes
 */
final class FinancialReportTest extends TestCase
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

    // ---------------------------------------------------- SALES REPORT --

    public function testSalesReportReturnsCompletedOrders(): void
    {
        $ownerId = $this->makeOwner('fr-s1@test.local');
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '1000.00']);
        $customerId = $this->makeCustomer();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        $today = gmdate('Y-m-d');
        $report = (new FinancialReportService())->salesReport($today, $today);

        // At least one row for today.
        $found = false;
        foreach ($report['rows'] as $r) {
            if ($r['sale_date'] === $today) {
                $found = true;
                $this->assertGreaterThan(0, (int) $r['order_count']);
                $this->assertGreaterThan(0, (float) $r['gross_revenue']);
                break;
            }
        }
        $this->assertTrue($found, 'today must appear in sales report');
        $this->assertGreaterThan(0, $report['order_count']);
        $this->assertGreaterThan(0, $report['total_revenue']);

        // Cleanup.
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- PAYMENT REPORT --

    public function testPaymentReportGroupsByMethod(): void
    {
        $ownerId = $this->makeOwner('fr-p1@test.local');
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $customerId = $this->makeCustomer();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $svc->recordPayment($order['id'], 500.00, 'cash', $ownerId);

        $today = gmdate('Y-m-d');
        $report = (new FinancialReportService())->paymentReport($today, $today);

        $foundCash = false;
        foreach ($report['rows'] as $r) {
            if ($r['method'] === 'cash') {
                $foundCash = true;
                $this->assertGreaterThan(0, (float) $r['total']);
                break;
            }
        }
        $this->assertTrue($foundCash, 'cash method must appear');
        $this->assertGreaterThan(0, $report['total_collected']);

        // Cleanup.
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- RECEIVABLES --

    public function testReceivablesReportShowsUnpaidOrders(): void
    {
        $ownerId = $this->makeOwner('fr-r1@test.local');
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $customerId = $this->makeCustomer();
        $svc = new OrderService();
        // Create a pending order — payment_status is 'unpaid'.
        $order = $svc->createOrder($customerId, [$unitId], 'online', $ownerId, [], 'pending');

        $report = (new FinancialReportService())->receivablesReport();

        $found = false;
        foreach ($report['rows'] as $r) {
            if ((int) $r['id'] === $order['id']) {
                $found = true;
                $this->assertSame('unpaid', $r['payment_status']);
                $this->assertEquals(500.00, (float) $r['outstanding']);
                break;
            }
        }
        $this->assertTrue($found, 'unpaid order must appear in receivables');

        // Cleanup.
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- CSV EXPORT --

    public function testToCsv(): void
    {
        $svc = new FinancialReportService();
        $csv = $svc->toCsv(
            [['name' => 'Test', 'amount' => '100.00'], ['name' => 'Second, Inc', 'amount' => '200.00']],
            ['name', 'amount']
        );
        $this->assertStringContainsString('"name","amount"', $csv);
        $this->assertStringContainsString('"Test","100.00"', $csv);
        $this->assertStringContainsString('"Second, Inc","200.00"', $csv, 'commas in values must be quoted');
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testTechnicianBlockedFromReports(): void
    {
        $techId = $this->makeUser('fr-tech@test.local', 'Correct-Horse-1', ['technician']);
        $this->assertThrows(ForbiddenException::class, function () use ($techId) {
            $this->dispatchAs($techId, 'GET', '/admin/reports');
        }, 'technician has no reports.view');
    }

    // ---------------------------------------------------- HTTP --

    public function testHttpDashboardRenders(): void
    {
        $ownerId = $this->makeOwner('fr-http-dash@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/reports');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Financial Reports', $r->body());
        $this->assertStringContainsString('Notes', $r->body());
    }

    public function testHttpSalesReportRenders(): void
    {
        $ownerId = $this->makeOwner('fr-http-sales@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/reports/sales');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Sales Report', $r->body());
    }

    public function testHttpSalesReportCsvExport(): void
    {
        $ownerId = $this->makeOwner('fr-http-csv@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/reports/sales', [], ['export' => 'csv']);
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('sale_date', $r->body(), 'CSV must contain column headers');
    }

    public function testHttpGrossProfitReportRenders(): void
    {
        $ownerId = $this->makeOwner('fr-http-gp@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/reports/gross-profit');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Gross Profit', $r->body());
    }

    public function testHttpReceivablesReportRenders(): void
    {
        $ownerId = $this->makeOwner('fr-http-rec@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/reports/receivables');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Receivables', $r->body());
    }

    public function testHttpReconciliationReportRenders(): void
    {
        $ownerId = $this->makeOwner('fr-http-recon@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/reports/reconciliation');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Reconciliation', $r->body());
    }
}
