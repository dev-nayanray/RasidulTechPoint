<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database\Connection;
use PDO;

/**
 * Customer management repository.
 *
 * - Search with status filter, pagination, optional finance scope.
 * - find() loads the customer + paginated order history + recent notes.
 * - Account status transitions are atomic and write to the audit log
 *   by the controller (repository never sees an HTTP request).
 * - Soft-delete preserves the row (and therefore every order's FK);
 *   a customer that has ever placed an order cannot be hard-deleted
 *   by this layer — orders.customer_id is ON DELETE RESTRICT.
 * - Lifetime value / orders_count / last_order_at are denormalized
 *   rollups refreshed by recalculateStats() after every order.
 */
final class CustomerRepository
{
    private PDO $pdo;

    public const ACCOUNT_STATUSES = ['active', 'suspended', 'blacklisted', 'vip'];

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /**
     * Paginated search across name / phone / email / alt_phone.
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(string $q = '', string $status = '', int $page = 1, int $perPage = 20): array
    {
        $where = ' WHERE c.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.alt_phone LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($status !== '' && in_array($status, self::ACCOUNT_STATUSES, true)) {
            $where .= ' AND c.account_status = ?';
            $params[] = $status;
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM customers c{$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.name, c.phone, c.email, c.alt_phone, c.city,
                    c.account_status, c.lifetime_value, c.orders_count, c.last_order_at,
                    c.created_at, c.deleted_at,
                    u.email AS account_email
             FROM customers c
             LEFT JOIN users u ON u.id = c.user_id
             {$where}
             ORDER BY c.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * Find one customer (excluding soft-deleted). Loads:
     *  - recent orders (full history, capped by $orderLimit)
     *  - recent staff notes (under staff_notes key; the customer.notes
     *    column is preserved as the summary text under the notes key)
     */
    public function find(int $id, int $orderLimit = 50, int $noteLimit = 25): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, u.email AS account_email
             FROM customers c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.id = ? AND c.deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['orders'] = $this->ordersFor($id, $orderLimit);
        $row['staff_notes'] = $this->notes($id, $noteLimit);
        return $row;
    }

    /** Like find() but ignores the soft-delete flag (for the show page on a deleted row). */
    public function findWithTrashed(int $id, int $orderLimit = 50, int $noteLimit = 25): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, u.email AS account_email
             FROM customers c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['orders'] = $this->ordersFor($id, $orderLimit);
        $row['staff_notes'] = $this->notes($id, $noteLimit);
        return $row;
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

    /**
     * Find a customer by phone. Used by the guest checkout flow to
     * resolve an existing customer record before placing an order
     * (so a returning customer doesn't get a duplicate row).
     */
    public function findByPhone(string $phone): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM customers WHERE phone = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Create a customer. Throws PDOException on duplicate phone/email thanks
     * to the unique indexes — callers should catch it for friendly errors.
     */
    public function create(array $d, ?int $userId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO customers
                (user_id, name, phone, alt_phone, email, address, city,
                 account_status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $d['name'],
            $d['phone'],
            $d['alt_phone'] ?? null ?: null,
            $d['email'] ?? null ?: null,
            $d['address'] ?? null ?: null,
            $d['city'] ?? null ?: null,
            $d['account_status'] ?? 'active',
            $d['notes'] ?? null ?: null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE customers
             SET name = ?, phone = ?, alt_phone = ?, email = ?, address = ?, city = ?, notes = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $d['name'],
            $d['phone'],
            $d['alt_phone'] ?? null ?: null,
            $d['email'] ?? null ?: null,
            $d['address'] ?? null ?: null,
            $d['city'] ?? null ?: null,
            $d['notes'] ?? null ?: null,
            $id,
        ]);
    }

    /** Atomic account_status update. Use for suspend/activate/blacklist/vip. */
    public function updateStatus(int $id, string $status): void
    {
        if (!in_array($status, self::ACCOUNT_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid account status: {$status}");
        }
        $stmt = $this->pdo->prepare('UPDATE customers SET account_status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    /**
     * Append a time-stamped staff note. The customer_notes table is
     * append-only: this method never updates or deletes a row.
     */
    public function addNote(int $customerId, int $authorId, string $body): int
    {
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('Note body cannot be empty.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO customer_notes (customer_id, author_id, body) VALUES (?, ?, ?)'
        );
        $stmt->execute([$customerId, $authorId, $body]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function notes(int $customerId, int $limit = 25): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cn.*, u.name AS author_name
             FROM customer_notes cn
             LEFT JOIN users u ON u.id = cn.author_id
             WHERE cn.customer_id = ?
             ORDER BY cn.id DESC
             LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function ordersFor(int $customerId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reference, channel, status, payment_status, total, created_at, completed_at
             FROM orders WHERE customer_id = ?
             ORDER BY id DESC
             LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recompute the denormalized rollups (lifetime_value, orders_count,
     * last_order_at) from the orders table. Called after every order
     * transition that affects totals.
     */
    public function recalculateStats(int $customerId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE customers c
             SET c.lifetime_value = (
                    SELECT COALESCE(SUM(o.total), 0)
                    FROM orders o
                    WHERE o.customer_id = c.id
                      AND o.status NOT IN ('cancelled','refunded')
                 ),
                 c.orders_count = (
                    SELECT COUNT(*) FROM orders o
                    WHERE o.customer_id = c.id
                      AND o.status NOT IN ('cancelled','refunded')
                 ),
                 c.last_order_at = (
                    SELECT MAX(o.created_at) FROM orders o
                    WHERE o.customer_id = c.id
                 )
             WHERE c.id = ?"
        );
        $stmt->execute([$customerId]);
    }

    public function softDelete(int $id): void
    {
        $this->pdo->prepare('UPDATE customers SET deleted_at = UTC_TIMESTAMP() WHERE id = ? AND deleted_at IS NULL')
            ->execute([$id]);
    }

    public function restore(int $id): void
    {
        $this->pdo->prepare('UPDATE customers SET deleted_at = NULL WHERE id = ?')
            ->execute([$id]);
    }

    public function isDeleted(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT deleted_at FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null;
    }

    /**
     * Has this customer ever placed an order? Used to forbid
     * soft-deleting customers with active history (the FK already
     * forbids hard deletes; soft-delete is the operator's call but
     * should still warn about history).
     */
    public function hasOrders(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM orders WHERE customer_id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function phoneTaken(string $phone, ?int $excludeId = null): bool
    {
        return $this->takenBy('phone', $phone, $excludeId);
    }

    public function emailTaken(string $email, ?int $excludeId = null): bool
    {
        return $this->takenBy('email', $email, $excludeId);
    }

    private function takenBy(string $column, string $value, ?int $excludeId): bool
    {
        if ($value === '') {
            return false;
        }
        $sql = "SELECT COUNT(*) FROM customers WHERE {$column} = ? AND deleted_at IS NULL";
        $params = [$value];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Find customers that share a phone or email with another customer.
     *
     * Surfaces BOTH active+active and active+soft-deleted collisions: the
     * active+active case is prevented by the unique index at insert time,
     * but the active+soft-deleted case is real (a customer was removed,
     * their phone was reused, and later someone tries to merge them).
     *
     * @return list<array<string,mixed>> pairs of customers that look alike
     */
    public function findDuplicates(): array
    {
        $stmt = $this->pdo->query(
            "SELECT 'phone' AS match_on,
                    a.id AS a_id, a.name AS a_name, a.phone, a.email AS a_email,
                    a.deleted_at AS a_deleted_at,
                    b.id AS b_id, b.name AS b_name, b.email AS b_email,
                    b.deleted_at AS b_deleted_at
             FROM customers a
             JOIN customers b ON a.phone = b.phone
                                AND a.phone <> ''
                                AND a.id < b.id
             ORDER BY a_id, b_id
             LIMIT 100"
        );
        $phoneRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->query(
            "SELECT 'email' AS match_on,
                    a.id AS a_id, a.name AS a_name, a.phone AS a_phone, a.email,
                    a.deleted_at AS a_deleted_at,
                    b.id AS b_id, b.name AS b_name, b.phone AS b_phone,
                    b.deleted_at AS b_deleted_at
             FROM customers a
             JOIN customers b ON a.email = b.email
                                AND a.email <> ''
                                AND a.id < b.id
             ORDER BY a_id, b_id
             LIMIT 100"
        );
        $emailRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rows = array_merge($phoneRows, $emailRows);
        usort($rows, fn ($x, $y) => [$x['match_on'], $x['a_id'], $x['b_id']]
            <=> [$y['match_on'], $y['a_id'], $y['b_id']]);
        return $rows;
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL'
        )->fetchColumn();
    }
}
