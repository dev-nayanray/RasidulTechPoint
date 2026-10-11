<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Payment records: verification, refunds, reconciliation, and the
 * never-store-card-security-data defense.
 *
 * Critical rules:
 *  - NEVER trust client-side payment success. The server always
 *    recomputes the paid total from the orders table and only flips
 *    payment_status to 'paid' when sum(completed payments) >= total.
 *  - NEVER store card security codes (CVV, CVC) or banking PINs.
 *    assertNoCardSecurityData() scans every payment payload for
 *    forbidden field names and throws if any is present.
 *  - Manual payments are supported when no gateway credentials are
 *    configured. The recordManualPayment() entry point covers cash,
 *    card (without storing the security code), bank transfer, bKash
 *    and Nagad — the cashier pastes in the bank slip / gateway
 *    transaction id as evidence and a manager verifies it later.
 *  - Refunds are payment-level. They mark a single payment row as
 *    'refunded' with refunded_by + refunded_at + refund_reason. The
 *    order's payment_status is recomputed so a fully-refunded order
 *    can be moved to status='refunded' via OrderService.updateStatus.
 */
final class PaymentService
{
    /** Field names that MUST NOT appear in any payment payload. */
    public const FORBIDDEN_FIELD_PATTERNS = [
        'cvv', 'cvc', 'cvn', 'csc', 'security_code', 'card_pin', 'pin',
        'card_security_code', 'cvv2', 'cvc2',
    ];

    /**
     * Defense-in-depth: scan a payload for forbidden card security
     * fields. Throws if any forbidden key is present (case-insensitive).
     * This catches both raw $_POST arrays and JSON-decoded payloads.
     */
    public function assertNoCardSecurityData(array $payload, string $context = 'payment'): void
    {
        foreach (array_keys($payload) as $key) {
            $normalised = strtolower((string) $key);
            foreach (self::FORBIDDEN_FIELD_PATTERNS as $forbidden) {
                if ($normalised === $forbidden || str_contains($normalised, $forbidden)) {
                    throw new ValidationException([
                        $key => "Field '{$key}' is a card security code or banking PIN. The system MUST NOT store these — discard the value and re-record without it.",
                    ]);
                }
            }
        }
    }

    /**
     * Verify a pending non-cash payment: promote it to 'completed'
     * with verified_by + verified_at + the supplied evidence reference.
     * Recomputes the order's payment_status so a fully-paid order flips
     * to 'paid'.
     */
    public function verify(int $paymentId, string $evidenceRef, int $verifierId): array
    {
        $evidenceRef = trim($evidenceRef);
        if ($evidenceRef === '') {
            throw new ValidationException(['evidence_ref' => 'Evidence reference is required to verify a payment.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($paymentId, $evidenceRef, $verifierId) {
            $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($payment === false) {
                throw new ValidationException(['payment_id' => 'Payment does not exist.']);
            }
            if ($payment['status'] === 'completed') {
                throw new ValidationException(['payment_id' => 'Payment is already completed.']);
            }
            if ($payment['status'] === 'refunded') {
                throw new ValidationException(['payment_id' => 'Cannot verify a refunded payment.']);
            }

            $stmt = $pdo->prepare(
                'UPDATE payments
                 SET status = "completed", evidence_ref = ?, verified_by = ?, verified_at = UTC_TIMESTAMP(),
                     paid_at = COALESCE(paid_at, UTC_TIMESTAMP())
                 WHERE id = ?'
            );
            $stmt->execute([$evidenceRef, $verifierId, $paymentId]);

            $orderId = (int) $payment['order_id'];
            $this->recomputeOrderPaymentStatus($pdo, $orderId);

            app_audit('payment.verified', 'payment', $paymentId, [
                'evidence_ref' => mb_substr($evidenceRef, 0, 80),
                'verifier' => $verifierId,
            ]);

            return [
                'payment_id' => $paymentId,
                'status' => 'completed',
                'verified_by' => $verifierId,
            ];
        });
    }

    /**
     * Refund a payment: mark it as 'refunded' with refunded_by +
     * refunded_at + refund_reason. Recomputes the order's payment_status
     * so a fully-refunded order can be moved to status='refunded' via
     * OrderService.updateStatus.
     *
     * Refunds are payment-level. They do NOT create a new payment row;
     * the existing payment's status is flipped. If you need to record a
     * partial refund, the caller should split the original payment into
     * a kept + refunded row first — that is out of scope here.
     */
    public function refund(int $paymentId, string $reason, int $refundedBy): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => 'A refund reason is required.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($paymentId, $reason, $refundedBy) {
            $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($payment === false) {
                throw new ValidationException(['payment_id' => 'Payment does not exist.']);
            }
            if ($payment['status'] === 'refunded') {
                throw new ValidationException(['payment_id' => 'Payment is already refunded.']);
            }
            if ($payment['status'] !== 'completed') {
                throw new ValidationException([
                    'payment_id' => 'Cannot refund a payment that is not completed (status: ' . $payment['status'] . '). Verify it first.',
                ]);
            }

            $stmt = $pdo->prepare(
                'UPDATE payments
                 SET status = "refunded", refunded_by = ?, refunded_at = UTC_TIMESTAMP(), refund_reason = ?
                 WHERE id = ?'
            );
            $stmt->execute([$refundedBy, mb_substr($reason, 0, 255), $paymentId]);

            $orderId = (int) $payment['order_id'];
            $this->recomputeOrderPaymentStatus($pdo, $orderId);

            app_audit('payment.refunded', 'payment', $paymentId, [
                'amount' => $payment['amount'],
                'method' => $payment['method'],
                'reason' => mb_substr($reason, 0, 80),
                'refunded_by' => $refundedBy,
            ]);

            return [
                'payment_id' => $paymentId,
                'status' => 'refunded',
                'refunded_by' => $refundedBy,
                'order_id' => $orderId,
            ];
        });
    }

    /**
     * Record a manual payment. Used when no gateway credentials are
     * configured (the cashier visually confirms the bank transfer / bKash
     * transaction on the merchant portal and pastes in the reference).
     *
     * Cash payments complete immediately. Non-cash payments without
     * evidence stay 'pending' and surface on the verification queue.
     *
     * The payload is always scanned for forbidden card security fields.
     */
    public function recordManualPayment(
        int $orderId,
        float $amount,
        string $method,
        int $recordedBy,
        ?string $reference = null,
        ?string $evidenceRef = null,
        ?int $shiftId = null,
        ?string $ipAddress = null,
        ?array $metadata = null,
    ): array {
        // Defense-in-depth: never accept card security codes via the
        // metadata map either.
        if ($metadata !== null) {
            $this->assertNoCardSecurityData($metadata, 'payment.metadata');
        }

        // Delegate to OrderService for the actual insert — it owns the
        // duplicate-reference guard and order payment_status recompute.
        $orderService = new OrderService();
        $result = $orderService->recordPayment($orderId, $amount, $method, $recordedBy, $reference, $evidenceRef);

        // Augment with the new audit columns (shift, ip, metadata).
        $stmt = Connection::pdo()->prepare(
            'UPDATE payments SET shift_id = ?, ip_address = ?, payment_metadata = ? WHERE id = ?'
        );
        $stmt->execute([
            $shiftId,
            $ipAddress,
            $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $result['id'],
        ]);

        return $result;
    }

    // ---------------------------------------------------- RECONCILIATION --

    /**
     * Create a daily reconciliation batch. expected_total = sum of
     * completed payments on the batch date. Throws if a batch already
     * exists for the same date (one batch per day).
     */
    public function createReconciliationBatch(string $batchDate, int $preparedBy, ?string $notes = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $batchDate)) {
            throw new ValidationException(['batch_date' => 'Batch date must be in YYYY-MM-DD format.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($batchDate, $preparedBy, $notes) {
            $stmt = $pdo->prepare('SELECT id FROM reconciliation_batches WHERE batch_date = ?');
            $stmt->execute([$batchDate]);
            if ($stmt->fetchColumn() !== false) {
                throw new ValidationException(['batch_date' => "A reconciliation batch already exists for {$batchDate}."]);
            }

            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM payments
                 WHERE status = 'completed'
                   AND DATE(paid_at) = ?"
            );
            $stmt->execute([$batchDate]);
            $expected = (float) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM payments
                 WHERE status = 'completed'
                   AND DATE(paid_at) = ?"
            );
            $stmt->execute([$batchDate]);
            $count = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'INSERT INTO reconciliation_batches
                    (batch_date, prepared_by, status, expected_total, payment_count, notes)
                 VALUES (?, ?, "draft", ?, ?, ?)'
            );
            $stmt->execute([
                $batchDate,
                $preparedBy,
                number_format($expected, 2, '.', ''),
                $count,
                $notes,
            ]);
            $batchId = (int) $pdo->lastInsertId();

            // Link all completed payments on that date to this batch.
            $stmt = $pdo->prepare(
                "UPDATE payments SET reconciliation_batch_id = ?
                 WHERE status = 'completed' AND DATE(paid_at) = ?"
            );
            $stmt->execute([$batchId, $batchDate]);

            app_audit('payment.reconciliation_batch_created', 'reconciliation_batch', $batchId, [
                'batch_date' => $batchDate,
                'expected' => number_format($expected, 2, '.', ''),
                'count' => $count,
            ]);

            return [
                'id' => $batchId,
                'batch_date' => $batchDate,
                'expected_total' => number_format($expected, 2, '.', ''),
                'payment_count' => $count,
            ];
        });
    }

    /**
     * Confirm a reconciliation batch: enter the actual counted total,
     * record discrepancy, flip status to 'confirmed' or 'discrepancy'.
     */
    public function confirmReconciliationBatch(int $batchId, float $actualTotal, int $confirmerId, ?string $notes = null): array
    {
        if ($actualTotal < 0) {
            throw new ValidationException(['actual_total' => 'Actual total cannot be negative.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($batchId, $actualTotal, $confirmerId, $notes) {
            $stmt = $pdo->prepare('SELECT * FROM reconciliation_batches WHERE id = ? FOR UPDATE');
            $stmt->execute([$batchId]);
            $batch = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($batch === false) {
                throw new ValidationException(['batch_id' => 'Reconciliation batch does not exist.']);
            }
            if ($batch['status'] !== 'draft') {
                throw new ValidationException(['batch_id' => 'Batch is already confirmed.']);
            }

            $expected = (float) $batch['expected_total'];
            $discrepancy = round($actualTotal - $expected, 2);
            $status = abs($discrepancy) < 0.005 ? 'confirmed' : 'discrepancy';

            $stmt = $pdo->prepare(
                'UPDATE reconciliation_batches
                 SET status = ?, actual_total = ?, discrepancy = ?, confirmed_by = ?, confirmed_at = UTC_TIMESTAMP(),
                     notes = CONCAT(IFNULL(notes, ""), ?)
                 WHERE id = ?'
            );
            $stmt->execute([
                $status,
                number_format($actualTotal, 2, '.', ''),
                number_format($discrepancy, 2, '.', ''),
                $confirmerId,
                $notes !== null ? "\nConfirm notes: " . $notes : '',
                $batchId,
            ]);

            app_audit('payment.reconciliation_batch_confirmed', 'reconciliation_batch', $batchId, [
                'expected' => number_format($expected, 2, '.', ''),
                'actual' => number_format($actualTotal, 2, '.', ''),
                'discrepancy' => number_format($discrepancy, 2, '.', ''),
                'status' => $status,
            ]);

            return [
                'id' => $batchId,
                'status' => $status,
                'discrepancy' => number_format($discrepancy, 2, '.', ''),
            ];
        });
    }

    // ---------------------------------------------------- HELPERS --

    private function recomputeOrderPaymentStatus(PDO $pdo, int $orderId): void
    {
        $stmt = $pdo->prepare('SELECT total FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $total = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND status = "completed"'
        );
        $stmt->execute([$orderId]);
        $paid = (float) $stmt->fetchColumn();

        $paymentStatus = 'unpaid';
        if ($paid >= $total - 0.001) {
            $paymentStatus = 'paid';
        } elseif ($paid > 0) {
            $paymentStatus = 'partial';
        }
        $stmt = $pdo->prepare('UPDATE orders SET payment_status = ? WHERE id = ?');
        $stmt->execute([$paymentStatus, $orderId]);
    }
}
