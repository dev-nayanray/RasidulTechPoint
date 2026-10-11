<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database\Connection;
use PDO;

final class CustomerRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(string $q = '', int $page = 1, int $perPage = 20): array
    {
        $where = ' WHERE c.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';
            $params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM customers c{$where}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT c.*, u.email AS account_email,
                    (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS order_count
             FROM customers c
             LEFT JOIN users u ON u.id = c.user_id
             {$where}
             ORDER BY c.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, u.email AS account_email
             FROM customers c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.id = ? AND c.deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $orders = $this->pdo->prepare(
                'SELECT id, reference, status, payment_status, total, created_at FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 10'
            );
            $orders->execute([$id]);
            $row['orders'] = $orders->fetchAll(PDO::FETCH_ASSOC);
        }
        return $row === false ? null : $row;
    }

    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.* FROM customers c WHERE c.user_id = ? AND c.deleted_at IS NULL'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function create(array $d, ?int $userId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO customers (user_id, name, phone, email, address, city, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId, $d['name'], $d['phone'], $d['email'] ?? null,
            $d['address'] ?? null, $d['city'] ?? null, $d['notes'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE customers SET name = ?, phone = ?, email = ?, address = ?, city = ?, notes = ? WHERE id = ?'
        );
        $stmt->execute([
            $d['name'], $d['phone'], $d['email'] ?? null,
            $d['address'] ?? null, $d['city'] ?? null, $d['notes'] ?? null, $id,
        ]);
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL'
        )->fetchColumn();
    }
}
