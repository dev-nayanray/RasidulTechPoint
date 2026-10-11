<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Centralized inventory operations.
 *
 * Every mutation happens inside a transaction, locks the unit row
 * (SELECT ... FOR UPDATE), validates the status transition against the
 * state machine below, and appends a stock_movements history row.
 * No physical unit can move into an invalid state, and history rows
 * are never deleted.
 */
final class InventoryService
{
    /**
     * State machine: movement type => from status => list of allowed targets.
     */
    public const TRANSITIONS = [
        'receipt' => [
            'in_transit' => ['awaiting_inspection', 'available'],
        ],
        'inspection' => [
            'awaiting_inspection' => ['available', 'damaged'],
            'returned' => ['available', 'damaged'],
            'damaged' => ['available'],
        ],
        'adjustment' => [
            'available' => ['damaged', 'written_off', 'in_transit'],
            'damaged' => ['written_off'],
            'reserved' => ['damaged'],
            'in_transit' => ['available'],
            'awaiting_inspection' => ['damaged'],
        ],
        'reservation' => [
            'available' => ['reserved'],
        ],
        'release' => [
            'reserved' => ['available'],
        ],
        'sale' => [
            'available' => ['sold'],
            'reserved' => ['sold'],
        ],
        'return' => [
            'sold' => ['returned'],
        ],
        'write_off' => [
            'available' => ['written_off'],
            'damaged' => ['written_off'],
            'returned' => ['written_off'],
            'awaiting_inspection' => ['written_off'],
        ],
    ];

    public const STATUSES = [
        'in_transit', 'awaiting_inspection', 'available', 'reserved',
        'sold', 'returned', 'damaged', 'written_off',
    ];

    /** Movement types that end a unit's sellable life. */
    public const TERMINAL_STATUSES = ['sold', 'written_off'];

    /**
     * Receive new stock. Creates the unit and its initial movement row.
     *
     * @param array $data sku, serial_number?, product_model_id, condition_grade,
     *                    purchase_cost, landed_cost, selling_price, min_selling_price?,
     *                    location_id?, supplier_ref?, purchase_ref?, notes?
     * @return int new unit id
     */
    public function receive(array $data, int $userId, string $initialState = 'awaiting_inspection'): int
    {
        if (!in_array($initialState, ['awaiting_inspection', 'in_transit'], true)) {
            throw new ValidationException(['initial_status' => 'Invalid initial inventory status.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($data, $userId, $initialState) {
            $stmt = $pdo->prepare(
                'INSERT INTO inventory_units
                    (sku, serial_number, product_model_id, condition_grade, status,
                     purchase_cost, landed_cost, selling_price, min_selling_price,
                     location_id, supplier_ref, purchase_ref, received_at, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)'
            );
            $stmt->execute([
                $data['sku'],
                $data['serial_number'] ?: null,
                (int) $data['product_model_id'],
                $data['condition_grade'],
                $initialState,
                number_format((float) $data['purchase_cost'], 2, '.', ''),
                number_format((float) $data['landed_cost'], 2, '.', ''),
                number_format((float) $data['selling_price'], 2, '.', ''),
                isset($data['min_selling_price']) && $data['min_selling_price'] !== ''
                    ? number_format((float) $data['min_selling_price'], 2, '.', '') : null,
                $data['location_id'] ?? null,
                $data['supplier_ref'] ?? null,
                $data['purchase_ref'] ?? null,
                $data['notes'] ?? null,
            ]);
            $unitId = (int) $pdo->lastInsertId();

            $this->insertMovement($pdo, $unitId, 'receipt', null, $initialState, 'Stock received', $userId, [
                'sku' => $data['sku'],
                'purchase_ref' => $data['purchase_ref'] ?? null,
            ]);

            return $unitId;
        });
    }

    /**
     * Validated transition. Locks the row first; refuses any transition
     * not whitelisted by the state machine.
     */
    public function transition(
        int $unitId,
        string $movementType,
        string $toStatus,
        string $reason,
        int $userId,
        array $extra = [],
    ): array {
        return Connection::transaction(function (PDO $pdo) use ($unitId, $movementType, $toStatus, $reason, $userId, $extra) {
            $unit = $this->lockUnit($pdo, $unitId);
            $from = $unit['status'];

            $allowed = self::TRANSITIONS[$movementType][$from] ?? [];
            if (!in_array($toStatus, $allowed, true)) {
                throw new ValidationException([
                    'status' => "Cannot move unit from '{$from}' to '{$toStatus}' via '{$movementType}'.",
                ]);
            }

            $updates = ['status = ?', 'version = version + 1'];
            $params = [$toStatus];

            if ($movementType === 'sale') {
                $updates[] = 'sold_at = UTC_TIMESTAMP()';
            }
            if ($movementType === 'inspection' && $toStatus === 'available') {
                $updates[] = 'inspected_at = UTC_TIMESTAMP()';
                $updates[] = 'inspected_by = ?';
                $params[] = $userId;
            }
            if (isset($extra['location_id']) && $movementType !== 'sale') {
                $updates[] = 'location_id = ?';
                $params[] = $extra['location_id'];
            }
            if (isset($extra['notes'])) {
                $updates[] = 'notes = ?';
                $params[] = $extra['notes'];
            }

            $params[] = $unitId;
            $stmt = $pdo->prepare('UPDATE inventory_units SET ' . implode(', ', $updates) . ' WHERE id = ?');
            $stmt->execute($params);

            $this->insertMovement($pdo, $unitId, $movementType, $from, $toStatus, $reason, $userId, $extra);

            $unit['status'] = $toStatus;
            return $unit;
        });
    }

    // ------------------------------------------------------------------
    // Convenience wrappers for the common operations.
    // ------------------------------------------------------------------

    public function reserve(int $unitId, string $reason, int $userId): array
    {
        return $this->transition($unitId, 'reservation', 'reserved', $reason, $userId);
    }

    public function release(int $unitId, string $reason, int $userId): array
    {
        return $this->transition($unitId, 'release', 'available', $reason, $userId);
    }

    public function markSold(int $unitId, string $reason, int $userId): array
    {
        return $this->transition($unitId, 'sale', 'sold', $reason, $userId);
    }

    public function markReturned(int $unitId, string $reason, int $userId): array
    {
        return $this->transition($unitId, 'return', 'returned', $reason, $userId);
    }

    public function passInspection(int $unitId, string $reason, int $userId): array
    {
        return $this->transition($unitId, 'inspection', 'available', $reason, $userId);
    }

    public function failInspection(int $unitId, string $reason, int $userId): array
    {
        return $this->transition($unitId, 'inspection', 'damaged', $reason, $userId);
    }

    public function writeOff(int $unitId, string $reason, int $userId): array
    {
        return $this->transition($unitId, 'write_off', 'written_off', $reason, $userId);
    }

    /**
     * Price change with audit movement. Selling price cannot fall below
     * min_selling_price when one is configured.
     */
    public function changePrice(int $unitId, float $newPrice, int $userId): void
    {
        Connection::transaction(function (PDO $pdo) use ($unitId, $newPrice, $userId) {
            $unit = $this->lockUnit($pdo, $unitId);
            if (in_array($unit['status'], self::TERMINAL_STATUSES, true)) {
                throw new ValidationException(['status' => 'Price cannot change for sold or written-off units.']);
            }
            if ($unit['min_selling_price'] !== null && $newPrice < (float) $unit['min_selling_price']) {
                throw new ValidationException([
                    'selling_price' => 'Price is below the configured minimum selling price ('
                        . money($unit['min_selling_price']) . ').',
                ]);
            }
            $stmt = $pdo->prepare('UPDATE inventory_units SET selling_price = ? WHERE id = ?');
            $stmt->execute([number_format($newPrice, 2, '.', ''), $unitId]);

            $this->insertMovement($pdo, $unitId, 'adjustment', $unit['status'], $unit['status'],
                'Price updated from ' . $unit['selling_price'] . ' to ' . number_format($newPrice, 2, '.', ''), $userId);
        });
    }

    /** @return array<string,mixed> locked unit row */
    private function lockUnit(PDO $pdo, int $unitId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM inventory_units WHERE id = ? FOR UPDATE');
        $stmt->execute([$unitId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($unit === false) {
            throw new ValidationException(['unit' => "Inventory unit {$unitId} does not exist."]);
        }
        return $unit;
    }

    private function insertMovement(
        PDO $pdo,
        int $unitId,
        string $type,
        ?string $from,
        ?string $to,
        string $reason,
        int $userId,
        array $metadata = [],
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO stock_movements (inventory_unit_id, movement_type, from_status, to_status, reason, metadata, performed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $unitId,
            $type,
            $from,
            $to,
            mb_substr($reason, 0, 255),
            $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            $userId,
        ]);
    }

    // ------------------------------------------------------------------
    // Seeding entry point (same code path as production logic).
    // ------------------------------------------------------------------

    public static function forSeeding(): self
    {
        return new self();
    }

    /** @internal used by DemoDataSeeder only */
    public function receiveUnitForSeeding(array $data, string $finalStatus, ?int $userId): int
    {
        $actor = $userId ?? 1;
        $unitId = $this->receive($data, $actor);

        if ($finalStatus === 'available' || $finalStatus === 'damaged') {
            $this->transition($unitId, 'inspection', $finalStatus, 'Seeded inspection result', $actor);
        } elseif ($finalStatus !== 'awaiting_inspection') {
            throw new \RuntimeException("Unsupported seeded final status {$finalStatus}");
        }
        return $unitId;
    }
}
