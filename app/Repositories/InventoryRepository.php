<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database\Connection;
use PDO;

final class InventoryRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(string $q = '', string $status = '', int $modelId = 0, int $page = 1, int $perPage = 20): array
    {
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

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM inventory_units iu JOIN product_models pm ON pm.id = iu.product_model_id{$where}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

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

    /** @return list<array<string,mixed>> available units for a model (storefront) */
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
}
