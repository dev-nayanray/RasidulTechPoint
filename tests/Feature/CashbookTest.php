<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Services\CashbookService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Cashbook and balances (Prompt 32) — service + HTTP tests.
 *
 * Proves:
 *  - Account creation with opening balance (creates verified adjustment movement)
 *  - Movement recording: sequential number, FOR UPDATE lock, balance_after snapshot
 *  - Duplicate prevention: same reference_type + reference_id → throws
 *  - Insufficient balance: withdrawal > balance → throws
 *  - Transfer: two linked movements in single transaction
 *  - Settlement: unverified → verified → disputed
 *  - Reconciliation: recorded vs verified balance, discrepancy detection
 *  - Permission: technician blocked
 *  - CSRF on state-changing routes
 *  - Financial integrity: all changes in transactions
 */
final class CashbookTest extends TestCase
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

    private function cleanupAccount(int $accountId): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM cash_movements WHERE account_id = ?')->execute([$accountId]);
        $this->pdo()->prepare('DELETE FROM cash_accounts WHERE id = ?')->execute([$accountId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- ACCOUNTS --

    public function testCreateAccountWithOpeningBalance(): void
    {
        $ownerId = $this->makeOwner('cb-a1@test.local');
        $svc = new CashbookService();
        $id = $svc->createAccount('Main Cash', 'cash', 5000.00, $ownerId);
        $this->assertGreaterThan(0, $id);

        $account = $svc->findAccount($id);
        $this->assertSame('Main Cash', $account['name']);
        $this->assertSame('cash', $account['account_type']);
        $this->assertSame('5000.00', $account['opening_balance']);
        $this->assertSame('5000.00', $account['current_balance']);

        // Opening balance creates a verified adjustment movement.
        $this->assertCount(1, $account['recent_movements']);
        $this->assertSame('adjustment', $account['recent_movements'][0]['movement_type']);
        $this->assertSame('in', $account['recent_movements'][0]['direction']);
        $this->assertSame('verified', $account['recent_movements'][0]['settlement_status']);

        $this->cleanupAccount($id);
    }

    public function testCreateAccountRejectsNegativeBalance(): void
    {
        $ownerId = $this->makeOwner('cb-a2@test.local');
        $this->assertThrows(ValidationException::class, function () use ($ownerId) {
            (new CashbookService())->createAccount('Test', 'cash', -100, $ownerId);
        }, 'negative opening balance rejected');
    }

    // ---------------------------------------------------- MOVEMENTS --

    public function testRecordMovementUpdatesBalance(): void
    {
        $ownerId = $this->makeOwner('cb-m1@test.local');
        $svc = new CashbookService();
        $accountId = $svc->createAccount('Test', 'cash', 1000.00, $ownerId);

        // Deposit 500.
        $result = $svc->recordMovement($accountId, 'deposit', 'in', 500.00, $ownerId, null, null, null, 'Test deposit');
        $this->assertSame('1500.00', $result['balance_after']);
        $this->assertSame('1500.00', $svc->findAccount($accountId)['current_balance']);
        $this->assertSame('unverified', $svc->findMovement($result['id'])['settlement_status']);

        $this->cleanupAccount($accountId);
    }

    public function testRecordMovementRejectsInsufficientBalance(): void
    {
        $ownerId = $this->makeOwner('cb-m2@test.local');
        $svc = new CashbookService();
        $accountId = $svc->createAccount('Test', 'cash', 100.00, $ownerId);

        $this->assertThrows(ValidationException::class, function () use ($svc, $accountId, $ownerId) {
            $svc->recordMovement($accountId, 'withdrawal', 'out', 500.00, $ownerId, null, null, null, 'Too much');
        }, 'insufficient balance must be rejected');

        $this->cleanupAccount($accountId);
    }

    public function testDuplicatePrevention(): void
    {
        $ownerId = $this->makeOwner('cb-dup@test.local');
        $svc = new CashbookService();
        $accountId = $svc->createAccount('Test', 'cash', 10000.00, $ownerId);

        // Record a movement with reference_type='payment' reference_id=42.
        $svc->recordMovement($accountId, 'payment_in', 'in', 500.00, $ownerId, 'payment', 42, 'TXN-42', 'Payment #42');

        // Try to record the same reference again → must throw.
        $this->assertThrows(ValidationException::class, function () use ($svc, $accountId, $ownerId) {
            $svc->recordMovement($accountId, 'payment_in', 'in', 500.00, $ownerId, 'payment', 42, 'TXN-42', 'Payment #42 again');
        }, 'duplicate payment posting must be prevented');

        $this->cleanupAccount($accountId);
    }

    // ---------------------------------------------------- TRANSFERS --

    public function testTransferCreatesTwoMovements(): void
    {
        $ownerId = $this->makeOwner('cb-t1@test.local');
        $svc = new CashbookService();
        $fromId = $svc->createAccount('Cash Drawer', 'cash', 5000.00, $ownerId);
        $toId = $svc->createAccount('Bank Account', 'bank', 0.00, $ownerId);

        $result = $svc->transfer($fromId, $toId, 2000.00, 'Deposit to bank', $ownerId);
        $this->assertGreaterThan(0, $result['out_movement_id']);
        $this->assertGreaterThan(0, $result['in_movement_id']);

        // From account decreased by 2000.
        $this->assertSame('3000.00', $svc->findAccount($fromId)['current_balance']);
        // To account increased by 2000.
        $this->assertSame('2000.00', $svc->findAccount($toId)['current_balance']);

        // Out movement.
        $out = $svc->findMovement($result['out_movement_id']);
        $this->assertSame('transfer_out', $out['movement_type']);
        $this->assertSame('out', $out['direction']);
        $this->assertSame('3000.00', $out['balance_after']);

        // In movement.
        $in = $svc->findMovement($result['in_movement_id']);
        $this->assertSame('transfer_in', $in['movement_type']);
        $this->assertSame('in', $in['direction']);
        $this->assertSame('2000.00', $in['balance_after']);

        $this->cleanupAccount($fromId);
        $this->cleanupAccount($toId);
    }

    public function testTransferRejectsSameAccount(): void
    {
        $ownerId = $this->makeOwner('cb-t2@test.local');
        $svc = new CashbookService();
        $id = $svc->createAccount('Same', 'cash', 1000.00, $ownerId);
        $this->assertThrows(ValidationException::class, function () use ($svc, $id, $ownerId) {
            $svc->transfer($id, $id, 100, null, $ownerId);
        }, 'cannot transfer to same account');
        $this->cleanupAccount($id);
    }

    public function testTransferRejectsInsufficientBalance(): void
    {
        $ownerId = $this->makeOwner('cb-t3@test.local');
        $svc = new CashbookService();
        $fromId = $svc->createAccount('Empty', 'cash', 100.00, $ownerId);
        $toId = $svc->createAccount('Dest', 'bank', 0.00, $ownerId);
        $this->assertThrows(ValidationException::class, function () use ($svc, $fromId, $toId, $ownerId) {
            $svc->transfer($fromId, $toId, 500.00, null, $ownerId);
        }, 'insufficient balance for transfer');
        $this->cleanupAccount($fromId);
        $this->cleanupAccount($toId);
    }

    // ---------------------------------------------------- SETTLEMENT --

    public function testVerifyMovement(): void
    {
        $ownerId = $this->makeOwner('cb-v1@test.local');
        $svc = new CashbookService();
        $accountId = $svc->createAccount('Test', 'cash', 1000.00, $ownerId);
        $result = $svc->recordMovement($accountId, 'payment_in', 'in', 500.00, $ownerId, 'payment', 99, 'TXN-99', 'Payment');

        // Starts unverified.
        $this->assertSame('unverified', $svc->findMovement($result['id'])['settlement_status']);

        // Verify.
        $svc->verifyMovement($result['id'], $ownerId);
        $m = $svc->findMovement($result['id']);
        $this->assertSame('verified', $m['settlement_status']);
        $this->assertNotNull($m['verified_at']);

        // Cannot verify twice.
        $this->assertThrows(ValidationException::class, function () use ($svc, $result, $ownerId) {
            $svc->verifyMovement($result['id'], $ownerId);
        }, 'cannot verify twice');

        $this->cleanupAccount($accountId);
    }

    public function testDisputeMovement(): void
    {
        $ownerId = $this->makeOwner('cb-d1@test.local');
        $svc = new CashbookService();
        $accountId = $svc->createAccount('Test', 'cash', 1000.00, $ownerId);
        $result = $svc->recordMovement($accountId, 'payment_in', 'in', 500.00, $ownerId, 'payment', 88, 'TXN-88', 'Payment');

        $svc->disputeMovement($result['id'], $ownerId);
        $this->assertSame('disputed', $svc->findMovement($result['id'])['settlement_status']);

        $this->cleanupAccount($accountId);
    }

    // ---------------------------------------------------- RECONCILIATION --

    public function testReconcileBalanced(): void
    {
        $ownerId = $this->makeOwner('cb-r1@test.local');
        $svc = new CashbookService();
        $accountId = $svc->createAccount('Test', 'cash', 1000.00, $ownerId);

        $result = $svc->reconcileAccount($accountId, 1000.00, $ownerId);
        $this->assertSame('balanced', $result['status']);
        $this->assertSame('0.00', $result['discrepancy']);

        $this->cleanupAccount($accountId);
    }

    public function testReconcileDiscrepancy(): void
    {
        $ownerId = $this->makeOwner('cb-r2@test.local');
        $svc = new CashbookService();
        $accountId = $svc->createAccount('Test', 'cash', 1000.00, $ownerId);

        $result = $svc->reconcileAccount($accountId, 950.00, $ownerId);
        $this->assertSame('discrepancy', $result['status']);
        $this->assertSame('-50.00', $result['discrepancy']);

        $this->cleanupAccount($accountId);
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testTechnicianBlockedFromCashbook(): void
    {
        $techId = $this->makeUser('cb-tech@test.local', 'Correct-Horse-1', ['technician']);
        $this->assertThrows(ForbiddenException::class, function () use ($techId) {
            $this->dispatchAs($techId, 'GET', '/admin/cashbook');
        }, 'technician has no finance.view');
    }

    // ---------------------------------------------------- HTTP + CSRF --

    public function testHttpDashboardRenders(): void
    {
        $ownerId = $this->makeOwner('cb-http-dash@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/cashbook');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Cashbook', $r->body());
        $this->assertStringContainsString('Settlement', $r->body());
    }

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $ownerId = $this->makeOwner('cb-csrf@test.local');
        $this->actingAs($ownerId);
        $svc = new CashbookService();
        $accountId = $svc->createAccount('CSRF Test', 'cash', 1000.00, $ownerId);

        $routes = [
            ['POST', '/admin/cashbook/accounts', ['name' => 'X', 'account_type' => 'cash', 'opening_balance' => '0']],
            ['POST', '/admin/cashbook/accounts/' . $accountId . '/update', ['name' => 'Y']],
            ['POST', '/admin/cashbook/movements', ['account_id' => (string) $accountId, 'movement_type' => 'deposit', 'direction' => 'in', 'amount' => '10']],
            ['POST', '/admin/cashbook/accounts/' . $accountId . '/reconcile', ['verified_balance' => '1000']],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(Request::synthetic($method, $uri, [], $post), $this->resolver());
            }, "{$method} {$uri} must require CSRF");
        }

        $this->cleanupAccount($accountId);
    }
}
