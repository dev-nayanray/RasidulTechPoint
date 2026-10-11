<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ValidationException;
use App\Repositories\OrderRepository;
use App\Services\OrderService;
use Tests\TestCase;

final class OrderTest extends TestCase
{
    private OrderService $orders;

    public function setUp(): void
    {
        parent::setUp();
        $this->orders = new OrderService();
    }

    public function testServerCalculatesTotalsFromInventoryPrices(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $a = $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);
        $b = $this->makeAvailableUnit($modelId, ['selling_price' => '250.50']);
        $customerId = $this->makeCustomer();

        $order = $this->orders->createOrder($customerId, [$a, $b], 'pos', 1, ['discount' => '10.00'], 'completed');

        $this->assertEquals(340.50, (float) $order['total']);
        $fetched = (new OrderRepository())->find($order['id']);
        $this->assertEquals(340.50, (float) $fetched['total']);
        $this->assertEquals(10.00, (float) $fetched['discount']);
        $this->assertEquals(350.50, (float) $fetched['subtotal']);
    }

    public function testOrderItemsSnapshotSaleTimeDetails(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '555.00', 'sku' => 'SNAP-1']);
        $customerId = $this->makeCustomer();

        $order = $this->orders->createOrder($customerId, [$unitId], 'pos', 1, [], 'completed');

        // Change the catalog afterwards — history must stay intact.
        $this->pdo()->prepare('UPDATE inventory_units SET selling_price = 1.00 WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare("UPDATE product_models SET name = 'RENAMED MODEL' WHERE id = ?")->execute([$modelId]);

        $fetched = (new OrderRepository())->find($order['id']);
        $this->assertTrue(str_starts_with((string) $fetched['items'][0]['product_name'], 'TestModel 100'));
        $this->assertSame('SNAP-1', (string) $fetched['items'][0]['sku']);
        $this->assertEquals(555.00, (float) $fetched['items'][0]['unit_price']);
    }

    public function testReferenceIsNonSequentialAndUnique(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unit1 = $this->makeAvailableUnit($modelId);
        $unit2 = $this->makeAvailableUnit($modelId);
        $customerId = $this->makeCustomer();

        $o1 = $this->orders->createOrder($customerId, [$unit1], 'pos', 1);
        $o2 = $this->orders->createOrder($customerId, [$unit2], 'pos', 1);

        $this->assertMatchesRegularExpression('/^RTP-\d{6}-[A-F0-9]{6}$/', $o1['reference']);
        $this->assertNotSame($o1['reference'], $o2['reference']);
    }

    public function testUnitsAreMarkedSoldAndCannotBeDoubleSold(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId);
        $customerId = $this->makeCustomer();

        $this->orders->createOrder($customerId, [$unitId], 'pos', 1, [], 'completed');
        $this->assertSame('sold', $this->unitStatus($unitId));

        $this->assertThrows(ValidationException::class,
            fn () => $this->orders->createOrder($customerId, [$unitId], 'pos', 1, [], 'completed'),
            'the same physical unit must never be sold twice');
    }

    public function testUnavailableUnitRejected(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId); // awaiting_inspection
        $customerId = $this->makeCustomer();

        $this->assertThrows(ValidationException::class,
            fn () => $this->orders->createOrder($customerId, [$unitId], 'pos', 1));
    }

    public function testDiscountCannotExceedSubtotal(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '100.00']);
        $customerId = $this->makeCustomer();

        $this->assertThrows(ValidationException::class,
            fn () => $this->orders->createOrder($customerId, [$unitId], 'pos', 1, ['discount' => '500.00']));
    }

    public function testOrderAndPaymentStatesAreSeparate(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '1000.00']);
        $customerId = $this->makeCustomer();

        $order = $this->orders->createOrder($customerId, [$unitId], 'online', 1, [], 'pending');
        $fetched = (new OrderRepository())->find($order['id']);
        $this->assertSame('pending', (string) $fetched['status']);
        $this->assertSame('unpaid', (string) $fetched['payment_status']);

        // Partial payment with verified evidence (gateway txn id) changes
        // only payment state — order status remains pending.
        $result = $this->orders->recordPayment($order['id'], 400.00, 'bkash', 1, 'BKASH-TXN-PROOF-1');
        $this->assertSame('partial', $result['payment_status']);

        $fetched = (new OrderRepository())->find($order['id']);
        $this->assertSame('pending', (string) $fetched['status'], 'order status must be untouched by payments');
        $this->assertSame('partial', (string) $fetched['payment_status']);

        // Completing payment marks paid.
        $result = $this->orders->recordPayment($order['id'], 600.00, 'cash', 1);
        $this->assertSame('paid', $result['payment_status']);
    }

    public function testNonCashPaymentWithoutEvidenceStaysPending(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '1000.00']);
        $customerId = $this->makeCustomer();

        $order = $this->orders->createOrder($customerId, [$unitId], 'online', 1, [], 'pending');

        // bkash payment with no reference and no evidence_ref → stays pending.
        // The order payment_status must NOT move to 'paid' without evidence.
        $result = $this->orders->recordPayment($order['id'], 1000.00, 'bkash', 1);
        $this->assertSame('pending', $result['payment_state'], 'non-cash payment without evidence must be pending');
        $this->assertSame('unpaid', $result['payment_status'], 'pending payment must not move order to paid');

        // Confirm with evidence → payment promoted, order marked paid.
        $confirm = $this->orders->confirmPayment($result['id'], 'BANK-SLIP-9', 1);
        $this->assertSame('paid', $confirm['payment_status']);
    }

    public function testDuplicatePaymentReferenceRejected(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $a = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $b = $this->makeAvailableUnit($modelId, ['selling_price' => '300.00']);
        $customerId = $this->makeCustomer();

        $o1 = $this->orders->createOrder($customerId, [$a], 'pos', 1);
        $o2 = $this->orders->createOrder($customerId, [$b], 'pos', 1);

        $this->orders->recordPayment($o1['id'], 100.00, 'bkash', 1, 'BKASH-TXN-777');
        $this->assertThrows(ValidationException::class,
            fn () => $this->orders->recordPayment($o2['id'], 100.00, 'bkash', 1, 'BKASH-TXN-777'),
            'a gateway callback replay must be rejected');
    }

    public function testPaymentsRejectedForCancelledOrders(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId);
        $customerId = $this->makeCustomer();

        $order = $this->orders->createOrder($customerId, [$unitId], 'pos', 1);
        $this->orders->updateStatus($order['id'], 'cancelled', 1);

        $this->assertThrows(ValidationException::class,
            fn () => $this->orders->recordPayment($order['id'], 50.0, 'cash', 1));
    }

    public function testCancellingOrderReturnsUnitsToStock(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId);
        $customerId = $this->makeCustomer();

        $order = $this->orders->createOrder($customerId, [$unitId], 'pos', 1);
        $this->assertSame('sold', $this->unitStatus($unitId));

        $this->orders->updateStatus($order['id'], 'cancelled', 1);
        $this->assertSame('returned', $this->unitStatus($unitId));
    }

    public function testCustomerSeesOnlyOwnOrders(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unit = $this->makeAvailableUnit($modelId);
        $customerA = $this->makeCustomer('+8801700000101');
        $customerB = $this->makeCustomer('+8801700000102');

        $order = $this->orders->createOrder($customerA, [$unit], 'pos', 1);

        $repo = new OrderRepository();
        $this->assertNotNull($repo->findByReferenceForCustomer($order['reference'], $customerA));
        $this->assertNull($repo->findByReferenceForCustomer($order['reference'], $customerB),
            'IDOR guard: another customer must not resolve the reference');
    }

    public function testOrderForMissingCustomerRejected(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unit = $this->makeAvailableUnit($modelId);
        $this->assertThrows(ValidationException::class,
            fn () => $this->orders->createOrder(999999, [$unit], 'pos', 1));
    }

    public function testEmptyOrderRejected(): void
    {
        $customerId = $this->makeCustomer();
        $this->assertThrows(ValidationException::class,
            fn () => $this->orders->createOrder($customerId, [], 'pos', 1));
    }

    public function testOnlineOrderCannotStartCompleted(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unit = $this->makeAvailableUnit($modelId);
        $customerId = $this->makeCustomer();

        $order = $this->orders->createOrder($customerId, [$unit], 'online', 1, [], 'completed');
        $fetched = (new OrderRepository())->find($order['id']);
        $this->assertSame('processing', (string) $fetched['status'],
            'online orders complete through fulfilment, not at creation');
    }

    private function unitStatus(int $unitId): string
    {
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        return (string) $stmt->fetchColumn();
    }
}
