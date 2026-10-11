<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database\Connection;
use PDO;

/**
 * Purchase order CRUD + line items + status transitions.
 *
 * Status flow: draft → submitted → approved → received
 * (or cancelled at any point before receiving).
 *
 * Stock is NOT added when a PO is created/approved. Stock is
 * only added via InventoryService::receive() when individual
 * units arrive — the PO just provides agreed costs and batch ref.
 */
final class PurchaseOrderRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(string $q = '', string $status = '', int $page = 1, int $perPage = 20): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (po.reference LIKE ? OR po.import_batch_ref LIKE ? OR s.name LIKE ?)';
            $like = '%' . $q . '%';
            $params = [$like, $like, $like];
        }
        if ($status !== '' && in_array($status, ['draft', 'submitted', 'approved', 'received', 'cancelled'], true)) {
            $where .= ' AND po.status = ?';
            $params[] = $status;
        }

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM purchase_orders po
             JOIN suppliers s ON s.id = po.supplier_id{$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT po.*, s.name AS supplier_name,
                    (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS item_count
             FROM purchase_orders po
             JOIN suppliers s ON s.id = po.supplier_id
             {$where}
             ORDER BY po.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT po.*, s.name AS supplier_name, s.slug AS supplier_slug
             FROM purchase_orders po
             JOIN suppliers s ON s.id = po.supplier_id
             WHERE po.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['items'] = $this->items($id);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function items(int $poId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT poi.*, pm.name AS product_name, pm.slug AS product_slug
             FROM purchase_order_items poi
             JOIN product_models pm ON pm.id = poi.product_model_id
             WHERE poi.purchase_order_id = ?
             ORDER BY poi.sort_order ASC, poi.id ASC'
        );
        $stmt->execute([$poId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(int $supplierId, ?string $importBatchRef, ?string $notes, int $createdBy, array $items): int
    {
        return Connection::transaction(function (PDO $pdo) use ($supplierId, $importBatchRef, $notes, $createdBy, $items) {
            $reference = $this->generateReference($pdo);
            $subtotal = 0.0;
            foreach ($items as $item) {
                $lineTotal = (int) $item['quantity'] * (float) $item['unit_cost'];
                $subtotal += $lineTotal;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO purchase_orders (reference, supplier_id, status, import_batch_ref, subtotal, discount, total, notes, created_by)
                 VALUES (?, ?, "draft", ?, ?, 0, ?, ?, ?)'
            );
            $stmt->execute([
                $reference, $supplierId, $importBatchRef,
                number_format($subtotal, 2, '.', ''),
                number_format($subtotal, 2, '.', ''),
                $notes, $createdBy,
            ]);
            $poId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO purchase_order_items (purchase_order_id, product_model_id, description, quantity, unit_cost, line_total, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $sort = 0;
            foreach ($items as $item) {
                $qty = max(1, (int) $item['quantity']);
                $cost = max(0, (float) $item['unit_cost']);
                $lineTotal = $qty * $cost;
                $itemStmt->execute([
                    $poId, (int) $item['product_model_id'],
                    $item['description'] ?? null,
                    $qty, number_format($cost, 2, '.', ''),
                    number_format($lineTotal, 2, '.', ''),
                    $sort++,
                ]);
            }

            return $poId;
        });
    }

    public function update(int $id, int $supplierId, ?string $importBatchRef, ?string $notes, array $items): void
    {
        Connection::transaction(function (PDO $pdo) use ($id, $supplierId, $importBatchRef, $notes, $items) {
            $subtotal = 0.0;
            foreach ($items as $item) {
                $subtotal += (int) $item['quantity'] * (float) $item['unit_cost'];
            }

            $stmt = $pdo->prepare(
                'UPDATE purchase_orders SET supplier_id = ?, import_batch_ref = ?, subtotal = ?, total = ?, notes = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $supplierId, $importBatchRef,
                number_format($subtotal, 2, '.', ''),
                number_format($subtotal, 2, '.', ''),
                $notes, $id,
            ]);

            // Replace line items.
            $pdo->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$id]);
            $itemStmt = $pdo->prepare(
                'INSERT INTO purchase_order_items (purchase_order_id, product_model_id, description, quantity, unit_cost, line_total, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $sort = 0;
            foreach ($items as $item) {
                $qty = max(1, (int) $item['quantity']);
                $cost = max(0, (float) $item['unit_cost']);
                $lineTotal = $qty * $cost;
                $itemStmt->execute([
                    $id, (int) $item['product_model_id'],
                    $item['description'] ?? null,
                    $qty, number_format($cost, 2, '.', ''),
                    number_format($lineTotal, 2, '.', ''),
                    $sort++,
                ]);
            }
        });
    }

    public function updateStatus(int $id, string $status, int $userId): void
    {
        Connection::transaction(function (PDO $pdo) use ($id, $status, $userId) {
            $timestamp = match ($status) {
                'submitted' => 'submitted_at = UTC_TIMESTAMP(), ',
                'approved' => 'approved_at = UTC_TIMESTAMP(), approved_by = ?, ',
                'received' => 'received_at = UTC_TIMESTAMP(), ',
                default => '',
            };
            $params = [];
            if ($status === 'approved') {
                $params[] = $userId;
            }
            $params[] = $status;
            $params[] = $id;
            $pdo->prepare("UPDATE purchase_orders SET {$timestamp} status = ? WHERE id = ?")
                ->execute($params);
        });
    }

    private function generateReference(PDO $pdo): string
    {
        $prefix = 'PO';
        for ($i = 0; $i < 10; $i++) {
            $candidate = sprintf('%s-%s-%s', $prefix, gmdate('ymd'), strtoupper(bin2hex(random_bytes(3))));
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE reference = ?');
            $stmt->execute([$candidate]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $candidate;
            }
        }
        throw new \RuntimeException('Could not generate a unique PO reference.');
    }
}
