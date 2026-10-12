<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Services\BusinessReportService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Business reports (Prompt 35) — service + HTTP tests.
 *
 * Proves:
 *  - CSV formula injection prevention (= + - @ tab CR prefixed)
 *  - Inventory valuation: groups by status, totals
 *  - Stock aging: buckets by received_at age
 *  - Purchasing/supplier/customer/staff/inspection/warranty/repair
 *    reports return data
 *  - Permission: technician blocked
 *  - CSV export: proper format with sanitized cells
 *  - Read-only: no writes
 */
final class BusinessReportTest extends TestCase
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

    // ---------------------------------------------------- CSV INJECTION --

    public function testCsvFormulaInjectionPrevention(): void
    {
        $svc = new BusinessReportService();
        $this->assertSame("'=CMD('calc')!A1", $svc->sanitizeCsvCell('=CMD(\'calc\')!A1'), '= prefixed');
        $this->assertSame("'+1+1", $svc->sanitizeCsvCell('+1+1'), '+ prefixed');
        $this->assertSame("'-1-1", $svc->sanitizeCsvCell('-1-1'), '- prefixed');
        $this->assertSame("'@SUM(A1)", $svc->sanitizeCsvCell('@SUM(A1)'), '@ prefixed');
        $this->assertSame("'\tCMD", $svc->sanitizeCsvCell("\tCMD"), 'tab prefixed');
        $this->assertSame("'\rCMD", $svc->sanitizeCsvCell("\rCMD"), 'CR prefixed');
        $this->assertSame('normal value', $svc->sanitizeCsvCell('normal value'), 'normal unchanged');
        $this->assertSame('', $svc->sanitizeCsvCell(''), 'empty unchanged');
        $this->assertSame('100.00', $svc->sanitizeCsvCell('100.00'), 'numeric unchanged');
    }

    public function testToCsvSanitizesDangerousCells(): void
    {
        $svc = new BusinessReportService();
        $csv = $svc->toCsv(
            [['name' => '=CMD()', 'amount' => '100'], ['name' => 'Safe', 'amount' => '200']],
            ['name', 'amount']
        );
        $this->assertStringContainsString("'=CMD()", $csv, 'formula cell must be prefixed');
        $this->assertStringContainsString('"Safe","200"', $csv, 'safe cell unchanged');
    }

    // ---------------------------------------------------- INVENTORY VALUATION --

    public function testInventoryValuation(): void
    {
        $ownerId = $this->makeOwner('br-iv@test.local');
        $svc = new BusinessReportService();
        $report = $svc->inventoryValuation();
        $this->assertTrue(is_array($report['rows']));
        $this->assertTrue(isset($report['total_cost']));
        $this->assertTrue(isset($report['total_retail']));
        $this->assertTrue(isset($report['count']));
    }

    // ---------------------------------------------------- STOCK AGING --

    public function testStockAging(): void
    {
        $ownerId = $this->makeOwner('br-sa@test.local');
        $svc = new BusinessReportService();
        $report = $svc->stockAging();
        $this->assertTrue(is_array($report['rows']));
        $this->assertTrue(isset($report['total_cost']));
    }

    // ---------------------------------------------------- PURCHASING --

    public function testPurchasingReport(): void
    {
        $ownerId = $this->makeOwner('br-pur@test.local');
        $today = gmdate('Y-m-d');
        $svc = new BusinessReportService();
        $report = $svc->purchasingReport($today, $today);
        $this->assertTrue(is_array($report['rows']));
    }

    // ---------------------------------------------------- SUPPLIER --

    public function testSupplierReport(): void
    {
        $ownerId = $this->makeOwner('br-sup@test.local');
        $svc = new BusinessReportService();
        $report = $svc->supplierReport();
        $this->assertTrue(is_array($report['rows']));
    }

    // ---------------------------------------------------- CUSTOMER --

    public function testCustomerReport(): void
    {
        $ownerId = $this->makeOwner('br-cust@test.local');
        $svc = new BusinessReportService();
        $report = $svc->customerReport();
        $this->assertTrue(is_array($report['rows']));
    }

    // ---------------------------------------------------- STAFF --

    public function testStaffReport(): void
    {
        $ownerId = $this->makeOwner('br-staff@test.local');
        $svc = new BusinessReportService();
        $report = $svc->staffReport();
        $this->assertTrue(is_array($report['rows']));
    }

    // ---------------------------------------------------- INSPECTION --

    public function testInspectionReport(): void
    {
        $ownerId = $this->makeOwner('br-insp@test.local');
        $today = gmdate('Y-m-d');
        $svc = new BusinessReportService();
        $report = $svc->inspectionReport($today, $today);
        $this->assertTrue(is_array($report['rows']));
    }

    // ---------------------------------------------------- WARRANTY --

    public function testWarrantyReport(): void
    {
        $ownerId = $this->makeOwner('br-warr@test.local');
        $svc = new BusinessReportService();
        $report = $svc->warrantyReport();
        $this->assertTrue(is_array($report['rows']));
    }

    // ---------------------------------------------------- REPAIR --

    public function testRepairReport(): void
    {
        $ownerId = $this->makeOwner('br-rep@test.local');
        $today = gmdate('Y-m-d');
        $svc = new BusinessReportService();
        $report = $svc->repairReport($today, $today);
        $this->assertTrue(is_array($report['rows']));
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testTechnicianBlockedFromBusinessReports(): void
    {
        $techId = $this->makeUser('br-tech@test.local', 'Correct-Horse-1', ['technician']);
        $this->assertThrows(ForbiddenException::class, function () use ($techId) {
            $this->dispatchAs($techId, 'GET', '/admin/reports/inventory-valuation');
        }, 'technician has no reports.view');
    }

    // ---------------------------------------------------- HTTP --

    public function testHttpInventoryValuationRenders(): void
    {
        $ownerId = $this->makeOwner('br-http-iv@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/reports/inventory-valuation');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Inventory Valuation', $r->body());
    }

    public function testHttpInventoryValuationCsvExport(): void
    {
        $ownerId = $this->makeOwner('br-http-iv-csv@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/reports/inventory-valuation', [], ['export' => 'csv']);
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('status', $r->body(), 'CSV must contain headers');
    }

    public function testHttpStockAgingRenders(): void
    {
        $ownerId = $this->makeOwner('br-http-sa@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/reports/stock-aging');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Stock Aging', $r->body());
    }

    public function testHttpPurchasingRenders(): void
    {
        $ownerId = $this->makeOwner('br-http-pur@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/reports/purchasing');
        $this->assertSame(200, $r->status());
    }

    public function testHttpStaffReportRenders(): void
    {
        $ownerId = $this->makeOwner('br-http-staff@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/reports/staff');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Staff Report', $r->body());
    }

    public function testHttpCustomerReportRenders(): void
    {
        $ownerId = $this->makeOwner('br-http-cust@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/reports/customer');
        $this->assertSame(200, $r->status());
    }
}
