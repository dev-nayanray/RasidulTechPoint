<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Supplier CRUD + search with pagination.
 *
 * Outstanding balance is a manual field (DECIMAL 14,2). When the
 * Purchases module is built, it will be computed from purchase order
 * totals minus payments. For now it can be set/updated manually.
 */
final class SupplierRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(string $q = '', int $page = 1, int $perPage = 20): array
    {
        $where = ' WHERE s.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (s.name LIKE ? OR s.contact_name LIKE ? OR s.email LIKE ? OR s.phone LIKE ?)';
            $like = '%' . $q . '%';
            $params = [$like, $like, $like, $like];
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM suppliers s{$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM inventory_units iu WHERE iu.supplier_ref = s.name) AS unit_count
             FROM suppliers s{$where}
             ORDER BY s.name ASC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM suppliers WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        // Count units referenced by this supplier (by name match).
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM inventory_units WHERE supplier_ref = ?');
        $stmt->execute([$row['name']]);
        $row['unit_count'] = (int) $stmt->fetchColumn();
        return $row;
    }

    public function findWithTrashed(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function create(string $name, string $slug, ?string $contactName, ?string $email, ?string $phone, ?string $address, ?string $city, ?string $country, ?string $notes): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO suppliers (name, slug, contact_name, email, phone, address, city, country, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $slug, $contactName, $email, $phone, $address, $city, $country, $notes]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, ?string $contactName, ?string $email, ?string $phone, ?string $address, ?string $city, ?string $country, ?string $notes, bool $isActive): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE suppliers SET name = ?, contact_name = ?, email = ?, phone = ?, address = ?, city = ?, country = ?, notes = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([$name, $contactName, $email, $phone, $address, $city, $country, $notes, $isActive ? 1 : 0, $id]);
    }

    public function softDelete(int $id): void
    {
        $this->pdo->prepare('UPDATE suppliers SET deleted_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
    }

    public function restore(int $id): void
    {
        $this->pdo->prepare('UPDATE suppliers SET deleted_at = NULL WHERE id = ?')->execute([$id]);
    }

    public function isDeleted(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT deleted_at FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetchColumn();
        return $row !== false && $row !== null;
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE slug = ? AND id != ? AND deleted_at IS NULL');
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE slug = ? AND deleted_at IS NULL');
            $stmt->execute([$slug]);
        }
        return (int) $stmt->fetchColumn() > 0;
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT id, name FROM suppliers WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }
}
