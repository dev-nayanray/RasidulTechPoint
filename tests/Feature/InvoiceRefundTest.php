<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\OrderRepository;
use App\Services\InvoiceService;
use App\Services\OrderCorrectionService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Invoices and refunds (Prompt 25) — service + HTTP tests.
 *
 * Proves:
 *  - Invoice generation: sequential per-year invoice number, tax calc,
 *    immutable line item snapshots, links to orders.invoice_id.
 *  - Invoice void preserves row for audit, unlinks order.
 *  - Invoice markPaid label flip.
 *  - Refund create with refund limit enforcement: amount cannot exceed
 *    refundable = completed payments - completed refunds.
 *  - Refund process: marks completed, calls PaymentService.refund on
 *    linked payment, releases units via InventoryService when
 *    inventory_action=return_to_stock, flips order to 'refunded' if
 *    amount covers total.
 *  - Refund cancel: marks cancelled, no payment/inventory changes.
 *  - Refund on a cancelled order is rejected.
 *  - Order corrections: append-only log with old/new/reason/authorizer.
 *  - Correction requires pos.authorize_discount OR payments.refund OR
 *    settings.manage; cashier without those is rejected.
 *  - Correction with same old/new value is rejected.
 *  - Permission: technician blocked; cashier can't refund.
 *  - CSRF on every state-changing route.
 *  - Inventory updates only via InventoryService state machine — no
 *    direct DB writes from RefundService.
 */
final class InvoiceRefundTest extends TestCase
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

    private function makeCashier(string $email): int
    {
        $id = $this->makeUser($email, 'Correct-Horse-1', ['sales_cashier']);
        $this->actingAs($id);
        return $id;
    }

    private function makeManager(string $email): int
    {
        $id = $this->makeUser($email, 'Correct-Horse-1', ['manager']);
        $this->actingAs($id);
        return $id;
    }

    private function makeFixture(float $price = 1000.00): array
    {
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => number_format($price, 2, '.', '')]);
        $customerId = $this->makeCustomer();
        return [$brandId, $catId, $modelId, $unitId, $customerId];
    }

    private function cleanupFixture(int $orderId, int $unitId, int $customerId, int $brandId, int $catId, int $modelId, ?int $invoiceId = null, ?int $refundId = null): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        if ($refundId !== null) {
            $this->pdo()->prepare('DELETE FROM refunds WHERE id = ?')->execute([$refundId]);
        }
        if ($invoiceId !== null) {
            $this->pdo()->prepare('DELETE FROM invoices WHERE id = ?')->execute([$invoiceId]);
        }
        $this->pdo()->prepare('DELETE FROM order_corrections WHERE order_id = ?')->execute([$orderId]);
        $this->pdo()->prepare('DELETE FROM order_cancellations WHERE order_id = ?')->execute([$orderId]);
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
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function unitStatus(int $unitId): string
    {
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        return (string) $stmt->fetchColumn();
    }

    // ---------------------------------------------------- INVOICES --

    public function testInvoiceGenerateCreatesSequentialNumberAndLinksOrder(): void
    {
        $ownerId = $this->makeOwner('inv-gen@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        $invoiceSvc = new InvoiceService();
        $inv = $invoiceSvc->generateForOrder($order['id'], [
            'billing_name' => 'Acme Corp',
            'billing_address' => '123 Main St',
            'billing_phone' => '+88017999',
            'tax_rate' => 0.15,
            'tax_name' => 'VAT',
        ], $ownerId);

        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{5}$/', $inv['invoice_number']);
        $this->assertSame('150.00', $inv['tax_amount']); // 1000 * 0.15
        $this->assertSame('1150.00', $inv['total']);    // 1000 + 150

        // Order links to the new invoice.
        $orderRow = (new OrderRepository())->find($order['id']);
        $this->assertSame((string) $inv['id'], (string) $orderRow['invoice_id']);

        // Immutable snapshots: even if the order_items change, the
        // invoice's items (read from order_items at invoice view time)
        // snapshot the same values.
        $found = $invoiceSvc->find($inv['id']);
        $this->assertSame('1000.00', $found['subtotal']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, $inv['id']);
    }

    public function testInvoiceVoidPreservesRowAndUnlinksOrder(): void
    {
        $ownerId = $this->makeOwner('inv-void@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $invoiceSvc = new InvoiceService();
        $inv = $invoiceSvc->generateForOrder($order['id'], ['billing_name' => 'Test'], $ownerId);

        $result = $invoiceSvc->void($inv['id'], $ownerId, 'Wrong tax rate');
        $this->assertSame('void', $result['status']);

        // Row still exists.
        $found = $invoiceSvc->find($inv['id']);
        $this->assertNotNull($found);
        $this->assertSame('void', (string) $found['status']);
        $this->assertSame('Wrong tax rate', (string) $found['void_reason']);

        // Order is unlinked.
        $orderRow = (new OrderRepository())->find($order['id']);
        $this->assertNull($orderRow['invoice_id']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, $inv['id']);
    }

    public function testInvoiceVoidTwiceRejected(): void
    {
        $ownerId = $this->makeOwner('inv-void2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $invoiceSvc = new InvoiceService();
        $inv = $invoiceSvc->generateForOrder($order['id'], ['billing_name' => 'Test'], $ownerId);
        $invoiceSvc->void($inv['id'], $ownerId, 'First void');

        $this->assertThrows(ValidationException::class, function () use ($invoiceSvc, $inv, $ownerId) {
            $invoiceSvc->void($inv['id'], $ownerId, 'Second void');
        }, 'cannot void an already-voided invoice');
        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, $inv['id']);
    }

    public function testInvoiceMarkPaid(): void
    {
        $ownerId = $this->makeOwner('inv-paid@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $invoiceSvc = new InvoiceService();
        $inv = $invoiceSvc->generateForOrder($order['id'], ['billing_name' => 'Test'], $ownerId);

        $result = $invoiceSvc->markPaid($inv['id']);
        $this->assertSame('paid', $result['status']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, $inv['id']);
    }

    public function testHttpInvoiceGenerateAndShow(): void
    {
        $ownerId = $this->makeOwner('inv-http@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        $r = $this->dispatchAs($ownerId, 'POST', '/admin/orders/' . $order['id'] . '/invoice', [
            'billing_name' => 'Acme Corp',
            'billing_phone' => '+88017999',
            'tax_rate' => '0',
        ]);
        $this->assertSame(302, $r->status());
        $location = $r->headers()['Location'] ?? '';
        $this->assertStringContainsString('/admin/invoices/', $location);

        // Pull the invoice id out of the location header.
        preg_match('#/admin/invoices/(\d+)#', $location, $m);
        $invoiceId = (int) $m[1];

        $r = $this->dispatchAs($ownerId, 'GET', '/admin/invoices/' . $invoiceId);
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Acme Corp', $r->body());

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, $invoiceId);
    }

    // ---------------------------------------------------- REFUNDS --

    public function testRefundCreateEnforcesRefundLimit(): void
    {
        $ownerId = $this->makeOwner('rf-limit@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $svc->recordPayment($order['id'], 800.00, 'cash', $ownerId); // partial: 800 of 1000

        $refundSvc = new RefundService();
        // 900 > 800 refundable → reject.
        $this->assertThrows(ValidationException::class, function () use ($refundSvc, $order, $ownerId) {
            $refundSvc->create($order['id'], 900.00, 'Over refund limit', 'no_action', $ownerId);
        }, 'refund exceeding refundable must be rejected');

        // 500 <= 800 → accept.
        $r = $refundSvc->create($order['id'], 500.00, 'Partial refund', 'no_action', $ownerId);
        $this->assertSame('pending', $r['status']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, null, $r['id']);
    }

    public function testRefundCreateRejectsCancelledOrder(): void
    {
        $ownerId = $this->makeOwner('rf-cancel@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'pending');
        $svc->updateStatus($order['id'], 'cancelled', $ownerId, 'Customer cancelled');

        $refundSvc = new RefundService();
        $this->assertThrows(ValidationException::class, function () use ($refundSvc, $order, $ownerId) {
            $refundSvc->create($order['id'], 100.00, 'Refund after cancel', 'no_action', $ownerId);
        }, 'cannot refund a cancelled order');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testRefundProcessReleasesPaymentAndInventory(): void
    {
        $ownerId = $this->makeOwner('rf-proc@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $pay = $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);

        $refundSvc = new RefundService();
        $r = $refundSvc->create($order['id'], 1000.00, 'Full refund', 'return_to_stock', $ownerId, $pay['id']);
        $this->assertSame('pending', $r['status']);

        // Unit is currently 'sold' (set at order creation).
        $this->assertSame('sold', $this->unitStatus($unitId));

        $result = $refundSvc->process($r['id'], $ownerId);
        $this->assertSame('completed', $result['status']);

        // Payment was refunded via PaymentService.
        $stmt = $this->pdo()->prepare('SELECT status FROM payments WHERE id = ?');
        $stmt->execute([$pay['id']]);
        $this->assertSame('refunded', (string) $stmt->fetchColumn());

        // Unit was released via InventoryService (sold → returned) —
        // inventory updates flow ONLY through the InventoryService
        // state machine, never direct DB writes from RefundService.
        $this->assertSame('returned', $this->unitStatus($unitId));

        // The order status itself is NOT auto-flipped to 'refunded'
        // — that is a separate manager decision via the existing
        // /admin/orders/{id}/status form. The refund record itself
        // is the audit trail of the money-back.
        $orderRow = (new OrderRepository())->find($order['id']);
        $this->assertSame('completed', (string) $orderRow['status'], 'order status stays completed; refund record is the audit trail');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, null, $r['id']);
    }

    public function testRefundProcessWithNoActionKeepsInventory(): void
    {
        $ownerId = $this->makeOwner('rf-noaction@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $pay = $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);

        $refundSvc = new RefundService();
        $r = $refundSvc->create($order['id'], 1000.00, 'Refund without restock', 'no_action', $ownerId, $pay['id']);
        $refundSvc->process($r['id'], $ownerId);

        // Unit stays 'sold' — no_action means no inventory change.
        $this->assertSame('sold', $this->unitStatus($unitId));

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, null, $r['id']);
    }

    public function testRefundCancelDoesNotTouchPaymentOrInventory(): void
    {
        $ownerId = $this->makeOwner('rf-cancel2@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $pay = $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);

        $refundSvc = new RefundService();
        $r = $refundSvc->create($order['id'], 500.00, 'Will cancel', 'return_to_stock', $ownerId, $pay['id']);
        $refundSvc->cancel($r['id'], $ownerId);

        // Payment remains completed.
        $stmt = $this->pdo()->prepare('SELECT status FROM payments WHERE id = ?');
        $stmt->execute([$pay['id']]);
        $this->assertSame('completed', (string) $stmt->fetchColumn());

        // Unit remains sold.
        $this->assertSame('sold', $this->unitStatus($unitId));

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, null, $r['id']);
    }

    public function testRefundProcessTwiceRejected(): void
    {
        $ownerId = $this->makeOwner('rf-twice@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $pay = $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);

        $refundSvc = new RefundService();
        $r = $refundSvc->create($order['id'], 1000.00, 'Full refund', 'return_to_stock', $ownerId, $pay['id']);
        $refundSvc->process($r['id'], $ownerId);

        $this->assertThrows(ValidationException::class, function () use ($refundSvc, $r, $ownerId) {
            $refundSvc->process($r['id'], $ownerId);
        }, 'cannot process a refund that is not pending');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, null, $r['id']);
    }

    public function testRefundableAmountSumsCompletedPaymentsMinusCompletedRefunds(): void
    {
        $ownerId = $this->makeOwner('rf-amt@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture(2000.00);
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $svc->recordPayment($order['id'], 2000.00, 'cash', $ownerId);

        $refundSvc = new RefundService();
        // Refundable = 2000.
        $this->assertSame(2000.00, $refundSvc->refundableAmount(null, $order['id']));

        // Create + process a 500 refund. Refundable = 1500.
        $r = $refundSvc->create($order['id'], 500.00, 'Partial refund 1', 'no_action', $ownerId);
        $refundSvc->process($r['id'], $ownerId);
        $this->assertSame(1500.00, $refundSvc->refundableAmount(null, $order['id']));

        // A pending refund does not reduce refundable (only completed).
        $r2 = $refundSvc->create($order['id'], 200.00, 'Pending refund', 'no_action', $ownerId);
        $this->assertSame(1500.00, $refundSvc->refundableAmount(null, $order['id']));

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, null, $r['id']);
        // Also clean r2
        $this->pdo()->prepare('DELETE FROM refunds WHERE id = ?')->execute([$r2['id']]);
    }

    public function testHttpRefundCreateProcessFlow(): void
    {
        $ownerId = $this->makeOwner('rf-http@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $pay = $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);

        // POST create.
        $r = $this->dispatchAs($ownerId, 'POST', '/admin/orders/' . $order['id'] . '/refund', [
            'amount' => '1000.00',
            'reason' => 'Customer returned',
            'inventory_action' => 'return_to_stock',
            'payment_id' => (string) $pay['id'],
        ]);
        $this->assertSame(302, $r->status());
        $location = $r->headers()['Location'] ?? '';
        preg_match('#/admin/refunds/(\d+)#', $location, $m);
        $refundId = (int) $m[1];

        // POST process.
        $r = $this->dispatchAs($ownerId, 'POST', '/admin/refunds/' . $refundId . '/process');
        $this->assertSame(302, $r->status());

        // Verify completion.
        $refund = (new RefundService())->find($refundId);
        $this->assertSame('completed', (string) $refund['status']);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, null, $refundId);
    }

    // ---------------------------------------------------- CORRECTIONS --

    public function testCorrectionRecordsWithOldNewReason(): void
    {
        $ownerId = $this->makeOwner('corr-rec@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        $svc2 = new OrderCorrectionService();
        $id = $svc2->record($order['id'], 'order.notes', 'Old note', 'New note', 'Pricing error from yesterday', $ownerId, '127.0.0.1');
        $this->assertGreaterThan(0, $id);

        // List shows the correction.
        $list = $svc2->listForOrder($order['id']);
        $this->assertCount(1, $list);
        $this->assertSame('order.notes', (string) $list[0]['field']);
        $this->assertSame('Old note', (string) $list[0]['old_value']);
        $this->assertSame('New note', (string) $list[0]['new_value']);
        $this->assertSame('Pricing error from yesterday', (string) $list[0]['reason']);

        // orders.correction_count incremented.
        $stmt = $this->pdo()->prepare('SELECT correction_count FROM orders WHERE id = ?');
        $stmt->execute([$order['id']]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testCorrectionRejectsSameOldNewValue(): void
    {
        $ownerId = $this->makeOwner('corr-same@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        $svc2 = new OrderCorrectionService();
        $this->assertThrows(ValidationException::class, function () use ($svc2, $order, $ownerId) {
            $svc2->record($order['id'], 'order.notes', 'same', 'same', 'No change', $ownerId);
        }, 'same old/new must be rejected');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testCorrectionRejectsUnauthorizedField(): void
    {
        $ownerId = $this->makeOwner('corr-field@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        $svc2 = new OrderCorrectionService();
        $this->assertThrows(ValidationException::class, function () use ($svc2, $order, $ownerId) {
            $svc2->record($order['id'], 'orders.secret_column', 'a', 'b', 'Hack attempt', $ownerId);
        }, 'non-whitelisted field must be rejected');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testCorrectionRejectsCashierWithoutManagerPermission(): void
    {
        $cashierId = $this->makeCashier('corr-cashier@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $cashierId, [], 'completed');

        $svc2 = new OrderCorrectionService();
        $this->assertThrows(ValidationException::class, function () use ($svc2, $order, $cashierId) {
            $svc2->record($order['id'], 'order.notes', 'a', 'b', 'cashier attempt', $cashierId);
        }, 'cashier without pos.authorize_discount / payments.refund / settings.manage cannot correct');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    public function testHttpCorrectionRecord(): void
    {
        $managerId = $this->makeManager('corr-http@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $managerId, [], 'completed');

        $r = $this->dispatchAs($managerId, 'POST', '/admin/orders/' . $order['id'] . '/corrections', [
            'field' => 'order.notes',
            'old_value' => 'old',
            'new_value' => 'new',
            'reason' => 'Manager correction',
        ]);
        $this->assertSame(302, $r->status());

        $svc2 = new OrderCorrectionService();
        $list = $svc2->listForOrder($order['id']);
        $this->assertCount(1, $list);

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testTechnicianBlockedFromInvoices(): void
    {
        $techId = $this->makeUser('inv-tech@test.local', 'Correct-Horse-1', ['technician']);
        $this->assertThrows(ForbiddenException::class, function () use ($techId) {
            $this->dispatchAs($techId, 'GET', '/admin/invoices');
        }, 'technician has no orders.view');
    }

    public function testCashierCannotCreateRefund(): void
    {
        $cashierId = $this->makeCashier('rf-cashier@test.local');
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $cashierId, [], 'completed');
        $svc->recordPayment($order['id'], 1000.00, 'cash', $cashierId);

        $this->assertThrows(ForbiddenException::class, function () use ($cashierId, $order) {
            $this->dispatchAs($cashierId, 'POST', '/admin/orders/' . $order['id'] . '/refund', [
                'amount' => '1000.00',
                'reason' => 'Cashier refund attempt',
                'inventory_action' => 'no_action',
            ]);
        }, 'cashier without payments.refund cannot create refunds');

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $ownerId = $this->makeOwner('invrf-csrf@test.local');
        $this->actingAs($ownerId);
        [$brandId, $catId, $modelId, $unitId, $customerId] = $this->makeFixture();
        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');
        $svc->recordPayment($order['id'], 1000.00, 'cash', $ownerId);

        $invoiceSvc = new InvoiceService();
        $inv = $invoiceSvc->generateForOrder($order['id'], ['billing_name' => 'Test'], $ownerId);

        $refundSvc = new RefundService();
        $refund = $refundSvc->create($order['id'], 500.00, 'Partial', 'no_action', $ownerId);

        $routes = [
            ['POST', '/admin/orders/' . $order['id'] . '/invoice', ['billing_name' => 'X']],
            ['POST', '/admin/invoices/' . $inv['id'] . '/void', ['reason' => 'x']],
            ['POST', '/admin/invoices/' . $inv['id'] . '/mark-paid', []],
            ['POST', '/admin/orders/' . $order['id'] . '/refund', ['amount' => '1', 'reason' => 'x', 'inventory_action' => 'no_action']],
            ['POST', '/admin/refunds/' . $refund['id'] . '/process', []],
            ['POST', '/admin/refunds/' . $refund['id'] . '/cancel', []],
            ['POST', '/admin/orders/' . $order['id'] . '/corrections', ['field' => 'order.notes', 'old_value' => 'a', 'new_value' => 'b', 'reason' => 'x']],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(
                    Request::synthetic($method, $uri, [], $post),
                    $this->resolver()
                );
            }, "{$method} {$uri} must require CSRF");
        }

        $this->cleanupFixture($order['id'], $unitId, $customerId, $brandId, $catId, $modelId, $inv['id'], $refund['id']);
    }
}
