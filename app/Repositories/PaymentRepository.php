<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database\Connection;
use PDO;

/**
 * Payment reads: filtered + paginated list across orders, single
 * payment detail with order + customer + verifier + reconciler,
 * verification queue (unverified non-cash payments).
 */
final class PaymentRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /**
     * Paginated, filtered search across all payments.
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(
        string $q = '',
        string $status = '',
        string $method = '',
        string $gateway = '',
        string $from = '',
        string $to = '',
        string $verified = '',
        int $shiftId = 0,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (p.reference LIKE ? OR p.evidence_ref LIKE ? OR p.gateway_txn_id LIKE ? OR o.reference LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($status !== '' && in_array($status, ['pending', 'completed', 'failed', 'refunded'], true)) {
            $where .= ' AND p.status = ?';
            $params[] = $status;
        }
        if ($method !== '' && in_array($method, ['cash', 'card', 'bank_transfer', 'bkash', 'nagad', 'other'], true)) {
            $where .= ' AND p.method = ?';
            $params[] = $method;
        }
        if ($gateway !== '') {
            $where .= ' AND p.gateway_name = ?';
            $params[] = $gateway;
        }
        if ($from !== '') {
            $where .= ' AND p.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where .= ' AND p.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        if ($verified === 'yes') {
            $where .= ' AND p.verified_at IS NOT NULL';
        } elseif ($verified === 'no') {
            $where .= ' AND p.verified_at IS NULL AND p.method != "cash"';
        }
        if ($shiftId > 0) {
            $where .= ' AND p.shift_id = ?';
            $params[] = $shiftId;
        }

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM payments p
             JOIN orders o ON o.id = p.order_id
             JOIN customers c ON c.id = o.customer_id
             {$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.order_id, p.amount, p.method, p.status, p.reference, p.evidence_ref,
                    p.gateway_name, p.gateway_txn_id, p.paid_at, p.verified_at, p.refunded_at,
                    p.shift_id, p.parent_payment_id, p.ip_address, p.created_at,
                    o.reference AS order_reference, o.total AS order_total, o.channel AS order_channel,
                    c.name AS customer_name, c.phone AS customer_phone,
                    u.name AS received_by_name,
                    v.name AS verified_by_name,
                    r.name AS refunded_by_name
             FROM payments p
             JOIN orders o ON o.id = p.order_id
             JOIN customers c ON c.id = o.customer_id
             LEFT JOIN users u ON u.id = p.received_by
             LEFT JOIN users v ON v.id = p.verified_by
             LEFT JOIN users r ON r.id = p.refunded_by
             {$where}
             ORDER BY p.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * Single payment with order, customer, verifier, refund trail.
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, o.reference AS order_reference, o.total AS order_total, o.channel AS order_channel,
                    o.status AS order_status, o.payment_status AS order_payment_status,
                    c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                    u.name AS received_by_name,
                    v.name AS verified_by_name,
                    r.name AS refunded_by_name,
                    cs.id AS shift_id, cs.started_at AS shift_started_at, cs.ended_at AS shift_ended_at
             FROM payments p
             JOIN orders o ON o.id = p.order_id
             JOIN customers c ON c.id = o.customer_id
             LEFT JOIN users u ON u.id = p.received_by
             LEFT JOIN users v ON v.id = p.verified_by
             LEFT JOIN users r ON r.id = p.refunded_by
             LEFT JOIN cashier_shifts cs ON cs.id = p.shift_id
             WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        // If this is a refund row (parent_payment_id is set), load the
        // original payment for the timeline.
        if (!empty($row['parent_payment_id'])) {
            $stmt = $this->pdo->prepare(
                'SELECT id, amount, method, status, reference, evidence_ref, paid_at
                 FROM payments WHERE id = ?'
            );
            $stmt->execute([(int) $row['parent_payment_id']]);
            $row['parent_payment'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $row['parent_payment'] = null;
        }

        // Load any refund payments linked to this payment.
        $stmt = $this->pdo->prepare(
            'SELECT id, amount, status, refund_reason, refunded_at, refunded_by
             FROM payments WHERE parent_payment_id = ? ORDER BY id'
        );
        $stmt->execute([$id]);
        $row['refunds'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $row;
    }

    /**
     * Verification queue: non-cash payments that are still 'pending'
     * (no verified_at, no paid_at). Cash is verified at the till by
     * nature so it never appears here.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingVerification(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.order_id, p.amount, p.method, p.reference, p.evidence_ref, p.created_at,
                    p.gateway_name, p.gateway_txn_id,
                    o.reference AS order_reference, o.total AS order_total,
                    c.name AS customer_name, c.phone AS customer_phone,
                    u.name AS received_by_name
             FROM payments p
             JOIN orders o ON o.id = p.order_id
             JOIN customers c ON c.id = o.customer_id
             LEFT JOIN users u ON u.id = p.received_by
             WHERE p.status = "pending" AND p.method != "cash"
             ORDER BY p.id ASC
             LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute([]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------- CATALOG --

    /** @return list<array<string,mixed>> */
    public function methodsCatalog(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM payment_methods_catalog';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY display_order ASC, id ASC';
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findMethod(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payment_methods_catalog WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Find an active payment method by its code. Used by the storefront
     * checkout to validate that the customer's selected method is
     * actually enabled. Returns null when the code doesn't exist or
     * the method is disabled.
     */
    public function findByCode(string $code, bool $activeOnly = false): ?array
    {
        $sql = 'SELECT * FROM payment_methods_catalog WHERE code = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function updateMethod(int $id, ?string $label, ?string $instructions, ?int $isActive, ?int $displayOrder): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE payment_methods_catalog
             SET label = COALESCE(?, label),
                 instructions = COALESCE(?, instructions),
                 is_active = COALESCE(?, is_active),
                 display_order = COALESCE(?, display_order)
             WHERE id = ?'
        );
        $stmt->execute([$label, $instructions, $isActive, $displayOrder, $id]);
    }

    // ---------------------------------------------------- RECONCILIATION --

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function batches(int $page = 1, int $perPage = 25): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM reconciliation_batches')->fetchColumn();
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT b.*, u.name AS prepared_by_name, c.name AS confirmed_by_name
             FROM reconciliation_batches b
             LEFT JOIN users u ON u.id = b.prepared_by
             LEFT JOIN users c ON c.id = b.confirmed_by
             ORDER BY b.batch_date DESC, b.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute([]);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function findBatch(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, u.name AS prepared_by_name, c.name AS confirmed_by_name
             FROM reconciliation_batches b
             LEFT JOIN users u ON u.id = b.prepared_by
             LEFT JOIN users c ON c.id = b.confirmed_by
             WHERE b.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findBatchByDate(string $date): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM reconciliation_batches WHERE batch_date = ?');
        $stmt->execute([$date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
