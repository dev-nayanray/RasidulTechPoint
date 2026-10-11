<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Repositories\InspectionRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\InventoryService;
use App\Support\Config;
use App\Support\Database\Connection;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Quality inspection: configurable checklists, technician assignment,
 * serial verification, hardware test records, condition grading,
 * defect notes, photo evidence, pass/fail/hold outcomes, and approval
 * history.
 *
 * Only authorized approval (inventory.approve permission) may make a
 * unit sellable — the inspection record stores the outcome, but the
 * unit's status transition to 'available' requires the
 * InventoryService::transition('inspection', 'available') which is
 * gated behind inventory.approve in the controller.
 */
final class InspectionController
{
    public function index(Request $request): Response
    {
        $repo = new InspectionRepository();
        $q = trim((string) $request->query('q', ''));

        $records = $q !== ''
            ? Connection::pdo()->prepare(
                "SELECT ir.*, iu.sku AS unit_sku, t.name AS technician_name
                 FROM inspection_records ir
                 JOIN inventory_units iu ON iu.id = ir.inventory_unit_id
                 LEFT JOIN users t ON t.id = ir.technician_id
                 WHERE iu.sku LIKE ? OR iu.serial_number LIKE ?
                 ORDER BY ir.id DESC LIMIT 50"
            )->execute(['%' . $q . '%', '%' . $q . '%'])->fetchAll(\PDO::FETCH_ASSOC)
            : $repo->recentRecords(50);

        return Response::html(View::render('admin/inspections/index', [
            'pageTitle' => 'QA inspections',
            'records' => $records,
            'q' => $q,
            'checklist' => $repo->allChecklist(),
        ]));
    }

    public function show(string $id): Response
    {
        $record = (new InspectionRepository())->findRecord((int) $id);
        if ($record === null) {
            throw new NotFoundException('Inspection record not found.');
        }

        return Response::html(View::render('admin/inspections/show', [
            'pageTitle' => 'Inspection #' . $record['id'],
            'record' => $record,
        ]));
    }

    public function createForm(Request $request): Response
    {
        $unitId = (int) $request->query('unit', 0);
        $unit = null;
        if ($unitId > 0) {
            $stmt = Connection::pdo()->prepare(
                'SELECT iu.id, iu.sku, iu.serial_number, iu.condition_grade, iu.status,
                        pm.name AS product_name
                 FROM inventory_units iu
                 JOIN product_models pm ON pm.id = iu.product_model_id
                 WHERE iu.id = ?'
            );
            $stmt->execute([$unitId]);
            $unit = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        return Response::html(View::render('admin/inspections/form', [
            'pageTitle' => 'New inspection',
            'unit' => $unit,
            'checklist' => (new InspectionRepository())->activeChecklist(),
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        $unitId = (int) $request->input('inventory_unit_id', 0);
        if ($unitId <= 0) {
            flash_error('An inventory unit is required.');
            return Response::redirect(url('/admin/inspections/create'));
        }

        // Verify the unit exists.
        $stmt = Connection::pdo()->prepare('SELECT id, status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $unit = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($unit === false) {
            throw new NotFoundException('Inventory unit not found.');
        }

        // Require inspections.manage permission (checked by route middleware
        // but re-verified server-side for defense in depth).
        if (!AuthService::can('inspections.manage')) {
            throw new ForbiddenException('Missing permission: inspections.manage');
        }

        $outcome = (string) $request->input('outcome', '');
        if (!in_array($outcome, ['pass', 'fail', 'hold'], true)) {
            flash_error('Outcome must be pass, fail, or hold.');
            return Response::redirect(url('/admin/inspections/create?unit=' . $unitId));
        }

        $conditionGrade = (string) $request->input('condition_grade', '');
        if (!in_array($conditionGrade, ['A', 'B', 'C', 'D'], true)) {
            $conditionGrade = null;
        }

        $defectNotes = trim((string) $request->input('defect_notes', ''));
        if ($outcome === 'fail' && $defectNotes === '') {
            flash_error('Defect notes are required when the outcome is "fail".');
            return Response::redirect(url('/admin/inspections/create?unit=' . $unitId));
        }

        // Collect checklist results.
        $checklistIds = $request->input('checklist_id', []);
        $checklistStatuses = $request->input('checklist_status', []);
        $results = [];
        if (is_array($checklistIds)) {
            foreach ($checklistIds as $i => $cid) {
                $status = $checklistStatuses[$i] ?? 'pending';
                $results[] = ['id' => (int) $cid, 'status' => $status];
            }
        }

        // Handle photo uploads.
        $photoPaths = $this->handlePhotos($request);

        $repo = new InspectionRepository();
        $recordId = $repo->createRecord(
            $unitId,
            current_user_id(),
            $outcome,
            $conditionGrade ?: null,
            $defectNotes ?: null,
            $results !== [] ? json_encode($results, JSON_UNESCAPED_UNICODE) : null,
            $photoPaths !== [] ? json_encode($photoPaths, JSON_UNESCAPED_UNICODE) : null,
        );

        AuditService::record('inspection.created', 'inspection_record', $recordId, [
            'unit_id' => $unitId,
            'outcome' => $outcome,
            'condition_grade' => $conditionGrade,
        ], $request);

        // If the outcome is 'pass' and the unit is awaiting_inspection,
        // and the user has inventory.approve, transition the unit to
        // available (make it sellable).
        if ($outcome === 'pass' && $unit['status'] === 'awaiting_inspection') {
            if (AuthService::can('inventory.approve')) {
                (new InventoryService())->transition(
                    $unitId, 'inspection', 'available',
                    'Passed QA inspection (record #' . $recordId . ')',
                    current_user_id(),
                );
                $repo->approveRecord($recordId, current_user_id());
                AuditService::record('inspection.approved', 'inspection_record', $recordId, [
                    'unit_id' => $unitId,
                    'made_sellable' => true,
                ], $request);
                flash_success('Inspection passed. Unit is now available for sale.');
            } else {
                flash_success('Inspection recorded as pass. An approver must make the unit sellable.');
            }
        } elseif ($outcome === 'fail' && $unit['status'] === 'awaiting_inspection') {
            // Transition to damaged.
            (new InventoryService())->transition(
                $unitId, 'inspection', 'damaged',
                'Failed QA inspection (record #' . $recordId . ')',
                current_user_id(),
            );
            flash_success('Inspection recorded as fail. Unit marked damaged.');
        } else {
            flash_success('Inspection recorded as ' . $outcome . '.');
        }

        return Response::redirect(url('/admin/inspections/' . $recordId));
    }

    public function approve(string $id, Request $request): Response
    {
        $repo = new InspectionRepository();
        $record = $repo->findRecord((int) $id);
        if ($record === null) {
            throw new NotFoundException('Inspection record not found.');
        }
        if ($record['outcome'] !== 'pass') {
            flash_error('Only "pass" inspections can be approved.');
            return Response::redirect(url('/admin/inspections/' . $id));
        }
        if ($record['approved_by'] !== null) {
            flash_error('This inspection is already approved.');
            return Response::redirect(url('/admin/inspections/' . $id));
        }

        // Server-side permission re-check.
        if (!AuthService::can('inventory.approve')) {
            throw new ForbiddenException('Missing permission: inventory.approve');
        }

        $repo->approveRecord((int) $id, current_user_id());

        // Transition the unit to available (make it sellable).
        $unitId = (int) $record['inventory_unit_id'];
        $unit = Connection::pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $unit->execute([$unitId]);
        $status = (string) $unit->fetchColumn();

        if ($status === 'awaiting_inspection' || $status === 'damaged') {
            (new InventoryService())->transition(
                $unitId, 'inspection', 'available',
                'Approved inspection #' . $id,
                current_user_id(),
            );
        }

        AuditService::record('inspection.approved', 'inspection_record', (int) $id, [
            'unit_id' => $unitId,
            'made_sellable' => true,
        ], $request);

        flash_success('Inspection approved. Unit is now available for sale.');
        return Response::redirect(url('/admin/inspections/' . $id));
    }

    // ---- Checklist management ----

    public function addChecklistItem(Request $request): Response
    {
        $name = trim((string) $request->input('name', ''));
        $category = trim((string) $request->input('category', 'general'));
        $description = trim((string) $request->input('description', ''));

        if ($name === '' || mb_strlen($name) < 2) {
            flash_error('Checklist item name is required (min 2 characters).');
            return Response::redirect(url('/admin/inspections'));
        }

        (new InspectionRepository())->addChecklistItem($name, $category, $description ?: null);
        AuditService::record('checklist.item_added', null, null, ['name' => $name], $request);

        flash_success('Checklist item added.');
        return Response::redirect(url('/admin/inspections'));
    }

    public function toggleChecklistItem(string $id, Request $request): Response
    {
        $repo = new InspectionRepository();
        $item = $repo->findChecklistItem((int) $id);
        if ($item === null) {
            throw new NotFoundException('Checklist item not found.');
        }
        $repo->toggleChecklistItem((int) $id, (int) $item['is_active'] === 0);
        AuditService::record('checklist.item_toggled', null, (int) $id, [
            'name' => $item['name'],
            'now_active' => (int) $item['is_active'] === 0,
        ], $request);
        return Response::redirect(url('/admin/inspections'));
    }

    public function deleteChecklistItem(string $id, Request $request): Response
    {
        $repo = new InspectionRepository();
        $item = $repo->findChecklistItem((int) $id);
        if ($item === null) {
            throw new NotFoundException('Checklist item not found.');
        }
        $repo->deleteChecklistItem((int) $id);
        AuditService::record('checklist.item_deleted', null, (int) $id, ['name' => $item['name']], $request);
        flash_success('Checklist item deleted.');
        return Response::redirect(url('/admin/inspections'));
    }

    /** @return list<string> file paths */
    private function handlePhotos(Request $request): array
    {
        $paths = [];
        $files = $request->files();
        $allowedMimes = Config::get('security.uploads.allowed_image_mimes', ['image/jpeg', 'image/png', 'image/webp']);
        $maxBytes = (int) Config::get('security.uploads.max_bytes', 4 * 1024 * 1024);

        for ($i = 0; $i < 5; $i++) {
            $file = $request->file('photo_' . $i);
            if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
                continue;
            }
            if (($file['size'] ?? 0) > $maxBytes) {
                continue;
            }
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $realMime = $finfo->file($file['tmp_name']);
            if (!in_array($realMime, $allowedMimes, true)) {
                continue;
            }
            $ext = match ($realMime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'bin',
            };
            $safeName = 'inspection-' . bin2hex(random_bytes(16)) . '.' . $ext;
            $destDir = BASE_PATH . '/public/uploads/inspections';
            if (!is_dir($destDir)) {
                mkdir($destDir, 0770, true);
            }
            $destPath = $destDir . '/' . $safeName;
            if (is_uploaded_file($file['tmp_name'])) {
                move_uploaded_file($file['tmp_name'], $destPath);
            } else {
                rename($file['tmp_name'], $destPath);
            }
            $paths[] = 'uploads/inspections/' . $safeName;
        }
        return $paths;
    }
}
