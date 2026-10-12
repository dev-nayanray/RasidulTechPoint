<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database\Connection;
use PDO;

/**
 * Coupon reads: list with filters, single coupon detail with target
 * products / categories, and per-coupon usage stats. All writes
 * (create / update / status transitions / record usage) live in
 * CouponService — the repository is read-only by design.
 */
final class CouponRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /**
     * Paginated, filtered list.
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(string $q = '', string $status = '', int $page = 1, int $perPage = 20): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (c.code LIKE ? OR c.description LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $validStatuses = ['draft', 'pending_approval', 'active', 'paused', 'rejected', 'archived'];
        if ($status !== '' && in_array($status, $validStatuses, true)) {
            $where .= ' AND c.status = ?';
            $params[] = $status;
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM coupons c{$where}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT c.*,
                    u.name AS created_by_name,
                    au.name AS approved_by_name,
                    ru.name AS rejected_by_name,
                    (SELECT COUNT(*) FROM coupon_usages cu WHERE cu.coupon_id = c.id) AS usage_count
             FROM coupons c
             LEFT JOIN users u ON u.id = c.created_by
             LEFT JOIN users au ON au.id = c.approved_by
             LEFT JOIN users ru ON ru.id = c.rejected_by
             {$where}
             ORDER BY c.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*,
                    u.name AS created_by_name,
                    au.name AS approved_by_name,
                    ru.name AS rejected_by_name
             FROM coupons c
             LEFT JOIN users u ON u.id = c.created_by
             LEFT JOIN users au ON au.id = c.approved_by
             LEFT JOIN users ru ON ru.id = c.rejected_by
             WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['target_products'] = $this->targetProducts((int) $id);
        $row['target_categories'] = $this->targetCategories((int) $id);
        return $row;
    }

    public function findByCode(string $code): ?array
    {
        // Codes are case-insensitive (DB has unique index on the value
        // as stored; we normalise on read so 'SUMMER10' and 'summer10'
        // resolve to the same coupon).
        $stmt = $this->pdo->prepare('SELECT * FROM coupons WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function targetProducts(int $couponId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ctp.product_model_id AS id, pm.name
             FROM coupon_target_products ctp
             JOIN product_models pm ON pm.id = ctp.product_model_id
             WHERE ctp.coupon_id = ?
             ORDER BY pm.name'
        );
        $stmt->execute([$couponId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function targetCategories(int $couponId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ctc.category_id AS id, c.name
             FROM coupon_target_categories ctc
             JOIN categories c ON c.id = ctc.category_id
             WHERE ctc.coupon_id = ?
             ORDER BY c.name'
        );
        $stmt->execute([$couponId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countUsages(int $couponId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ?');
        $stmt->execute([$couponId]);
        return (int) $stmt->fetchColumn();
    }

    public function countUsagesByCustomer(int $couponId, int $customerId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ? AND customer_id = ?'
        );
        $stmt->execute([$couponId, $customerId]);
        return (int) $stmt->fetchColumn();
    }

    public function countPendingApprovals(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM coupons WHERE status = 'pending_approval'")->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function usagesForCoupon(int $couponId, int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cu.*, o.reference AS order_reference, o.total AS order_total,
                    c.name AS customer_name, u.name AS applied_by_name
             FROM coupon_usages cu
             LEFT JOIN orders o ON o.id = cu.order_id
             LEFT JOIN customers c ON c.id = cu.customer_id
             LEFT JOIN users u ON u.id = cu.applied_by
             WHERE cu.coupon_id = ?
             ORDER BY cu.applied_at DESC
             LIMIT ' . max(1, min(500, $limit)
        ));
        $stmt->execute([$couponId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
