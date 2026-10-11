<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database\Connection;
use PDO;

/**
 * Courier + delivery zone CRUD with soft-delete for couriers.
 *
 * Couriers are soft-deleted (deleted_at) so historical shipments keep
 * a valid FK target (the courier row survives even if deactivated).
 * Delivery zones are hard-deleted — they're referenced via nullable FK
 * on shipments, so a deleted zone leaves the shipment's zone_id NULL.
 */
final class CourierRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    // ---------------------------------------------------- COURIERS --

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(string $q = '', int $page = 1, int $perPage = 20): array
    {
        $where = ' WHERE c.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (c.name LIKE ? OR c.contact_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)';
            $like = '%' . $q . '%';
            $params = [$like, $like, $like, $like];
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM couriers c{$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM shipments s WHERE s.courier_id = c.id) AS shipment_count,
                    (SELECT COUNT(*) FROM delivery_zones z WHERE z.courier_id = c.id) AS zone_count
             FROM couriers c{$where}
             ORDER BY c.name ASC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM couriers WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM shipments WHERE courier_id = ?'
        );
        $stmt->execute([$id]);
        $row['shipment_count'] = (int) $stmt->fetchColumn();
        return $row;
    }

    public function findWithTrashed(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM couriers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function create(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO couriers (name, slug, contact_name, email, phone, api_provider, api_credentials, base_fee, per_km_fee, tracking_url_template, is_active, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $d['name'],
            $d['slug'],
            $d['contact_name'] ?? null ?: null,
            $d['email'] ?? null ?: null,
            $d['phone'] ?? null ?: null,
            $d['api_provider'] ?? 'manual',
            $d['api_credentials'] ?? null ?: null,
            number_format((float) ($d['base_fee'] ?? 0), 2, '.', ''),
            number_format((float) ($d['per_km_fee'] ?? 0), 2, '.', ''),
            $d['tracking_url_template'] ?? null ?: null,
            !empty($d['is_active']) ? 1 : 0,
            $d['notes'] ?? null ?: null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE couriers
             SET name = ?, contact_name = ?, email = ?, phone = ?, api_provider = ?, api_credentials = ?,
                 base_fee = ?, per_km_fee = ?, tracking_url_template = ?, is_active = ?, notes = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $d['name'],
            $d['contact_name'] ?? null ?: null,
            $d['email'] ?? null ?: null,
            $d['phone'] ?? null ?: null,
            $d['api_provider'] ?? 'manual',
            $d['api_credentials'] ?? null ?: null,
            number_format((float) ($d['base_fee'] ?? 0), 2, '.', ''),
            number_format((float) ($d['per_km_fee'] ?? 0), 2, '.', ''),
            $d['tracking_url_template'] ?? null ?: null,
            !empty($d['is_active']) ? 1 : 0,
            $d['notes'] ?? null ?: null,
            $id,
        ]);
    }

    public function softDelete(int $id): void
    {
        $this->pdo->prepare('UPDATE couriers SET deleted_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
    }

    public function restore(int $id): void
    {
        $this->pdo->prepare('UPDATE couriers SET deleted_at = NULL WHERE id = ?')->execute([$id]);
    }

    public function isDeleted(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT deleted_at FROM couriers WHERE id = ?');
        $stmt->execute([$id]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null;
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM couriers WHERE slug = ? AND deleted_at IS NULL';
        $params = [$slug];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT id, name, base_fee, tracking_url_template FROM couriers WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------- DELIVERY ZONES --

    /** @return list<array<string,mixed>> */
    public function allZones(): array
    {
        return $this->pdo->query(
            'SELECT z.*, c.name AS courier_name
             FROM delivery_zones z
             LEFT JOIN couriers c ON c.id = z.courier_id
             WHERE z.is_active = 1
             ORDER BY z.name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findZone(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT z.*, c.name AS courier_name
             FROM delivery_zones z
             LEFT JOIN couriers c ON c.id = z.courier_id
             WHERE z.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function createZone(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO delivery_zones (name, description, courier_id, base_fee, estimated_delivery_hours, is_active)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $d['name'],
            $d['description'] ?? null ?: null,
            $d['courier_id'] ?? null ?: null,
            number_format((float) ($d['base_fee'] ?? 0), 2, '.', ''),
            $d['estimated_delivery_hours'] ?? null ?: null,
            !empty($d['is_active']) ? 1 : 0,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateZone(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE delivery_zones
             SET name = ?, description = ?, courier_id = ?, base_fee = ?, estimated_delivery_hours = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $d['name'],
            $d['description'] ?? null ?: null,
            $d['courier_id'] ?? null ?: null,
            number_format((float) ($d['base_fee'] ?? 0), 2, '.', ''),
            $d['estimated_delivery_hours'] ?? null ?: null,
            !empty($d['is_active']) ? 1 : 0,
            $id,
        ]);
    }

    public function deleteZone(int $id): void
    {
        $this->pdo->prepare('DELETE FROM delivery_zones WHERE id = ?')->execute([$id]);
    }
}
