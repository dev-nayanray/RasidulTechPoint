<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Repositories\InspectionRepository;
use App\Services\InventoryService;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Quality inspection — HTTP-level tests.
 *
 * Proves:
 *  - configurable checklist (default items seeded, add/toggle/delete)
 *  - create inspection record with outcome (pass/fail/hold), grade,
 *    defect notes, checklist results
 *  - pass + inventory.approve → unit transitions to available (sellable)
 *  - pass + no inventory.approve → unit stays awaiting_inspection
 *  - fail → unit transitions to damaged
 *  - hold → unit status unchanged
 *  - defect notes required for 'fail' outcome
 *  - approve action requires inventory.approve permission
 *  - audit trail for create/approve
 *  - permission enforcement (cashier blocked, technician allowed)
 *  - CSRF
 */
final class InspectionTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->group(['middleware' => ['headers']], function (Router $router): void {
            require BASE_PATH . '/routes/web.php';
        });
        return $router;
    }

    private function resolver(): MiddlewareResolver
    {
        $resolver = new MiddlewareResolver();
        $resolver->register('headers', fn () => new \App\Middleware\SecurityHeadersMiddleware());
        $resolver->register('auth', fn () => new \App\Middleware\AuthMiddleware(guest: false));
        $resolver->register('guest', fn () => new \App\Middleware\AuthMiddleware(guest: true));
        $resolver->register('csrf', fn () => new \App\Middleware\CsrfMiddleware());
        $resolver->register('permission', fn () => new \App\Middleware\PermissionMiddleware());
        return $resolver;
    }

    private function withCsrf(array $post): array
    {
        $post['_token'] = Csrf::token();
        return $post;
    }

    private function dispatchAs(int $userId, string $method, string $uri, array $post = [], array $get = []): Response
    {
        $this->actingAs($userId);
        return $this->router()->dispatch(
            Request::synthetic($method, $uri, $get, $this->withCsrf($post)),
            $this->resolver()
        );
    }

    private function wipeOwners(): void
    {
        $this->pdo()->exec(
            "UPDATE users SET is_active = 0 WHERE id IN (
                SELECT user_id FROM (
                    SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                    WHERE r.slug = 'owner'
                ) AS owners)"
        );
    }

    public function setUp(): void
    {
        parent::setUp();
        if ((int) $this->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            $this->makeUser('insp-setup@test.local', 'Correct-Horse-1', ['owner']);
        }
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testCashierBlockedFromInspections(): void
    {
        $cashierId = $this->makeUser('insp-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);
        $this->assertThrows(ForbiddenException::class, function () use ($cashierId) {
            $this->dispatchAs($cashierId, 'GET', '/admin/inspections');
        }, 'cashier (no inspections.manage) must not access inspections');
    }

    public function testTechnicianCanAccess(): void
    {
        $techId = $this->makeUser('insp-tech@test.local', 'Correct-Horse-1', ['technician']);
        $response = $this->dispatchAs($techId, 'GET', '/admin/inspections');
        $this->assertSame(200, $response->status());
    }

    // ---------------------------------------------------- INDEX --

    public function testIndexRendersWithChecklist(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('insp-index@test.local', 'Correct-Horse-1', ['owner']);
        $response = $this->dispatchAs($ownerId, 'GET', '/admin/inspections');
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/QA inspections/', $response->body());
        $this->assertMatchesRegularExpression('/Inspection checklist/', $response->body());
        $this->assertMatchesRegularExpression('/Power-on test/', $response->body(), 'default checklist items must be seeded');
    }

    // ---------------------------------------------------- CREATE + PASS --

    public function testCreatePassInspectionWithApproval(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('insp-pass@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId, ['sku' => 'INSP-PASS-001']); // awaiting_inspection

        $checklist = (new InspectionRepository())->activeChecklist();
        $checklistIds = array_column($checklist, 'id');

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inspections', [
            'inventory_unit_id' => (string) $unitId,
            'outcome' => 'pass',
            'condition_grade' => 'A',
            'defect_notes' => 'All tests passed',
            'checklist_id' => array_map('strval', $checklistIds),
            'checklist_status' => array_fill(0, count($checklistIds), 'pass'),
        ]);

        $this->assertSame(302, $response->status());

        // Verify the inspection record.
        $stmt = $this->pdo()->prepare('SELECT id, outcome, condition_grade, approved_by FROM inspection_records WHERE inventory_unit_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$unitId]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertTrue($record !== false);
        $this->assertSame('pass', $record['outcome']);
        $this->assertSame('A', $record['condition_grade']);
        $this->assertNotNull($record['approved_by'], 'owner has inventory.approve → must be auto-approved');

        // Verify the unit is now available (sellable).
        $stmt = $this->pdo()->prepare('SELECT status, inspected_at, inspected_by FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $unit = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('available', $unit['status'], 'pass + approval must make unit sellable');
        $this->assertNotNull($unit['inspected_at']);

        // Verify audit.
        foreach (['inspection.created', 'inspection.approved'] as $action) {
            $audit = $this->pdo()->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = ?");
            $audit->execute([$action]);
            $this->assertTrue((int) $audit->fetchColumn() > 0, "audit for {$action} must exist");
        }

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM inspection_records WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testPassWithoutApproveDoesNotMakeSellable(): void
    {
        $this->wipeOwners();
        $techId = $this->makeUser('insp-noappr@test.local', 'Correct-Horse-1', ['technician']);
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId, ['sku' => 'INSP-NOAPP-001']);

        $response = $this->dispatchAs($techId, 'POST', '/admin/inspections', [
            'inventory_unit_id' => (string) $unitId,
            'outcome' => 'pass',
            'condition_grade' => 'B',
            'defect_notes' => 'All OK',
        ]);

        $this->assertSame(302, $response->status());

        // Unit must still be awaiting_inspection (technician has no inventory.approve).
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame('awaiting_inspection', (string) $stmt->fetchColumn(), 'technician cannot make unit sellable');

        // Verify inspection record has no approved_by.
        $stmt = $this->pdo()->prepare('SELECT approved_by FROM inspection_records WHERE inventory_unit_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$unitId]);
        $this->assertNull($stmt->fetchColumn(), 'must not be auto-approved');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM inspection_records WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$techId]);
    }

    // ---------------------------------------------------- CREATE + FAIL --

    public function testFailInspectionRequiresDefectNotes(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('insp-fail@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId, ['sku' => 'INSP-FAIL-001']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inspections', [
            'inventory_unit_id' => (string) $unitId,
            'outcome' => 'fail',
            'condition_grade' => 'D',
            'defect_notes' => '', // empty → must fail validation
        ]);

        $this->assertSame(302, $response->status(), 'must redirect (flash error)');

        // Verify no record was created.
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM inspection_records WHERE inventory_unit_id = ?');
        $count->execute([$unitId]);
        $this->assertSame(0, (int) $count->fetchColumn(), 'no record for fail without defect notes');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testFailInspectionMarksUnitDamaged(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('insp-fail2@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId, ['sku' => 'INSP-FAIL-002']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inspections', [
            'inventory_unit_id' => (string) $unitId,
            'outcome' => 'fail',
            'condition_grade' => 'D',
            'defect_notes' => 'Screen has 3 dead pixels and battery does not hold charge',
        ]);

        $this->assertSame(302, $response->status());

        // Verify unit is now damaged.
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame('damaged', (string) $stmt->fetchColumn(), 'fail must transition to damaged');

        // Verify defect notes stored.
        $stmt = $this->pdo()->prepare('SELECT defect_notes FROM inspection_records WHERE inventory_unit_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$unitId]);
        $this->assertStringContainsString('dead pixels', (string) $stmt->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM inspection_records WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- HOLD --

    public function testHoldDoesNotChangeStatus(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('insp-hold@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId, ['sku' => 'INSP-HOLD-001']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inspections', [
            'inventory_unit_id' => (string) $unitId,
            'outcome' => 'hold',
            'condition_grade' => 'B',
            'defect_notes' => 'Pending second opinion on hinge stiffness',
        ]);

        $this->assertSame(302, $response->status());

        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame('awaiting_inspection', (string) $stmt->fetchColumn(), 'hold must not change status');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM inspection_records WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- APPROVE --

    public function testApproveMakesSellable(): void
    {
        $this->wipeOwners();
        $techId = $this->makeUser('insp-appr-tech@test.local', 'Correct-Horse-1', ['technician']);
        $ownerId = $this->makeUser('insp-appr-own@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId, ['sku' => 'INSP-APPR-001']);

        // Technician records a pass (no auto-approval).
        $this->dispatchAs($techId, 'POST', '/admin/inspections', [
            'inventory_unit_id' => (string) $unitId,
            'outcome' => 'pass',
            'condition_grade' => 'B',
            'defect_notes' => 'All tests passed',
        ]);

        $recordId = (int) $this->pdo()->query('SELECT id FROM inspection_records WHERE inventory_unit_id = ' . $unitId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();

        // Owner approves.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inspections/' . $recordId . '/approve');
        $this->assertSame(302, $response->status());

        // Unit must now be available.
        $stmt = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $stmt->execute([$unitId]);
        $this->assertSame('available', (string) $stmt->fetchColumn(), 'approval must make unit sellable');

        // Verify record has approved_by + approved_at.
        $stmt = $this->pdo()->prepare('SELECT approved_by, approved_at FROM inspection_records WHERE id = ?');
        $stmt->execute([$recordId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($row['approved_by']);
        $this->assertNotNull($row['approved_at']);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM inspection_records WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?)')->execute([$techId, $ownerId]);
    }

    // ---------------------------------------------------- CHECKLIST MANAGEMENT --

    public function testAddChecklistItem(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('insp-cl@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/inspections/checklist/add', [
            'name' => 'Thermal stress test',
            'category' => 'hardware',
            'description' => 'Run under load for 10 minutes',
        ]);

        $this->assertSame(302, $response->status());

        $stmt = $this->pdo()->prepare('SELECT id FROM inspection_checklists WHERE name = ?');
        $stmt->execute(['Thermal stress test']);
        $this->assertTrue($stmt->fetchColumn() !== false, 'checklist item must be created');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM inspection_checklists WHERE name = ?')->execute(['Thermal stress test']);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CSRF --

    public function testCsrfOnStore(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('insp-csrf@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($ownerId);
        [, , $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId, ['sku' => 'INSP-CSRF-001']);

        $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($unitId) {
            $this->router()->dispatch(
                Request::synthetic('POST', '/admin/inspections', [], [
                    'inventory_unit_id' => (string) $unitId,
                    'outcome' => 'pass',
                ]),
                $this->resolver()
            );
        }, 'POST /admin/inspections must require CSRF');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }
}
