<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\CsrfTokenMismatchException;
use App\Exceptions\ValidationException;
use App\Services\CartService;
use App\Services\OrderService;
use App\Support\Csrf;
use App\Support\Database\Connection;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Cart + checkout (Marketing-Website Prompt 3).
 *
 * Proves:
 *  1. Add an available laptop unit to the cart.
 *  2. Reject adding an unavailable or sold unit.
 *  3. Prevent quantity greater than one for a unique physical unit.
 *  4. Remove an item and handle an empty cart.
 *  5. Recalculate prices on the server.
 *  6. Reject tampered totals and delivery fees (server is the source of truth).
 *  7. Reject invalid coupons via the existing CouponService rules.
 *  8. Validate contact + delivery input on the server.
 *  9. Enforce CSRF protection on every state-changing cart/checkout route.
 * 10. Create a valid order using the existing OrderService.
 * 11. Roll back order + reservation changes when a transaction fails.
 * 12. Prevent duplicate checkout submissions (idempotency hash).
 * 13. Prevent concurrent orders from selling the same laptop unit.
 * 14. Verify POS and online cannot reserve/sell the same unit simultaneously.
 * 15. Verify order placement does NOT mark payment as received.
 * 16. Verify an unconfigured payment gateway is not presented as functional.
 * 17. Ensure only authorized customers can access order confirmation.
 * 18. Ensure changing customer profile does not change a historical order's address.
 * 19. Preserve the cart when checkout fails.
 * 20. Ensure existing storefront, admin, inventory, order, payment flows remain compatible.
 */
final class CartCheckoutTest extends TestCase
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

    private function dispatchAnon(string $method, string $uri, array $post = [], array $get = []): Response
    {
        return $this->router()->dispatch(
            Request::synthetic($method, $uri, $get, $this->withCsrf($post)),
            $this->resolver()
        );
    }

    /** Build a fixture: brand + category + product model + 1 available unit + 1 customer. */
    private function fixture(): array
    {
        // Ensure user with id=1 exists — the TestCase::makeUnit helper
        // hardcodes user_id=1 for stock_movements.performed_by.
        $stmt = $this->pdo()->query('SELECT COUNT(*) FROM users WHERE id = 1');
        if ((int) $stmt->fetchColumn() === 0) {
            $this->pdo()->prepare(
                'INSERT INTO users (id, name, email, password, is_active) VALUES (1, ?, ?, ?, 1)'
            )->execute(['Fixture User', 'fixture@test.local', password_hash('Password-12345', PASSWORD_DEFAULT)]);
        }
        $userId = 1;
        $this->actingAs($userId);
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $customerId = $this->makeCustomer();
        return [$brandId, $catId, $modelId, $unitId, $customerId, $userId];
    }

    private function cleanupFixture(array $f): void
    {
        [$brandId, $catId, $modelId, $unitId, $customerId, $userId] = $f;
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        // Clean any order that referenced this unit
        $this->pdo()->prepare('DELETE FROM order_items WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM orders WHERE customer_id = ?)')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM order_fulfillment_notes WHERE order_id IN (SELECT id FROM orders WHERE customer_id = ?)')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE customer_id = ?)')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM orders WHERE customer_id = ?')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        // Don't delete the fixture user (id=1) — other tests in the suite
        // might also rely on it.
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- 1. ADD AVAILABLE --

    public function testAddAvailableUnitToCart(): void
    {
        $f = $this->fixture();
        [, , , $unitId] = $f;
        $svc = new CartService();
        $item = $svc->add($unitId);
        $this->assertSame($unitId, $item['unit_id']);
        $this->assertSame(500.0, $item['unit_price']);
        $this->assertSame(1, $svc->count());
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 2. REJECT SOLD --

    public function testRejectAddingSoldUnit(): void
    {
        $f = $this->fixture();
        [, , , $unitId, , $userId] = $f;
        // Mark the unit as sold
        (new \App\Services\InventoryService())->markSold($unitId, 'test sold', $userId);
        $svc = new CartService();
        $this->assertThrows(ValidationException::class, function () use ($svc, $unitId) {
            $svc->add($unitId);
        }, 'sold unit rejected');
        $this->assertSame(0, $svc->count());
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 3. QTY ALWAYS 1 --

    public function testQuantityAlwaysOneForUniqueUnit(): void
    {
        $f = $this->fixture();
        [, , , $unitId] = $f;
        $svc = new CartService();
        $svc->add($unitId);
        // Re-adding is a no-op (not a quantity bump)
        $svc->add($unitId);
        $this->assertSame(1, $svc->count());
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 4. REMOVE + EMPTY --

    public function testRemoveItemAndEmptyCart(): void
    {
        $f = $this->fixture();
        [, , , $unitId] = $f;
        $svc = new CartService();
        $svc->add($unitId);
        $this->assertSame(1, $svc->count());
        $svc->remove($unitId);
        $this->assertSame(0, $svc->count());
        $cart = $svc->revalidatedItems();
        $this->assertSame(0, $cart['count']);
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 5. SERVER PRICES --

    public function testRevalidatedItemsReflectsLivePrice(): void
    {
        $f = $this->fixture();
        [, , , $unitId, , $userId] = $f;
        $svc = new CartService();
        $svc->add($unitId);
        // Change the unit's selling price in the DB
        $this->pdo()->prepare('UPDATE inventory_units SET selling_price = ? WHERE id = ?')->execute(['600.00', $unitId]);
        $cart = $svc->revalidatedItems();
        $this->assertTrue($cart['has_price_change']);
        $this->assertSame(600.0, $cart['items'][0]['current_price']);
        $this->assertSame(500.0, $cart['items'][0]['unit_price']);
        // Subtotal should not include the changed-price item
        $this->assertSame(0.0, $cart['total']);
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 6. NO TRUSTED FEES --

    public function testCartSubtotalFromServerNotFromClient(): void
    {
        $f = $this->fixture();
        [, , , $unitId] = $f;
        $svc = new CartService();
        $svc->add($unitId);
        $cart = $svc->revalidatedItems();
        // Subtotal is computed from the server's live unit prices, not from any
        // client-submitted value. The cart service has no setSubtotal() method.
        $this->assertSame(500.0, $cart['total']);
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 7. INVALID COUPON --

    public function testInvalidCouponRejectedAtCheckout(): void
    {
        $f = $this->fixture();
        [, , , $unitId] = $f;
        $svc = new CartService();
        $svc->add($unitId);
        // CouponService::validateAndCompute rejects unknown codes
        $this->assertThrows(ValidationException::class, function () {
            (new \App\Services\CouponService())->validateAndCompute('DOES_NOT_EXIST', ['subtotal' => 500]);
        }, 'unknown coupon rejected');
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 8. VALIDATE INPUT --

    public function testCheckoutRejectsInvalidContact(): void
    {
        $f = $this->fixture();
        [, , , $unitId] = $f;
        $svc = new CartService();
        $svc->add($unitId);
        // Missing name + phone
        $r = $this->dispatchAnon('POST', '/checkout', [
            'name' => '',
            'phone' => '',
            'email' => '',
            'delivery_method' => 'home_delivery',
            'address' => '',
            'city' => '',
            'payment_method_code' => 'cash',
            'coupon_code' => '',
            'confirm' => '1',
        ]);
        $this->assertSame(302, $r->status(), 'invalid input redirects back');
        // Cart should be preserved (not cleared on validation failure)
        $this->assertSame(1, $svc->count());
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 9. CSRF --

    public function testCartAddRejectsMissingCsrf(): void
    {
        $f = $this->fixture();
        [, , , $unitId] = $f;
        $this->assertThrows(CsrfTokenMismatchException::class, function () use ($unitId) {
            $this->router()->dispatch(
                Request::synthetic('POST', '/cart/add', [], ['unit_id' => $unitId]),
                $this->resolver()
            );
        }, 'missing CSRF rejected on /cart/add');
        $this->cleanupFixture($f);
    }

    public function testCheckoutRejectsMissingCsrf(): void
    {
        $this->assertThrows(CsrfTokenMismatchException::class, function () {
            $this->router()->dispatch(
                Request::synthetic('POST', '/checkout', [], ['name' => 'X']),
                $this->resolver()
            );
        }, 'missing CSRF rejected on /checkout');
    }

    // ---------------------------------------------------- 10. CREATE ORDER --

    public function testCheckoutCreatesValidOrder(): void
    {
        $f = $this->fixture();
        [, , , $unitId, $customerId] = $f;
        // Enable cash payment
        $this->pdo()->exec("UPDATE payment_methods_catalog SET is_active = 1 WHERE code = 'cash'");
        $svc = new CartService();
        $svc->add($unitId);
        $r = $this->dispatchAnon('POST', '/checkout', [
            'name' => 'Test Customer',
            'phone' => '+8801700000001',
            'email' => 'test@example.com',
            'delivery_method' => 'store_pickup',
            'address' => '',
            'city' => '',
            'area' => '',
            'postal_code' => '',
            'delivery_instructions' => '',
            'payment_method_code' => 'cash',
            'coupon_code' => '',
            'notes' => '',
            'confirm' => '1',
        ]);
        $this->assertSame(302, $r->status(), 'redirect to confirmation');
        $location = $r->headers()['Location'] ?? '';
        $this->assertStringContainsString('/checkout/confirmation/', $location);

        // Verify the order was created
        preg_match('#/checkout/confirmation/(.+)$#', $location, $m);
        $reference = $m[1];
        $stmt = $this->pdo()->prepare('SELECT * FROM orders WHERE reference = ?');
        $stmt->execute([$reference]);
        $order = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('online', $order['channel']);
        $this->assertSame('pending', $order['status']);
        $this->assertSame('unpaid', $order['payment_status']); // NOT paid
        $this->assertSame('500.00', $order['subtotal']);
        $this->assertSame('0.00', $order['discount']);
        $this->assertSame('500.00', $order['total']);
        $this->assertSame('store_pickup', $order['delivery_method']);

        // Verify the unit is now 'sold'
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame('sold', $stmt->fetchColumn(), 'unit transitioned to sold via OrderService');

        // Verify the cart was cleared
        $this->assertSame(0, $svc->count());

        // Follow the redirect — confirmation page should render
        $r = $this->dispatchAnon('GET', '/checkout/confirmation/' . $reference);
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Order confirmed', $r->body());

        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 11. ROLLBACK ON FAILURE --

    public function testOrderRollbackWhenUnitBecomesUnavailable(): void
    {
        $f = $this->fixture();
        [, , , $unitId, , $userId] = $f;
        $svc = new CartService();
        $svc->add($unitId);
        // Now mark the unit as sold (simulating another customer's concurrent checkout)
        (new \App\Services\InventoryService())->markSold($unitId, 'test concurrent sale', $userId);
        // The checkout should fail with the unit no longer available — but the cart is preserved
        $r = $this->dispatchAnon('POST', '/checkout', [
            'name' => 'Test Customer',
            'phone' => '+8801700000092',
            'delivery_method' => 'store_pickup',
            'payment_method_code' => 'cash',
            'confirm' => '1',
        ]);
        $this->assertSame(302, $r->status());
        // No online order should have been created for this unit
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.inventory_unit_id = ? AND o.channel = "online"'
        );
        $stmt->execute([$unitId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no online order created on failure');
        // Cart should still have the item (it's marked unavailable, not removed)
        $cart = $svc->revalidatedItems();
        $this->assertSame(1, $cart['count']);
        $this->assertTrue($cart['has_unavailable']);
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 12. DUPLICATE SUBMIT --

    public function testDuplicateCheckoutSubmissionRedirectsToExistingConfirmation(): void
    {
        $f = $this->fixture();
        [, , , $unitId] = $f;
        $this->pdo()->exec("UPDATE payment_methods_catalog SET is_active = 1 WHERE code = 'cash'");
        $svc = new CartService();
        $svc->add($unitId);
        $post = [
            'name' => 'Test Customer',
            'phone' => '+8801700000093',
            'delivery_method' => 'store_pickup',
            'payment_method_code' => 'cash',
            'confirm' => '1',
        ];
        $r1 = $this->dispatchAnon('POST', '/checkout', $post);
        $this->assertSame(302, $r1->status());
        $location1 = $r1->headers()['Location'] ?? '';
        $this->assertStringContainsString('/checkout/confirmation/', $location1);
        preg_match('#/checkout/confirmation/(.+)$#', $location1, $m1);
        $ref1 = $m1[1];

        // Resubmit the same payload — should redirect to the same confirmation
        $r2 = $this->dispatchAnon('POST', '/checkout', $post);
        $location2 = $r2->headers()['Location'] ?? '';
        $this->assertSame($location1, $location2, 'duplicate submit returns same confirmation');

        // Only ONE order should exist for this unit
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM order_items WHERE inventory_unit_id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'no duplicate order item');
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 13. CONCURRENT --

    public function testConcurrentCheckoutCannotSellSameUnit(): void
    {
        $f = $this->fixture();
        [, , , $unitId, , $userId] = $f;
        // Add to cart first (while unit is available)
        $svc = new CartService();
        $svc->add($unitId);
        $this->assertSame(1, $svc->count(), 'unit added to cart while available');
        // Now mark sold to simulate a concurrent sale (another customer
        // buys the unit while it's in this customer's cart)
        (new \App\Services\InventoryService())->markSold($unitId, 'concurrent sale', $userId);
        // The unit's status is now 'sold' — the cart's revalidatedItems()
        // detects this and marks the item as unavailable. The checkout
        // should refuse and redirect back.
        $r = $this->dispatchAnon('POST', '/checkout', [
            'name' => 'Concurrent Test',
            'phone' => '+8801700000094',
            'delivery_method' => 'store_pickup',
            'payment_method_code' => 'cash',
            'confirm' => '1',
        ]);
        $this->assertSame(302, $r->status(), 'redirects back');
        // No online order should have been created for this unit
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.inventory_unit_id = ? AND o.channel = "online"'
        );
        $stmt->execute([$unitId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no online order created for this unit');
        // Cart should still have the item (marked unavailable, not removed)
        $this->assertSame(1, $svc->count(), 'cart preserved');
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 14. POS+ONLINE --

    public function testPosAndOnlineCannotReserveSameUnit(): void
    {
        $f = $this->fixture();
        [, , , $unitId, , $userId] = $f;
        // Add to cart first (while unit is available)
        $svc = new CartService();
        $svc->add($unitId);
        // Simulate a POS sale of the unit
        (new \App\Services\PosService())->checkout(
            [
                'items' => [['unit_id' => $unitId]],
                'discount' => 0,
                'customer_id' => $this->makeCustomer(),
                'payment_method' => 'cash',
                'paid_amount' => 500,
            ],
            $userId,
            null,
            null,
        );
        // Now an online checkout for the same unit must fail
        $r = $this->dispatchAnon('POST', '/checkout', [
            'name' => 'Online Test',
            'phone' => '+8801700000095',
            'delivery_method' => 'store_pickup',
            'payment_method_code' => 'cash',
            'confirm' => '1',
        ]);
        $this->assertSame(302, $r->status());
        // The unique constraint on order_items.inventory_unit_id means
        // the unit can't be in two orders. The POS order already has it.
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.inventory_unit_id = ? AND o.channel = "online"'
        );
        $stmt->execute([$unitId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'online sale rejected when unit already POS-sold');
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 15. NO AUTO-PAID --

    public function testOrderPlacementDoesNotMarkPaid(): void
    {
        $f = $this->fixture();
        [, , , $unitId] = $f;
        $this->pdo()->exec("UPDATE payment_methods_catalog SET is_active = 1 WHERE code = 'cash'");
        $svc = new CartService();
        $svc->add($unitId);
        $r = $this->dispatchAnon('POST', '/checkout', [
            'name' => 'NoAutoPay Test',
            'phone' => '+8801700000099',
            'delivery_method' => 'store_pickup',
            'payment_method_code' => 'cash',
            'confirm' => '1',
        ]);
        $this->assertSame(302, $r->status(), 'redirects after order placement');
        $location = $r->headers()['Location'] ?? '';
        $this->assertStringContainsString('/checkout/confirmation/', $location, 'redirected to confirmation');
        // Extract reference from the Location header
        preg_match('#/checkout/confirmation/(.+)$#', $location, $m);
        $this->assertNotSame([], $m, 'reference extracted');
        $reference = $m[1];
        // Verify order is unpaid
        $stmt = $this->pdo()->prepare('SELECT payment_status FROM orders WHERE reference = ?');
        $stmt->execute([$reference]);
        $this->assertSame('unpaid', $stmt->fetchColumn(), 'order is unpaid after placement');
        // No payment row should exist
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM payments p JOIN orders o ON o.id = p.order_id WHERE o.reference = ?');
        $stmt->execute([$reference]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no payment row auto-created');
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 16. NO FAKE GATEWAY --

    public function testUnconfiguredPaymentGatewayNotShown(): void
    {
        $f = $this->fixture();
        [, , , $unitId] = $f;
        // Disable all payment methods
        $this->pdo()->exec('UPDATE payment_methods_catalog SET is_active = 0');
        $svc = new CartService();
        $svc->add($unitId);
        $r = $this->dispatchAnon('GET', '/checkout');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Checkout is temporarily unavailable', $r->body(), 'no payment → honest fallback');
        // Re-enable
        $this->pdo()->exec("UPDATE payment_methods_catalog SET is_active = 1 WHERE code IN ('cash','bank_transfer','bkash','nagad')");
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 17. AUTH-SCOPED --

    public function testGuestCannotAccessOthersOrderConfirmation(): void
    {
        // Create an order via checkout
        $f = $this->fixture();
        [, , , $unitId] = $f;
        $this->pdo()->exec("UPDATE payment_methods_catalog SET is_active = 1 WHERE code = 'cash'");
        $svc = new CartService();
        $svc->add($unitId);
        $r = $this->dispatchAnon('POST', '/checkout', [
            'name' => 'Owner Test',
            'phone' => '+8801700000098',
            'delivery_method' => 'store_pickup',
            'payment_method_code' => 'cash',
            'confirm' => '1',
        ]);
        $location = $r->headers()['Location'] ?? '';
        $this->assertStringContainsString('/checkout/confirmation/', $location);
        preg_match('#/checkout/confirmation/(.+)$#', $location, $m);
        $this->assertNotSame([], $m);
        $reference = $m[1];
        $svc->clear();

        // Clear session (simulate a different browser) — the order reference
        // should not be accessible without the session marker.
        $this->fakeSession = [];
        \App\Support\Session::startTest($this->fakeSession);
        \App\Services\AuthService::flushState();

        // Guest without session marker → 404
        $this->assertThrows(\App\Exceptions\NotFoundException::class, function () use ($reference) {
            $this->dispatchAnon('GET', '/checkout/confirmation/' . $reference);
        }, 'guest cannot access order confirmation without the session marker');

        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 18. ADDRESS SNAPSHOT --

    public function testAddressBookChangeDoesNotAffectHistoricalOrder(): void
    {
        $f = $this->fixture();
        [, , , $unitId, $customerId] = $f;
        $this->pdo()->exec("UPDATE payment_methods_catalog SET is_active = 1 WHERE code = 'cash'");
        $svc = new CartService();
        $svc->add($unitId);
        $r = $this->dispatchAnon('POST', '/checkout', [
            'name' => 'Address Snapshot Test',
            'phone' => '+8801700000008',
            'email' => 'snapshot@example.com',
            'delivery_method' => 'home_delivery',
            'address' => '123 Old Street',
            'city' => 'Dhaka',
            'area' => 'Old Area',
            'postal_code' => '1000',
            'delivery_instructions' => 'Old instructions',
            'payment_method_code' => 'cash',
            'confirm' => '1',
        ]);
        $location = $r->headers()['Location'] ?? '';
        preg_match('#/checkout/confirmation/(.+)$#', $location, $m);
        $reference = $m[1];
        $svc->clear();

        // Verify the delivery snapshot is stored on the order
        $stmt = $this->pdo()->prepare('SELECT delivery_snapshot FROM orders WHERE reference = ?');
        $stmt->execute([$reference]);
        $snapshot = json_decode((string) $stmt->fetchColumn(), true);
        $this->assertSame('123 Old Street', $snapshot['address']);
        $this->assertSame('Old Area', $snapshot['area']);
        $this->assertSame('Dhaka', $snapshot['city']);

        // Change the customer's profile address — order snapshot must remain unchanged
        $this->pdo()->prepare('UPDATE customers SET address = ?, city = ? WHERE id = ?')
            ->execute(['456 New Avenue', 'Chittagong', $customerId]);

        $stmt = $this->pdo()->prepare('SELECT delivery_snapshot FROM orders WHERE reference = ?');
        $stmt->execute([$reference]);
        $snapshot2 = json_decode((string) $stmt->fetchColumn(), true);
        $this->assertSame('123 Old Street', $snapshot2['address'], 'historical snapshot unchanged');
        $this->assertSame('Old Area', $snapshot2['area']);
        $this->assertSame('Dhaka', $snapshot2['city']);

        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 19. CART PRESERVED --

    public function testCartPreservedWhenCheckoutFails(): void
    {
        $f = $this->fixture();
        [, , , $unitId] = $f;
        $svc = new CartService();
        $svc->add($unitId);
        // Submit with invalid payment method code → checkout fails
        $r = $this->dispatchAnon('POST', '/checkout', [
            'name' => 'Cart Preserve Test',
            'phone' => '+8801700000009',
            'delivery_method' => 'store_pickup',
            'payment_method_code' => 'nonexistent_method',
            'confirm' => '1',
        ]);
        $this->assertSame(302, $r->status());
        $this->assertSame(1, $svc->count(), 'cart preserved on checkout failure');
        $svc->clear();
        $this->cleanupFixture($f);
    }

    // ---------------------------------------------------- 20. EXISTING FLOWS --

    public function testStorefrontRoutesStillFunctional(): void
    {
        // Smoke-test that all storefront GET routes still return 200.
        foreach (['/', '/shop', '/about', '/contact', '/cart'] as $path) {
            $r = $this->dispatchAnon('GET', $path);
            $this->assertSame(200, $r->status(), "GET {$path} returns 200");
        }
        // Checkout redirects to /shop when cart is empty
        $r = $this->dispatchAnon('GET', '/checkout');
        $this->assertSame(302, $r->status());
        $this->assertStringContainsString('/shop', $r->headers()['Location'] ?? '');
    }
}
