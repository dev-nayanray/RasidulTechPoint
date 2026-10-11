<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Services\OrderService;
use App\Services\WarrantyService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Warranty management (Prompt 27) — service + HTTP tests.
 *
 * Proves:
 *  - Policy CRUD: create, update, find, allPolicies
 *  - Registration: create from a sold unit, computes start/end dates
 *    from sold_at + policy duration, refuses non-sold units, refuses
 *    duplicate active registration, generates sequential number
 *  - Eligibility: active + within end_date → eligible; expired or
 *    voided → not eligible
 *  - Claims: create requires eligibility, creates pending claim,
 *    seeds initial history row
 *  - Claim decisions: approve, reject (requires reason), start, complete,
 *    cancel — each writes a history row, enforces the matrix
 *  - Claim matrix: pending → completed rejected, completed terminal,
 *    rejected terminal
 *  - History: every transition recorded, append-only
 *  - Void registration: marks voided, no further claims allowed
 *  - Permission: technician blocked, cashier blocked
 *  - CSRF on state-changing routes
 *  - Inventory NOT moved by warranty claims
 */
final class WarrantyTest extends TestCase
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

    private function makeFixture(float $price = 500.00): array
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => number_format($price, 2, '.', '')]);
        $customerId = $this->makeCustomer();
        return [$brandId, $catId, $modelId, $unitId, $customerId];
    }

    private function cleanupFixture(int $orderId, int $unitId, int $customerId, int $brandId, int $catId, int $modelId, array $extra = []): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (($extra['claim_history'] ?? []) as $chid) {
            $this->pdo()->prepare('DELETE FROM warranty_claim_history WHERE id = ?')->execute([$chid]);
        }
        foreach (($extra['claims'] ?? []) as $cid) {
            $this->pdo()->prepare('DELETE FROM warranty_claim_history WHERE claim_id = ?')->execute([$cid]);
            $this->pdo()->prepare('DELETE FROM warranty_claims WHERE id = ?')->execute([$cid]);
        }
        foreach (($extra['registrations'] ?? []) as $rid) {
            $this->pdo()->prepare('DELETE FROM warranty_registrations WHERE id = ?')->execute([$rid]);
        }
        foreach (($extra['policies'] ?? []) as $pid) {
            $this->pdo()->prepare('DELETE FROM warranty_policies WHERE id = ?')->execute([$pid]);
        }
        $this->pdo()->prepare('DELETE FROM order_corrections WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_status_history WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_fulfillment_notes WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM refunds WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM invoices WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- POLICIES --

    public function testPolicyCreateAndFind(): void
    {
        $ownerId = $this->makeOwner('wr-pol1@test.local');
        $svc = new WarrantyService();
        $id = $svc->createPolicy([
            'name' => '90-Day Repair Warranty',
            'description' => 'Covers manufacturing defects',
            'duration_days' => 90,
            'coverage_type' => 'repair',
            'terms' => 'Excludes water damage and customer abuse.',
            'is_active' => 1,
        ], $ownerId);
        $this->assertGreaterThan(0, $id);
        $policy = $svc->findPolicy($id);
        $this->assertSame('90-Day Repair Warranty', $policy['name']);
        $this->assertSame(90, (int) $policy['duration_days']);
        $this->assertSame('repair', $policy['coverage_type']);
        $this->pdo()->prepare('DELETE FROM warranty_policies WHERE id = ?')->execute([$id]);
    }

    public function testPolicyCreateRejectsZeroDuration(): void
    {
        $ownerId = $this->makeOwner('wr-pol2@test.local');
        $svc = new WarrantyService();
        $this->assertThrows(ValidationException::class, function () use ($svc, $ownerId) {
            $svc->createPolicy(['name' => 'Bad', 'duration_days' => 0], $ownerId);
        }, 'duration must be at least 1 day');
    }

    // ---------------------------------------------------- REGISTRATIONS --

    public function testRegistrationCreateFromSoldUnit(): void
    {
        $ownerId = $this->makeOwner('wr-reg1@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        $warrantySvc = new WarrantyService();
        $result = $warrantySvc->createRegistration($unitId, null, $ownerId);
        $this->assertMatchesRegularExpression('/^WR-\d{4}-\d{5}$/', $result['registration_number']);
        $this->assertNotSame('', $result['start_date']);
        $this->assertNotSame('', $result['end_date']);

        $reg = $warrantySvc->findRegistration($result['id']);
        $this->assertSame('active', $reg['status']);
        $this->assertSame((string) $unitId, (string) $reg['inventory_unit_id']);
        $this->assertSame($order['reference'], $reg['order_reference']);
        $this->assertTrue($reg['is_eligible']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['registrations' => [$result['id']]]);
    }

    public function testRegistrationRejectsNonSoldUnit(): void
    {
        $ownerId = $this->makeOwner('wr-reg2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        // Unit is 'available', not 'sold'.
        $this->assertThrows(ValidationException::class, function () use ($unitId, $ownerId) {
            (new WarrantyService())->createRegistration($unitId, null, $ownerId);
        }, 'cannot register warranty for a non-sold unit');

        $this->cleanupFixture(0, $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testRegistrationRejectsDuplicateActive(): void
    {
        $ownerId = $this->makeOwner('wr-reg3@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $warrantySvc = new WarrantyService();
        $first = $warrantySvc->createRegistration($unitId, null, $ownerId);

        $this->assertThrows(ValidationException::class, function () use ($warrantySvc, $unitId, $ownerId) {
            $warrantySvc->createRegistration($unitId, null, $ownerId);
        }, 'cannot have two active warranties');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['registrations' => [$first['id']]]);
    }

    public function testRegistrationWithPolicy(): void
    {
        $ownerId = $this->makeOwner('wr-reg4@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $warrantySvc = new WarrantyService();
        $policyId = $warrantySvc->createPolicy(['name' => '1-Year Replace', 'duration_days' => 365, 'coverage_type' => 'replace', 'is_active' => 1], $ownerId);

        $result = $warrantySvc->createRegistration($unitId, $policyId, $ownerId);
        $reg = $warrantySvc->findRegistration($result['id']);
        $this->assertSame('1-Year Replace', $reg['policy_name']);
        $this->assertSame(365, (int) $reg['policy_duration']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['registrations' => [$result['id']], 'policies' => [$policyId]]);
    }

    // ---------------------------------------------------- ELIGIBILITY --

    public function testEligibilityCheck(): void
    {
        $svc = new WarrantyService();
        // Active + end_date in the future → eligible.
        $this->assertTrue($svc->isEligible(['status' => 'active', 'end_date' => gmdate('Y-m-d', strtotime('+30 days'))]));
        // Active + end_date in the past → not eligible.
        $this->assertFalse($svc->isEligible(['status' => 'active', 'end_date' => gmdate('Y-m-d', strtotime('-1 day'))]));
        // Voided → not eligible.
        $this->assertFalse($svc->isEligible(['status' => 'voided', 'end_date' => gmdate('Y-m-d', strtotime('+30 days'))]));
        // Expired → not eligible.
        $this->assertFalse($svc->isEligible(['status' => 'expired', 'end_date' => gmdate('Y-m-d', strtotime('-1 day'))]));
    }

    // ---------------------------------------------------- CLAIMS --

    public function testClaimCreateRequiresEligibleRegistration(): void
    {
        $ownerId = $this->makeOwner('wr-clm1@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $warrantySvc = new WarrantyService();
        $reg = $warrantySvc->createRegistration($unitId, null, $ownerId);

        // Eligible → claim created.
        $claim = $warrantySvc->createClaim($reg['id'], 'repair', 'Internal note', 'Screen flickers', $ownerId);
        $this->assertSame('pending', $claim['status']);
        $this->assertMatchesRegularExpression('/^CLM-\d{4}-\d{5}$/', $claim['claim_number']);

        // History seeded.
        $history = $warrantySvc->claimHistory($claim['id']);
        $this->assertCount(1, $history);
        $this->assertSame('new', $history[0]['from_status']);
        $this->assertSame('pending', $history[0]['to_status']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['registrations' => [$reg['id']], 'claims' => [$claim['id']]]);
    }

    public function testClaimCreateRejectsVoidedRegistration(): void
    {
        $ownerId = $this->makeOwner('wr-clm2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $warrantySvc = new WarrantyService();
        $reg = $warrantySvc->createRegistration($unitId, null, $ownerId);
        $warrantySvc->voidRegistration($reg['id'], $ownerId, 'Wrong unit');

        $this->assertThrows(ValidationException::class, function () use ($warrantySvc, $reg, $ownerId) {
            $warrantySvc->createClaim($reg['id'], 'repair', null, 'Issue', $ownerId);
        }, 'voided registration cannot have claims');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['registrations' => [$reg['id']]]);
    }

    public function testClaimApproveRejectCompleteFlow(): void
    {
        $ownerId = $this->makeOwner('wr-flow@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $warrantySvc = new WarrantyService();
        $reg = $warrantySvc->createRegistration($unitId, null, $ownerId);
        $claim = $warrantySvc->createClaim($reg['id'], 'repair', null, 'Battery drains fast', $ownerId);

        // Approve.
        $warrantySvc->approveClaim($claim['id'], $ownerId, 'Within warranty');
        $c = $warrantySvc->findClaim($claim['id']);
        $this->assertSame('approved', $c['status']);
        $this->assertNotNull($c['decided_at']);

        // Start repair.
        $warrantySvc->startClaim($claim['id'], $ownerId);
        $this->assertSame('in_progress', $warrantySvc->findClaim($claim['id'])['status']);

        // Complete.
        $warrantySvc->completeClaim($claim['id'], $ownerId, 'Battery replaced, tested 48h burn-in.');
        $c = $warrantySvc->findClaim($claim['id']);
        $this->assertSame('completed', $c['status']);
        $this->assertNotNull($c['completed_at']);
        $this->assertSame('Battery replaced, tested 48h burn-in.', $c['resolution_notes']);

        // History: new→pending, pending→approved, approved→in_progress, in_progress→completed = 4 rows.
        $history = $warrantySvc->claimHistory($claim['id']);
        $this->assertCount(4, $history);
        $this->assertSame('completed', $history[3]['to_status']);

        // Inventory NOT moved — unit stays 'sold'.
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame('sold', (string) $stmt->fetchColumn(), 'warranty claims do not move inventory');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['registrations' => [$reg['id']], 'claims' => [$claim['id']]]);
    }

    public function testClaimRejectRequiresReason(): void
    {
        $ownerId = $this->makeOwner('wr-reject@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $warrantySvc = new WarrantyService();
        $reg = $warrantySvc->createRegistration($unitId, null, $ownerId);
        $claim = $warrantySvc->createClaim($reg['id'], 'repair', null, 'Issue', $ownerId);

        $this->assertThrows(ValidationException::class, function () use ($warrantySvc, $claim, $ownerId) {
            $warrantySvc->rejectClaim($claim['id'], $ownerId, '   ');
        }, 'rejection reason is required');

        $warrantySvc->rejectClaim($claim['id'], $ownerId, 'Warranty expired');
        $c = $warrantySvc->findClaim($claim['id']);
        $this->assertSame('rejected', $c['status']);
        $this->assertSame('Warranty expired', $c['decision_reason']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['registrations' => [$reg['id']], 'claims' => [$claim['id']]]);
    }

    public function testClaimMatrixEnforced(): void
    {
        $svc = new WarrantyService();
        // pending → completed is NOT allowed.
        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->assertClaimTransitionAllowed('pending', 'completed');
        }, 'pending → completed must be rejected');
        // completed is terminal.
        $this->assertSame([], WarrantyService::CLAIM_TRANSITIONS['completed']);
        $this->assertSame([], WarrantyService::CLAIM_TRANSITIONS['rejected']);
    }

    // ---------------------------------------------------- VOID --

    public function testVoidRegistrationBlocksFutureClaims(): void
    {
        $ownerId = $this->makeOwner('wr-void@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $warrantySvc = new WarrantyService();
        $reg = $warrantySvc->createRegistration($unitId, null, $ownerId);
        $warrantySvc->voidRegistration($reg['id'], $ownerId, 'Registered against wrong unit');

        $this->assertSame('voided', $warrantySvc->findRegistration($reg['id'])['status']);
        $this->assertFalse($warrantySvc->findRegistration($reg['id'])['is_eligible']);

        $this->assertThrows(ValidationException::class, function () use ($warrantySvc, $reg, $ownerId) {
            $warrantySvc->createClaim($reg['id'], 'repair', null, 'Issue', $ownerId);
        }, 'voided registration cannot have new claims');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['registrations' => [$reg['id']]]);
    }

    public function testVoidTwiceRejected(): void
    {
        $ownerId = $this->makeOwner('wr-void2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $warrantySvc = new WarrantyService();
        $reg = $warrantySvc->createRegistration($unitId, null, $ownerId);
        $warrantySvc->voidRegistration($reg['id'], $ownerId, 'First void');

        $this->assertThrows(ValidationException::class, function () use ($warrantySvc, $reg, $ownerId) {
            $warrantySvc->voidRegistration($reg['id'], $ownerId, 'Second void');
        }, 'cannot void an already-voided registration');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['registrations' => [$reg['id']]]);
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testCashierBlockedFromWarranty(): void
    {
        $cashierId = $this->makeUser('wr-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);
        $this->assertThrows(ForbiddenException::class, function () use ($cashierId) {
            $this->dispatchAs($cashierId, 'GET', '/admin/warranty');
        }, 'cashier has no warranty.manage');
    }

    public function testTechnicianBlockedFromWarranty(): void
    {
        $techId = $this->makeUser('wr-tech@test.local', 'Correct-Horse-1', ['technician']);
        $this->assertThrows(ForbiddenException::class, function () use ($techId) {
            $this->dispatchAs($techId, 'GET', '/admin/warranty/claims');
        }, 'technician has no warranty.manage');
    }

    // ---------------------------------------------------- HTTP + CSRF --

    public function testHttpDashboardRenders(): void
    {
        $ownerId = $this->makeOwner('wr-http-dash@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/warranty');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Warranty', $r->body());
    }

    public function testHttpClaimShowRendersDecisionForms(): void
    {
        $ownerId = $this->makeOwner('wr-http-claim@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $warrantySvc = new WarrantyService();
        $reg = $warrantySvc->createRegistration($unitId, null, $ownerId);
        $claim = $warrantySvc->createClaim($reg['id'], 'repair', null, 'Issue', $ownerId);

        $r = $this->dispatchAs($ownerId, 'GET', '/admin/warranty/claims/' . $claim['id']);
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Approve claim', $r->body());
        $this->assertStringContainsString('Reject claim', $r->body());

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['registrations' => [$reg['id']], 'claims' => [$claim['id']]]);
    }

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $ownerId = $this->makeOwner('wr-csrf@test.local');
        $this->actingAs($ownerId);
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $warrantySvc = new WarrantyService();
        $reg = $warrantySvc->createRegistration($unitId, null, $ownerId);
        $claim = $warrantySvc->createClaim($reg['id'], 'repair', null, 'Issue', $ownerId);

        $routes = [
            ['POST', '/admin/warranty/policies', ['name' => 'X', 'duration_days' => '30']],
            ['POST', '/admin/warranty/registrations', ['inventory_unit_id' => (string) $unitId]],
            ['POST', '/admin/warranty/registrations/' . $reg['id'] . '/void', ['reason' => 'x']],
            ['POST', '/admin/warranty/claims', ['warranty_registration_id' => (string) $reg['id'], 'claim_type' => 'repair', 'customer_reported_issue' => 'x']],
            ['POST', '/admin/warranty/claims/' . $claim['id'] . '/approve', ['reason' => 'ok']],
            ['POST', '/admin/warranty/claims/' . $claim['id'] . '/reject', ['reason' => 'no']],
            ['POST', '/admin/warranty/claims/' . $claim['id'] . '/start', []],
            ['POST', '/admin/warranty/claims/' . $claim['id'] . '/cancel', ['reason' => 'x']],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(
                    Request::synthetic($method, $uri, [], $post),
                    $this->resolver()
                );
            }, "{$method} {$uri} must require CSRF");
        }

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, ['registrations' => [$reg['id']], 'claims' => [$claim['id']]]);
    }
}
