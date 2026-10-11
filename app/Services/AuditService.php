<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Request;
use App\Support\Database\Connection;

/**
 * Audit trail writer + reader.
 *
 * The audit_logs table is APPEND-ONLY. No UPDATE or DELETE is ever
 * issued by this service or by any controller — the immutability is
 * verified by AuditLogTest. Metadata must already be free of secrets;
 * callers pass only safe, describable change information.
 *
 * Action categories (used for the filter dropdown in the UI):
 *   auth        — login, logout, register, password reset
 *   user        — staff CRUD, deactivation, deletion, restore
 *   role        — role creation, permission sync, deletion
 *   settings    — business profile, logo, hours, invoice
 *   inventory   — receipt, transitions, price changes
 *   catalog     — product model, brand, category CRUD
 *   location    — location CRUD
 *   orders      — order creation, status, payment recording
 *   permissions — permission.denied (middleware blocks)
 *   account     — customer self-service profile updates
 */
final class AuditService
{
    /**
     * Map action prefix → category. The category is derived from the
     * action string at query time (no DB column needed).
     */
    public const ACTION_CATEGORIES = [
        'auth.' => 'auth',
        'user.' => 'user',
        'role' => 'role',
        'roles.' => 'role',
        'settings.' => 'settings',
        'inventory.' => 'inventory',
        'product_model.' => 'catalog',
        'brand.' => 'catalog',
        'category.' => 'catalog',
        'location.' => 'location',
        'order.' => 'orders',
        'payment.' => 'orders',
        'permission.' => 'permissions',
        'account.' => 'account',
        'admin.' => 'system',
    ];

    public static function record(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        array $metadata = [],
        ?Request $request = null,
    ): void {
        $userId = AuthService::id();

        $stmt = Connection::pdo()->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata, ip, correlation_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            substr($action, 0, 120),
            $entityType !== null ? substr($entityType, 0, 60) : null,
            $entityId !== null ? (int) $entityId : null,
            $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            $request?->ip(),
            $request?->correlationId(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 10, ?int $userId = null): array
    {
        $sql = 'SELECT a.*, u.name AS user_name
                FROM audit_logs a
                LEFT JOIN users u ON u.id = a.user_id';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE a.user_id = ?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY a.id DESC LIMIT ' . max(1, min(100, $limit));

        $stmt = Connection::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Derive the category from an action string. Returns 'other' for
     * unrecognised prefixes.
     */
    public static function categoryForAction(string $action): string
    {
        foreach (self::ACTION_CATEGORIES as $prefix => $category) {
            if (str_starts_with($action, $prefix)) {
                return $category;
            }
        }
        return 'other';
    }

    /** @return list<string> */
    public static function categories(): array
    {
        return array_values(array_unique(self::ACTION_CATEGORIES));
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function search(
        string $q = '',
        string $category = '',
        string $entityType = '',
        ?int $userId = null,
        ?string $from = null,
        ?string $to = null,
        int $page = 1,
        int $perPage = 50,
    ): array {
        $pdo = Connection::pdo();
        $where = ' WHERE 1=1';
        $params = [];

        if ($q !== '') {
            $where .= ' AND (a.action LIKE ? OR a.entity_type LIKE ? OR u.name LIKE ? OR a.ip LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($entityType !== '') {
            $where .= ' AND a.entity_type = ?';
            $params[] = $entityType;
        }
        if ($userId !== null && $userId > 0) {
            $where .= ' AND a.user_id = ?';
            $params[] = $userId;
        }
        if ($from !== null && $from !== '') {
            $where .= ' AND a.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== null && $to !== '') {
            $where .= ' AND a.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        // Category filter uses a CASE/WHEN on the action prefix.
        if ($category !== '' && $category !== 'all') {
            $clauses = [];
            foreach (self::ACTION_CATEGORIES as $prefix => $cat) {
                if ($cat === $category) {
                    $clauses[] = 'a.action LIKE ?';
                    $params[] = $prefix . '%';
                }
            }
            if ($clauses !== []) {
                $where .= ' AND (' . implode(' OR ', $clauses) . ')';
            }
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id{$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare(
            "SELECT a.*, u.name AS user_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             {$where}
             ORDER BY a.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'total' => $total];
    }
}
