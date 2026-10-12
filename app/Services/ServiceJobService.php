<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Repairs and service jobs: create, assign, diagnose, add notes,
 * add parts, transition status, quality check, close.
 *
 * Status matrix:
 *   received → diagnosing, cancelled
 *   diagnosing → awaiting_parts, in_progress, cancelled
 *   awaiting_parts → in_progress, cancelled
 *   in_progress → quality_check, completed, cancelled
 *   quality_check → completed (passed), in_progress (failed), cancelled
 *   completed → (terminal)
 *   cancelled → (terminal)
 *
 * Financial integrity:
 *  - Service costs (labor_charge + parts_charge + total_charge) are
 *    separate from sale revenue. They live on the service job, never
 *    in orders, payments, or reconciliation_batches.
 *  - A warranty job (service_type = 'warranty') has all charges set
 *    to 0.00. A paid job accumulates labor + parts charges.
 *  - Adding a part recalculates parts_charge (sum of line_totals)
 *    and total_charge (labor_charge + parts_charge).
 *
 * Inventory integrity:
 *  - A service job does NOT move inventory. The physical unit stays
 *    in its current status. If a job results in a unit being scrapped
 *    or returned, that flows through the InventoryService state
 *    machine, not through the service module.
 */
final class ServiceJobService
{
    public const JOB_STATUSES = [
        'received', 'diagnosing', 'awaiting_parts', 'in_progress',
        'quality_check', 'completed', 'cancelled',
    ];

    public const SERVICE_TYPES = ['warranty', 'paid', 'internal'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const NOTE_TYPES = ['diagnosis', 'repair', 'customer_update', 'internal'];

    public const STATUS_TRANSITIONS = [
        'received' => ['diagnosing', 'cancelled'],
        'diagnosing' => ['awaiting_parts', 'in_progress', 'cancelled'],
        'awaiting_parts' => ['in_progress', 'cancelled'],
        'in_progress' => ['quality_check', 'completed', 'cancelled'],
        'quality_check' => ['completed', 'in_progress', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    /**
     * Create a new service job. Generates a sequential job number,
     * links to customer + inventory unit + warranty claim + order
     * as provided.
     */
    public function createJob(
        ?int $customerId,
        ?int $inventoryUnitId,
        ?int $warrantyClaimId,
        ?int $orderId,
        string $serviceType,
        string $reportedIssue,
        string $priority,
        int $createdBy,
        ?float $estimatedCost = null,
    ): array {
        if (!in_array($serviceType, self::SERVICE_TYPES, true)) {
            throw new ValidationException(['service_type' => 'Invalid service type.']);
        }
        if (trim($reportedIssue) === '') {
            throw new ValidationException(['reported_issue' => 'Reported issue is required.']);
        }
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new ValidationException(['priority' => 'Invalid priority.']);
        }
        // Warranty jobs must have a warranty_claim_id.
        if ($serviceType === 'warranty' && $warrantyClaimId === null) {
            throw new ValidationException(['warranty_claim_id' => 'Warranty jobs must be linked to a warranty claim.']);
        }
        // Internal jobs don't require a customer.
        if ($serviceType !== 'internal' && $customerId === null) {
            throw new ValidationException(['customer_id' => 'Customer is required for paid and warranty jobs.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($customerId, $inventoryUnitId, $warrantyClaimId, $orderId, $serviceType, $reportedIssue, $priority, $createdBy, $estimatedCost) {
            $jobNumber = $this->generateJobNumber($pdo);

            $stmt = $pdo->prepare(
                'INSERT INTO service_jobs
                    (job_number, customer_id, inventory_unit_id, warranty_claim_id, order_id,
                     service_type, status, priority, reported_issue, estimated_cost, created_by, received_at)
                 VALUES (?, ?, ?, ?, ?, ?, "received", ?, ?, ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute([
                $jobNumber,
                $customerId,
                $inventoryUnitId,
                $warrantyClaimId,
                $orderId,
                $serviceType,
                $priority,
                $reportedIssue,
                $estimatedCost !== null ? number_format($estimatedCost, 2, '.', '') : null,
                $createdBy,
            ]);
            $jobId = (int) $pdo->lastInsertId();

            // Seed the history with the initial 'new → received' row.
            $this->recordHistory($pdo, $jobId, 'new', 'received', $createdBy, 'Job created');

            app_audit('service.job_created', 'service_job', $jobId, [
                'job_number' => $jobNumber,
                'service_type' => $serviceType,
                'customer_id' => $customerId,
            ]);

            return ['id' => $jobId, 'job_number' => $jobNumber, 'status' => 'received'];
        });
    }

    /**
     * Assign a technician to the job.
     */
    public function assignTechnician(int $jobId, int $technicianId, int $assignedBy): array
    {
        return Connection::transaction(function (PDO $pdo) use ($jobId, $technicianId, $assignedBy) {
            $stmt = $pdo->prepare('SELECT * FROM service_jobs WHERE id = ? FOR UPDATE');
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($job === false) {
                throw new ValidationException(['job_id' => 'Service job does not exist.']);
            }
            if (in_array($job['status'], ['completed', 'cancelled'], true)) {
                throw new ValidationException(['job_id' => 'Cannot assign a technician to a completed or cancelled job.']);
            }

            $stmt = $pdo->prepare('UPDATE service_jobs SET assigned_to = ? WHERE id = ?');
            $stmt->execute([$technicianId, $jobId]);

            app_audit('service.assigned', 'service_job', $jobId, [
                'technician_id' => $technicianId,
                'assigned_by' => $assignedBy,
            ]);

            return ['id' => $jobId, 'assigned_to' => $technicianId];
        });
    }

    /**
     * Record a diagnosis and transition to 'diagnosing' (if currently
     * 'received'). Appends a 'diagnosis' note.
     */
    public function addDiagnosis(int $jobId, string $diagnosis, int $technicianId): array
    {
        $diagnosis = trim($diagnosis);
        if ($diagnosis === '') {
            throw new ValidationException(['diagnosis' => 'Diagnosis text is required.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($jobId, $diagnosis, $technicianId) {
            $stmt = $pdo->prepare('SELECT * FROM service_jobs WHERE id = ? FOR UPDATE');
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($job === false) {
                throw new ValidationException(['job_id' => 'Service job does not exist.']);
            }
            if (in_array($job['status'], ['completed', 'cancelled'], true)) {
                throw new ValidationException(['job_id' => 'Cannot add a diagnosis to a completed or cancelled job.']);
            }

            // Update diagnosis text + set diagnosed_at.
            $stmt = $pdo->prepare(
                'UPDATE service_jobs SET diagnosis = ?, diagnosed_at = UTC_TIMESTAMP() WHERE id = ?'
            );
            $stmt->execute([$diagnosis, $jobId]);

            // Append a 'diagnosis' note.
            $this->addNoteInternal($pdo, $jobId, $technicianId, $diagnosis, 'diagnosis');

            // Auto-transition received → diagnosing.
            if ($job['status'] === 'received') {
                $this->transitionInternal($pdo, $jobId, 'received', 'diagnosing', $technicianId, 'Diagnosis started');
            }

            app_audit('service.diagnosed', 'service_job', $jobId, [
                'diagnosis_preview' => mb_substr($diagnosis, 0, 80),
            ]);

            return ['id' => $jobId, 'status' => $job['status'] === 'received' ? 'diagnosing' : $job['status']];
        });
    }

    /**
     * Append a note to the job's append-only log.
     */
    public function addNote(int $jobId, int $authorId, string $body, string $noteType = 'internal'): int
    {
        $body = trim($body);
        if ($body === '') {
            throw new ValidationException(['body' => 'Note body cannot be empty.']);
        }
        if (!in_array($noteType, self::NOTE_TYPES, true)) {
            throw new ValidationException(['note_type' => 'Invalid note type.']);
        }

        return $this->addNoteInternal(Connection::pdo(), $jobId, $authorId, $body, $noteType);
    }

    /**
     * Add a billable part to the job. Recalculates parts_charge +
     * total_charge on the parent job.
     */
    public function addPart(int $jobId, string $partName, ?string $partCode, int $quantity, float $unitCost, float $unitPrice): array
    {
        if (trim($partName) === '') {
            throw new ValidationException(['part_name' => 'Part name is required.']);
        }
        if ($quantity <= 0) {
            throw new ValidationException(['quantity' => 'Quantity must be at least 1.']);
        }
        if ($unitCost < 0 || $unitPrice < 0) {
            throw new ValidationException(['unit_cost' => 'Costs cannot be negative.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($jobId, $partName, $partCode, $quantity, $unitCost, $unitPrice) {
            $stmt = $pdo->prepare('SELECT * FROM service_jobs WHERE id = ? FOR UPDATE');
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($job === false) {
                throw new ValidationException(['job_id' => 'Service job does not exist.']);
            }
            if (in_array($job['status'], ['completed', 'cancelled'], true)) {
                throw new ValidationException(['job_id' => 'Cannot add parts to a completed or cancelled job.']);
            }

            $lineTotal = round($unitPrice * $quantity, 2);
            $stmt = $pdo->prepare(
                'INSERT INTO service_parts (service_job_id, part_name, part_code, quantity, unit_cost, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $jobId,
                $partName,
                $partCode,
                $quantity,
                number_format($unitCost, 2, '.', ''),
                number_format($unitPrice, 2, '.', ''),
                number_format($lineTotal, 2, '.', ''),
            ]);
            $partId = (int) $pdo->lastInsertId();

            // Recalculate parts_charge + total_charge.
            $this->recalculateCharges($pdo, $jobId);

            app_audit('service.part_added', 'service_job', $jobId, [
                'part_name' => $partName,
                'quantity' => $quantity,
                'line_total' => number_format($lineTotal, 2, '.', ''),
            ]);

            return ['id' => $partId, 'line_total' => number_format($lineTotal, 2, '.', '')];
        });
    }

    /**
     * Transition the job's status. Must be allowed by
     * STATUS_TRANSITIONS.
     */
    public function transition(int $jobId, string $toStatus, int $actorId, ?string $reason = null): array
    {
        if (!in_array($toStatus, self::JOB_STATUSES, true)) {
            throw new ValidationException(['status' => 'Invalid status.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($jobId, $toStatus, $actorId, $reason) {
            $stmt = $pdo->prepare('SELECT * FROM service_jobs WHERE id = ? FOR UPDATE');
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($job === false) {
                throw new ValidationException(['job_id' => 'Service job does not exist.']);
            }
            $from = (string) $job['status'];

            $this->assertTransitionAllowed($from, $toStatus);

            $timestampClause = match ($toStatus) {
                'diagnosing' => 'diagnosed_at = COALESCE(diagnosed_at, UTC_TIMESTAMP()), ',
                'in_progress' => 'started_at = COALESCE(started_at, UTC_TIMESTAMP()), ',
                'completed' => 'completed_at = UTC_TIMESTAMP(), ',
                'cancelled' => 'cancelled_at = UTC_TIMESTAMP(), ',
                default => '',
            };

            $stmt = $pdo->prepare("UPDATE service_jobs SET {$timestampClause}status = ? WHERE id = ?");
            $stmt->execute([$toStatus, $jobId]);

            $this->recordHistory($pdo, $jobId, $from, $toStatus, $actorId, $reason);

            app_audit('service.status_updated', 'service_job', $jobId, [
                'from' => $from,
                'to' => $toStatus,
            ]);

            return ['id' => $jobId, 'status' => $toStatus];
        });
    }

    /**
     * Quality check: transitions to 'quality_check' and either
     * auto-completes (passed) or sends back to 'in_progress' (failed).
     */
    public function qualityCheck(int $jobId, int $checkedBy, bool $passed, ?string $notes = null): array
    {
        return Connection::transaction(function (PDO $pdo) use ($jobId, $checkedBy, $passed, $notes) {
            $stmt = $pdo->prepare('SELECT * FROM service_jobs WHERE id = ? FOR UPDATE');
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($job === false) {
                throw new ValidationException(['job_id' => 'Service job does not exist.']);
            }

            // Must be in_progress to start a quality check.
            if (!in_array($job['status'], ['in_progress', 'quality_check'], true)) {
                throw new ValidationException(['job_id' => 'Quality check requires the job to be in progress. Current status: ' . $job['status']]);
            }

            // Transition to quality_check first (if not already there).
            if ($job['status'] === 'in_progress') {
                $this->transitionInternal($pdo, $jobId, 'in_progress', 'quality_check', $checkedBy, 'Quality check started');
            }

            // Set quality_checked_by + quality_checked_at.
            $stmt = $pdo->prepare(
                'UPDATE service_jobs SET quality_checked_by = ?, quality_checked_at = UTC_TIMESTAMP() WHERE id = ?'
            );
            $stmt->execute([$checkedBy, $jobId]);

            if ($notes !== null) {
                $this->addNoteInternal($pdo, $jobId, $checkedBy, $notes, 'repair');
            }

            if ($passed) {
                $this->transitionInternal($pdo, $jobId, 'quality_check', 'completed', $checkedBy, 'Quality check passed');
                // Set completed_at explicitly — transitionInternal doesn't
                // set timestamps; the closeJob path does it but qualityCheck
                // skips closeJob.
                $stmt = $pdo->prepare('UPDATE service_jobs SET completed_at = UTC_TIMESTAMP() WHERE id = ?');
                $stmt->execute([$jobId]);
            } else {
                $this->transitionInternal($pdo, $jobId, 'quality_check', 'in_progress', $checkedBy, 'Quality check failed — back to repair');
            }

            app_audit('service.quality_checked', 'service_job', $jobId, [
                'passed' => $passed,
                'checked_by' => $checkedBy,
            ]);

            return ['id' => $jobId, 'passed' => $passed, 'status' => $passed ? 'completed' : 'in_progress'];
        });
    }

    /**
     * Close the job: transitions to 'completed', sets actual_cost.
     */
    public function closeJob(int $jobId, int $closedBy, ?string $resolutionNotes = null, ?float $actualCost = null): array
    {
        return Connection::transaction(function (PDO $pdo) use ($jobId, $closedBy, $resolutionNotes, $actualCost) {
            $stmt = $pdo->prepare('SELECT * FROM service_jobs WHERE id = ? FOR UPDATE');
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($job === false) {
                throw new ValidationException(['job_id' => 'Service job does not exist.']);
            }
            if ($job['status'] === 'completed') {
                throw new ValidationException(['job_id' => 'Job is already completed.']);
            }
            if ($job['status'] === 'cancelled') {
                throw new ValidationException(['job_id' => 'Cannot close a cancelled job.']);
            }

            // Transition to completed (skip quality_check if not already
            // there — the matrix allows in_progress → completed).
            $from = (string) $job['status'];
            $this->assertTransitionAllowed($from, 'completed');

            $stmt = $pdo->prepare(
                'UPDATE service_jobs SET status = "completed", completed_at = UTC_TIMESTAMP() WHERE id = ?'
            );
            $stmt->execute([$jobId]);

            if ($actualCost !== null) {
                $stmt = $pdo->prepare('UPDATE service_jobs SET actual_cost = ? WHERE id = ?');
                $stmt->execute([number_format($actualCost, 2, '.', ''), $jobId]);
            }

            if ($resolutionNotes !== null) {
                $this->addNoteInternal($pdo, $jobId, $closedBy, $resolutionNotes, 'repair');
            }

            $this->recordHistory($pdo, $jobId, $from, 'completed', $closedBy, $resolutionNotes);

            app_audit('service.closed', 'service_job', $jobId, [
                'actual_cost' => $actualCost !== null ? number_format($actualCost, 2, '.', '') : null,
            ]);

            return ['id' => $jobId, 'status' => 'completed'];
        });
    }

    /**
     * Cancel the job.
     */
    public function cancelJob(int $jobId, int $cancelledBy, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => 'A cancellation reason is required.']);
        }
        return $this->transition($jobId, 'cancelled', $cancelledBy, $reason);
    }

    /**
     * Set the labor charge on a job. Recalculates total_charge.
     */
    public function setLaborCharge(int $jobId, float $laborCharge): void
    {
        if ($laborCharge < 0) {
            throw new ValidationException(['labor_charge' => 'Labor charge cannot be negative.']);
        }

        Connection::transaction(function (PDO $pdo) use ($jobId, $laborCharge) {
            $stmt = $pdo->prepare('SELECT labor_charge, parts_charge FROM service_jobs WHERE id = ? FOR UPDATE');
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($job === false) {
                throw new ValidationException(['job_id' => 'Service job does not exist.']);
            }

            $partsCharge = (float) $job['parts_charge'];
            $total = round($laborCharge + $partsCharge, 2);
            $stmt = $pdo->prepare(
                'UPDATE service_jobs SET labor_charge = ?, total_charge = ? WHERE id = ?'
            );
            $stmt->execute([
                number_format($laborCharge, 2, '.', ''),
                number_format($total, 2, '.', ''),
                $jobId,
            ]);
        });
    }

    // ---------------------------------------------------- READS --

    public function findJob(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT sj.*, c.name AS customer_name, c.phone AS customer_phone,
                    iu.sku AS unit_sku, iu.serial_number AS unit_serial,
                    pm.name AS product_name,
                    wc.claim_number AS warranty_claim_number,
                    o.reference AS order_reference,
                    t.name AS assigned_to_name,
                    cb.name AS created_by_name,
                    qc.name AS quality_checked_by_name
             FROM service_jobs sj
             LEFT JOIN customers c ON c.id = sj.customer_id
             LEFT JOIN inventory_units iu ON iu.id = sj.inventory_unit_id
             LEFT JOIN product_models pm ON pm.id = iu.product_model_id
             LEFT JOIN warranty_claims wc ON wc.id = sj.warranty_claim_id
             LEFT JOIN orders o ON o.id = sj.order_id
             LEFT JOIN users t ON t.id = sj.assigned_to
             LEFT JOIN users cb ON cb.id = sj.created_by
             LEFT JOIN users qc ON qc.id = sj.quality_checked_by
             WHERE sj.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['notes'] = $this->notes($id);
        $row['parts'] = $this->parts($id);
        $row['history'] = $this->history($id);
        $row['allowed_transitions'] = $this->allowedTransitions((string) $row['status']);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function notes(int $jobId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT sn.*, u.name AS author_name
             FROM service_notes sn
             LEFT JOIN users u ON u.id = sn.author_id
             WHERE sn.service_job_id = ?
             ORDER BY sn.id'
        );
        $stmt->execute([$jobId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function parts(int $jobId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT * FROM service_parts WHERE service_job_id = ? ORDER BY id'
        );
        $stmt->execute([$jobId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function history(int $jobId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT sh.*, u.name AS actor_name
             FROM service_history sh
             LEFT JOIN users u ON u.id = sh.actor_id
             WHERE sh.service_job_id = ?
             ORDER BY sh.id'
        );
        $stmt->execute([$jobId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(string $q = '', string $status = '', string $serviceType = '', int $page = 1, int $perPage = 25): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (sj.job_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR iu.sku LIKE ? OR iu.serial_number LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($status !== '' && in_array($status, self::JOB_STATUSES, true)) {
            $where .= ' AND sj.status = ?';
            $params[] = $status;
        }
        if ($serviceType !== '' && in_array($serviceType, self::SERVICE_TYPES, true)) {
            $where .= ' AND sj.service_type = ?';
            $params[] = $serviceType;
        }

        $countStmt = Connection::pdo()->prepare(
            "SELECT COUNT(*) FROM service_jobs sj
             LEFT JOIN customers c ON c.id = sj.customer_id
             LEFT JOIN inventory_units iu ON iu.id = sj.inventory_unit_id{$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = Connection::pdo()->prepare(
            "SELECT sj.id, sj.job_number, sj.service_type, sj.status, sj.priority, sj.total_charge,
                    sj.received_at, sj.completed_at, sj.assigned_to,
                    c.name AS customer_name, c.phone AS customer_phone,
                    iu.sku AS unit_sku,
                    pm.name AS product_name,
                    t.name AS assigned_to_name
             FROM service_jobs sj
             LEFT JOIN customers c ON c.id = sj.customer_id
             LEFT JOIN inventory_units iu ON iu.id = sj.inventory_unit_id
             LEFT JOIN product_models pm ON pm.id = iu.product_model_id
             LEFT JOIN users t ON t.id = sj.assigned_to
             {$where}
             ORDER BY sj.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public function allowedTransitions(string $from): array
    {
        $list = self::STATUS_TRANSITIONS[$from] ?? [];
        $out = [];
        foreach ($list as $to) {
            $out[] = ['from' => $from, 'to' => $to, 'label' => ucfirst(str_replace('_', ' ', $to))];
        }
        return $out;
    }

    public function assertTransitionAllowed(string $from, string $to): void
    {
        $allowed = self::STATUS_TRANSITIONS[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new ValidationException([
                'status' => "Service job status transition '{$from}' → '{$to}' is not allowed.",
            ]);
        }
    }

    // ---------------------------------------------------- HELPERS --

    private function addNoteInternal(PDO $pdo, int $jobId, int $authorId, string $body, string $noteType): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO service_notes (service_job_id, author_id, body, note_type)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$jobId, $authorId, $body, $noteType]);
        return (int) $pdo->lastInsertId();
    }

    private function transitionInternal(PDO $pdo, int $jobId, string $from, string $to, int $actorId, ?string $reason): void
    {
        $stmt = $pdo->prepare('UPDATE service_jobs SET status = ? WHERE id = ?');
        $stmt->execute([$to, $jobId]);
        $this->recordHistory($pdo, $jobId, $from, $to, $actorId, $reason);
    }

    private function recordHistory(PDO $pdo, int $jobId, string $from, string $to, int $actorId, ?string $reason): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO service_history (service_job_id, from_status, to_status, actor_id, reason)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $jobId,
            $from,
            $to,
            $actorId,
            $reason !== null ? mb_substr($reason, 0, 255) : null,
        ]);
    }

    private function recalculateCharges(PDO $pdo, int $jobId): void
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(line_total), 0) FROM service_parts WHERE service_job_id = ?');
        $stmt->execute([$jobId]);
        $partsCharge = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT labor_charge FROM service_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $laborCharge = (float) $stmt->fetchColumn();

        $total = round($laborCharge + $partsCharge, 2);
        $stmt = $pdo->prepare('UPDATE service_jobs SET parts_charge = ?, total_charge = ? WHERE id = ?');
        $stmt->execute([
            number_format($partsCharge, 2, '.', ''),
            number_format($total, 2, '.', ''),
            $jobId,
        ]);
    }

    private function generateJobNumber(PDO $pdo): string
    {
        $year = (int) gmdate('Y');
        $prefix = 'SVC-' . $year . '-';
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM service_jobs WHERE job_number LIKE ?');
        $stmt->execute([$prefix . '%']);
        $next = (int) $stmt->fetchColumn() + 1;
        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
