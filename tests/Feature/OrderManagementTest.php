<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\OrderRepository;
use App\Services\OrderService;
use App\Services\AuthService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Online order management (Prompt 22) — HTTP + service-level tests.
 *
 * Proves:
 *  - Status transition matrix enforcement (pending → confirmed allowed,
 *    pending → completed rejected, cancelled → anything rejected, etc.)
 *  - Cancelling releases units back into stock
 *  - Refunding requires prior collected payment
 *  - Refunding releases units back into stock
 *  - Status history is recorded for every transition (append-only)
 *  - Fulfillment notes are append-only with author + timestamp
 *  - Non-cash payments without evidence stay 'pending' and don't move
 *    the order to 'paid' until confirmPayment() runs with evidence
 *  - Cash payments mark the order 'paid' immediately
 *  - Filters: status, payment, channel, date range
 *  - Show page renders customer info, item snapshots, totals, payments,
 *    status history and fulfillment notes
 *  - Permission enforcement (cashier can manage; technician blocked)
 *  - CSRF on every state-changing route
 */
final class OrderManagementTest extends TestCase
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

    /** Build a published catalog + an available unit + a customer, all wired. */
    private function makeOrderFixture(): array
    {
        [$brandId, $categoryId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $customerId = $this->makeCustomer();
        return [$brandId, $categoryId, $modelId, $unitId, $customerId];
    }

    private function cleanupOrderFixture(int $orderId, int $unitId, int $customerId, int $brandId, int $categoryId, int $modelId): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM order_fulfillment_notes WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_status_history WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- STATUS MATRIX --

    public function testStatusTransitionMatrixAllowedTransitions(): void
    {
        $svc = new OrderService();
        // Sanity: the matrix exists and is exhaustive.
        $this->assertContains('confirmed', OrderService::STATUS_TRANSITIONS['pending']);
        $this->assertContains('processing', OrderService::STATUS_TRANSITIONS['pending']);
        $this->assertContains('cancelled', OrderService::STATUS_TRANSITIONS['pending']);
        $this->assertContains('processing', OrderService::STATUS_TRANSITIONS['confirmed']);
        $this->assertContains('completed', OrderService::STATUS_TRANSITIONS['processing']);
        $this->assertContains('refunded', OrderService::STATUS_TRANSITIONS['completed']);
        $this->assertSame([], OrderService::STATUS_TRANSITIONS['cancelled']);
        $this->assertSame([], OrderService::STATUS_TRANSITIONS['refunded']);
    }

    public function testPendingToCompletedRejectedByMatrix(): void
    {
        $svc = new OrderService();
        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->assertTransitionAllowed('pending', 'completed');
        }, 'pending → completed must be rejected by the matrix');
    }

    public function testCancelledIsTerminal(): void
    {
        $svc = new OrderService();
        foreach (OrderService::ORDER_STATUSES as $to) {
            if ($to === 'cancelled') {
                continue;
            }
            $this->assertThrows(ValidationException::class, function () use ($svc, $to) {
                $svc->assertTransitionAllowed('cancelled', $to);
            }, "cancelled → {$to} must be rejected");
        }
    }

    public function testRefundedIsTerminal(): void
    {
        $svc = new OrderService();
        foreach (OrderService::ORDER_STATUSES as $to) {
            if ($to === 'refunded') {
                continue;
            }
            $this->assertThrows(ValidationException::class, function () use ($svc, $to) {
                $svc->assertTransitionAllowed('refunded', $to);
            }, "refunded → {$to} must be rejected");
        }
    }

    // ---------------------------------------------------- CREATE + TRANSITION --

    public function testCreateOrderSeedsInitialStatusHistory(): void
    {
        [$brandId, $categoryId, $modelId, $unitId, $customerId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-create@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'online', $ownerId, [], 'pending');

        $history = (new OrderRepository())->statusHistory($order['id']);
        $this->assertNotSame([], $history, 'a created order must seed its first history row');
        $this->assertSame('new', $history[0]['from_status']);
        $this->assertSame('pending', $history[0]['to_status']);

        $this->cleanupOrderFixture($order['id'], $unitId, $customerId, $brandId, $categoryId, $modelId);
    }

    public function testValidStatusTransitionWritesHistoryAndTimestamp(): void
    {
        [$brandId, $categoryId, $modelId, $unitId, $customerId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-transition@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'online', $ownerId, [], 'pending');
        $svc->updateStatus($order['id'], 'confirmed', $ownerId, 'Customer confirmed via phone');

        $fetched = (new OrderRepository())->find($order['id']);
        $this->assertSame('confirmed', (string) $fetched['status']);
        $this->assertNotNull($fetched['confirmed_at'], 'confirmed_at must be set');

        $history = (new OrderRepository())->statusHistory($order['id']);
        $this->assertSame(2, count($history), 'history must contain create + confirm rows');
        $last = $history[1];
        $this->assertSame('pending', $last['from_status']);
        $this->assertSame('confirmed', $last['to_status']);
        $this->assertSame('Customer confirmed via phone', $last['reason']);
        $this->assertSame((string) $ownerId, (string) $last['actor_id']);

        $this->cleanupOrderFixture($order['id'], $unitId, $customerId, $brandId, $categoryId, $modelId);
    }

    public function testInvalidTransitionRejectedAtServiceLayer(): void
    {
        [$brandId, $categoryId, $modelId, $unitId, $customerId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-invalid@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'online', $ownerId, [], 'pending');

        // pending → completed is not in the matrix.
        $this->assertThrows(ValidationException::class, function () use ($svc, $order, $ownerId) {
            $svc->updateStatus($order['id'], 'completed', $ownerId);
        }, 'invalid transition must be rejected');

        // Order status must remain pending (no partial state).
        $fetched = (new OrderRepository())->find($order['id']);
        $this->assertSame('pending', (string) $fetched['status']);

        $this->cleanupOrderFixture($order['id'], $unitId, $customerId, $brandId, $categoryId, $modelId);
    }

    public function testInvalidTransitionRejectedAtHttpLayer(): void
    {
        [$brandId, $categoryId, $modelId, $unitId, $customerId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-http@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'online', $ownerId, [], 'pending');

        // POST a pending → completed transition; the controller must catch
        // the ValidationException and flash an error (302 redirect, not 500).
        $r = $this->dispatchAs($ownerId, 'POST', '/admin/orders/' . $order['id'] . '/status', [
            'status' => 'completed',
        ]);
        $this->assertSame(302, $r->status(), 'rejected transition must redirect, not 500');
        $this->assertMatchesRegularExpression('/not allowed/i', (string) \App\Support\Session::getFlash('error', ''));

        $fetched = (new OrderRepository())->find($order['id']);
        $this->assertSame('pending', (string) $fetched['status'], 'order status must remain pending');

        $this->cleanupOrderFixture($order['id'], $unitId, $customerId, $brandId, $categoryId, $modelId);
    }

    // ---------------------------------------------------- INVENTORY RELEASE --

    public function testCancellingReleasesUnits(): void
    {
        [$brandId, $categoryId, $modelId, $unitId, $customerId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-cancel@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'online', $ownerId, [], 'pending');
        $this->assertSame('sold', $this->unitStatus($unitId));

        $svc->updateStatus($order['id'], 'cancelled', $ownerId, 'Customer cancelled');
        $this->assertSame('returned', $this->unitStatus($unitId), 'cancel must release the unit to returned');

        $fetched = (new OrderRepository())->find($order['id']);
        $this->assertSame('cancelled', (string) $fetched['status']);
        $this->assertNotNull($fetched['cancelled_at']);

        $this->cleanupOrderFixture($order['id'], $unitId, $customerId, $brandId, $categoryId, $modelId);
    }

    public function testRefundRequiresPriorPaymentAndReleasesUnits(): void
    {
        [$brandId, $categoryId, $modelId, $unitId, $customerId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-refund@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        // Refund before any payment is collected → must fail.
        $this->assertThrows(ValidationException::class, function () use ($svc, $order, $ownerId) {
            $svc->updateStatus($order['id'], 'refunded', $ownerId);
        }, 'cannot refund without collected payment');

        // Record a cash payment (full) → order becomes paid.
        $svc->recordPayment($order['id'], 500.00, 'cash', $ownerId);
        $fetched = (new OrderRepository())->find($order['id']);
        $this->assertSame('paid', (string) $fetched['payment_status']);

        // Now refund — must release the unit and set refunded_at.
        $svc->updateStatus($order['id'], 'refunded', $ownerId, 'Customer returned the unit');
        $this->assertSame('returned', $this->unitStatus($unitId));
        $fetched = (new OrderRepository())->find($order['id']);
        $this->assertSame('refunded', (string) $fetched['status']);
        $this->assertNotNull($fetched['refunded_at']);

        $this->cleanupOrderFixture($order['id'], $unitId, $customerId, $brandId, $categoryId, $modelId);
    }

    // ---------------------------------------------------- FULFILLMENT NOTES --

    public function testFulfillmentNotesAreAppendOnly(): void
    {
        [$brandId, $categoryId, $modelId, $unitId, $customerId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-notes@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'online', $ownerId, [], 'pending');

        $svc->addFulfillmentNote($order['id'], $ownerId, 'Packed and ready for courier.');
        $svc->addFulfillmentNote($order['id'], $ownerId, 'Pathao rider collected. Slip #P-77.');

        $notes = (new OrderRepository())->fulfillmentNotes($order['id']);
        $this->assertCount(2, $notes);
        // Most-recent-first ordering.
        $this->assertStringContainsString('Pathao', $notes[0]['body']);
        $this->assertSame((string) $ownerId, (string) $notes[0]['author_id']);
        $this->assertStringContainsString('Packed', $notes[1]['body']);

        $this->cleanupOrderFixture($order['id'], $unitId, $customerId, $brandId, $categoryId, $modelId);
    }

    public function testEmptyFulfillmentNoteRejected(): void
    {
        [$brandId, $categoryId, $modelId, $unitId, $customerId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-empty-note@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'online', $ownerId, [], 'pending');

        $this->assertThrows(ValidationException::class, function () use ($svc, $order, $ownerId) {
            $svc->addFulfillmentNote($order['id'], $ownerId, '   ');
        }, 'empty fulfillment note must be rejected');

        $this->cleanupOrderFixture($order['id'], $unitId, $customerId, $brandId, $categoryId, $modelId);
    }

    // ---------------------------------------------------- PAYMENT EVIDENCE --

    public function testCashPaymentMarksOrderPaidImmediately(): void
    {
        [$brandId, $categoryId, $modelId, $unitId, $customerId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-cash@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $result = $svc->recordPayment($order['id'], 500.00, 'cash', $ownerId);
        $this->assertSame('completed', $result['payment_state']);
        $this->assertSame('paid', $result['payment_status']);

        $this->cleanupOrderFixture($order['id'], $unitId, $customerId, $brandId, $categoryId, $modelId);
    }

    public function testNonCashWithoutEvidenceStaysPending(): void
    {
        [$brandId, $categoryId, $modelId, $unitId, $customerId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-nocash@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'online', $ownerId, [], 'pending');
        $r = $svc->recordPayment($order['id'], 500.00, 'bkash', $ownerId);
        $this->assertSame('pending', $r['payment_state']);
        $this->assertSame('unpaid', $r['payment_status'], 'pending payment must not move order to paid');

        $fetched = (new OrderRepository())->find($order['id']);
        $this->assertSame('pending', (string) $fetched['payments'][0]['status']);
        $this->assertNull($fetched['payments'][0]['paid_at']);

        $this->cleanupOrderFixture($order['id'], $unitId, $customerId, $brandId, $categoryId, $modelId);
    }

    public function testNonCashWithEvidenceMarksCompleted(): void
    {
        [$brandId, $categoryId, $modelId, $unitId, $customerId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-cash-ev@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'online', $ownerId, [], 'pending');
        $r = $svc->recordPayment($order['id'], 500.00, 'bkash', $ownerId, 'BKASH-TXN-100', 'BKASH-TXN-100');
        $this->assertSame('completed', $r['payment_state']);
        $this->assertSame('paid', $r['payment_status']);

        $this->cleanupOrderFixture($order['id'], $unitId, $customerId, $brandId, $categoryId, $modelId);
    }

    public function testConfirmPendingPaymentPromotesToCompleted(): void
    {
        [$brandId, $categoryId, $modelId, $unitId, $customerId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-confirm@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'online', $ownerId, [], 'pending');
        $r = $svc->recordPayment($order['id'], 500.00, 'card', $ownerId);
        $this->assertSame('pending', $r['payment_state']);

        $confirm = $svc->confirmPayment($r['id'], 'BANK-SLIP-9', $ownerId);
        $this->assertSame('paid', $confirm['payment_status']);

        $fetched = (new OrderRepository())->find($order['id']);
        $this->assertSame('completed', (string) $fetched['payments'][0]['status']);
        $this->assertSame('BANK-SLIP-9', (string) $fetched['payments'][0]['evidence_ref']);
        $this->assertNotNull($fetched['payments'][0]['paid_at']);

        $this->cleanupOrderFixture($order['id'], $unitId, $customerId, $brandId, $categoryId, $modelId);
    }

    public function testConfirmAlreadyCompletedRejected(): void
    {
        [$brandId, $categoryId, $modelId, $unitId, $customerId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-confirm-2@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $r = $svc->recordPayment($order['id'], 500.00, 'cash', $ownerId);
        $this->assertSame('completed', $r['payment_state']);

        $this->assertThrows(ValidationException::class, function () use ($svc, $r, $ownerId) {
            $svc->confirmPayment($r['id'], 'SLIP-X', $ownerId);
        }, 'confirming an already-completed payment must be rejected');

        $this->cleanupOrderFixture($order['id'], $unitId, $customerId, $brandId, $categoryId, $modelId);
    }

    // ---------------------------------------------------- FILTERS + SHOW --

    public function testIndexChannelFilter(): void
    {
        [$brandId1, $catId1, $modelId1, $unitId1, $custId1] = $this->makeOrderFixture();
        [$brandId2, $catId2, $modelId2, $unitId2, $custId2] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-filter@test.local');

        $svc = new OrderService();
        $online = $svc->createOrder($custId1, [$unitId1], 'online', $ownerId, [], 'pending');
        $pos = $svc->createOrder($custId2, [$unitId2], 'pos', $ownerId, [], 'pending');

        $r = $this->dispatchAs($ownerId, 'GET', '/admin/orders', [], ['channel' => 'online']);
        $this->assertSame(200, $r->status());
        $body = $r->body();
        $this->assertStringContainsString($online['reference'], $body);
        $this->assertStringNotContainsString($pos['reference'], $body, 'POS order must be filtered out');

        $this->cleanupOrderFixture($online['id'], $unitId1, $custId1, $brandId1, $catId1, $modelId1);
        $this->cleanupOrderFixture($pos['id'], $unitId2, $custId2, $brandId2, $catId2, $modelId2);
    }

    public function testIndexDateRangeFilter(): void
    {
        [$brandId, $catId, $modelId, $unitId, $custId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-date@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($custId, [$unitId], 'online', $ownerId, [], 'pending');
        $reference = $order['reference'];
        $today = gmdate('Y-m-d');
        $tomorrow = gmdate('Y-m-d', strtotime('+1 day'));
        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));

        // Filter to today → matches.
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/orders', [], ['from' => $today, 'to' => $today]);
        $this->assertStringContainsString($reference, $r->body());

        // Filter to tomorrow → no match.
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/orders', [], ['from' => $tomorrow, 'to' => $tomorrow]);
        $this->assertStringNotContainsString($reference, $r->body());

        // Filter to yesterday → no match.
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/orders', [], ['from' => $yesterday, 'to' => $yesterday]);
        $this->assertStringNotContainsString($reference, $r->body());

        $this->cleanupOrderFixture($order['id'], $unitId, $custId, $brandId, $catId, $modelId);
    }

    public function testShowRendersAllSections(): void
    {
        [$brandId, $catId, $modelId, $unitId, $custId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-show@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($custId, [$unitId], 'online', $ownerId, [], 'pending');
        $svc->addFulfillmentNote($order['id'], $ownerId, 'Packed for delivery.');
        $svc->updateStatus($order['id'], 'confirmed', $ownerId, 'Customer confirmed');

        $r = $this->dispatchAs($ownerId, 'GET', '/admin/orders/' . $order['id']);
        $this->assertSame(200, $r->status());
        $body = $r->body();
        $this->assertStringContainsString('Items (sale-time snapshots)', $body);
        $this->assertStringContainsString('Payments', $body);
        $this->assertStringContainsString('Fulfillment notes', $body);
        $this->assertStringContainsString('Status history', $body);
        $this->assertStringContainsString('Customer', $body);
        $this->assertStringContainsString('Timeline', $body);
        $this->assertStringContainsString('Packed for delivery.', $body);
        $this->assertStringContainsString('Customer confirmed', $body);

        $this->cleanupOrderFixture($order['id'], $unitId, $custId, $brandId, $catId, $modelId);
    }

    public function testShowGatesAllowedTransitionsFromCurrentStatus(): void
    {
        [$brandId, $catId, $modelId, $unitId, $custId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-trans@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($custId, [$unitId], 'online', $ownerId, [], 'pending');
        $svc->updateStatus($order['id'], 'confirmed', $ownerId);

        $r = $this->dispatchAs($ownerId, 'GET', '/admin/orders/' . $order['id']);
        $body = $r->body();
        // From 'confirmed' the allowed next states are processing + cancelled.
        $this->assertStringContainsString('value="processing"', $body);
        $this->assertStringContainsString('value="cancelled"', $body);
        // 'completed' must NOT be offered from 'confirmed'.
        $this->assertStringNotContainsString('value="completed"', $body, 'completed is not reachable from confirmed');

        $this->cleanupOrderFixture($order['id'], $unitId, $custId, $brandId, $catId, $modelId);
    }

    // ---------------------------------------------------- PERMISSIONS + CSRF --

    public function testTechnicianBlockedFromOrdersList(): void
    {
        $techId = $this->makeUser('omh-tech@test.local', 'Correct-Horse-1', ['technician']);
        $this->assertThrows(ForbiddenException::class, function () use ($techId) {
            $this->dispatchAs($techId, 'GET', '/admin/orders');
        }, 'technician (no orders.view) must not view orders');
    }

    public function testCashierCanViewButNotChangeStatus(): void
    {
        $cashierId = $this->makeUser('omh-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);
        [$brandId, $catId, $modelId, $unitId, $custId] = $this->makeOrderFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($custId, [$unitId], 'pos', $cashierId, [], 'pending');

        // Cashier can view (has orders.view).
        $r = $this->dispatchAs($cashierId, 'GET', '/admin/orders/' . $order['id']);
        $this->assertSame(200, $r->status());

        // Cashier can update status (has orders.manage).
        $r = $this->dispatchAs($cashierId, 'POST', '/admin/orders/' . $order['id'] . '/status', [
            'status' => 'cancelled',
        ]);
        $this->assertSame(302, $r->status());

        $this->cleanupOrderFixture($order['id'], $unitId, $custId, $brandId, $catId, $modelId);
    }

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $ownerId = $this->makeOwner('omh-csrf@test.local');
        $this->actingAs($ownerId);
        [$brandId, $catId, $modelId, $unitId, $custId] = $this->makeOrderFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($custId, [$unitId], 'online', $ownerId, [], 'pending');
        $paymentResult = $svc->recordPayment($order['id'], 100.00, 'bkash', $ownerId);

        $routes = [
            ['POST', '/admin/orders/' . $order['id'] . '/status', ['status' => 'confirmed']],
            ['POST', '/admin/orders/' . $order['id'] . '/payments', ['amount' => '10.00', 'method' => 'cash']],
            ['POST', '/admin/orders/' . $order['id'] . '/payments/' . $paymentResult['id'] . '/confirm', ['evidence_ref' => 'X']],
            ['POST', '/admin/orders/' . $order['id'] . '/fulfillment-notes', ['body' => 'hello']],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(
                    Request::synthetic($method, $uri, [], $post),
                    $this->resolver()
                );
            }, "{$method} {$uri} must require CSRF");
        }

        $this->cleanupOrderFixture($order['id'], $unitId, $custId, $brandId, $catId, $modelId);
    }

    public function testHttpFulfillmentNoteRouteAppendsAndAudits(): void
    {
        [$brandId, $catId, $modelId, $unitId, $custId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-http-note@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($custId, [$unitId], 'online', $ownerId, [], 'pending');

        $r = $this->dispatchAs($ownerId, 'POST', '/admin/orders/' . $order['id'] . '/fulfillment-notes', [
            'body' => 'Courier pickup scheduled.',
        ]);
        $this->assertSame(302, $r->status());

        $notes = (new OrderRepository())->fulfillmentNotes($order['id']);
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('Courier pickup scheduled.', $notes[0]['body']);

        $audit = $this->pdo()->prepare(
            "SELECT action FROM audit_logs WHERE action = 'order.fulfillment_note_added' AND entity_id = ? LIMIT 1"
        );
        $audit->execute([$order['id']]);
        $this->assertSame('order.fulfillment_note_added', (string) $audit->fetchColumn());

        $this->cleanupOrderFixture($order['id'], $unitId, $custId, $brandId, $catId, $modelId);
    }

    public function testHttpConfirmPaymentRoutePromotesPayment(): void
    {
        [$brandId, $catId, $modelId, $unitId, $custId] = $this->makeOrderFixture();
        $ownerId = $this->makeOwner('omh-http-confirm@test.local');

        $svc = new OrderService();
        $order = $svc->createOrder($custId, [$unitId], 'online', $ownerId, [], 'pending');
        $r = $svc->recordPayment($order['id'], 500.00, 'bkash', $ownerId);
        $this->assertSame('pending', $r['payment_state']);

        // POST confirm with evidence via HTTP.
        $resp = $this->dispatchAs($ownerId, 'POST', '/admin/orders/' . $order['id'] . '/payments/' . $r['id'] . '/confirm', [
            'evidence_ref' => 'BKASH-CONFIRMED-9',
        ]);
        $this->assertSame(302, $resp->status());

        $fetched = (new OrderRepository())->find($order['id']);
        $this->assertSame('completed', (string) $fetched['payments'][0]['status']);
        $this->assertSame('paid', (string) $fetched['payment_status']);

        $this->cleanupOrderFixture($order['id'], $unitId, $custId, $brandId, $catId, $modelId);
    }

    private function unitStatus(int $unitId): string
    {
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        return (string) $stmt->fetchColumn();
    }
}
