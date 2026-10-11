<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Landed cost allocation service.
 *
 * Allocates documented import expenses (shipping, customs, transport,
 * other) across the inventory units received against a purchase order.
 *
 * Two allocation methods:
 *  - 'equal': total_amount / unit_count (each unit gets the same share)
 *  - 'by_cost': proportional to each unit's purchase_cost
 *
 * Each allocation updates inventory_units.landed_cost (adds to the
 * existing value, not replaces — units may receive costs from multiple
 * allocation batches). The per-unit breakdown is stored in
 * landed_cost_allocations.per_unit_breakdown (JSON).
 *
 * Cost history is retained: each allocation is a separate row in
 * landed_cost_allocations, never updated or deleted.
 */
final class LandedCostService
{
    /**
     * Allocate an import expense across units received against a PO.
     *
     * @param int $poId purchase order ID
     * @param string $expenseType shipping|customs|transport|other
     * @param float $totalAmount total expense to allocate
     * @param string $method equal|by_cost
     * @param int $userId the actor
     * @param string|null $description optional description
     * @return int the allocation ID
     */
    public function allocate(int $poId, string $expenseType, float $totalAmount, string $method, int $userId, ?string $description = null): int
    {
        if ($totalAmount < 0) {
            throw new ValidationException(['total_amount' => 'Total amount cannot be negative.']);
        }
        if (!in_array($expenseType, ['shipping', 'customs', 'transport', 'other'], true)) {
            throw new ValidationException(['expense_type' => 'Invalid expense type.']);
        }
        if (!in_array($method, ['equal', 'by_cost'], true)) {
            throw new ValidationException(['method' => 'Invalid allocation method.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($poId, $expenseType, $totalAmount, $method, $userId, $description) {
            // Get all units linked to this PO.
            $stmt = $pdo->prepare(
                'SELECT id, purchase_cost, landed_cost FROM inventory_units
                 WHERE purchase_order_id = ? ORDER BY id ASC FOR UPDATE'
            );
            $stmt->execute([$poId]);
            $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($units) === 0) {
                throw new ValidationException(['units' => 'No inventory units are linked to this purchase order. Receive units first.']);
            }

            $breakdown = [];
            $allocated = 0.0;

            if ($method === 'equal') {
                $perUnit = $totalAmount / count($units);
                foreach ($units as $unit) {
                    $newLanded = (float) $unit['landed_cost'] + $perUnit;
                    $pdo->prepare('UPDATE inventory_units SET landed_cost = ? WHERE id = ?')
                        ->execute([number_format($newLanded, 2, '.', ''), $unit['id']]);
                    $breakdown[] = [
                        'unit_id' => (int) $unit['id'],
                        'amount' => round($perUnit, 2),
                        'new_landed_cost' => round($newLanded, 2),
                    ];
                    $allocated += $perUnit;
                }
            } else { // by_cost
                $totalCost = 0.0;
                foreach ($units as $unit) {
                    $totalCost += (float) $unit['purchase_cost'];
                }
                if ($totalCost <= 0) {
                    throw new ValidationException(['method' => 'Cannot allocate by cost when total purchase cost is zero.']);
                }
                foreach ($units as $unit) {
                    $share = $totalAmount * ((float) $unit['purchase_cost'] / $totalCost);
                    $newLanded = (float) $unit['landed_cost'] + $share;
                    $pdo->prepare('UPDATE inventory_units SET landed_cost = ? WHERE id = ?')
                        ->execute([number_format($newLanded, 2, '.', ''), $unit['id']]);
                    $breakdown[] = [
                        'unit_id' => (int) $unit['id'],
                        'amount' => round($share, 2),
                        'new_landed_cost' => round($newLanded, 2),
                    ];
                    $allocated += $share;
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO landed_cost_allocations (purchase_order_id, expense_type, description, total_amount, allocation_method, allocated_count, per_unit_breakdown, allocated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $poId, $expenseType, $description,
                number_format($totalAmount, 2, '.', ''),
                $method, count($units),
                json_encode($breakdown, JSON_UNESCAPED_UNICODE),
                $userId,
            ]);
            return (int) $pdo->lastInsertId();
        });
    }

    /** @return list<array<string,mixed>> */
    public function allocationsFor(int $poId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT lca.*, u.name AS user_name
             FROM landed_cost_allocations lca
             LEFT JOIN users u ON u.id = lca.allocated_by
             WHERE lca.purchase_order_id = ?
             ORDER BY lca.id ASC'
        );
        $stmt->execute([$poId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
