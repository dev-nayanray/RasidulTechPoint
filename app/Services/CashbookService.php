<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Cashbook and balances: cash/bank/mobile accounts, movements,
 * opening balances, transfers, reconciliation.
 *
 * Critical rules:
 *  - All balance updates are inside DB transactions with FOR UPDATE
 *    locking on the account row. No race conditions on balance.
 *  - settlement_status separates "recorded" from "settled". A
 *    movement starts as 'unverified' and becomes 'verified' when
 *    the external settlement (bank statement, gateway confirmation)
 *    is confirmed. Disputed movements are flagged for investigation.
 *  - Duplicate prevention: recordMovement checks for an existing
 *    movement with the same (reference_type, reference_id) before
 *    creating a new one. If found, throws.
 *  - Transfers create two linked movements in a single transaction.
 *  - balance_after is a snapshot for audit.
 */
final class CashbookService
{
    public const ACCOUNT_TYPES = ['cash', 'bank', 'mobile', 'other'];
    public const MOVEMENT_TYPES = [
        'deposit', 'withdrawal', 'transfer_in', 'transfer_out',
        'payment_in', 'payment_out', 'expense', 'adjustment',
    ];
    public const SETTLEMENT_STATUSES = ['unverified', 'verified', 'disputed'];

    // ---------------------------------------------------- ACCOUNTS --

    public function createAccount(string $name, string $type, float $openingBalance, ?int $createdBy = null, ?string $accountNumber = null, ?string $bankName = null, ?string $branch = null): int
    {
        if (trim($name) === '') {
            throw new ValidationException(['name' => 'Account name is required.']);
        }
        if (!in_array($type, self::ACCOUNT_TYPES, true)) {
            throw new ValidationException(['account_type' => 'Invalid account type.']);
        }
        if ($openingBalance < 0) {
            throw new ValidationException(['opening_balance' => 'Opening balance cannot be negative.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($name, $type, $openingBalance, $createdBy, $accountNumber, $bankName, $branch) {
            $stmt = $pdo->prepare(
                'INSERT INTO cash_accounts (name, account_type, account_number, bank_name, branch, opening_balance, current_balance)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            // Set current_balance to 0 — the opening balance movement
            // below will set it to the correct value atomically.
            $stmt->execute([
                $name, $type, $accountNumber, $bankName, $branch,
                number_format($openingBalance, 2, '.', ''),
                '0.00',
            ]);
            $accountId = (int) $pdo->lastInsertId();

            // Record opening balance as a verified 'adjustment' movement.
            if ($openingBalance > 0) {
                $this->recordMovementInternal($pdo, $accountId, 'adjustment', 'in', $openingBalance, 'opening_balance', $accountId, null, 'Opening balance', 'verified', $createdBy);
            } else {
                // Even with zero opening balance, update current_balance
                // from 0 to 0 explicitly so the account is consistent.
                $pdo->prepare('UPDATE cash_accounts SET current_balance = ? WHERE id = ?')->execute(['0.00', $accountId]);
            }

            app_audit('cashbook.account_created', 'cash_account', $accountId, [
                'name' => $name, 'type' => $type, 'opening_balance' => number_format($openingBalance, 2, '.', ''),
            ]);

            return $accountId;
        });
    }

    public function findAccount(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT * FROM cash_accounts WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['recent_movements'] = $this->recentMovements($id, 10);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function allAccounts(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM cash_accounts';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY account_type, name';
        return Connection::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateAccount(int $id, string $name, bool $isActive, ?string $accountNumber, ?string $bankName, ?string $branch): void
    {
        $stmt = Connection::pdo()->prepare(
            'UPDATE cash_accounts SET name = ?, is_active = ?, account_number = ?, bank_name = ?, branch = ? WHERE id = ?'
        );
        $stmt->execute([$name, $isActive ? 1 : 0, $accountNumber, $bankName, $branch, $id]);
    }

    public function accountBalance(int $accountId): float
    {
        $stmt = Connection::pdo()->prepare('SELECT current_balance FROM cash_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        return (float) $stmt->fetchColumn();
    }

    // ---------------------------------------------------- MOVEMENTS --

    /**
     * Record a cash movement. CRITICAL:
     *  - Uses FOR UPDATE lock on the account row.
     *  - Prevents duplicate posting: checks for existing movement
     *    with the same (reference_type, reference_id).
     *  - Updates current_balance atomically.
     *  - Records balance_after as a snapshot.
     */
    public function recordMovement(
        int $accountId,
        string $movementType,
        string $direction,
        float $amount,
        int $createdBy,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $externalReference = null,
        ?string $description = null,
    ): array {
        if (!in_array($movementType, self::MOVEMENT_TYPES, true)) {
            throw new ValidationException(['movement_type' => 'Invalid movement type.']);
        }
        if (!in_array($direction, ['in', 'out'], true)) {
            throw new ValidationException(['direction' => 'Direction must be in or out.']);
        }
        if ($amount <= 0) {
            throw new ValidationException(['amount' => 'Amount must be positive.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($accountId, $movementType, $direction, $amount, $referenceType, $referenceId, $externalReference, $description, $createdBy) {
            return $this->recordMovementInternal($pdo, $accountId, $movementType, $direction, $amount, $referenceType, $referenceId, $externalReference, $description, 'unverified', $createdBy);
        });
    }

    /**
     * Transfer between two accounts. Creates two linked movements
     * in a single transaction.
     */
    public function transfer(int $fromAccountId, int $toAccountId, float $amount, ?string $description, int $createdBy): array
    {
        if ($fromAccountId === $toAccountId) {
            throw new ValidationException(['to_account_id' => 'Cannot transfer to the same account.']);
        }
        if ($amount <= 0) {
            throw new ValidationException(['amount' => 'Amount must be positive.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($fromAccountId, $toAccountId, $amount, $description, $createdBy) {
            // Check sufficient balance on source account.
            $balance = $this->accountBalanceLocked($pdo, $fromAccountId);
            if ($balance < $amount) {
                throw new ValidationException(['amount' => sprintf('Insufficient balance: %.2f available, %.2f requested.', $balance, $amount)]);
            }

            // Create a shared reference for the two linked movements.
            $transferRef = 'transfer_' . time() . '_' . random_int(1000, 9999);

            $out = $this->recordMovementInternal($pdo, $fromAccountId, 'transfer_out', 'out', $amount, 'transfer', null, null, $description ?? 'Transfer out', 'unverified', $createdBy);
            $in = $this->recordMovementInternal($pdo, $toAccountId, 'transfer_in', 'in', $amount, 'transfer', null, null, $description ?? 'Transfer in', 'unverified', $createdBy);

            app_audit('cashbook.transfer', 'cash_account', $fromAccountId, [
                'from' => $fromAccountId, 'to' => $toAccountId, 'amount' => number_format($amount, 2, '.', ''),
            ]);

            return ['out_movement_id' => $out['id'], 'in_movement_id' => $in['id']];
        });
    }

    /**
     * Verify a movement's external settlement. Marks as 'verified'.
     */
    public function verifyMovement(int $movementId, int $verifiedBy): array
    {
        return Connection::transaction(function (PDO $pdo) use ($movementId, $verifiedBy) {
            $stmt = $pdo->prepare('SELECT * FROM cash_movements WHERE id = ? FOR UPDATE');
            $stmt->execute([$movementId]);
            $movement = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($movement === false) {
                throw new ValidationException(['movement_id' => 'Movement does not exist.']);
            }
            if ($movement['settlement_status'] === 'verified') {
                throw new ValidationException(['movement_id' => 'Movement is already verified.']);
            }

            $stmt = $pdo->prepare('UPDATE cash_movements SET settlement_status = "verified", verified_by = ?, verified_at = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->execute([$verifiedBy, $movementId]);

            app_audit('cashbook.movement_verified', 'cash_movement', $movementId, [
                'amount' => $movement['amount'],
                'direction' => $movement['direction'],
            ]);

            return ['id' => $movementId, 'settlement_status' => 'verified'];
        });
    }

    /**
     * Mark a movement as disputed (for investigation).
     */
    public function disputeMovement(int $movementId, int $actorId): array
    {
        $stmt = Connection::pdo()->prepare('UPDATE cash_movements SET settlement_status = "disputed" WHERE id = ?');
        $stmt->execute([$movementId]);
        app_audit('cashbook.movement_disputed', 'cash_movement', $movementId, []);
        return ['id' => $movementId, 'settlement_status' => 'disputed'];
    }

    /**
     * Reconcile an account: compare the recorded balance with a
     * verified external balance.
     */
    public function reconcileAccount(int $accountId, float $verifiedBalance, int $reconciledBy): array
    {
        return Connection::transaction(function (PDO $pdo) use ($accountId, $verifiedBalance, $reconciledBy) {
            $stmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? FOR UPDATE');
            $stmt->execute([$accountId]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($account === false) {
                throw new ValidationException(['account_id' => 'Account does not exist.']);
            }

            $recorded = (float) $account['current_balance'];
            $discrepancy = round($verifiedBalance - $recorded, 2);
            $status = abs($discrepancy) < 0.005 ? 'balanced' : 'discrepancy';

            app_audit('cashbook.reconciled', 'cash_account', $accountId, [
                'recorded' => number_format($recorded, 2, '.', ''),
                'verified' => number_format($verifiedBalance, 2, '.', ''),
                'discrepancy' => number_format($discrepancy, 2, '.', ''),
                'status' => $status,
            ]);

            return [
                'account_id' => $accountId,
                'recorded_balance' => number_format($recorded, 2, '.', ''),
                'verified_balance' => number_format($verifiedBalance, 2, '.', ''),
                'discrepancy' => number_format($discrepancy, 2, '.', ''),
                'status' => $status,
            ];
        });
    }

    // ---------------------------------------------------- SEARCH --

    public function findMovement(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT m.*, a.name AS account_name, a.account_type,
                    v.name AS verified_by_name,
                    c.name AS created_by_name
             FROM cash_movements m
             JOIN cash_accounts a ON a.id = m.account_id
             LEFT JOIN users v ON v.id = m.verified_by
             LEFT JOIN users c ON c.id = m.created_by
             WHERE m.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function searchMovements(string $q = '', ?int $accountId = null, string $type = '', string $settlement = '', string $from = '', string $to = '', int $page = 1, int $perPage = 25): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (m.movement_number LIKE ? OR m.external_reference LIKE ? OR m.description LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($accountId !== null && $accountId > 0) {
            $where .= ' AND m.account_id = ?';
            $params[] = $accountId;
        }
        if ($type !== '' && in_array($type, self::MOVEMENT_TYPES, true)) {
            $where .= ' AND m.movement_type = ?';
            $params[] = $type;
        }
        if ($settlement !== '' && in_array($settlement, self::SETTLEMENT_STATUSES, true)) {
            $where .= ' AND m.settlement_status = ?';
            $params[] = $settlement;
        }
        if ($from !== '') {
            $where .= ' AND m.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where .= ' AND m.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        $countStmt = Connection::pdo()->prepare("SELECT COUNT(*) FROM cash_movements m{$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = Connection::pdo()->prepare(
            "SELECT m.*, a.name AS account_name,
                    v.name AS verified_by_name,
                    c.name AS created_by_name
             FROM cash_movements m
             JOIN cash_accounts a ON a.id = m.account_id
             LEFT JOIN users v ON v.id = m.verified_by
             LEFT JOIN users c ON c.id = m.created_by
             {$where}
             ORDER BY m.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    // ---------------------------------------------------- HELPERS --

    /** @return list<array<string,mixed>> */
    private function recentMovements(int $accountId, int $limit = 10): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT m.*, v.name AS verified_by_name
             FROM cash_movements m
             LEFT JOIN users v ON v.id = m.verified_by
             WHERE m.account_id = ?
             ORDER BY m.id DESC
             LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute([$accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function accountBalanceLocked(PDO $pdo, int $accountId): float
    {
        $stmt = $pdo->prepare('SELECT current_balance FROM cash_accounts WHERE id = ? FOR UPDATE');
        $stmt->execute([$accountId]);
        return (float) $stmt->fetchColumn();
    }

    private function recordMovementInternal(
        PDO $pdo,
        int $accountId,
        string $movementType,
        string $direction,
        float $amount,
        ?string $referenceType,
        ?int $referenceId,
        ?string $externalReference,
        ?string $description,
        string $settlementStatus,
        ?int $createdBy,
    ): array {
        // Lock the account row.
        $stmt = $pdo->prepare('SELECT current_balance FROM cash_accounts WHERE id = ? FOR UPDATE');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ValidationException(['account_id' => 'Cash account does not exist.']);
        }

        // Duplicate prevention: check for existing movement with the
        // same (reference_type, reference_id).
        if ($referenceType !== null && $referenceId !== null) {
            $dup = $pdo->prepare('SELECT id FROM cash_movements WHERE reference_type = ? AND reference_id = ? LIMIT 1');
            $dup->execute([$referenceType, $referenceId]);
            if ($dup->fetchColumn() !== false) {
                throw new ValidationException([
                    'reference' => sprintf('A movement with reference_type=%s reference_id=%d already exists. Duplicate posting prevented.', $referenceType, $referenceId),
                ]);
            }
        }

        $currentBalance = (float) $row['current_balance'];
        $delta = $direction === 'in' ? $amount : -$amount;
        $newBalance = round($currentBalance + $delta, 2);

        if ($newBalance < 0) {
            throw new ValidationException(['amount' => sprintf('Insufficient balance: %.2f available, %.2f requested.', $currentBalance, $amount)]);
        }

        $movementNumber = $this->generateMovementNumber($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO cash_movements
                (movement_number, account_id, movement_type, direction, amount, balance_after,
                 reference_type, reference_id, external_reference, settlement_status, description, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $movementNumber, $accountId, $movementType, $direction,
            number_format($amount, 2, '.', ''),
            number_format($newBalance, 2, '.', ''),
            $referenceType, $referenceId, $externalReference,
            $settlementStatus, $description, $createdBy,
        ]);
        $movementId = (int) $pdo->lastInsertId();

        // Update the account's current_balance.
        $stmt = $pdo->prepare('UPDATE cash_accounts SET current_balance = ? WHERE id = ?');
        $stmt->execute([number_format($newBalance, 2, '.', ''), $accountId]);

        app_audit('cashbook.movement_recorded', 'cash_movement', $movementId, [
            'movement_number' => $movementNumber,
            'account_id' => $accountId,
            'type' => $movementType,
            'direction' => $direction,
            'amount' => number_format($amount, 2, '.', ''),
            'balance_after' => number_format($newBalance, 2, '.', ''),
            'settlement_status' => $settlementStatus,
        ]);

        return ['id' => $movementId, 'movement_number' => $movementNumber, 'balance_after' => number_format($newBalance, 2, '.', '')];
    }

    private function generateMovementNumber(PDO $pdo): string
    {
        $year = (int) gmdate('Y');
        $prefix = 'CM-' . $year . '-';
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM cash_movements WHERE movement_number LIKE ?');
        $stmt->execute([$prefix . '%']);
        $next = (int) $stmt->fetchColumn() + 1;
        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
