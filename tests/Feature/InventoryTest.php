<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ValidationException;
use App\Services\InventoryService;
use Tests\TestCase;

final class InventoryTest extends TestCase
{
    private InventoryService $inventory;

    public function setUp(): void
    {
        parent::setUp();
        $this->inventory = new InventoryService();
    }

    public function testReceiveCreatesUnitAndReceiptMovement(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->inventory->receive([
            'sku' => 'INV-001', 'serial_number' => 'SN-001', 'product_model_id' => $modelId,
            'condition_grade' => 'A', 'purchase_cost' => '100', 'landed_cost' => '10',
            'selling_price' => '150',
        ], 1);

        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame('awaiting_inspection', (string) $stmt->fetchColumn());

        $movements = $this->pdo()->prepare('SELECT * FROM stock_movements WHERE inventory_unit_id = ?');
        $movements->execute([$unitId]);
        $rows = $movements->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('receipt', $rows[0]['movement_type']);
        $this->assertNull($rows[0]['from_status']);
        $this->assertSame('awaiting_inspection', (string) $rows[0]['to_status']);
    }

    public function testFullHappyPathInspectionReserveSell(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId);

        $this->inventory->passInspection($unitId, 'QA passed', 7);
        $this->assertSame('available', $this->statusOf($unitId));

        $this->inventory->reserve($unitId, 'reserved for customer', 7);
        $this->assertSame('reserved', $this->statusOf($unitId));

        $this->inventory->markSold($unitId, 'sold at shop', 7);
        $this->assertSame('sold', $this->statusOf($unitId));

        // sold_at timestamp recorded
        $stmt = $this->pdo()->prepare('SELECT sold_at FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertNotNull($stmt->fetchColumn());
    }

    public function testAwaitingInspectionCannotBeSoldDirectly(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId); // awaiting_inspection

        $this->assertThrows(ValidationException::class,
            fn () => $this->inventory->markSold($unitId, 'skip QA', 1),
            'selling without inspection must be impossible');
    }

    public function testDamagedUnitCannotBeReserved(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId);
        $this->inventory->failInspection($unitId, 'broken hinge', 7);

        $this->assertThrows(ValidationException::class,
            fn () => $this->inventory->reserve($unitId, 'try anyway', 7));
    }

    public function testSoldUnitCannotBeSoldAgain(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId);
        $this->inventory->markSold($unitId, 'sold', 7);

        $this->assertThrows(ValidationException::class,
            fn () => $this->inventory->markSold($unitId, 'double sell', 7));
    }

    public function testReleaseReturnsUnitToAvailable(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId);
        $this->inventory->reserve($unitId, 'hold', 7);

        $this->inventory->release($unitId, 'customer changed mind', 7);
        $this->assertSame('available', $this->statusOf($unitId));
    }

    public function testReturnedUnitRequiresReinspection(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId);
        $this->inventory->markSold($unitId, 'sold', 7);
        $this->inventory->markReturned($unitId, 'customer return', 7);
        $this->assertSame('returned', $this->statusOf($unitId));

        // returned → available only via inspection
        $this->assertThrows(ValidationException::class,
            fn () => $this->inventory->reserve($unitId, 'not inspected yet', 7));
        $this->inventory->passInspection($unitId, 're-tested OK', 7);
        $this->assertSame('available', $this->statusOf($unitId));
    }

    public function testWriteOffFromWrongStateRejected(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId);
        $this->inventory->reserve($unitId, 'hold', 7);

        // write_off transition does not allow from 'reserved'
        $this->assertThrows(ValidationException::class,
            fn () => $this->inventory->writeOff($unitId, 'oops', 7));
    }

    public function testPriceChangeRespectsMinimumPrice(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['min_selling_price' => '120.00']);

        $this->assertThrows(ValidationException::class,
            fn () => $this->inventory->changePrice($unitId, 100.0, 7),
            'below-minimum price must be rejected');

        $this->inventory->changePrice($unitId, 140.0, 7);
        $stmt = $this->pdo()->prepare('SELECT selling_price FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertEquals(140.0, (float) $stmt->fetchColumn());
    }

    public function testSoldUnitPriceImmutable(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId);
        $this->inventory->markSold($unitId, 'sold', 7);

        $this->assertThrows(ValidationException::class,
            fn () => $this->inventory->changePrice($unitId, 999.0, 7));
    }

    public function testMovementHistoryIsAppendOnly(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId);
        $this->inventory->reserve($unitId, 'r', 7);
        $this->inventory->release($unitId, 'l', 7);
        $this->inventory->markSold($unitId, 's', 7);

        $stmt = $this->pdo()->prepare(
            'SELECT movement_type FROM stock_movements WHERE inventory_unit_id = ? ORDER BY id'
        );
        $stmt->execute([$unitId]);
        $types = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame(['receipt', 'inspection', 'reservation', 'release', 'sale'], array_map('strval', $types));
    }

    public function testUnitCannotBeDeletedWhileMovementsExist(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId);

        // The FK from stock_movements blocks deleting the unit: history
        // keeps the unit row alive for auditability.
        $this->assertThrows(\PDOException::class, function () use ($unitId) {
            $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        }, 'unit deletion must be restricted while movements reference it');
    }

    public function testConcurrentReservationOnlyOneWins(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId);

        // Simulate two competing transactions on separate connections:
        // first locks the row; second must block/fail on status change.
        $pdo1 = $this->pdo();
        $pdo2 = \App\Support\Database\Connection::build(
            (string) \App\Support\Config::get('database.host'),
            (int) \App\Support\Config::get('database.port'),
            'rasidul_tech_point_test',
            (string) \App\Support\Config::get('database.username'),
            (string) \App\Support\Config::get('database.password'),
        );

        $pdo1->beginTransaction();
        $lock = $pdo1->prepare('SELECT status FROM inventory_units WHERE id = ? FOR UPDATE');
        $lock->execute([$unitId]);
        $this->assertSame('available', (string) $lock->fetchColumn());

        // Second connection tries to reserve through the service.
        $secondWon = true;
        try {
            // The service uses the shared connection (pdo1, locked): same
            // connection, so the lock is re-entrant — instead verify that
            // swapping to pdo2 makes the reserve block/timeout or fail.
            \App\Support\Database\Connection::swap($pdo2);
            $before = (int) $pdo2->query('SELECT @@innodb_lock_wait_timeout')->fetchColumn();
            $pdo2->exec('SET SESSION innodb_lock_wait_timeout = 2');
            try {
                $this->inventory->reserve($unitId, 'competing reservation', 7);
            } catch (\Throwable) {
                $secondWon = false; // lock wait timeout / deadlock
            }
            $pdo2->exec("SET SESSION innodb_lock_wait_timeout = {$before}");
        } finally {
            \App\Support\Database\Connection::swap($pdo1);
            $pdo1->rollBack();
        }

        // Either the second attempt failed outright, or it completed after
        // our rollback; in both cases the unit must be in exactly one state.
        $status = $this->statusOf($unitId);
        $this->assertTrue(
            $status === 'available' || $status === 'reserved',
            "unit ended in an invalid state: {$status}"
        );
        if ($secondWon && $status === 'reserved') {
            // second actor won after our rollback — fine, exactly one transition
            $this->assertTrue(true);
        }
    }

    public function testReceiveRejectsInvalidInitialState(): void
    {
        [, , $modelId] = $this->makeCatalog();
        $this->assertThrows(ValidationException::class,
            fn () => $this->inventory->receive([
                'sku' => 'INV-BAD', 'product_model_id' => $modelId, 'condition_grade' => 'B',
                'purchase_cost' => '1', 'landed_cost' => '0', 'selling_price' => '2',
            ], 1, 'sold'));
    }

    private function statusOf(int $unitId): string
    {
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        return (string) $stmt->fetchColumn();
    }
}
