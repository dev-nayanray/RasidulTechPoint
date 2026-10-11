<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Services\AuthService;
use App\Support\Database\Connection;
use PDO;

/**
 * Authorized corrections: append-only log of manager edits to order
 * fields (line item prices, quantities, notes, etc.) AFTER sale.
 *
 * Each correction records field, old_value, new_value, reason,
 * authorized_by, and ip_address. The table is INSERT-only — corrections
 * cannot be edited or deleted. orders.correction_count is incremented
 * per correction so the order show page can surface a badge without
 * joining the corrections table.
 *
 * Authorization: the actor must hold pos.authorize_discount OR
 * payments.refund OR settings.manage. This deliberately excludes
 * cashiers — only managers / accountants / owners can correct a
 * completed sale.
 */
final class OrderCorrectionService
{
    /** Fields that can be corrected through this service. */
    public const CORRECTABLE_FIELDS = [
        'order.notes', 'order.discount', 'order.total',
        'item.unit_price', 'item.product_name', 'item.sku',
    ];

    /**
     * Record a correction. Returns the new correction id.
     *
     * @param string $field One of CORRECTABLE_FIELDS.
     * @param string $oldValue The value before the correction (caller is responsible for reading it).
     * @param string $newValue The value after the correction (caller is responsible for writing it to the underlying table).
     */
    public function record(
        int $orderId,
        string $field,
        string $oldValue,
        string $newValue,
        string $reason,
        int $authorizedBy,
        ?string $ipAddress = null,
    ): int {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => 'A correction reason is required.']);
        }
        if (!in_array($field, self::CORRECTABLE_FIELDS, true)) {
            throw new ValidationException(['field' => "Field '{$field}' is not in the correctable list."]);
        }
        if ($oldValue === $newValue) {
            throw new ValidationException(['new_value' => 'New value must differ from the old value.']);
        }

        // Authorization: managers / accountants / owners only.
        if (!AuthService::can('pos.authorize_discount')
            && !AuthService::can('payments.refund')
            && !AuthService::can('settings.manage')) {
            throw new ValidationException([
                'authorized_by' => 'You do not have permission to correct order fields. A manager, accountant, or owner must authorise.',
            ]);
        }

        return Connection::transaction(function (PDO $pdo) use ($orderId, $field, $oldValue, $newValue, $reason, $authorizedBy, $ipAddress) {
            $stmt = $pdo->prepare(
                'INSERT INTO order_corrections
                    (order_id, field, old_value, new_value, reason, authorized_by, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $orderId,
                $field,
                mb_substr($oldValue, 0, 65000),
                mb_substr($newValue, 0, 65000),
                mb_substr($reason, 0, 255),
                $authorizedBy,
                $ipAddress,
            ]);
            $id = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare('UPDATE orders SET correction_count = correction_count + 1 WHERE id = ?');
            $stmt->execute([$orderId]);

            app_audit('order.correction_recorded', 'order', $orderId, [
                'field' => $field,
                'old' => mb_substr($oldValue, 0, 80),
                'new' => mb_substr($newValue, 0, 80),
                'reason' => mb_substr($reason, 0, 80),
            ]);

            return $id;
        });
    }

    /** @return list<array<string,mixed>> */
    public function listForOrder(int $orderId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT c.*, u.name AS authorized_by_name
             FROM order_corrections c
             LEFT JOIN users u ON u.id = c.authorized_by
             WHERE c.order_id = ?
             ORDER BY c.id DESC'
        );
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
