<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database\Connection;
use PDO;

/**
 * Inspection checklists + inspection records.
 */
final class InspectionRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /** @return list<array<string,mixed>> */
    public function activeChecklist(): array
    {
        return $this->pdo->query(
            'SELECT id, name, category, description, sort_order
             FROM inspection_checklists
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function allChecklist(): array
    {
        return $this->pdo->query(
            'SELECT * FROM inspection_checklists ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addChecklistItem(string $name, string $category, ?string $description): int
    {
        $maxSort = (int) $this->pdo->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM inspection_checklists')->fetchColumn();
        $stmt = $this->pdo->prepare(
            'INSERT INTO inspection_checklists (name, category, description, sort_order)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $category, $description, $maxSort]);
        return (int) $this->pdo->lastInsertId();
    }

    public function toggleChecklistItem(int $id, bool $active): void
    {
        $this->pdo->prepare('UPDATE inspection_checklists SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
    }

    public function deleteChecklistItem(int $id): void
    {
        $this->pdo->prepare('DELETE FROM inspection_checklists WHERE id = ?')->execute([$id]);
    }

    public function findChecklistItem(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inspection_checklists WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function createRecord(int $unitId, ?int $technicianId, string $outcome, ?string $conditionGrade, ?string $defectNotes, ?string $checklistResults, ?string $photoPaths): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inspection_records (inventory_unit_id, technician_id, outcome, condition_grade, defect_notes, checklist_results, photo_paths)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$unitId, $technicianId, $outcome, $conditionGrade, $defectNotes, $checklistResults, $photoPaths]);
        return (int) $this->pdo->lastInsertId();
    }

    public function approveRecord(int $recordId, int $approverId): void
    {
        $this->pdo->prepare(
            'UPDATE inspection_records SET approved_by = ?, approved_at = UTC_TIMESTAMP() WHERE id = ?'
        )->execute([$approverId, $recordId]);
    }

    /** @return list<array<string,mixed>> */
    public function recordsForUnit(int $unitId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ir.*, t.name AS technician_name, a.name AS approver_name
             FROM inspection_records ir
             LEFT JOIN users t ON t.id = ir.technician_id
             LEFT JOIN users a ON a.id = ir.approved_by
             WHERE ir.inventory_unit_id = ?
             ORDER BY ir.id DESC'
        );
        $stmt->execute([$unitId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findRecord(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ir.*, iu.sku AS unit_sku, t.name AS technician_name, a.name AS approver_name
             FROM inspection_records ir
             JOIN inventory_units iu ON iu.id = ir.inventory_unit_id
             LEFT JOIN users t ON t.id = ir.technician_id
             LEFT JOIN users a ON a.id = ir.approved_by
             WHERE ir.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function recentRecords(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ir.*, iu.sku AS unit_sku, t.name AS technician_name
             FROM inspection_records ir
             JOIN inventory_units iu ON iu.id = ir.inventory_unit_id
             LEFT JOIN users t ON t.id = ir.technician_id
             ORDER BY ir.id DESC
             LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
