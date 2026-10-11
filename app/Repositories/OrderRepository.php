<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database\Connection;
use PDO;

final class OrderRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(string $q = '', string $status = '', string $paymentStatus = '', int $page = 1, int $perPage = 20): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (o.reference LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        if ($status !== '' && in_array($status, \App\Services\OrderService::ORDER_STATUSES, true)) {
            $where .= ' AND o.status = ?';
            $params[] = $status;
        }
        if ($paymentStatus !== '' && in_array($paymentStatus, \App\Services\OrderService::PAYMENT_STATUSES, true)) {
            $where .= ' AND o.payment_status = ?';
            $params[] = $paymentStatus;
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM orders o JOIN customers c ON c.id = o.customer_id{$where}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT o.id, o.reference, o.channel, o.status, o.payment_status, o.total, o.created_at,
                    c.name AS customer_name, c.phone AS customer_phone
             FROM orders o JOIN customers c ON c.id = o.customer_id
             {$where}
             ORDER BY o.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                    c.address AS customer_address, u.name AS placed_by_name
             FROM orders o
             JOIN customers c ON c.id = o.customer_id
             LEFT JOIN users u ON u.id = o.placed_by
             WHERE o.id = ?'
        );
        $stmt->execute([$id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order === false) {
            return null;
        }

        $items = $this->pdo->prepare(
            'SELECT oi.*, iu.serial_number, iu.condition_grade
             FROM order_items oi
             LEFT JOIN inventory_units iu ON iu.id = oi.inventory_unit_id
             WHERE oi.order_id = ?'
        );
        $items->execute([$id]);
        $order['items'] = $items->fetchAll(PDO::FETCH_ASSOC);

        $payments = $this->pdo->prepare(
            'SELECT p.*, u.name AS received_by_name
             FROM payments p LEFT JOIN users u ON u.id = p.received_by
             WHERE p.order_id = ? ORDER BY p.id'
        );
        $payments->execute([$id]);
        $order['payments'] = $payments->fetchAll(PDO::FETCH_ASSOC);

        return $order;
    }

    public function findByReferenceForCustomer(string $reference, int $customerId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM orders WHERE reference = ? AND customer_id = ?'
        );
        $stmt->execute([$reference, $customerId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : $this->find((int) $id);
    }

    /** @return list<array<string,mixed>> */
    public function forCustomer(int $customerId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reference, status, payment_status, total, created_at, completed_at
             FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT ' . (int) $limit
        );
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    }
}
