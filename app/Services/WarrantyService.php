<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Warranty management: policies, registrations, claims, decisions, history.
 *
 * Policy rules:
 *  - A registration's start_date = DATE(inventory_unit.sold_at) — the
 *    date the physical unit was sold. end_date = start_date + policy.
 *    duration_days. These are SNAPSHOTTED at registration time.
 *    Editing a policy later does not retroactively change existing
 *    warranties.
 *  - A unit can only have one active warranty. The service checks
 *    for existing active registrations before creating a new one.
 *    A voided registration allows re-registration.
 *
 * Eligibility:
 *  - A claim is eligible if: registration.status = 'active' AND
 *    claim date <= registration.end_date. Expired or voided
 *    registrations cannot have new claims filed.
 *
 * Claim status matrix:
 *   pending → approved, rejected, in_progress, cancelled
 *   approved → in_progress, completed, cancelled
 *   in_progress → completed, cancelled
 *   completed → (terminal)
 *   rejected → (terminal)
 *   cancelled → (terminal)
 *
 * History:
 *  - Every claim status transition is recorded in warranty_claim_history
 *    (append-only, INSERT-only). Historical records cannot be rewritten.
 *  - Voiding a registration is itself an audited action — the
 *    voided_by + void_reason + voided_at columns capture the audit trail.
 *
 * Inventory + financial integrity:
 *  - A warranty claim does NOT move inventory. If a claim results in
 *    a replacement or refund, that flows through the existing
 *    RefundService / OrderService workflows, not through the warranty
 *    module. The warranty module only tracks the claim's status.
 */
final class WarrantyService
{
    public const CLAIM_STATUSES = ['pending', 'approved', 'rejected', 'in_progress', 'completed', 'cancelled'];
    public const REGISTRATION_STATUSES = ['active', 'expired', 'voided'];
    public const COVERAGE_TYPES = ['repair', 'replace', 'refund', 'repair_or_replace'];
    public const CLAIM_TYPES = ['repair', 'replace', 'refund'];

    public const CLAIM_TRANSITIONS = [
        'pending' => ['approved', 'rejected', 'in_progress', 'cancelled'],
        'approved' => ['in_progress', 'completed', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    // ---------------------------------------------------- POLICIES --

    public function createPolicy(array $d, int $createdBy): int
    {
        if (trim($d['name'] ?? '') === '') {
            throw new ValidationException(['name' => 'Policy name is required.']);
        }
        if ((int) ($d['duration_days'] ?? 0) <= 0) {
            throw new ValidationException(['duration_days' => 'Duration must be at least 1 day.']);
        }
        if (!in_array($d['coverage_type'] ?? 'repair', self::COVERAGE_TYPES, true)) {
            throw new ValidationException(['coverage_type' => 'Invalid coverage type.']);
        }

        $slug = str_slug($d['name']);
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO warranty_policies (name, slug, description, duration_days, coverage_type, terms, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $d['name'],
            $slug . '-' . bin2hex(random_bytes(2)),
            $d['description'] ?? null ?: null,
            (int) $d['duration_days'],
            $d['coverage_type'] ?? 'repair',
            $d['terms'] ?? null ?: null,
            !empty($d['is_active']) ? 1 : 0,
            $createdBy,
        ]);
        return (int) Connection::pdo()->lastInsertId();
    }

    public function updatePolicy(int $id, array $d): void
    {
        $stmt = Connection::pdo()->prepare(
            'UPDATE warranty_policies
             SET name = ?, description = ?, duration_days = ?, coverage_type = ?, terms = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $d['name'],
            $d['description'] ?? null ?: null,
            (int) $d['duration_days'],
            $d['coverage_type'] ?? 'repair',
            $d['terms'] ?? null ?: null,
            !empty($d['is_active']) ? 1 : 0,
            $id,
        ]);
    }

    public function findPolicy(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare('SELECT * FROM warranty_policies WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function allPolicies(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM warranty_policies';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY duration_days ASC, name ASC';
        return Connection::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------- REGISTRATIONS --

    /**
     * Register a warranty for a sold physical unit. Computes
     * start_date from the unit's sold_at timestamp and end_date from
     * the policy's duration_days. Refuses if the unit is not 'sold',
     * if it already has an active warranty, or if no sale order/item
     * can be found for the unit.
     */
    public function createRegistration(int $unitId, ?int $policyId, int $registeredBy, ?string $notes = null): array
    {
        return Connection::transaction(function (PDO $pdo) use ($unitId, $policyId, $registeredBy, $notes) {
            // Lock the unit and verify it's sold.
            $stmt = $pdo->prepare('SELECT * FROM inventory_units WHERE id = ? FOR UPDATE');
            $stmt->execute([$unitId]);
            $unit = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($unit === false) {
                throw new ValidationException(['unit_id' => 'Inventory unit does not exist.']);
            }
            if ($unit['status'] !== 'sold') {
                throw new ValidationException(['unit_id' => "Cannot register a warranty for a unit that is not sold (status: {$unit['status']})."]);
            }
            if ($unit['sold_at'] === null) {
                throw new ValidationException(['unit_id' => 'Unit has no sold_at timestamp — cannot compute warranty start date.']);
            }

            // Check for existing active warranty.
            $stmt = $pdo->prepare('SELECT id FROM warranty_registrations WHERE inventory_unit_id = ? AND status = "active" FOR UPDATE');
            $stmt->execute([$unitId]);
            if ($stmt->fetchColumn() !== false) {
                throw new ValidationException(['unit_id' => 'This unit already has an active warranty. Void it before registering a new one.']);
            }

            // Find the sale order + order_item for this unit.
            $stmt = $pdo->prepare(
                'SELECT oi.order_id, oi.id AS order_item_id, o.customer_id
                 FROM order_items oi
                 JOIN orders o ON o.id = oi.order_id
                 WHERE oi.inventory_unit_id = ?
                 ORDER BY oi.id DESC LIMIT 1'
            );
            $stmt->execute([$unitId]);
            $saleLine = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($saleLine === false) {
                throw new ValidationException(['unit_id' => 'No sale order found for this unit. Cannot register a warranty without a sale.']);
            }

            // Resolve the policy — use the provided one or the
            // business default duration from business_profiles.
            $policy = null;
            $durationDays = (int) Connection::pdo()->query(
                'SELECT default_warranty_days FROM business_profiles ORDER BY id LIMIT 1'
            )->fetchColumn() ?: 30;
            if ($policyId !== null) {
                $policy = $this->findPolicy($policyId);
                if ($policy === null) {
                    throw new ValidationException(['policy_id' => 'Warranty policy does not exist.']);
                }
                $durationDays = (int) $policy['duration_days'];
            }

            // Compute start/end dates from the unit's sold_at.
            $startDate = gmdate('Y-m-d', strtotime((string) $unit['sold_at']));
            $endDate = gmdate('Y-m-d', strtotime("+{$durationDays} days", strtotime($startDate)));

            $registrationNumber = $this->generateRegistrationNumber($pdo);

            $stmt = $pdo->prepare(
                'INSERT INTO warranty_registrations
                    (registration_number, inventory_unit_id, order_id, order_item_id, customer_id,
                     warranty_policy_id, start_date, end_date, status, notes, registered_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "active", ?, ?)'
            );
            $stmt->execute([
                $registrationNumber,
                $unitId,
                (int) $saleLine['order_id'],
                (int) $saleLine['order_item_id'],
                (int) $saleLine['customer_id'],
                $policyId,
                $startDate,
                $endDate,
                $notes,
                $registeredBy,
            ]);
            $registrationId = (int) $pdo->lastInsertId();

            app_audit('warranty.registered', 'warranty_registration', $registrationId, [
                'registration_number' => $registrationNumber,
                'unit_id' => $unitId,
                'order_id' => (int) $saleLine['order_id'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'duration_days' => $durationDays,
            ]);

            return [
                'id' => $registrationId,
                'registration_number' => $registrationNumber,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ];
        });
    }

    public function voidRegistration(int $registrationId, int $voidedBy, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => 'A void reason is required.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($registrationId, $voidedBy, $reason) {
            $stmt = $pdo->prepare('SELECT * FROM warranty_registrations WHERE id = ? FOR UPDATE');
            $stmt->execute([$registrationId]);
            $reg = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($reg === false) {
                throw new ValidationException(['registration_id' => 'Warranty registration does not exist.']);
            }
            if ($reg['status'] === 'voided') {
                throw new ValidationException(['registration_id' => 'Registration is already voided.']);
            }

            $stmt = $pdo->prepare(
                'UPDATE warranty_registrations
                 SET status = "voided", voided_at = UTC_TIMESTAMP(), voided_by = ?, void_reason = ?
                 WHERE id = ?'
            );
            $stmt->execute([$voidedBy, mb_substr($reason, 0, 255), $registrationId]);

            app_audit('warranty.voided', 'warranty_registration', $registrationId, [
                'reason' => mb_substr($reason, 0, 80),
            ]);

            return ['id' => $registrationId, 'status' => 'voided'];
        });
    }

    public function findRegistration(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT wr.*, wp.name AS policy_name, wp.duration_days AS policy_duration,
                    wp.coverage_type AS policy_coverage,
                    iu.sku AS unit_sku, iu.serial_number AS unit_serial,
                    iu.condition_grade AS unit_grade, iu.sold_at AS unit_sold_at,
                    pm.name AS product_name,
                    o.reference AS order_reference,
                    c.name AS customer_name, c.phone AS customer_phone,
                    u.name AS registered_by_name,
                    v.name AS voided_by_name
             FROM warranty_registrations wr
             JOIN inventory_units iu ON iu.id = wr.inventory_unit_id
             JOIN product_models pm ON pm.id = iu.product_model_id
             JOIN orders o ON o.id = wr.order_id
             JOIN customers c ON c.id = wr.customer_id
             LEFT JOIN warranty_policies wp ON wp.id = wr.warranty_policy_id
             LEFT JOIN users u ON u.id = wr.registered_by
             LEFT JOIN users v ON v.id = wr.voided_by
             WHERE wr.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        // Load claims for this registration.
        $stmt = Connection::pdo()->prepare(
            'SELECT id, claim_number, claim_type, status, filed_at, completed_at
             FROM warranty_claims WHERE warranty_registration_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$id]);
        $row['claims'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $row['is_eligible'] = $this->isEligible($row);
        return $row;
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function searchRegistrations(string $q = '', string $status = '', int $page = 1, int $perPage = 25): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (wr.registration_number LIKE ? OR iu.sku LIKE ? OR iu.serial_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR o.reference LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($status !== '' && in_array($status, self::REGISTRATION_STATUSES, true)) {
            $where .= ' AND wr.status = ?';
            $params[] = $status;
        }

        $countStmt = Connection::pdo()->prepare(
            "SELECT COUNT(*) FROM warranty_registrations wr
             JOIN inventory_units iu ON iu.id = wr.inventory_unit_id
             JOIN customers c ON c.id = wr.customer_id
             JOIN orders o ON o.id = wr.order_id{$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = Connection::pdo()->prepare(
            "SELECT wr.id, wr.registration_number, wr.start_date, wr.end_date, wr.status,
                    wr.created_at,
                    iu.sku AS unit_sku, iu.serial_number AS unit_serial,
                    pm.name AS product_name,
                    c.name AS customer_name, c.phone AS customer_phone,
                    o.reference AS order_reference,
                    wp.name AS policy_name
             FROM warranty_registrations wr
             JOIN inventory_units iu ON iu.id = wr.inventory_unit_id
             JOIN product_models pm ON pm.id = iu.product_model_id
             JOIN customers c ON c.id = wr.customer_id
             JOIN orders o ON o.id = wr.order_id
             LEFT JOIN warranty_policies wp ON wp.id = wr.warranty_policy_id
             {$where}
             ORDER BY wr.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    // ---------------------------------------------------- CLAIMS --

    /**
     * Create a warranty claim. Checks eligibility first — expired or
     * voided registrations cannot have new claims filed.
     */
    public function createClaim(int $registrationId, string $claimType, ?string $description, string $customerReportedIssue, int $createdBy): array
    {
        if (!in_array($claimType, self::CLAIM_TYPES, true)) {
            throw new ValidationException(['claim_type' => 'Invalid claim type.']);
        }
        $customerReportedIssue = trim($customerReportedIssue);
        if ($customerReportedIssue === '') {
            throw new ValidationException(['customer_reported_issue' => 'Customer-reported issue is required.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($registrationId, $claimType, $description, $customerReportedIssue, $createdBy) {
            $stmt = $pdo->prepare('SELECT * FROM warranty_registrations WHERE id = ? FOR UPDATE');
            $stmt->execute([$registrationId]);
            $reg = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($reg === false) {
                throw new ValidationException(['registration_id' => 'Warranty registration does not exist.']);
            }

            if (!$this->isEligible($reg)) {
                throw new ValidationException([
                    'registration_id' => 'This warranty is not eligible for a new claim. Status: ' . $reg['status'] . ', end date: ' . $reg['end_date'] . '.',
                ]);
            }

            $claimNumber = $this->generateClaimNumber($pdo);
            $stmt = $pdo->prepare(
                'INSERT INTO warranty_claims
                    (claim_number, warranty_registration_id, claim_type, description, customer_reported_issue, status, created_by)
                 VALUES (?, ?, ?, ?, ?, "pending", ?)'
            );
            $stmt->execute([
                $claimNumber,
                $registrationId,
                $claimType,
                $description,
                $customerReportedIssue,
                $createdBy,
            ]);
            $claimId = (int) $pdo->lastInsertId();

            // Seed the claim history with the initial 'new → pending' row.
            $this->recordClaimHistory($pdo, $claimId, 'new', 'pending', $createdBy, 'Claim filed');

            app_audit('warranty.claim_filed', 'warranty_claim', $claimId, [
                'claim_number' => $claimNumber,
                'registration_id' => $registrationId,
                'claim_type' => $claimType,
            ]);

            return ['id' => $claimId, 'claim_number' => $claimNumber, 'status' => 'pending'];
        });
    }

    /**
     * Approve a pending claim. Sets decided_by + decided_at + decision_reason.
     */
    public function approveClaim(int $claimId, int $decidedBy, ?string $reason = null): array
    {
        return $this->transitionClaim($claimId, 'approved', $decidedBy, $reason);
    }

    public function rejectClaim(int $claimId, int $decidedBy, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => 'A rejection reason is required.']);
        }
        return $this->transitionClaim($claimId, 'rejected', $decidedBy, $reason);
    }

    public function startClaim(int $claimId, int $actorId): array
    {
        return $this->transitionClaim($claimId, 'in_progress', $actorId, null);
    }

    public function completeClaim(int $claimId, int $actorId, ?string $resolutionNotes = null): array
    {
        return $this->transitionClaim($claimId, 'completed', $actorId, null, function (PDO $pdo, array $claim) use ($resolutionNotes) {
            $stmt = $pdo->prepare('UPDATE warranty_claims SET resolution_notes = ?, completed_at = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->execute([$resolutionNotes, $claim['id']]);
        });
    }

    public function cancelClaim(int $claimId, int $actorId, ?string $reason = null): array
    {
        return $this->transitionClaim($claimId, 'cancelled', $actorId, $reason);
    }

    public function findClaim(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT wc.*, wr.registration_number, wr.start_date, wr.end_date, wr.status AS registration_status,
                    iu.sku AS unit_sku, iu.serial_number AS unit_serial,
                    pm.name AS product_name,
                    c.name AS customer_name, c.phone AS customer_phone,
                    o.reference AS order_reference,
                    d.name AS decided_by_name,
                    cr.name AS created_by_name
             FROM warranty_claims wc
             JOIN warranty_registrations wr ON wr.id = wc.warranty_registration_id
             JOIN inventory_units iu ON iu.id = wr.inventory_unit_id
             JOIN product_models pm ON pm.id = iu.product_model_id
             JOIN orders o ON o.id = wr.order_id
             JOIN customers c ON c.id = wr.customer_id
             LEFT JOIN users d ON d.id = wc.decided_by
             LEFT JOIN users cr ON cr.id = wc.created_by
             WHERE wc.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['history'] = $this->claimHistory($id);
        $row['allowed_transitions'] = $this->allowedClaimTransitions((string) $row['status']);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function claimHistory(int $claimId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT h.*, u.name AS actor_name
             FROM warranty_claim_history h
             LEFT JOIN users u ON u.id = h.actor_id
             WHERE h.claim_id = ?
             ORDER BY h.id'
        );
        $stmt->execute([$claimId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function searchClaims(string $q = '', string $status = '', int $page = 1, int $perPage = 25): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (wc.claim_number LIKE ? OR wr.registration_number LIKE ? OR iu.sku LIKE ? OR iu.serial_number LIKE ? OR c.name LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($status !== '' && in_array($status, self::CLAIM_STATUSES, true)) {
            $where .= ' AND wc.status = ?';
            $params[] = $status;
        }

        $countStmt = Connection::pdo()->prepare(
            "SELECT COUNT(*) FROM warranty_claims wc
             JOIN warranty_registrations wr ON wr.id = wc.warranty_registration_id
             JOIN inventory_units iu ON iu.id = wr.inventory_unit_id
             JOIN customers c ON c.id = wr.customer_id{$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = Connection::pdo()->prepare(
            "SELECT wc.id, wc.claim_number, wc.claim_type, wc.status, wc.filed_at, wc.completed_at,
                    wr.registration_number, wr.end_date AS warranty_end_date,
                    iu.sku AS unit_sku,
                    pm.name AS product_name,
                    c.name AS customer_name, c.phone AS customer_phone
             FROM warranty_claims wc
             JOIN warranty_registrations wr ON wr.id = wc.warranty_registration_id
             JOIN inventory_units iu ON iu.id = wr.inventory_unit_id
             JOIN product_models pm ON pm.id = iu.product_model_id
             JOIN customers c ON c.id = wr.customer_id
             {$where}
             ORDER BY wc.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    // ---------------------------------------------------- HELPERS --

    /**
     * Check eligibility: registration is 'active' AND current date
     * is <= end_date.
     */
    public function isEligible(array $registration): bool
    {
        if (($registration['status'] ?? 'active') !== 'active') {
            return false;
        }
        $endDate = (string) ($registration['end_date'] ?? '');
        if ($endDate === '') {
            return false;
        }
        return gmdate('Y-m-d') <= $endDate;
    }

    /** @return list<array<string,mixed>> */
    public function allowedClaimTransitions(string $from): array
    {
        $list = self::CLAIM_TRANSITIONS[$from] ?? [];
        $out = [];
        foreach ($list as $to) {
            $out[] = ['from' => $from, 'to' => $to, 'label' => ucfirst(str_replace('_', ' ', $to))];
        }
        return $out;
    }

    public function assertClaimTransitionAllowed(string $from, string $to): void
    {
        $allowed = self::CLAIM_TRANSITIONS[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new ValidationException([
                'status' => "Claim status transition '{$from}' → '{$to}' is not allowed.",
            ]);
        }
    }

    private function transitionClaim(int $claimId, string $to, int $actorId, ?string $reason, ?callable $extraUpdate = null): array
    {
        return Connection::transaction(function (PDO $pdo) use ($claimId, $to, $actorId, $reason, $extraUpdate) {
            $stmt = $pdo->prepare('SELECT * FROM warranty_claims WHERE id = ? FOR UPDATE');
            $stmt->execute([$claimId]);
            $claim = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($claim === false) {
                throw new ValidationException(['claim_id' => 'Claim does not exist.']);
            }
            $from = (string) $claim['status'];
            $this->assertClaimTransitionAllowed($from, $to);

            $decidedClause = '';
            $decidedParams = [];
            if (in_array($to, ['approved', 'rejected'], true)) {
                $decidedClause = ', decided_at = UTC_TIMESTAMP(), decided_by = ?, decision_reason = ?';
                $decidedParams[] = $actorId;
                $decidedParams[] = $reason !== null ? mb_substr($reason, 0, 255) : null;
            }

            $stmt = $pdo->prepare("UPDATE warranty_claims SET status = ?{$decidedClause} WHERE id = ?");
            $params = [$to, ...$decidedParams, $claimId];
            $stmt->execute($params);

            if ($extraUpdate !== null) {
                $claim['id'] = (int) $claim['id'];
                $extraUpdate($pdo, $claim);
            }

            $this->recordClaimHistory($pdo, $claimId, $from, $to, $actorId, $reason);

            app_audit('warranty.claim_' . $to, 'warranty_claim', $claimId, [
                'from' => $from,
                'to' => $to,
                'reason' => $reason !== null ? mb_substr($reason, 0, 80) : null,
            ]);

            return ['id' => $claimId, 'status' => $to];
        });
    }

    private function recordClaimHistory(PDO $pdo, int $claimId, string $from, string $to, int $actorId, ?string $reason): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO warranty_claim_history (claim_id, from_status, to_status, actor_id, reason)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $claimId,
            $from,
            $to,
            $actorId,
            $reason !== null ? mb_substr($reason, 0, 255) : null,
        ]);
    }

    private function generateRegistrationNumber(PDO $pdo): string
    {
        $year = (int) gmdate('Y');
        $prefix = 'WR-' . $year . '-';
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM warranty_registrations WHERE registration_number LIKE ?');
        $stmt->execute([$prefix . '%']);
        $next = (int) $stmt->fetchColumn() + 1;
        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function generateClaimNumber(PDO $pdo): string
    {
        $year = (int) gmdate('Y');
        $prefix = 'CLM-' . $year . '-';
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM warranty_claims WHERE claim_number LIKE ?');
        $stmt->execute([$prefix . '%']);
        $next = (int) $stmt->fetchColumn() + 1;
        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
