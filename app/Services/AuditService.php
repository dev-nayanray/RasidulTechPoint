<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Request;
use App\Support\Database\Connection;

/**
 * Audit trail writer. Metadata must already be free of secrets; callers
 * pass only safe, describable change information.
 */
final class AuditService
{
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
}
