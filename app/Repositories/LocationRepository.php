<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Location CRUD + stock-location relationships.
 *
 * Each location can be a shop, warehouse, or service center. One
 * location can be marked is_primary (the main shop). The stock-
 * location relationship is read via inventory_units.location_id —
 * this repo provides per-status counts so the UI can show "12
 * available, 3 awaiting inspection" per branch.
 */
final class LocationRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /** All locations with aggregated stock counts per status. */
    public function allWithStockCounts(): array
    {
        $sql = "SELECT l.id, l.name, l.code, l.type, l.address, l.phone,
                       l.is_active, l.is_primary,
                       (SELECT COUNT(*) FROM inventory_units iu WHERE iu.location_id = l.id AND iu.status = 'available') AS available_count,
                       (SELECT COUNT(*) FROM inventory_units iu WHERE iu.location_id = l.id AND iu.status = 'awaiting_inspection') AS awaiting_count,
                       (SELECT COUNT(*) FROM inventory_units iu WHERE iu.location_id = l.id AND iu.status = 'reserved') AS reserved_count,
                       (SELECT COUNT(*) FROM inventory_units iu WHERE iu.location_id = l.id AND iu.status = 'in_transit') AS in_transit_count,
                       (SELECT COUNT(*) FROM inventory_units iu WHERE iu.location_id = l.id AND iu.status = 'sold') AS sold_count,
                       (SELECT COUNT(*) FROM inventory_units iu WHERE iu.location_id = l.id) AS total_units
                FROM locations l
                ORDER BY l.is_primary DESC, l.name ASC";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Single location with its stock breakdown. */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, code, type, address, phone, is_active, is_primary
             FROM locations WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['stock'] = $this->stockCounts($id);
        return $row;
    }

    /** Count inventory units at this location, grouped by status. */
    public function stockCounts(int $locationId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT status, COUNT(*) AS cnt
             FROM inventory_units WHERE location_id = ?
             GROUP BY status"
        );
        $stmt->execute([$locationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
        }
        return $counts;
    }

    public function create(string $name, ?string $code, string $type, ?string $address, ?string $phone, bool $isPrimary = false): int
    {
        return Connection::transaction(function (PDO $pdo) use ($name, $code, $type, $address, $phone, $isPrimary) {
            // If this is the primary, unset any existing primary first.
            if ($isPrimary) {
                $pdo->exec('UPDATE locations SET is_primary = 0 WHERE is_primary = 1');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO locations (name, code, type, address, phone, is_active, is_primary)
                 VALUES (?, ?, ?, ?, ?, 1, ?)'
            );
            $stmt->execute([$name, $code, $type, $address, $phone, $isPrimary ? 1 : 0]);
            return (int) $pdo->lastInsertId();
        });
    }

    public function update(int $id, string $name, ?string $code, string $type, ?string $address, ?string $phone, bool $isActive): void
    {
        Connection::transaction(function (PDO $pdo) use ($id, $name, $code, $type, $address, $phone, $isActive) {
            $stmt = $pdo->prepare(
                'UPDATE locations SET name = ?, code = ?, type = ?, address = ?, phone = ?, is_active = ? WHERE id = ?'
            );
            $stmt->execute([$name, $code, $type, $address, $phone, $isActive ? 1 : 0, $id]);
        });
    }

    /** Designate this location as the primary shop (unsets all others). */
    public function setPrimary(int $id): void
    {
        Connection::transaction(function (PDO $pdo) use ($id) {
            $pdo->exec('UPDATE locations SET is_primary = 0 WHERE is_primary = 1');
            $pdo->prepare('UPDATE locations SET is_primary = 1, type = \'shop\' WHERE id = ?')->execute([$id]);
        });
    }

    /**
     * Delete a location that has no inventory units assigned. Refuses
     * if any unit still references it (FK is ON DELETE SET NULL but
     * we don't want units to silently lose their location).
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM inventory_units WHERE location_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new ValidationException([
                'location' => 'Cannot delete a location that still has inventory units. Reassign them first.',
            ]);
        }

        $this->pdo->prepare('DELETE FROM locations WHERE id = ?')->execute([$id]);
    }

    /** Whether a code is already used by a different location. */
    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        if ($code === '') {
            return false;
        }
        if ($excludeId !== null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM locations WHERE code = ? AND id != ?');
            $stmt->execute([$code, $excludeId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM locations WHERE code = ?');
            $stmt->execute([$code]);
        }
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Whether a name is already used by a different location. */
    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM locations WHERE name = ? AND id != ?');
            $stmt->execute([$name, $excludeId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM locations WHERE name = ?');
            $stmt->execute([$name]);
        }
        return (int) $stmt->fetchColumn() > 0;
    }
}
