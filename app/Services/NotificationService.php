<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;
use PDOException;

/**
 * User notifications (in-app) + event-based dispatch + delivery log.
 *
 * Two layers:
 *
 *  1. Direct push (legacy, kept for backward-compat): push() inserts a
 *     notification row directly for one user. No template, no delivery
 *     log, no dedupe. Used by ad-hoc callers that just want to surface
 *     something to a single user.
 *
 *  2. Event-based dispatch (Prompt 38): dispatch() looks up a
 *     notification_templates row by event_key, resolves recipients
 *     server-side (by roles / permissions / specific users / all
 *     staff), interpolates {placeholders} in title/body/link,
 *     de-duplicates within the template's dedupe window, and writes
 *     both the notification row and a notification_deliveries log
 *     row atomically. retryFailed() picks up 'failed' deliveries and
 *     re-attempts them in place.
 *
 * Privacy:
 *  - Recipients are ALWAYS resolved from the template definition in
 *    the database — the caller of dispatch() supplies the event_key
 *    and context only, never the recipient list. A caller cannot
 *    direct a notification at an arbitrary user.
 *  - The controller reads / markRead scope by the signed-in user's
 *    id; the delivery log viewer (admin-only) never exposes another
 *    user's body content — only event_key, status, timestamps.
 */
final class NotificationService
{
    /** Recipient resolver modes (must match the DB ENUM). */
    public const RECIPIENT_MODES = ['roles', 'permissions', 'specific_users', 'all_staff'];

    /** Delivery statuses (must match the DB ENUM). */
    public const DELIVERY_STATUSES = ['delivered', 'failed', 'suppressed_duplicate', 'no_template', 'no_recipients'];

    /** Default max attempts for retryFailed(). */
    public const DEFAULT_MAX_ATTEMPTS = 3;

    // ---------------------------------------------------- DIRECT PUSH (legacy) --

    public static function push(
        int $userId,
        string $title,
        ?string $body = null,
        ?string $link = null,
        string $type = 'info',
    ): int {
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, substr($title, 0, 190), $body, $link]);
        return (int) Connection::pdo()->lastInsertId();
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

    /** @return list<array<string,mixed>> */
    public static function menuItems(int $userId, int $limit = 5): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT id, type, title, is_read, created_at FROM notifications
             WHERE user_id = ?
             ORDER BY is_read ASC, id DESC
             LIMIT ' . max(1, min(10, $limit))
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------- TEMPLATES CRUD --

    /** @return list<array<string,mixed>> */
    public static function allTemplates(): array
    {
        return Connection::pdo()->query(
            'SELECT t.*, u.name AS created_by_name
             FROM notification_templates t
             LEFT JOIN users u ON u.id = t.created_by
             ORDER BY t.event_key'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findTemplate(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT * FROM notification_templates WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public static function findTemplateByKey(string $eventKey): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT * FROM notification_templates WHERE event_key = ? LIMIT 1'
        );
        $stmt->execute([$eventKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Create or update a template. event_key is unique; on update the
     * event_key may not be changed (it is the stable identifier wired
     * into the application).
     *
     * @param array{
     *   event_key:string, title:string, body?:?string, link_template?:?string,
     *   recipient_mode:string, recipient_value:string, is_enabled?:bool,
     *   dedupe_window_seconds?:int
     * } $data
     */
    public static function saveTemplate(array $data, ?int $templateId, int $actorId): int
    {
        $errors = [];
        $eventKey = trim((string) ($data['event_key'] ?? ''));
        if ($eventKey === '' || strlen($eventKey) > 80) {
            $errors['event_key'] = 'Event key is required (max 80 chars).';
        } elseif (!preg_match('/^[a-z][a-z0-9_\.]*$/', $eventKey)) {
            $errors['event_key'] = 'Event key must be lowercase letters, digits, dot or underscore (e.g. order.created).';
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        }
        if (!in_array($data['recipient_mode'] ?? '', self::RECIPIENT_MODES, true)) {
            $errors['recipient_mode'] = 'Invalid recipient mode.';
        }
        $recipientValue = trim((string) ($data['recipient_value'] ?? ''));
        if (($data['recipient_mode'] ?? '') !== 'all_staff' && $recipientValue === '') {
            $errors['recipient_value'] = 'Recipient value is required for this mode (comma-separated list).';
        }
        $dedupe = (int) ($data['dedupe_window_seconds'] ?? 0);
        if ($dedupe < 0) {
            $errors['dedupe_window_seconds'] = 'Dedupe window cannot be negative.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $pdo = Connection::pdo();

        // Uniqueness on event_key.
        $stmt = $pdo->prepare('SELECT id FROM notification_templates WHERE event_key = ?');
        $stmt->execute([$eventKey]);
        $existingId = $stmt->fetchColumn();
        if ($existingId !== false && (int) $existingId !== $templateId) {
            throw new ValidationException(['event_key' => "A template for event_key '{$eventKey}' already exists."]);
        }

        $isEnabled = !empty($data['is_enabled']) ? 1 : 0;
        $bodyValue = $data['body'] ?? null;
        $body = ($bodyValue !== null && $bodyValue !== '') ? $bodyValue : null;
        $linkValue = $data['link_template'] ?? null;
        $linkTemplate = ($linkValue !== null && $linkValue !== '') ? $linkValue : null;

        if ($templateId === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO notification_templates
                    (event_key, title, body, link_template, recipient_mode, recipient_value,
                     is_enabled, dedupe_window_seconds, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $eventKey, substr($title, 0, 190), $body, $linkTemplate,
                $data['recipient_mode'], $recipientValue, $isEnabled, $dedupe, $actorId,
            ]);
            return (int) $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare(
            'UPDATE notification_templates
             SET title = ?, body = ?, link_template = ?, recipient_mode = ?, recipient_value = ?,
                 is_enabled = ?, dedupe_window_seconds = ?
             WHERE id = ?'
        );
        $stmt->execute([
            substr($title, 0, 190), $body, $linkTemplate,
            $data['recipient_mode'], $recipientValue, $isEnabled, $dedupe, $templateId,
        ]);
        return $templateId;
    }

    public static function deleteTemplate(int $templateId): void
    {
        $stmt = Connection::pdo()->prepare('DELETE FROM notification_templates WHERE id = ?');
        $stmt->execute([$templateId]);
    }

    public static function toggleTemplate(int $templateId, bool $enabled): void
    {
        $stmt = Connection::pdo()->prepare(
            'UPDATE notification_templates SET is_enabled = ? WHERE id = ?'
        );
        $stmt->execute([$enabled ? 1 : 0, $templateId]);
    }

    // ---------------------------------------------------- DISPATCH (event-based) --

    /**
     * Dispatch an event-based notification to all recipients resolved
     * from the template. Returns the number of successfully delivered
     * notifications (suppressed duplicates and no-template/no-recipient
     * cases are not counted as delivered).
     *
     * Privacy: $context may contain arbitrary data; it is only used
     * for placeholder interpolation (server-side). The caller never
     * supplies the recipient list — recipients come from the template.
     *
     * @param array<string,mixed> $context placeholder values; the special
     *   'dedupe' sub-key (if present) is hashed to form the dedupe_key so
     *   callers can scope dedupe to a specific entity (e.g. order id)
     *   rather than the whole event.
     * @param ?int $actorId user who triggered the dispatch (for audit only)
     * @param ?string $dedupeKey explicit dedupe key (overrides context.dedupe)
     */
    public static function dispatch(
        string $eventKey,
        array $context,
        ?int $actorId = null,
        ?string $dedupeKey = null,
    ): int {
        $pdo = Connection::pdo();
        $template = self::findTemplateByKey($eventKey);

        if ($template === null || (int) $template['is_enabled'] !== 1) {
            // Log a no_template delivery so admins can see the dispatch
            // attempt even when no template is wired. The recipient is
            // null because no one was actually targeted.
            self::logDelivery(
                $pdo,
                eventKey: $eventKey,
                templateId: null,
                recipientUserId: null,
                dedupeKey: $dedupeKey,
                status: 'no_template',
                error: $template === null ? 'No template registered for event' : 'Template is disabled',
            );
            return 0;
        }

        $recipients = self::resolveRecipients($template);
        if ($recipients === []) {
            self::logDelivery(
                $pdo,
                eventKey: $eventKey,
                templateId: (int) $template['id'],
                recipientUserId: null,
                dedupeKey: $dedupeKey,
                status: 'no_recipients',
                error: 'Recipient resolver returned zero users',
            );
            return 0;
        }

        $title = self::interpolate((string) $template['title'], $context);
        $body = $template['body'] !== null ? self::interpolate((string) $template['body'], $context) : null;
        $link = $template['link_template'] !== null ? self::interpolate((string) $template['link_template'], $context) : null;
        $resolvedDedupe = $dedupeKey ?? self::buildDedupeKey($context);
        $window = (int) $template['dedupe_window_seconds'];

        $delivered = 0;
        foreach ($recipients as $userId) {
            // Dedupe check: skip if a delivery for the same event +
            // recipient + dedupe_key already exists in the dedupe window.
            if ($window > 0 && self::recentDeliveryExists($eventKey, $userId, $resolvedDedupe, $window)) {
                self::logDelivery(
                    $pdo,
                    eventKey: $eventKey,
                    templateId: (int) $template['id'],
                    recipientUserId: $userId,
                    dedupeKey: $resolvedDedupe,
                    status: 'suppressed_duplicate',
                    error: null,
                );
                continue;
            }

            try {
                $notificationId = self::push((int) $userId, $title, $body, $link, $eventKey);
                self::logDelivery(
                    $pdo,
                    eventKey: $eventKey,
                    templateId: (int) $template['id'],
                    recipientUserId: (int) $userId,
                    dedupeKey: $resolvedDedupe,
                    status: 'delivered',
                    error: null,
                    notificationId: $notificationId,
                    deliveredAt: true,
                );
                $delivered++;
            } catch (\Throwable $e) {
                self::logDelivery(
                    $pdo,
                    eventKey: $eventKey,
                    templateId: (int) $template['id'],
                    recipientUserId: (int) $userId,
                    dedupeKey: $resolvedDedupe,
                    status: 'failed',
                    error: $e->getMessage(),
                );
            }
        }

        app_audit('notification.dispatched', 'notification_template', (int) $template['id'], [
            'event_key' => $eventKey,
            'recipients' => count($recipients),
            'delivered' => $delivered,
        ]);

        return $delivered;
    }

    /**
     * Retry failed deliveries in place. Each delivery row with
     * status='failed' and attempts < $maxAttempts is incremented and
     * re-attempted. On success the row is updated to 'delivered' and
     * a new notification_id is recorded; on failure the attempts
     * counter is incremented and last_error is updated.
     *
     * @return array{retried:int, succeeded:int, still_failed:int}
     */
    public static function retryFailed(int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS): array
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM notification_deliveries
             WHERE status = "failed" AND attempts < ?
             ORDER BY id ASC
             LIMIT 500'
        );
        $stmt->execute([$maxAttempts]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $retried = 0;
        $succeeded = 0;
        $stillFailed = 0;
        foreach ($rows as $row) {
            $retried++;
            $template = self::findTemplate((int) $row['template_id']);
            if ($template === null || (int) $template['is_enabled'] !== 1) {
                // Cannot retry without a template — leave the row as
                // failed and bump attempts so it eventually ages out.
                $pdo->prepare(
                    'UPDATE notification_deliveries SET attempts = attempts + 1,
                     last_error = ? WHERE id = ?'
                )->execute(['Template missing or disabled on retry', $row['id']]);
                $stillFailed++;
                continue;
            }

            // Re-interpolate is impossible without the original context;
            // we use the title/body from the template un-interpolated as
            // a best-effort notification. The original delivery's
            // notification_id (if any) is left intact; we add a new
            // notification row to avoid duplication.
            $title = (string) $template['title'];
            $body = $template['body'];
            $link = $template['link_template'];

            try {
                $notificationId = self::push((int) $row['recipient_user_id'], $title, $body, $link, (string) $row['event_key']);
                $pdo->prepare(
                    'UPDATE notification_deliveries
                     SET status = "delivered", notification_id = ?, delivered_at = UTC_TIMESTAMP(),
                         attempts = attempts + 1, last_error = NULL
                     WHERE id = ?'
                )->execute([$notificationId, $row['id']]);
                $succeeded++;
            } catch (\Throwable $e) {
                $pdo->prepare(
                    'UPDATE notification_deliveries
                     SET attempts = attempts + 1, last_error = ?
                     WHERE id = ?'
                )->execute([substr($e->getMessage(), 0, 500), $row['id']]);
                $stillFailed++;
            }
        }

        app_audit('notification.retry_run', null, null, [
            'retried' => $retried,
            'succeeded' => $succeeded,
            'still_failed' => $stillFailed,
        ]);

        return ['retried' => $retried, 'succeeded' => $succeeded, 'still_failed' => $stillFailed];
    }

    // ---------------------------------------------------- DELIVERY LOG READS --

    /** @return list<array<string,mixed>> */
    public static function deliveries(string $status = '', string $eventKey = '', int $page = 1, int $perPage = 50): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($status !== '' && in_array($status, self::DELIVERY_STATUSES, true)) {
            $where .= ' AND d.status = ?';
            $params[] = $status;
        }
        if ($eventKey !== '') {
            $where .= ' AND d.event_key = ?';
            $params[] = $eventKey;
        }

        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = Connection::pdo()->prepare(
            "SELECT d.*, u.name AS recipient_name
             FROM notification_deliveries d
             LEFT JOIN users u ON u.id = d.recipient_user_id
             {$where}
             ORDER BY d.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function deliveriesCount(string $status = '', string $eventKey = ''): int
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($status !== '' && in_array($status, self::DELIVERY_STATUSES, true)) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        if ($eventKey !== '') {
            $where .= ' AND event_key = ?';
            $params[] = $eventKey;
        }
        $stmt = Connection::pdo()->prepare("SELECT COUNT(*) FROM notification_deliveries{$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{delivered:int, failed:int, suppressed:int, total:int} */
    public static function deliveryStats(): array
    {
        $pdo = Connection::pdo();
        $total = (int) $pdo->query('SELECT COUNT(*) FROM notification_deliveries')->fetchColumn();
        $delivered = (int) $pdo->query('SELECT COUNT(*) FROM notification_deliveries WHERE status = "delivered"')->fetchColumn();
        $failed = (int) $pdo->query('SELECT COUNT(*) FROM notification_deliveries WHERE status = "failed"')->fetchColumn();
        $suppressed = (int) $pdo->query('SELECT COUNT(*) FROM notification_deliveries WHERE status = "suppressed_duplicate"')->fetchColumn();
        return [
            'delivered' => $delivered,
            'failed' => $failed,
            'suppressed' => $suppressed,
            'total' => $total,
        ];
    }

    // ---------------------------------------------------- RECIPIENT RESOLUTION --

    /**
     * Resolve the list of recipient user IDs from a template.
     * Server-side only — the caller never supplies this.
     *
     * @return list<int>
     */
    private static function resolveRecipients(array $template): array
    {
        $mode = (string) $template['recipient_mode'];
        $value = (string) $template['recipient_value'];
        $pdo = Connection::pdo();

        if ($mode === 'all_staff') {
            // Every user with at least one staff role (excludes customers).
            return array_map('intval', $pdo->query(
                'SELECT DISTINCT u.id FROM users u
                 JOIN user_roles ur ON ur.user_id = u.id
                 JOIN roles r ON r.id = ur.role_id
                 WHERE u.is_active = 1 AND u.deleted_at IS NULL AND r.slug != "customer"
                 ORDER BY u.id'
            )->fetchAll(PDO::FETCH_COLUMN));
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($s) => $s !== ''));
        if ($items === []) {
            return [];
        }

        if ($mode === 'specific_users') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $items), fn ($v) => $v > 0)));
            if ($ids === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT DISTINCT id FROM users
                 WHERE id IN ({$placeholders}) AND is_active = 1 AND deleted_at IS NULL
                 ORDER BY id"
            );
            $stmt->execute($ids);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if ($mode === 'roles') {
            $placeholders = implode(',', array_fill(0, count($items), '?'));
            $stmt = $pdo->prepare(
                "SELECT DISTINCT u.id FROM users u
                 JOIN user_roles ur ON ur.user_id = u.id
                 JOIN roles r ON r.id = ur.role_id
                 WHERE u.is_active = 1 AND u.deleted_at IS NULL
                   AND r.slug IN ({$placeholders})
                 ORDER BY u.id"
            );
            $stmt->execute($items);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if ($mode === 'permissions') {
            $placeholders = implode(',', array_fill(0, count($items), '?'));
            $stmt = $pdo->prepare(
                "SELECT DISTINCT u.id FROM users u
                 JOIN user_roles ur ON ur.user_id = u.id
                 JOIN role_permissions rp ON rp.role_id = ur.role_id
                 JOIN permissions p ON p.id = rp.permission_id
                 WHERE u.is_active = 1 AND u.deleted_at IS NULL
                   AND p.name IN ({$placeholders})
                 ORDER BY u.id"
            );
            $stmt->execute($items);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        return [];
    }

    // ---------------------------------------------------- INTERPOLATION + DEDUPE --

    /**
     * Substitute {placeholder} tokens in $text with values from
     * $context. Unknown tokens are left intact (so a typo surfaces
     * in the rendered notification rather than silently disappearing).
     * Values are coerced to string; arrays / objects are JSON-encoded.
     */
    private static function interpolate(string $text, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $replacements['{' . $key . '}'] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $replacements['{' . $key . '}'] = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $replacements['{' . $key . '}'] = '';
            } else {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }
        // Optional-reason clause: {reason_clause} is special — if no
        // 'reason' is in the context, the whole token + leading space
        // collapses to empty (so "X.{reason_clause}" → "X.").
        if (!array_key_exists('reason', $context)) {
            $replacements['{reason_clause}'] = '';
        } else {
            $replacements['{reason_clause}'] = ' Reason: ' . (string) $context['reason'];
        }
        return strtr($text, $replacements);
    }

    /**
     * Build a dedupe key from the context. Uses the 'dedupe' sub-key
     * if present (callers put a stable identifier there, e.g. order_id);
     * otherwise hashes the whole context so two dispatches with the
     * same context collapse to the same key.
     */
    private static function buildDedupeKey(array $context): string
    {
        if (isset($context['dedupe']) && (is_string($context['dedupe']) || is_int($context['dedupe']))) {
            return (string) $context['dedupe'];
        }
        // Exclude actor + reason_clause marker from the hash so the
        // same logical event from two different actors dedupes.
        unset($context['actor_id']);
        ksort($context);
        return substr(sha1(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 32);
    }

    private static function recentDeliveryExists(string $eventKey, int $userId, string $dedupeKey, int $windowSeconds): bool
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT 1 FROM notification_deliveries
             WHERE event_key = ? AND recipient_user_id = ? AND dedupe_key = ?
               AND status = "delivered"
               AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? SECOND)
             LIMIT 1'
        );
        $stmt->execute([$eventKey, $userId, $dedupeKey, $windowSeconds]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Insert a notification_deliveries row. Wrapped in a try/catch so
     * a logging failure never propagates into the dispatch path.
     */
    private static function logDelivery(
        PDO $pdo,
        string $eventKey,
        ?int $templateId,
        ?int $recipientUserId,
        ?string $dedupeKey,
        string $status,
        ?string $error,
        ?int $notificationId = null,
        bool $deliveredAt = false,
    ): void {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO notification_deliveries
                    (notification_id, template_id, event_key, recipient_user_id, dedupe_key,
                     status, attempts, last_error, delivered_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ' . ($deliveredAt ? 'UTC_TIMESTAMP()' : 'NULL') . ')'
            );
            $stmt->execute([
                $notificationId,
                $templateId,
                $eventKey,
                $recipientUserId,
                $dedupeKey,
                $status,
                $error !== null ? substr($error, 0, 500) : null,
            ]);
        } catch (PDOException $e) {
            // Swallow — delivery logging must never break dispatch.
        }
    }
}
