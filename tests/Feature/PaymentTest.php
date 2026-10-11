<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\PaymentRepository;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PosService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Payment records (Prompt 24) — service + HTTP tests.
 *
 * Proves:
 *  - assertNoCardSecurityData throws on cvv / cvc / pin / security_code
 *    / card_pin / cvv2 — defence-in-depth on every state-changing route
 *  - verify() promotes pending → completed, records verified_by +
 *    verified_at, recomputes order payment_status
 *  - refund() promotes completed → refunded, records refunded_by +
 *    refunded_at + refund_reason, recomputes order payment_status
 *  - refund on already-refunded payment is rejected
 *  - refund on pending payment is rejected (must verify first)
 *  - recordManualPayment records shift_id + ip_address + payment_metadata
 *  - Reconciliation batch: create with expected total auto-computed,
 *    confirm with actual, discrepancy sets status='discrepancy'
 *  - Duplicate reconciliation batch for same date rejected
 *  - Permission: cashier can verify but cannot refund; technician blocked
 *  - CSRF on every state-changing route
 *  - Never trust client-side: order.payment_status recomputed from
 *    actual completed payments, not from client's claim
 */
final class PaymentTest extends TestCase
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

    private function makeManager(string $email): int
    {
        $id = $this->makeUser($email, 'Correct-Horse-1', ['manager']);
        $this->actingAs($id);
        return $id;
    }

    private function makeCashier(string $email): int
    {
        $id = $this->makeUser($email, 'Correct-Horse-1', ['sales_cashier']);
        $this->actingAs($id);
        return $id;
    }

    private function makePaymentFixture(float $price = 1000.00): array
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => number_format($price, 2, '.', '')]);
        $customerId = $this->makeCustomer();
        return [$brandId, $catId, $modelId, $unitId, $customerId];
    }

    private function cleanupPaymentFixture(int $orderId, int $unitId, int $customerId, int $brandId, int $catId, int $modelId, ?int $shiftId = null, ?int $batchId = null): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        if ($batchId !== null) {
            $this->pdo()->prepare('DELETE FROM reconciliation_batches WHERE id = ?')->execute([$batchId]);
        }
        $this->pdo()->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_status_history WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_fulfillment_notes WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
        if ($shiftId !== null) {
            $this->pdo()->prepare('DELETE FROM cashier_shifts WHERE id = ?')->execute([$shiftId]);
        }
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- NO CARD SECURITY --

    public function testAssertNoCardSecurityDataRejectsCvv(): void
    {
        $svc = new PaymentService();
        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->assertNoCardSecurityData(['cvv' => '123']);
        }, 'cvv field must be rejected');
    }

    public function testAssertNoCardSecurityDataRejectsPin(): void
    {
        $svc = new PaymentService();
        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->assertNoCardSecurityData(['pin' => '4567']);
        }, 'pin field must be rejected');
    }

    public function testAssertNoCardSecurityDataRejectsCvcAndVariants(): void
    {
        $svc = new PaymentService();
        foreach (['cvc', 'cvn', 'security_code', 'card_pin', 'cvv2', 'cvc2'] as $field) {
            $this->assertThrows(ValidationException::class, function () use ($svc, $field) {
                $svc->assertNoCardSecurityData([$field => '999']);
            }, "{$field} must be rejected");
        }
    }

    public function testAssertNoCardSecurityDataAcceptsNormalPayload(): void
    {
        $svc = new PaymentService();
        // No exception means success.
        $svc->assertNoCardSecurityData([
            'amount' => '100.00',
            'method' => 'card',
            'reference' => 'CARD-12345',
            'evidence_ref' => 'BANK-SLIP-9',
        ]);
        $this->assertTrue(true);
    }

    public function testHttpVerifyRejectsCvvInPayload(): void
    {
        $ownerId = $this->makeOwner('pay-cvv@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $r = (new OrderService())->recordPayment($order['id'], 1000.00, 'card', $ownerId);
        $this->assertSame('pending', $r['payment_state']);

        $resp = $this->dispatchAs($ownerId, 'POST', '/admin/payments/' . $r['id'] . '/verify', [
            'evidence_ref' => 'BANK-SLIP-99',
            'cvv' => '123',  // forbidden
        ]);
        $this->assertSame(302, $resp->status(), 'cvv payload must be rejected');
        $this->assertMatchesRegularExpression('/card security code/i', (string) \App\Support\Session::getFlash('error', ''));

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    // ---------------------------------------------------- VERIFY --

    public function testVerifyPromotesPendingToCompleted(): void
    {
        $ownerId = $this->makeOwner('pay-verify@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $r = $svc->recordPayment($order['id'], 1000.00, 'bkash', $ownerId);
        $this->assertSame('pending', $r['payment_state']);

        $result = (new PaymentService())->verify($r['id'], 'BKASH-SLIP-77', $ownerId);
        $this->assertSame('completed', $result['status']);
        $this->assertSame($ownerId, $result['verified_by']);

        $payment = (new PaymentRepository())->find($r['id']);
        $this->assertSame('completed', (string) $payment['status']);
        $this->assertNotNull($payment['verified_at']);
        $this->assertSame((string) $ownerId, (string) $payment['verified_by']);

        // Order payment_status recomputed server-side.
        $orderRow = (new \App\Repositories\OrderRepository())->find($order['id']);
        $this->assertSame('paid', (string) $orderRow['payment_status'], 'order must be paid after the verify');

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testVerifyAlreadyCompletedRejected(): void
    {
        $ownerId = $this->makeOwner('pay-verify2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $r = $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);
        $this->assertSame('completed', $r['payment_state']);

        $this->assertThrows(ValidationException::class, function () use ($r, $ownerId) {
            (new PaymentService())->verify($r['id'], 'CASH-SLIP-X', $ownerId);
        }, 'verifying an already-completed payment must be rejected');

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    // ---------------------------------------------------- REFUND --

    public function testRefundPromotesCompletedToRefunded(): void
    {
        $ownerId = $this->makeOwner('pay-refund@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $r = $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);

        $result = (new PaymentService())->refund($r['id'], 'Customer returned the unit', $ownerId);
        $this->assertSame('refunded', $result['status']);
        $this->assertSame($ownerId, $result['refunded_by']);

        $payment = (new PaymentRepository())->find($r['id']);
        $this->assertSame('refunded', (string) $payment['status']);
        $this->assertNotNull($payment['refunded_at']);
        $this->assertSame('Customer returned the unit', (string) $payment['refund_reason']);

        // Order payment_status recomputed: no completed payments left → unpaid.
        $orderRow = (new \App\Repositories\OrderRepository())->find($order['id']);
        $this->assertSame('unpaid', (string) $orderRow['payment_status']);

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testRefundAlreadyRefundedRejected(): void
    {
        $ownerId = $this->makeOwner('pay-refund2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $r = $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);
        $pay = new PaymentService();
        $pay->refund($r['id'], 'First refund', $ownerId);

        $this->assertThrows(ValidationException::class, function () use ($pay, $r, $ownerId) {
            $pay->refund($r['id'], 'Second refund', $ownerId);
        }, 'refunding a refunded payment must be rejected');

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testRefundPendingPaymentRejected(): void
    {
        $ownerId = $this->makeOwner('pay-refund3@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $r = $svc->recordPayment($order['id'], 1000.00, 'bkash', $ownerId);
        $this->assertSame('pending', $r['payment_state']);

        $this->assertThrows(ValidationException::class, function () use ($r, $ownerId) {
            (new PaymentService())->refund($r['id'], 'Refund before verification', $ownerId);
        }, 'refunding a pending payment must be rejected — verify first');

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    // ---------------------------------------------------- MANUAL PAYMENT --

    public function testRecordManualPaymentAttachesShiftAndMetadata(): void
    {
        $ownerId = $this->makeOwner('pay-manual@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $pos = new PosService();
        $shift = $pos->startShift($ownerId, 0.00);

        $pay = new PaymentService();
        $result = $pay->recordManualPayment(
            $order['id'], 1000.00, 'cash', $ownerId,
            null, null,
            (int) $shift['id'],
            '127.0.0.1',
            ['note' => 'till 3'],
        );
        $this->assertSame('completed', $result['payment_state']);

        $payment = (new PaymentRepository())->find($result['id']);
        $this->assertSame((string) $shift['id'], (string) $payment['shift_id']);
        $this->assertSame('127.0.0.1', (string) $payment['ip_address']);
        $this->assertNotNull($payment['payment_metadata']);

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, (int) $shift['id']);
    }

    public function testRecordManualPaymentRejectsCvvInMetadata(): void
    {
        $ownerId = $this->makeOwner('pay-manual-cvv@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        $this->assertThrows(ValidationException::class, function () use ($order, $ownerId) {
            (new PaymentService())->recordManualPayment(
                $order['id'], 1000.00, 'card', $ownerId,
                null, null, null, '127.0.0.1',
                ['cvv' => '123'],
            );
        }, 'metadata containing cvv must be rejected');

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    // ---------------------------------------------------- RECONCILIATION --

    private function isolatePaymentDate(int $paymentId, string $date): void
    {
        // Force a payment's paid_at to a specific date so the daily
        // reconciliation batch (which sums by DATE(paid_at)) sees only
        // this test's payment and not leftover rows from earlier test
        // methods that share today's date.
        $this->pdo()->prepare('UPDATE payments SET paid_at = ? WHERE id = ?')
            ->execute([$date . ' 12:00:00', $paymentId]);
    }

    public function testReconciliationBatchCreateAndConfirmPerfect(): void
    {
        $ownerId = $this->makeOwner('pay-recon1@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $pay = $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);
        $date = '2024-06-' . str_pad((string) random_int(10, 28), 2, '0', STR_PAD_LEFT);
        $this->isolatePaymentDate($pay['id'], $date);

        $batch = (new PaymentService())->createReconciliationBatch($date, $ownerId, 'Daily close');
        $this->assertSame('1000.00', $batch['expected_total']);
        $this->assertSame(1, $batch['payment_count']);

        $confirmed = (new PaymentService())->confirmReconciliationBatch($batch['id'], 1000.00, $ownerId, 'Cash counted');
        $this->assertSame('confirmed', $confirmed['status']);

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, null, $batch['id']);
    }

    public function testReconciliationBatchFlagsDiscrepancy(): void
    {
        $ownerId = $this->makeOwner('pay-recon2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $pay = $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);
        $date = '2024-07-' . str_pad((string) random_int(10, 28), 2, '0', STR_PAD_LEFT);
        $this->isolatePaymentDate($pay['id'], $date);

        $batch = (new PaymentService())->createReconciliationBatch($date, $ownerId);
        $confirmed = (new PaymentService())->confirmReconciliationBatch($batch['id'], 950.00, $ownerId, '50 short');
        $this->assertSame('discrepancy', $confirmed['status']);
        $this->assertSame('-50.00', $confirmed['discrepancy']);

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, null, $batch['id']);
    }

    public function testDuplicateReconciliationBatchRejected(): void
    {
        $ownerId = $this->makeOwner('pay-recon3@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $pay = $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);
        $date = '2024-08-' . str_pad((string) random_int(10, 28), 2, '0', STR_PAD_LEFT);
        $this->isolatePaymentDate($pay['id'], $date);

        $svc2 = new PaymentService();
        $first = $svc2->createReconciliationBatch($date, $ownerId);

        $this->assertThrows(ValidationException::class, function () use ($svc2, $date, $ownerId) {
            $svc2->createReconciliationBatch($date, $ownerId);
        }, 'cannot create a second batch for the same date');

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, null, $first['id']);
    }

    // ---------------------------------------------------- NEVER TRUST CLIENT --

    public function testOrderPaymentStatusRecomputedFromServerNotClient(): void
    {
        $ownerId = $this->makeOwner('pay-server@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        // Record a pending non-cash payment. The client might CLAIM the
        // order is paid, but the server keeps it 'unpaid' until the
        // payment is verified.
        $r = $svc->recordPayment($order['id'], 1000.00, 'bkash', $ownerId);
        $this->assertSame('pending', $r['payment_state']);

        $orderRow = (new \App\Repositories\OrderRepository())->find($order['id']);
        $this->assertSame('unpaid', (string) $orderRow['payment_status'], 'server must not trust the pending payment');

        // After verification, the server flips payment_status to 'paid'.
        (new PaymentService())->verify($r['id'], 'BKASH-SLIP-X', $ownerId);
        $orderRow = (new \App\Repositories\OrderRepository())->find($order['id']);
        $this->assertSame('paid', (string) $orderRow['payment_status']);

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testCashierCanVerifyButNotRefund(): void
    {
        $cashierId = $this->makeCashier('pay-cashier@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $cashierId, [], 'completed');
        $r = $svc->recordPayment($order['id'], 1000.00, 'bkash', $cashierId);
        $this->assertSame('pending', $r['payment_state']);

        // Cashier can verify (has payments.manage).
        $resp = $this->dispatchAs($cashierId, 'POST', '/admin/payments/' . $r['id'] . '/verify', [
            'evidence_ref' => 'BKASH-VERIFIED-1',
        ]);
        $this->assertSame(302, $resp->status());

        // Cashier cannot refund (no payments.refund).
        $svc->recordPayment($order['id'], 500.00, 'cash', $cashierId); // partial cash completed
        // Find the cash payment id, then try to refund it.
        $stmt = $this->pdo()->prepare('SELECT id FROM payments WHERE order_id = ? AND method = "cash"');
        $stmt->execute([$order['id']]);
        $cashPaymentId = (int) $stmt->fetchColumn();

        // Cashier (no payments.refund) must throw ForbiddenException at the
        // middleware layer. The exception propagates from Router::dispatch
        // (the front controller would convert to a 403 response in prod).
        $this->assertThrows(ForbiddenException::class, function () use ($cashierId, $cashPaymentId) {
            $this->dispatchAs($cashierId, 'POST', '/admin/payments/' . $cashPaymentId . '/refund', [
                'reason' => 'Customer returned',
            ]);
        }, 'cashier without payments.refund must be blocked');

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testTechnicianBlockedFromPayments(): void
    {
        $techId = $this->makeUser('pay-tech@test.local', 'Correct-Horse-1', ['technician']);
        $this->assertThrows(ForbiddenException::class, function () use ($techId) {
            $this->dispatchAs($techId, 'GET', '/admin/payments');
        }, 'technician has no payments.view');
    }

    // ---------------------------------------------------- HTTP ROUTES --

    public function testHttpIndexRendersFilters(): void
    {
        $ownerId = $this->makeOwner('pay-index@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);

        $resp = $this->dispatchAs($ownerId, 'GET', '/admin/payments', [], ['status' => 'completed']);
        $this->assertSame(200, $resp->status());
        $this->assertStringContainsString('Payments', $resp->body());

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testHttpShowRendersVerifyAndRefundForms(): void
    {
        $ownerId = $this->makeOwner('pay-show@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $r = $svc->recordPayment($order['id'], 1000.00, 'bkash', $ownerId);

        $resp = $this->dispatchAs($ownerId, 'GET', '/admin/payments/' . $r['id']);
        $this->assertSame(200, $resp->status());
        $this->assertStringContainsString('Verify this payment', $resp->body());

        // After verification, the refund form appears.
        (new PaymentService())->verify($r['id'], 'BKASH-SLIP-X', $ownerId);
        $resp = $this->dispatchAs($ownerId, 'GET', '/admin/payments/' . $r['id']);
        $this->assertStringContainsString('Issue a refund', $resp->body());

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testHttpPendingQueueRenders(): void
    {
        $ownerId = $this->makeOwner('pay-pending@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $r = $svc->recordPayment($order['id'], 1000.00, 'bkash', $ownerId);
        $this->assertSame('pending', $r['payment_state']);

        $resp = $this->dispatchAs($ownerId, 'GET', '/admin/payments/pending');
        $this->assertSame(200, $resp->status());
        $this->assertStringContainsString('verification queue', $resp->body());

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testHttpReconciliationCreateAndConfirm(): void
    {
        $ownerId = $this->makeOwner('pay-recon-http@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);

        $date = gmdate('Y-m-d');
        $resp = $this->dispatchAs($ownerId, 'POST', '/admin/payments/reconciliation', [
            'batch_date' => $date,
            'notes' => 'Daily close',
        ]);
        $this->assertSame(302, $resp->status());

        $batchId = (int) $this->pdo()->query("SELECT id FROM reconciliation_batches WHERE batch_date = '{$date}'")->fetchColumn();

        $resp = $this->dispatchAs($ownerId, 'POST', '/admin/payments/reconciliation/' . $batchId . '/confirm', [
            'actual_total' => '1000.00',
            'notes' => 'Cash counted',
        ]);
        $this->assertSame(302, $resp->status());

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, null, $batchId);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $ownerId = $this->makeOwner('pay-csrf@test.local');
        $this->actingAs($ownerId);
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makePaymentFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $r = $svc->recordPayment($order['id'], 1000.00, 'bkash', $ownerId);
        $date = gmdate('Y-m-d');
        $batch = (new PaymentService())->createReconciliationBatch($date, $ownerId);

        $routes = [
            ['POST', '/admin/payments/' . $r['id'] . '/verify', ['evidence_ref' => 'X']],
            ['POST', '/admin/payments/' . $r['id'] . '/refund', ['reason' => 'x']], // would fail because status=pending, but CSRF fails first
            ['POST', '/admin/payments/reconciliation', ['batch_date' => $date]],
            ['POST', '/admin/payments/reconciliation/' . $batch['id'] . '/confirm', ['actual_total' => '0']],
            ['POST', '/admin/payments/methods/1/update', ['label' => 'Cash']],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(
                    Request::synthetic($method, $uri, [], $post),
                    $this->resolver()
                );
            }, "{$method} {$uri} must require CSRF");
        }

        $this->cleanupPaymentFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, null, $batch['id']);
    }
}
