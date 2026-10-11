<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database\Connection;

/**
 * User notifications (in-app). */
final class NotificationService
{
    public static function push(
        int $userId,
        string $title,
        ?string $body = null,
        ?string $link = null,
        string $type = 'info',
    ): void {
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, substr($title, 0, 190), $body, $link]);
    }

    public static function unreadCount(int $userId): int
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId, int $limit = 30): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function markRead(int $userId, int $notificationId): bool
    {
        $stmt = Connection::pdo()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$notificationId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function markAllRead(int $userId): void
    {
        $stmt = Connection::pdo()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $stmt->execute([$userId]);
    }
}
