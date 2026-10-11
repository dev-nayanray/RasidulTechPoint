<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database\Connection;
use PDO;

/**
 * Inventory unit search, filtering, and detail reads.
 *
 * Search supports: text query (SKU, serial, model name), status filter,
 * location filter, condition grade filter, and brand filter. All filters
 * are parameterised (no SQL injection).
 */
final class InventoryRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /**
     * Paginated search with filters.
     *
     * @param string $q text search (SKU, serial, model name)
     * @param string $status status filter (empty = all)
     * @param int $modelId model filter (0 = all)
     * @param int $page page number
     * @param int $perPage items per page
     * @param int $locationId location filter (0 = all)
     * @param string $grade condition grade filter (empty = all)
     * @param int $brandId brand filter (0 = all)
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(
        string $q = '',
        string $status = '',
        int $modelId = 0,
        int $page = 1,
        int $perPage = 20,
        int $locationId = 0,
        string $grade = '',
        int $brandId = 0,
    ): array {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (iu.sku LIKE ? OR iu.serial_number LIKE ? OR pm.name LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        if ($status !== '' && in_array($status, \App\Services\InventoryService::STATUSES, true)) {
            $where .= ' AND iu.status = ?';
            $params[] = $status;
        }
        if ($modelId > 0) {
            $where .= ' AND iu.product_model_id = ?';
            $params[] = $modelId;
        }
        if ($locationId > 0) {
            $where .= ' AND iu.location_id = ?';
            $params[] = $locationId;
        }
        if ($grade !== '' && in_array($grade, ['A', 'B', 'C', 'D'], true)) {
            $where .= ' AND iu.condition_grade = ?';
            $params[] = $grade;
        }
        if ($brandId > 0) {
            $where .= ' AND pm.brand_id = ?';
            $params[] = $brandId;
        }

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM inventory_units iu
             JOIN product_models pm ON pm.id = iu.product_model_id{$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT iu.id, iu.sku, iu.serial_number, iu.status, iu.condition_grade,
                    iu.selling_price, iu.purchase_cost, iu.landed_cost, iu.purchase_ref, iu.created_at,
                    pm.name AS product_name, l.name AS location_name
             FROM inventory_units iu
             JOIN product_models pm ON pm.id = iu.product_model_id
             LEFT JOIN locations l ON l.id = iu.location_id
             {$where}
             ORDER BY iu.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT iu.*, pm.name AS product_name, pm.slug AS product_slug, b.name AS brand_name,
                    l.name AS location_name
             FROM inventory_units iu
             JOIN product_models pm ON pm.id = iu.product_model_id
             JOIN brands b ON b.id = pm.brand_id
             LEFT JOIN locations l ON l.id = iu.location_id
             WHERE iu.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['movements'] = $this->movementsFor($id);
        $row['photos'] = $this->photos($id);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function movementsFor(int $unitId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sm.*, u.name AS user_name
             FROM stock_movements sm
             LEFT JOIN users u ON u.id = sm.performed_by
             WHERE sm.inventory_unit_id = ?
             ORDER BY sm.id DESC'
        );
        $stmt->execute([$unitId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function availableUnitsForModel(int $modelId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, sku, condition_grade, selling_price
             FROM inventory_units
             WHERE product_model_id = ? AND status = 'available'
             ORDER BY selling_price ASC"
        );
        $stmt->execute([$modelId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function statusCounts(): array
    {
        $counts = array_fill_keys(\App\Services\InventoryService::STATUSES, 0);
        foreach ($this->pdo->query(
            'SELECT status, COUNT(*) AS c FROM inventory_units GROUP BY status'
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    /** @return list<array<string,mixed>> */
    public function photos(int $unitId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, file_path, caption, sort_order, created_at
             FROM unit_photos
             WHERE inventory_unit_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$unitId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findPhoto(int $photoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM unit_photos WHERE id = ?');
        $stmt->execute([$photoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function addPhoto(int $unitId, string $filePath, ?string $caption = null): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM unit_photos WHERE inventory_unit_id = ?'
        );
        $stmt->execute([$unitId]);
        $sortOrder = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO unit_photos (inventory_unit_id, file_path, caption, sort_order)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$unitId, $filePath, $caption, $sortOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    public function deletePhoto(int $photoId): void
    {
        $this->pdo->prepare('DELETE FROM unit_photos WHERE id = ?')->execute([$photoId]);
    }
}
