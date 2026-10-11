<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database\Connection;
use PDO;

/**
 * Order reads: filtered + paginated list, single-order detail with
 * item snapshots / payments / customer / status history / fulfillment
 * notes, and a per-customer history.
 */
final class OrderRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /**
     * Paginated, filtered search.
     *
     * @param string $q          text search (reference, customer name, customer phone)
     * @param string $status     order status filter (empty = all)
     * @param string $payment   payment_status filter (empty = all)
     * @param string $channel   channel filter (empty = all)
     * @param string $fromDate  created_at >= (empty = no lower bound)
     * @param string $toDate    created_at <= (empty = no upper bound)
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(
        string $q = '',
        string $status = '',
        string $paymentStatus = '',
        int $page = 1,
        int $perPage = 20,
        string $channel = '',
        string $fromDate = '',
        string $toDate = '',
    ): array {
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
        if ($channel !== '' && in_array($channel, ['online', 'pos'], true)) {
            $where .= ' AND o.channel = ?';
            $params[] = $channel;
        }
        if ($fromDate !== '') {
            $where .= ' AND o.created_at >= ?';
            $params[] = $fromDate . ' 00:00:00';
        }
        if ($toDate !== '') {
            $where .= ' AND o.created_at <= ?';
            $params[] = $toDate . ' 23:59:59';
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM orders o JOIN customers c ON c.id = o.customer_id{$where}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
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

    /**
     * Single order with customer, placed_by, items (snapshots), payments
     * (with receivers), status history timeline and fulfillment notes.
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                    c.address AS customer_address, c.city AS customer_city,
                    u.name AS placed_by_name
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
            'SELECT oi.*, iu.serial_number, iu.condition_grade, iu.status AS unit_status
             FROM order_items oi
             LEFT JOIN inventory_units iu ON iu.id = oi.inventory_unit_id
             WHERE oi.order_id = ?
             ORDER BY oi.id'
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

        $order['status_history'] = $this->statusHistory($id);
        $order['fulfillment_notes'] = $this->fulfillmentNotes($id);
        $order['allowed_transitions'] = (new \App\Services\OrderService())->allowedTransitions((string) $order['status']);

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

    /** @return list<array<string,mixed>> */
    public function statusHistory(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT h.*, u.name AS actor_name
             FROM order_status_history h
             LEFT JOIN users u ON u.id = h.actor_id
             WHERE h.order_id = ?
             ORDER BY h.id'
        );
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function fulfillmentNotes(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT n.*, u.name AS author_name
             FROM order_fulfillment_notes n
             LEFT JOIN users u ON u.id = n.author_id
             WHERE n.order_id = ?
             ORDER BY n.id DESC'
        );
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    }
}
