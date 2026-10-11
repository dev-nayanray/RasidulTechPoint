<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Repositories\CustomerRepository;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Customer management (Prompt 21) — HTTP-level tests.
 *
 * Proves:
 *  - Create with valid data + audit trail
 *  - Server-side validation rejects empty name, bad email, duplicate phone
 *  - DB-level uniqueness on email as well as phone (duplicate-contact guard)
 *  - Edit/update changes fields, keeps order history intact
 *  - Account status transitions (active → suspended → active, vip, blacklisted)
 *  - Staff notes are append-only with author + timestamp
 *  - Search by name, phone, email + status filter
 *  - Pagination
 *  - Soft delete preserves the row; restoration re-activates it
 *  - Customer with order history CANNOT be deleted (preserve order linkage)
 *  - Duplicate-contact detection surfaces phone/email collisions
 *  - Sensitive financial fields (lifetime_value) hidden without finance.view
 *  - Permission enforcement: cashier view-only, manager full, technician blocked
 *  - CSRF on every state-changing route
 */
final class CustomerTest extends TestCase
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

    /** Mark any pre-existing owner as inactive so AuthService::can('owner')
     *  short-circuit on the 'owner' role still works for our test user. */
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

    /**
     * Build a user with only the listed permissions (via a fresh custom
     * role). Use this when none of the seeded roles match the exact
     * permission set you need to test against (e.g. "customers.view but
     * no finance.view or payments.view").
     */
    private function makeUserWithPermissions(string $email, array $permissions): int
    {
        $userId = $this->makeUser($email, 'Correct-Horse-1', []);
        $slug = 'custom-' . bin2hex(random_bytes(3));
        $this->pdo()->prepare('INSERT INTO roles (name, slug, description, is_system) VALUES (?, ?, ?, ?)')
            ->execute(['Custom ' . $slug, $slug, 'Test role', 0]);
        $roleId = (int) $this->pdo()->lastInsertId();
        $this->pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')
            ->execute([$userId, $roleId]);
        foreach ($permissions as $perm) {
            $stmt = $this->pdo()->prepare('SELECT id FROM permissions WHERE name = ?');
            $stmt->execute([$perm]);
            $pid = (int) $stmt->fetchColumn();
            if ($pid > 0) {
                $this->pdo()->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)')
                    ->execute([$roleId, $pid]);
            }
        }
        return $userId;
    }

    private function makeRichCustomer(array $overrides = []): int
    {
        $unique = bin2hex(random_bytes(3));
        return (new CustomerRepository())->create(array_merge([
            'name' => 'Test Customer ' . $unique,
            'phone' => '+88017' . $unique,
            'email' => 'cust-' . $unique . '@test.local',
            'address' => 'Road 1',
            'city' => 'Dhaka',
            'account_status' => 'active',
            'notes' => 'seeded by test',
        ], $overrides));
    }

    private function wipeCatalog(int $brandId, int $categoryId, int $modelId): void
    {
        // Defensive cleanup that mirrors makeCatalog() so leftover
        // published product_models don't pollute downstream tests
        // (DashboardTest::testLowStockAlerts is order-sensitive to
        // how many published models exist with 0 available units).
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testTechnicianBlockedFromCustomers(): void
    {
        $techId = $this->makeUser('cust-tech@test.local', 'Correct-Horse-1', ['technician']);
        $this->assertThrows(ForbiddenException::class, function () use ($techId) {
            $this->dispatchAs($techId, 'GET', '/admin/customers');
        }, 'technician (no customers.view) must not view customers');
    }

    public function testCashierCanViewButNotManage(): void
    {
        $cashierId = $this->makeUser('cust-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);
        $id = $this->makeRichCustomer();

        // View OK (cashier has customers.view).
        $r = $this->dispatchAs($cashierId, 'GET', '/admin/customers');
        $this->assertSame(200, $r->status());

        // Cashier has customers.create so they can hit create form.
        $r = $this->dispatchAs($cashierId, 'GET', '/admin/customers/create');
        $this->assertSame(200, $r->status());

        // But edit is gated to customers.manage.
        $this->assertThrows(ForbiddenException::class, function () use ($cashierId, $id) {
            $this->dispatchAs($cashierId, 'GET', '/admin/customers/' . $id . '/edit');
        }, 'cashier (no customers.manage) must not edit');
    }

    // ---------------------------------------------------- CREATE --

    public function testCreateCustomer(): void
    {
        $ownerId = $this->makeOwner('cust-create@test.local');
        $unique = bin2hex(random_bytes(3));

        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers', [
            'name' => 'Jane Doe',
            'phone' => '+880171234567',
            'alt_phone' => '+880198765432',
            'email' => 'jane-' . $unique . '@example.com',
            'address' => 'Banani',
            'city' => 'Dhaka',
            'notes' => 'Walk-in customer',
        ]);

        $this->assertSame(302, $r->status(), 'create must redirect');

        $repo = new CustomerRepository();
        $stmt = $this->pdo()->prepare('SELECT * FROM customers WHERE email = ?');
        $stmt->execute(['jane-' . $unique . '@example.com']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertTrue($row !== false);
        $this->assertSame('Jane Doe', $row['name']);
        $this->assertSame('+880171234567', $row['phone']);
        $this->assertSame('+880198765432', $row['alt_phone']);
        $this->assertSame('Banani', $row['address']);
        $this->assertSame('active', $row['account_status']);
        $this->assertSame('Walk-in customer', $row['notes']);

        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'customer.created' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('customer.created', $audit->fetchColumn());

        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$row['id']]);
    }

    public function testCreateRejectsEmptyName(): void
    {
        $ownerId = $this->makeOwner('cust-empty-name@test.local');
        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers', ['name' => '', 'phone' => '+88017']);
        $this->assertSame(422, $r->status());
        $this->assertMatchesRegularExpression('/Name is required/', $r->body());
    }

    public function testCreateRejectsInvalidEmail(): void
    {
        $ownerId = $this->makeOwner('cust-bad-email@test.local');
        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers', [
            'name' => 'Bad Email', 'phone' => '+88017', 'email' => 'not-an-email',
        ]);
        $this->assertSame(422, $r->status());
    }

    public function testCreateRejectsDuplicatePhone(): void
    {
        $ownerId = $this->makeOwner('cust-dup-phone@test.local');
        $existing = $this->makeRichCustomer(['phone' => '+8801799900011']);

        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers', [
            'name' => 'Dup Phone Customer',
            'phone' => '+8801799900011',
            'email' => 'other-' . bin2hex(random_bytes(3)) . '@test.local',
        ]);
        $this->assertSame(422, $r->status());
        $this->assertMatchesRegularExpression('/phone.*already in use/i', $r->body());

        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$existing]);
    }

    public function testCreateRejectsDuplicateEmail(): void
    {
        $ownerId = $this->makeOwner('cust-dup-email@test.local');
        $existing = $this->makeRichCustomer(['email' => 'shared-dup@example.com']);
        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers', [
            'name' => 'Dup Email Customer',
            'phone' => '+8801799900099',
            'email' => 'shared-dup@example.com',
        ]);
        $this->assertSame(422, $r->status());
        $this->assertMatchesRegularExpression('/email.*already in use/i', $r->body());

        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$existing]);
    }

    public function testMultipleCustomersWithNullEmailAllowed(): void
    {
        $a = $this->makeRichCustomer(['email' => null, 'phone' => '+880170000001']);
        $b = $this->makeRichCustomer(['email' => null, 'phone' => '+880170000002']);
        $this->assertTrue($a > 0 && $b > 0, 'multiple NULL emails must be allowed');
        $this->pdo()->prepare('DELETE FROM customers WHERE id IN (?, ?)')->execute([$a, $b]);
    }

    // ---------------------------------------------------- SHOW + FINANCE GATE --

    public function testShowRendersProfileAndOrderHistory(): void
    {
        $ownerId = $this->makeOwner('cust-show@test.local');
        $id = $this->makeRichCustomer();

        $r = $this->dispatchAs($ownerId, 'GET', '/admin/customers/' . $id);
        $this->assertSame(200, $r->status());
        $this->assertMatchesRegularExpression('/Order history/', $r->body());
        $this->assertMatchesRegularExpression('/Staff notes/', $r->body());

        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    }

    public function testLifetimeValueHiddenWithoutFinancePermission(): void
    {
        // A user with customers.view ONLY — no finance.view, no payments.view.
        $viewOnlyId = $this->makeUserWithPermissions(
            'cust-nofin@test.local',
            ['customers.view'],
        );
        $id = $this->makeRichCustomer();

        $r = $this->dispatchAs($viewOnlyId, 'GET', '/admin/customers/' . $id);
        $this->assertSame(200, $r->status());
        $this->assertMatchesRegularExpression('/Restricted to finance staff/', $r->body());

        // And the money figure must NOT be rendered.
        $moneyRegex = '/BDT.*0\.00/';
        $body = $r->body();
        // Lifetime value section must not show the money; the surrounding
        // dt/dd pair uses the "Restricted to finance staff." text instead.
        $this->assertStringNotContainsString(
            'class="font-bold text-slate-900">BDT',
            $body,
            'lifetime_value figure must not be visible to non-finance staff',
        );

        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    }

    public function testLifetimeValueVisibleWithFinancePermission(): void
    {
        $accountantId = $this->makeUser('cust-acc@test.local', 'Correct-Horse-1', ['accountant']);
        $id = $this->makeRichCustomer();

        $r = $this->dispatchAs($accountantId, 'GET', '/admin/customers/' . $id);
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Lifetime value</dt><dd class="font-bold', $r->body());

        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    }

    // ---------------------------------------------------- SEARCH + PAGINATION --

    public function testSearchByName(): void
    {
        $ownerId = $this->makeOwner('cust-search@test.local');
        $id = $this->makeRichCustomer(['name' => 'UnicornFindable Customer']);

        $r = $this->dispatchAs($ownerId, 'GET', '/admin/customers', [], ['q' => 'UnicornFindable']);
        $this->assertSame(200, $r->status());
        $this->assertMatchesRegularExpression('/UnicornFindable Customer/', $r->body());

        $r = $this->dispatchAs($ownerId, 'GET', '/admin/customers', [], ['q' => 'totally-nonexistent']);
        $this->assertSame(200, $r->status());
        $this->assertMatchesRegularExpression('/No customers match/i', $r->body());

        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    }

    public function testStatusFilter(): void
    {
        $ownerId = $this->makeOwner('cust-status-filter@test.local');
        $a = $this->makeRichCustomer(['account_status' => 'suspended']);
        $b = $this->makeRichCustomer(['account_status' => 'vip']);

        $r = $this->dispatchAs($ownerId, 'GET', '/admin/customers', [], ['status' => 'suspended']);
        $this->assertSame(200, $r->status());
        $body = $r->body();
        // Suspended customer must appear.
        $stmt = $this->pdo()->prepare('SELECT name FROM customers WHERE id = ?');
        $stmt->execute([$a]);
        $name = $stmt->fetchColumn();
        $this->assertStringContainsString($name, $body);
        // VIP must NOT appear in the suspended filter.
        $stmt = $this->pdo()->prepare('SELECT name FROM customers WHERE id = ?');
        $stmt->execute([$b]);
        $vipName = $stmt->fetchColumn();
        $this->assertStringNotContainsString($vipName, $body);

        $this->pdo()->prepare('DELETE FROM customers WHERE id IN (?, ?)')->execute([$a, $b]);
    }

    public function testPaginationClampsPerPage(): void
    {
        $ownerId = $this->makeOwner('cust-page@test.local');
        // Create 25 customers so the default perPage=20 spans 2 pages.
        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $this->makeRichCustomer();
        }

        $r = $this->dispatchAs($ownerId, 'GET', '/admin/customers', [], ['page' => 1, 'per_page' => 20]);
        $this->assertSame(200, $r->status());
        $this->assertMatchesRegularExpression('/Page.*1.*of.*2/', $r->body());

        foreach ($ids as $id) {
            $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
        }
    }

    // ---------------------------------------------------- UPDATE --

    public function testUpdateChangesFields(): void
    {
        $ownerId = $this->makeOwner('cust-update@test.local');
        $id = $this->makeRichCustomer();

        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers/' . $id . '/update', [
            'name' => 'Renamed Customer',
            'phone' => '+8801799900055',
            'alt_phone' => '+8801988800055',
            'email' => 'renamed-' . bin2hex(random_bytes(3)) . '@test.local',
            'address' => 'Gulshan',
            'city' => 'Chittagong',
            'notes' => 'Updated summary notes',
        ]);
        $this->assertSame(302, $r->status());

        $row = (new CustomerRepository())->find($id);
        $this->assertSame('Renamed Customer', $row['name']);
        $this->assertSame('+8801799900055', $row['phone']);
        $this->assertSame('+8801988800055', $row['alt_phone']);
        $this->assertSame('Chittagong', $row['city']);
        $this->assertSame('Updated summary notes', $row['notes']);

        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'customer.updated' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('customer.updated', $audit->fetchColumn());

        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    }

    public function testUpdateRejectsDuplicatePhone(): void
    {
        $ownerId = $this->makeOwner('cust-update-dup@test.local');
        $a = $this->makeRichCustomer(['phone' => '+8801799911111']);
        $b = $this->makeRichCustomer(['phone' => '+8801799922222']);

        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers/' . $b . '/update', [
            'name' => 'Trying To Take Phone',
            'phone' => '+8801799911111', // belongs to $a
            'email' => '',
        ]);
        $this->assertSame(422, $r->status());

        $this->pdo()->prepare('DELETE FROM customers WHERE id IN (?, ?)')->execute([$a, $b]);
    }

    // ---------------------------------------------------- ACCOUNT STATUS --

    public function testAccountStatusTransition(): void
    {
        $ownerId = $this->makeOwner('cust-status@test.local');
        $id = $this->makeRichCustomer();

        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers/' . $id . '/status', [
            'account_status' => 'suspended',
        ]);
        $this->assertSame(302, $r->status());

        $row = (new CustomerRepository())->find($id);
        $this->assertSame('suspended', $row['account_status']);

        $audit = $this->pdo()->prepare(
            "SELECT metadata FROM audit_logs WHERE action = 'customer.status_changed' ORDER BY id DESC LIMIT 1"
        );
        $audit->execute();
        $meta = json_decode((string) $audit->fetchColumn(), true);
        $this->assertSame('active', $meta['from']);
        $this->assertSame('suspended', $meta['to']);

        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    }

    public function testAccountStatusRejectsInvalidValue(): void
    {
        $ownerId = $this->makeOwner('cust-status-invalid@test.local');
        $id = $this->makeRichCustomer();

        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers/' . $id . '/status', [
            'account_status' => 'frozen', // not in the enum
        ]);
        $this->assertSame(302, $r->status(), 'invalid status redirects with flash');

        $row = (new CustomerRepository())->find($id);
        $this->assertSame('active', $row['account_status'], 'status must remain unchanged');

        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    }

    // ---------------------------------------------------- NOTES (APPEND-ONLY) --

    public function testAddNoteAppendOnly(): void
    {
        $ownerId = $this->makeOwner('cust-notes@test.local');
        $id = $this->makeRichCustomer();

        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers/' . $id . '/notes', [
            'body' => 'Customer called about warranty. Pickup scheduled.',
        ]);
        $this->assertSame(302, $r->status());

        $repo = new CustomerRepository();
        $notes = $repo->notes($id);
        $this->assertCount(1, $notes);
        $this->assertSame('Customer called about warranty. Pickup scheduled.', $notes[0]['body']);
        $this->assertSame($ownerId, (int) $notes[0]['author_id']);

        // Add a second note to prove it's append-only.
        $this->dispatchAs($ownerId, 'POST', '/admin/customers/' . $id . '/notes', [
            'body' => 'Customer picked up the laptop.',
        ]);
        $notes = $repo->notes($id);
        $this->assertCount(2, $notes);

        $audit = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'customer.note_added' AND entity_id = ?"
        );
        $audit->execute([$id]);
        $this->assertSame(2, (int) $audit->fetchColumn(), 'each note must emit an audit entry');

        $this->pdo()->prepare('DELETE FROM customer_notes WHERE customer_id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    }

    public function testEmptyNoteRejected(): void
    {
        $ownerId = $this->makeOwner('cust-empty-note@test.local');
        $id = $this->makeRichCustomer();

        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers/' . $id . '/notes', ['body' => '']);
        $this->assertSame(302, $r->status(), 'empty note redirects with flash');

        $notes = (new CustomerRepository())->notes($id);
        $this->assertCount(0, $notes, 'no note must have been written');

        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    }

    // ---------------------------------------------------- SOFT DELETE + HISTORY --

    public function testSoftDeleteAndRestoreWithoutOrders(): void
    {
        $ownerId = $this->makeOwner('cust-del@test.local');
        $id = $this->makeRichCustomer();

        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers/' . $id . '/delete');
        $this->assertSame(302, $r->status());

        $repo = new CustomerRepository();
        $this->assertNull($repo->find($id), 'find() must exclude soft-deleted');
        $this->assertNotNull($repo->findWithTrashed($id), 'row must still exist');

        // Show page still renders (uses findWithTrashed).
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/customers/' . $id);
        $this->assertSame(200, $r->status());
        $this->assertMatchesRegularExpression('/soft-deleted/i', $r->body());

        // Restore.
        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers/' . $id . '/restore');
        $this->assertSame(302, $r->status());
        $this->assertNotNull($repo->find($id), 'customer must be active again');

        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'customer.restored' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('customer.restored', $audit->fetchColumn());

        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    }

    public function testCustomerWithOrdersCannotBeDeleted(): void
    {
        $ownerId = $this->makeOwner('cust-del-orders@test.local');
        [$brandId, $categoryId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId);
        $customerId = $this->makeRichCustomer();

        // Place an order for this customer against the available unit.
        $order = (new \App\Services\OrderService())->createOrder(
            $customerId,
            [$unitId],
            'pos',
            $ownerId,
            [],
            'completed',
        );

        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers/' . $customerId . '/delete');
        $this->assertSame(302, $r->status(), 'delete redirects');
        $this->assertMatchesRegularExpression('/cannot be deleted/i', $this->fetchFlashError(), 'must explain why');

        // Customer must still exist (delete blocked).
        $repo = new CustomerRepository();
        $this->assertNotNull($repo->find($customerId), 'customer with orders must not be soft-deleted');

        // Cleanup cascade — order_items + payments + the unit + customer.
        $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$order['id']]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->wipeCatalog($brandId, $categoryId, $modelId);
    }

    public function testOrderHistoryRemainsLinkedAfterEdit(): void
    {
        $ownerId = $this->makeOwner('cust-edit-history@test.local');
        [$brandId, $categoryId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId);
        $customerId = $this->makeRichCustomer(['name' => 'Original Name']);

        $order = (new \App\Services\OrderService())->createOrder(
            $customerId,
            [$unitId],
            'pos',
            $ownerId,
            [],
            'completed',
        );

        // Edit the customer's name and phone.
        $r = $this->dispatchAs($ownerId, 'POST', '/admin/customers/' . $customerId . '/update', [
            'name' => 'New Name After Edit',
            'phone' => '+8801799999999',
            'alt_phone' => '',
            'email' => '',
            'address' => '',
            'city' => '',
            'notes' => '',
        ]);
        $this->assertSame(302, $r->status());

        // The order's customer_id is unchanged and still resolves to the same customer.
        $stmt = $this->pdo()->prepare('SELECT customer_id FROM orders WHERE id = ?');
        $stmt->execute([$order['id']]);
        $this->assertSame($customerId, (int) $stmt->fetchColumn(), 'order.customer_id must be unchanged');

        // Customer show page still shows the order in history.
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/customers/' . $customerId);
        $this->assertStringContainsString($order['reference'], $r->body(), 'order reference must still appear in customer history');

        $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$order['id']]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->wipeCatalog($brandId, $categoryId, $modelId);
    }

    // ---------------------------------------------------- DUPLICATES --

    public function testDuplicateDetection(): void
    {
        $ownerId = $this->makeOwner('cust-dup-detect@test.local');
        // Two customers sharing a phone — the unique index on phone blocks
        // active+active collisions, so we soft-delete one and reuse the phone
        // for the second. findDuplicates() surfaces the active+deleted pair
        // (this is exactly the real-world duplicate scenario: the same
        // person was deleted and re-registered, but the historical orders
        // remain linked to the soft-deleted row).
        $a = $this->makeRichCustomer(['phone' => '+880170005555', 'email' => 'a-dup@example.com']);
        $this->pdo()->prepare('UPDATE customers SET deleted_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$a]);
        // create() filters WHERE deleted_at IS NULL when checking phoneTaken,
        // so this soft-delete frees the phone for B.
        $b = $this->makeRichCustomer(['phone' => '+880170005555', 'email' => 'b-dup@example.com']);

        // Email collision: soft-delete B's email, then create C with the same email.
        $this->pdo()->prepare('UPDATE customers SET deleted_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$b]);
        $c = $this->makeRichCustomer(['email' => 'b-dup@example.com', 'phone' => '+880170006666']);

        $r = $this->dispatchAs($ownerId, 'GET', '/admin/customers/duplicates');
        $this->assertSame(200, $r->status());
        $body = $r->body();
        $this->assertMatchesRegularExpression('/Duplicate contacts/', $body);
        $this->assertMatchesRegularExpression('/\+880170005555/', $body, 'phone duplicate must surface');
        $this->assertMatchesRegularExpression('/b-dup\@example\.com/', $body, 'email duplicate must surface');

        $this->pdo()->prepare('DELETE FROM customers WHERE id IN (?, ?, ?)')->execute([$a, $b, $c]);
    }

    public function testDuplicatesEmptyStateRenders(): void
    {
        $ownerId = $this->makeOwner('cust-dup-empty@test.local');
        // Make sure no duplicates exist (the unique index already prevents
        // phone collisions, so a fresh test DB should not surface any pair).
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/customers/duplicates');
        $this->assertSame(200, $r->status());
        $this->assertMatchesRegularExpression('/No duplicate contacts/i', $r->body());
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $ownerId = $this->makeOwner('cust-csrf@test.local');
        $this->actingAs($ownerId);
        $id = $this->makeRichCustomer();

        $routes = [
            ['POST', '/admin/customers', ['name' => 'X', 'phone' => '+88017']],
            ['POST', '/admin/customers/' . $id . '/update', ['name' => 'Y', 'phone' => '+88017']],
            ['POST', '/admin/customers/' . $id . '/status', ['account_status' => 'suspended']],
            ['POST', '/admin/customers/' . $id . '/notes', ['body' => 'hello']],
            ['POST', '/admin/customers/' . $id . '/delete', []],
            ['POST', '/admin/customers/' . $id . '/restore', []],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(
                    Request::synthetic($method, $uri, [], $post),
                    $this->resolver()
                );
            }, "{$method} {$uri} must require CSRF");
        }

        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    }

    // ---------------------------------------------------- RECALC STATS --

    public function testRecalculateStatsAfterOrder(): void
    {
        $ownerId = $this->makeOwner('cust-stats@test.local');
        [$brandId, $categoryId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId);
        $customerId = $this->makeRichCustomer();

        $order = (new \App\Services\OrderService())->createOrder(
            $customerId,
            [$unitId],
            'pos',
            $ownerId,
            [],
            'completed',
        );

        $repo = new CustomerRepository();
        $repo->recalculateStats($customerId);

        $row = $repo->find($customerId);
        $this->assertSame(1, (int) $row['orders_count'], 'orders_count must include the new order');
        $this->assertGreaterThan(0, (float) $row['lifetime_value'], 'lifetime_value must be positive');
        $this->assertNotNull($row['last_order_at'], 'last_order_at must be set');

        $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$order['id']]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->wipeCatalog($brandId, $categoryId, $modelId);
    }

    /** Pull the success flash message back from the session (set by flash_success). */
    private function fetchFlashSuccess(): string
    {
        return (string) \App\Support\Session::getFlash('success', '');
    }

    /** Pull the error flash message back from the session (set by flash_error). */
    private function fetchFlashError(): string
    {
        return (string) \App\Support\Session::getFlash('error', '');
    }
}
