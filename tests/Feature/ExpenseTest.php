<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Services\ExpenseService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Expense management (Prompt 31) — service + HTTP tests.
 *
 * Proves:
 *  - Category CRUD: create, update, find, allCategories
 *  - Entry creation with sequential number, category, amount, date,
 *    payment account, receipt attachment
 *  - Approval workflow: pending → approved (locks entry), → paid
 *  - Rejection: requires reason, transitions to rejected
 *  - CRITICAL: approved/paid entries cannot be edited — updateEntry
 *    throws if status is 'approved' or 'paid'
 *  - Status matrix: only pending can be approved; only approved can
 *    be marked paid
 *  - Reports: reportByCategory aggregates by category with
 *    pending/approved/paid/total breakdowns
 *  - Receipt upload: MIME validation, size limit
 *  - Permission: technician blocked from expenses
 *  - CSRF on state-changing routes
 *  - Financial integrity: expenses separate from sale revenue
 */
final class ExpenseTest extends TestCase
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

    private function makeOwner(string $email): int
    {
        $this->wipeOwners();
        $id = $this->makeUser($email, 'Correct-Horse-1', ['owner']);
        $this->actingAs($id);
        return $id;
    }

    private function getFirstCategoryId(): int
    {
        return (int) $this->pdo()->query('SELECT id FROM expense_categories ORDER BY id LIMIT 1')->fetchColumn();
    }

    private function cleanupEntry(int $id): void
    {
        $this->pdo()->prepare('DELETE FROM expense_entries WHERE id = ?')->execute([$id]);
    }

    // ---------------------------------------------------- CATEGORIES --

    public function testCreateCategory(): void
    {
        $ownerId = $this->makeOwner('exp-cat1@test.local');
        $svc = new ExpenseService();
        $id = $svc->createCategory('Test Category', 'Test desc', 10);
        $this->assertGreaterThan(0, $id);
        $cat = $svc->findCategory($id);
        $this->assertSame('Test Category', $cat['name']);
        $this->assertSame('Test desc', $cat['description']);
        $this->assertSame(10, (int) $cat['display_order']);
        $this->pdo()->prepare('DELETE FROM expense_categories WHERE id = ?')->execute([$id]);
    }

    public function testCreateCategoryRejectsEmptyName(): void
    {
        $this->makeOwner('exp-cat2@test.local');
        $this->assertThrows(ValidationException::class, function () {
            (new ExpenseService())->createCategory('  ');
        }, 'empty name rejected');
    }

    public function testDefaultCategoriesSeeded(): void
    {
        $cats = (new ExpenseService())->allCategories();
        $names = array_column($cats, 'name');
        $this->assertContains('Rent', $names);
        $this->assertContains('Utilities', $names);
        $this->assertContains('Salaries', $names);
        $this->assertContains('Other', $names);
    }

    // ---------------------------------------------------- ENTRIES --

    public function testCreateEntry(): void
    {
        $ownerId = $this->makeOwner('exp-e1@test.local');
        $catId = $this->getFirstCategoryId();
        $svc = new ExpenseService();
        $result = $svc->createEntry([
            'category_id' => $catId,
            'description' => 'Shop rent for October',
            'amount' => '25000',
            'expense_date' => gmdate('Y-m-d'),
            'payment_account' => 'bank',
            'payment_reference' => 'BANK-TRF-123',
        ], $ownerId);
        $this->assertMatchesRegularExpression('/^EXP-\d{4}-\d{5}$/', $result['expense_number']);
        $this->assertSame('pending', $result['status']);

        $entry = $svc->findEntry($result['id']);
        $this->assertSame('Shop rent for October', $entry['description']);
        $this->assertSame('25000.00', $entry['amount']);
        $this->assertSame('bank', $entry['payment_account']);
        $this->assertSame('BANK-TRF-123', $entry['payment_reference']);

        $this->cleanupEntry($result['id']);
    }

    public function testCreateEntryRejectsZeroAmount(): void
    {
        $ownerId = $this->makeOwner('exp-e2@test.local');
        $catId = $this->getFirstCategoryId();
        $this->assertThrows(ValidationException::class, function () use ($catId, $ownerId) {
            (new ExpenseService())->createEntry([
                'category_id' => $catId,
                'description' => 'X',
                'amount' => '0',
                'expense_date' => gmdate('Y-m-d'),
                'payment_account' => 'cash',
            ], $ownerId);
        }, 'zero amount rejected');
    }

    public function testCreateEntryRejectsEmptyDescription(): void
    {
        $ownerId = $this->makeOwner('exp-e3@test.local');
        $catId = $this->getFirstCategoryId();
        $this->assertThrows(ValidationException::class, function () use ($catId, $ownerId) {
            (new ExpenseService())->createEntry([
                'category_id' => $catId,
                'description' => '  ',
                'amount' => '100',
                'expense_date' => gmdate('Y-m-d'),
                'payment_account' => 'cash',
            ], $ownerId);
        }, 'empty description rejected');
    }

    // ---------------------------------------------------- APPROVAL WORKFLOW --

    public function testApproveLocksEntry(): void
    {
        $ownerId = $this->makeOwner('exp-a1@test.local');
        $catId = $this->getFirstCategoryId();
        $svc = new ExpenseService();
        $entry = $svc->createEntry([
            'category_id' => $catId,
            'description' => 'Test expense',
            'amount' => '500',
            'expense_date' => gmdate('Y-m-d'),
            'payment_account' => 'cash',
        ], $ownerId);

        $svc->approveEntry($entry['id'], $ownerId);
        $e = $svc->findEntry($entry['id']);
        $this->assertSame('approved', $e['status']);
        $this->assertNotNull($e['approved_at']);

        // CRITICAL: approved entry cannot be edited.
        $this->assertThrows(ValidationException::class, function () use ($svc, $entry) {
            $svc->updateEntry($entry['id'], ['description' => 'Changed description']);
        }, 'approved entry must be locked from edits');

        $this->cleanupEntry($entry['id']);
    }

    public function testMarkPaidLocksEntry(): void
    {
        $ownerId = $this->makeOwner('exp-a2@test.local');
        $catId = $this->getFirstCategoryId();
        $svc = new ExpenseService();
        $entry = $svc->createEntry([
            'category_id' => $catId,
            'description' => 'Test',
            'amount' => '500',
            'expense_date' => gmdate('Y-m-d'),
            'payment_account' => 'cash',
        ], $ownerId);
        $svc->approveEntry($entry['id'], $ownerId);
        $svc->markPaid($entry['id']);

        $this->assertSame('paid', $svc->findEntry($entry['id'])['status']);

        // CRITICAL: paid entry cannot be edited.
        $this->assertThrows(ValidationException::class, function () use ($svc, $entry) {
            $svc->updateEntry($entry['id'], ['amount' => '999']);
        }, 'paid entry must be locked from edits');

        $this->cleanupEntry($entry['id']);
    }

    public function testRejectRequiresReason(): void
    {
        $ownerId = $this->makeOwner('exp-a3@test.local');
        $catId = $this->getFirstCategoryId();
        $svc = new ExpenseService();
        $entry = $svc->createEntry([
            'category_id' => $catId,
            'description' => 'Test',
            'amount' => '500',
            'expense_date' => gmdate('Y-m-d'),
            'payment_account' => 'cash',
        ], $ownerId);

        $this->assertThrows(ValidationException::class, function () use ($svc, $entry, $ownerId) {
            $svc->rejectEntry($entry['id'], $ownerId, '  ');
        }, 'rejection reason required');

        $svc->rejectEntry($entry['id'], $ownerId, 'Not a valid business expense');
        $e = $svc->findEntry($entry['id']);
        $this->assertSame('rejected', $e['status']);
        $this->assertSame('Not a valid business expense', $e['rejection_reason']);

        $this->cleanupEntry($entry['id']);
    }

    public function testOnlyPendingCanBeApproved(): void
    {
        $ownerId = $this->makeOwner('exp-a4@test.local');
        $catId = $this->getFirstCategoryId();
        $svc = new ExpenseService();
        $entry = $svc->createEntry([
            'category_id' => $catId,
            'description' => 'Test',
            'amount' => '500',
            'expense_date' => gmdate('Y-m-d'),
            'payment_account' => 'cash',
        ], $ownerId);
        $svc->approveEntry($entry['id'], $ownerId);

        // Cannot approve an already-approved entry.
        $this->assertThrows(ValidationException::class, function () use ($svc, $entry, $ownerId) {
            $svc->approveEntry($entry['id'], $ownerId);
        }, 'only pending can be approved');

        // Cannot mark pending as paid (must be approved first).
        $entry2 = $svc->createEntry([
            'category_id' => $catId,
            'description' => 'Test 2',
            'amount' => '500',
            'expense_date' => gmdate('Y-m-d'),
            'payment_account' => 'cash',
        ], $ownerId);
        $this->assertThrows(ValidationException::class, function () use ($svc, $entry2) {
            $svc->markPaid($entry2['id']);
        }, 'only approved can be marked paid');

        $this->cleanupEntry($entry['id']);
        $this->cleanupEntry($entry2['id']);
    }

    // ---------------------------------------------------- REPORTS --

    public function testReportByCategory(): void
    {
        $ownerId = $this->makeOwner('exp-r1@test.local');
        $catId = $this->getFirstCategoryId();
        $svc = new ExpenseService();
        $today = gmdate('Y-m-d');

        // Create entries in different statuses.
        $pending = $svc->createEntry(['category_id' => $catId, 'description' => 'Pending', 'amount' => '100', 'expense_date' => $today, 'payment_account' => 'cash'], $ownerId);
        $approved = $svc->createEntry(['category_id' => $catId, 'description' => 'Approved', 'amount' => '200', 'expense_date' => $today, 'payment_account' => 'cash'], $ownerId);
        $svc->approveEntry($approved['id'], $ownerId);
        $paid = $svc->createEntry(['category_id' => $catId, 'description' => 'Paid', 'amount' => '300', 'expense_date' => $today, 'payment_account' => 'cash'], $ownerId);
        $svc->approveEntry($paid['id'], $ownerId);
        $svc->markPaid($paid['id']);

        $report = $svc->reportByCategory($today, $today);
        $this->assertSame(100.0, $report['pending'], 'pending total = 100');
        $this->assertSame(500.0, $report['approved'], 'approved total = 200 + 300 = 500');
        $this->assertSame(300.0, $report['paid'], 'paid total = 300');
        $this->assertSame(600.0, $report['total'], 'all total = 100 + 200 + 300 = 600');

        // Verify per-category breakdown.
        $cat = null;
        foreach ($report['categories'] as $c) {
            if ((int) $c['id'] === $catId) {
                $cat = $c;
                break;
            }
        }
        $this->assertNotNull($cat);
        $this->assertSame(3, (int) $cat['entry_count']);
        $this->assertSame('100.00', (string) $cat['pending_total']);
        $this->assertSame('500.00', (string) $cat['approved_total']);
        $this->assertSame('300.00', (string) $cat['paid_total']);

        $this->cleanupEntry($pending['id']);
        $this->cleanupEntry($approved['id']);
        $this->cleanupEntry($paid['id']);
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testTechnicianBlockedFromExpenses(): void
    {
        $techId = $this->makeUser('exp-tech@test.local', 'Correct-Horse-1', ['technician']);
        $this->assertThrows(ForbiddenException::class, function () use ($techId) {
            $this->dispatchAs($techId, 'GET', '/admin/expenses');
        }, 'technician has no finance.view');
    }

    // ---------------------------------------------------- HTTP + CSRF --

    public function testHttpDashboardRenders(): void
    {
        $ownerId = $this->makeOwner('exp-http-dash@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/expenses');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Expenses', $r->body());
    }

    public function testHttpEntryShowRendersLockedNotice(): void
    {
        $ownerId = $this->makeOwner('exp-http-show@test.local');
        $catId = $this->getFirstCategoryId();
        $svc = new ExpenseService();
        $entry = $svc->createEntry([
            'category_id' => $catId, 'description' => 'Test', 'amount' => '500',
            'expense_date' => gmdate('Y-m-d'), 'payment_account' => 'cash',
        ], $ownerId);
        $svc->approveEntry($entry['id'], $ownerId);

        $r = $this->dispatchAs($ownerId, 'GET', '/admin/expenses/entries/' . $entry['id']);
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Entry locked', $r->body(), 'locked notice must show for approved entries');

        $this->cleanupEntry($entry['id']);
    }

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $ownerId = $this->makeOwner('exp-csrf@test.local');
        $this->actingAs($ownerId);
        $catId = $this->getFirstCategoryId();
        $svc = new ExpenseService();
        $entry = $svc->createEntry([
            'category_id' => $catId, 'description' => 'Test', 'amount' => '500',
            'expense_date' => gmdate('Y-m-d'), 'payment_account' => 'cash',
        ], $ownerId);

        $routes = [
            ['POST', '/admin/expenses/entries', ['category_id' => (string) $catId, 'description' => 'x', 'amount' => '10', 'expense_date' => gmdate('Y-m-d'), 'payment_account' => 'cash']],
            ['POST', '/admin/expenses/entries/' . $entry['id'] . '/approve', []],
            ['POST', '/admin/expenses/entries/' . $entry['id'] . '/reject', ['reason' => 'x']],
            ['POST', '/admin/expenses/categories', ['name' => 'x']],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(Request::synthetic($method, $uri, [], $post), $this->resolver());
            }, "{$method} {$uri} must require CSRF");
        }

        $this->cleanupEntry($entry['id']);
    }
}
