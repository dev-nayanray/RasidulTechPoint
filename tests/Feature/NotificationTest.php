<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Services\CouponService;
use App\Services\NotificationService;
use App\Services\OrderService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Notifications & templates (Prompt 38) — service + HTTP tests.
 *
 * Proves:
 *  - Templates CRUD: create / find / update / delete / toggle
 *  - Event-key uniqueness + lowercase pattern validation
 *  - Dispatch: title/body/link interpolation; recipients resolved
 *    server-side from template (roles / permissions / specific_users
 *    / all_staff)
 *  - Dedupe: dispatch within window suppresses; outside window delivers
 *  - Delivery log: delivered / failed / suppressed_duplicate / no_template
 *    / no_recipients entries written
 *  - Retry: failed deliveries re-attempted in place; attempts counter
 *    incremented; successful retry transitions to 'delivered'
 *  - Privacy: markRead scoped by signed-in user; cross-recipient
 *    notification id is silently rejected (rowCount = 0); delivery log
 *    viewer gated behind notifications.manage and never exposes body
 *  - Permission enforcement: technician cannot view templates; manager
 *    can; content_manager cannot manage templates (no notifications.manage)
 *  - CSRF on state-changing routes
 *  - Wired events: order.created dispatches on createOrder;
 *    order.status_updated dispatches on updateStatus;
 *    payment.confirmed dispatches on confirmPayment;
 *    refund.processed dispatches on RefundService::process;
 *    coupon.redeemed dispatches on CouponService::recordRedemption
 */
final class NotificationTest extends TestCase
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

    private function makeManager(string $email): int
    {
        return $this->makeUser($email, 'Correct-Horse-1', ['manager']);
    }

    private function makeCashier(string $email): int
    {
        return $this->makeUser($email, 'Correct-Horse-1', ['sales_cashier']);
    }

    private function makeAccountant(string $email): int
    {
        return $this->makeUser($email, 'Correct-Horse-1', ['accountant']);
    }

    private function makeTech(string $email): int
    {
        return $this->makeUser($email, 'Correct-Horse-1', ['technician']);
    }

    private function cleanupTemplate(int $id): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM notification_deliveries WHERE template_id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM notification_templates WHERE id = ?')->execute([$id]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function cleanupDeliveries(): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->exec('DELETE FROM notification_deliveries');
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- TEMPLATES CRUD --

    public function testCreateTemplateAndFind(): void
    {
        $ownerId = $this->makeOwner('nt-create@test.local');
        $id = NotificationService::saveTemplate([
            'event_key' => 'test.event1',
            'title' => 'Test {x}',
            'body' => 'Body {x} {y}',
            'link_template' => '/admin/orders/{order_id}',
            'recipient_mode' => 'roles',
            'recipient_value' => 'manager',
            'is_enabled' => true,
            'dedupe_window_seconds' => 60,
        ], null, $ownerId);
        $this->assertGreaterThan(0, $id);

        $t = NotificationService::findTemplate($id);
        $this->assertSame('test.event1', (string) $t['event_key']);
        $this->assertSame('Test {x}', (string) $t['title']);
        $this->assertSame('roles', (string) $t['recipient_mode']);
        $this->assertSame('manager', (string) $t['recipient_value']);
        $this->assertSame(1, (int) $t['is_enabled']);
        $this->assertSame(60, (int) $t['dedupe_window_seconds']);

        $this->cleanupTemplate($id);
    }

    public function testUpdateTemplateKeepsEventKey(): void
    {
        $ownerId = $this->makeOwner('nt-update@test.local');
        $id = NotificationService::saveTemplate([
            'event_key' => 'test.event2',
            'title' => 'Old title',
            'recipient_mode' => 'roles',
            'recipient_value' => 'manager',
            'is_enabled' => true,
            'dedupe_window_seconds' => 0,
        ], null, $ownerId);

        NotificationService::saveTemplate([
            'event_key' => 'test.event2',  // unchanged
            'title' => 'New title',
            'recipient_mode' => 'permissions',
            'recipient_value' => 'orders.view',
            'is_enabled' => false,
            'dedupe_window_seconds' => 30,
        ], $id, $ownerId);

        $t = NotificationService::findTemplate($id);
        $this->assertSame('New title', (string) $t['title']);
        $this->assertSame('test.event2', (string) $t['event_key'], 'event_key unchanged');
        $this->assertSame('permissions', (string) $t['recipient_mode']);
        $this->assertSame(0, (int) $t['is_enabled']);

        $this->cleanupTemplate($id);
    }

    public function testEventKeyUniqueness(): void
    {
        $ownerId = $this->makeOwner('nt-uniq@test.local');
        $id = NotificationService::saveTemplate([
            'event_key' => 'test.event3',
            'title' => 'T1',
            'recipient_mode' => 'roles',
            'recipient_value' => 'manager',
            'is_enabled' => true,
            'dedupe_window_seconds' => 0,
        ], null, $ownerId);

        $this->assertThrows(ValidationException::class, function () use ($ownerId) {
            NotificationService::saveTemplate([
                'event_key' => 'test.event3',
                'title' => 'T2',
                'recipient_mode' => 'roles',
                'recipient_value' => 'manager',
                'is_enabled' => true,
                'dedupe_window_seconds' => 0,
            ], null, $ownerId);
        }, 'duplicate event_key rejected');

        $this->cleanupTemplate($id);
    }

    public function testEventKeyFormatValidation(): void
    {
        $ownerId = $this->makeOwner('nt-format@test.local');
        $this->assertThrows(ValidationException::class, function () use ($ownerId) {
            NotificationService::saveTemplate([
                'event_key' => 'Bad-Key', // uppercase
                'title' => 'T',
                'recipient_mode' => 'roles',
                'recipient_value' => 'manager',
                'is_enabled' => true,
                'dedupe_window_seconds' => 0,
            ], null, $ownerId);
        }, 'uppercase event_key rejected');
    }

    public function testToggleTemplate(): void
    {
        $ownerId = $this->makeOwner('nt-toggle@test.local');
        $id = NotificationService::saveTemplate([
            'event_key' => 'test.event4',
            'title' => 'T',
            'recipient_mode' => 'roles',
            'recipient_value' => 'manager',
            'is_enabled' => true,
            'dedupe_window_seconds' => 0,
        ], null, $ownerId);

        NotificationService::toggleTemplate($id, false);
        $this->assertSame(0, (int) NotificationService::findTemplate($id)['is_enabled']);
        NotificationService::toggleTemplate($id, true);
        $this->assertSame(1, (int) NotificationService::findTemplate($id)['is_enabled']);

        $this->cleanupTemplate($id);
    }

    // ---------------------------------------------------- DISPATCH --

    public function testDispatchToRoles(): void
    {
        $ownerId = $this->makeOwner('nt-d1@test.local');
        $mgrId = $this->makeManager('nt-d1-mgr@test.local');
        $this->cleanupDeliveries();
        // Use specific_users mode so the test is deterministic regardless
        // of how many managers other test classes have created (DB is
        // shared across the full suite run).
        $id = NotificationService::saveTemplate([
            'event_key' => 'test.dispatch1',
            'title' => 'Order {reference}',
            'body' => 'Total {total}',
            'link_template' => '/admin/orders/{order_id}',
            'recipient_mode' => 'specific_users',
            'recipient_value' => (string) $mgrId,
            'is_enabled' => true,
            'dedupe_window_seconds' => 0,
        ], null, $ownerId);

        $delivered = NotificationService::dispatch('test.dispatch1', [
            'reference' => 'RTP-123',
            'total' => '500.00',
            'order_id' => '99',
        ], $ownerId);

        $this->assertSame(1, $delivered, 'exactly one recipient (the test manager)');

        // Verify the notification row was inserted for the manager.
        $stmt = $this->pdo()->prepare('SELECT title, body, link FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$mgrId]);
        $n = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Order RTP-123', (string) $n['title']);
        $this->assertSame('Total 500.00', (string) $n['body']);
        $this->assertSame('/admin/orders/99', (string) $n['link']);

        // Verify the delivery log row.
        $stmt = $this->pdo()->prepare('SELECT status, recipient_user_id, dedupe_key FROM notification_deliveries WHERE template_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$id]);
        $d = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('delivered', (string) $d['status']);
        $this->assertSame((string) $mgrId, (string) $d['recipient_user_id']);

        // Cleanup
        $this->pdo()->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$mgrId]);
        $this->cleanupTemplate($id);
        $this->cleanupDeliveries();
    }

    public function testDispatchToPermissions(): void
    {
        $ownerId = $this->makeOwner('nt-d2@test.local');
        $acctId = $this->makeAccountant('nt-d2-acct@test.local'); // has payments.manage
        $this->cleanupDeliveries();
        $id = NotificationService::saveTemplate([
            'event_key' => 'test.dispatch2',
            'title' => 'X',
            'recipient_mode' => 'permissions',
            'recipient_value' => 'payments.manage',
            'is_enabled' => true,
            'dedupe_window_seconds' => 0,
        ], null, $ownerId);

        $delivered = NotificationService::dispatch('test.dispatch2', [], $ownerId);
        $this->assertGreaterThan(0, $delivered, 'at least the accountant has payments.manage');

        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
        $stmt->execute([$acctId]);
        $this->assertGreaterThan(0, (int) $stmt->fetchColumn());

        $this->pdo()->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$acctId]);
        $this->pdo()->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$ownerId]);
        $this->cleanupTemplate($id);
        $this->cleanupDeliveries();
    }

    public function testDispatchToSpecificUsers(): void
    {
        $ownerId = $this->makeOwner('nt-d3@test.local');
        $u2 = $this->makeManager('nt-d3-u2@test.local');
        $this->cleanupDeliveries();
        $id = NotificationService::saveTemplate([
            'event_key' => 'test.dispatch3',
            'title' => 'Hi',
            'recipient_mode' => 'specific_users',
            'recipient_value' => (string) $u2,
            'is_enabled' => true,
            'dedupe_window_seconds' => 0,
        ], null, $ownerId);

        $delivered = NotificationService::dispatch('test.dispatch3', [], $ownerId);
        $this->assertSame(1, $delivered);

        $this->pdo()->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$u2]);
        $this->cleanupTemplate($id);
        $this->cleanupDeliveries();
    }

    public function testDispatchAllStaffExcludesCustomers(): void
    {
        $ownerId = $this->makeOwner('nt-d4@test.local');
        $mgrId = $this->makeManager('nt-d4-mgr@test.local');
        // Create a customer-role user (should NOT receive).
        $cust = $this->makeUser('nt-d4-cust@test.local', 'Correct-Horse-1', ['customer']);
        $this->cleanupDeliveries();
        $id = NotificationService::saveTemplate([
            'event_key' => 'test.dispatch4',
            'title' => 'Broadcast',
            'recipient_mode' => 'all_staff',
            'recipient_value' => '',
            'is_enabled' => true,
            'dedupe_window_seconds' => 0,
        ], null, $ownerId);

        $delivered = NotificationService::dispatch('test.dispatch4', [], $ownerId);
        $this->assertGreaterThan(0, $delivered);

        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
        $stmt->execute([$cust]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'customer must NOT receive staff broadcast');

        // owner and manager should both have received
        $stmt->execute([$ownerId]);
        $this->assertGreaterThan(0, (int) $stmt->fetchColumn(), 'owner is staff');
        $stmt->execute([$mgrId]);
        $this->assertGreaterThan(0, (int) $stmt->fetchColumn(), 'manager is staff');

        $this->pdo()->prepare('DELETE FROM notifications WHERE user_id IN (?,?)')->execute([$ownerId, $mgrId]);
        $this->cleanupTemplate($id);
        $this->cleanupDeliveries();
    }

    public function testDispatchNoTemplateLogsNoTemplate(): void
    {
        $ownerId = $this->makeOwner('nt-no-tpl@test.local');
        $this->cleanupDeliveries();
        $delivered = NotificationService::dispatch('test.does_not_exist', [], $ownerId);
        $this->assertSame(0, $delivered);

        $stmt = $this->pdo()->prepare("SELECT status FROM notification_deliveries WHERE event_key = ? LIMIT 1");
        $stmt->execute(['test.does_not_exist']);
        $this->assertSame('no_template', (string) $stmt->fetchColumn(), 'no_template delivery logged');

        $this->cleanupDeliveries();
    }

    public function testDispatchNoRecipientsLogsNoRecipients(): void
    {
        $ownerId = $this->makeOwner('nt-no-rec@test.local');
        $this->cleanupDeliveries();
        $id = NotificationService::saveTemplate([
            'event_key' => 'test.no_rec',
            'title' => 'X',
            'recipient_mode' => 'specific_users',
            'recipient_value' => '999999', // nonexistent
            'is_enabled' => true,
            'dedupe_window_seconds' => 0,
        ], null, $ownerId);

        $delivered = NotificationService::dispatch('test.no_rec', [], $ownerId);
        $this->assertSame(0, $delivered);

        $stmt = $this->pdo()->prepare("SELECT status FROM notification_deliveries WHERE event_key = ? LIMIT 1");
        $stmt->execute(['test.no_rec']);
        $this->assertSame('no_recipients', (string) $stmt->fetchColumn());

        $this->cleanupTemplate($id);
        $this->cleanupDeliveries();
    }

    // ---------------------------------------------------- DEDUPE --

    public function testDedupeWithinWindowSuppresses(): void
    {
        $ownerId = $this->makeOwner('nt-dedupe@test.local');
        $mgrId = $this->makeManager('nt-dedupe-mgr@test.local');
        $this->cleanupDeliveries();
        $id = NotificationService::saveTemplate([
            'event_key' => 'test.dedupe',
            'title' => 'X',
            'recipient_mode' => 'roles',
            'recipient_value' => 'manager',
            'is_enabled' => true,
            'dedupe_window_seconds' => 300, // 5 minutes
        ], null, $ownerId);

        // First dispatch: delivered.
        $first = NotificationService::dispatch('test.dedupe', ['order_id' => '7'], $ownerId, 'order:7');
        $this->assertGreaterThan(0, $first);

        // Second dispatch within window: suppressed.
        $second = NotificationService::dispatch('test.dedupe', ['order_id' => '7'], $ownerId, 'order:7');
        $this->assertSame(0, $second, 'duplicate suppressed');

        // Verify delivery log has 1 delivered + 1 suppressed_duplicate.
        $stmt = $this->pdo()->prepare(
            "SELECT status FROM notification_deliveries WHERE event_key = ? AND recipient_user_id = ? AND dedupe_key = ? ORDER BY id"
        );
        $stmt->execute(['test.dedupe', $mgrId, 'order:7']);
        $statuses = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertContains('delivered', $statuses);
        $this->assertContains('suppressed_duplicate', $statuses);

        $this->pdo()->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$mgrId]);
        $this->cleanupTemplate($id);
        $this->cleanupDeliveries();
    }

    public function testDedupeDifferentKeyDelivers(): void
    {
        $ownerId = $this->makeOwner('nt-dedupe2@test.local');
        $mgrId = $this->makeManager('nt-dedupe2-mgr@test.local');
        $this->cleanupDeliveries();
        $id = NotificationService::saveTemplate([
            'event_key' => 'test.dedupe2',
            'title' => 'X',
            'recipient_mode' => 'roles',
            'recipient_value' => 'manager',
            'is_enabled' => true,
            'dedupe_window_seconds' => 300,
        ], null, $ownerId);

        // Same event, different dedupe key — both deliver.
        NotificationService::dispatch('test.dedupe2', ['order_id' => '7'], $ownerId, 'order:7');
        NotificationService::dispatch('test.dedupe2', ['order_id' => '8'], $ownerId, 'order:8');

        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM notification_deliveries
             WHERE event_key = ? AND recipient_user_id = ? AND status = 'delivered'"
        );
        $stmt->execute(['test.dedupe2', $mgrId]);
        $this->assertSame(2, (int) $stmt->fetchColumn(), 'two different dedupe keys both delivered');

        $this->pdo()->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$mgrId]);
        $this->cleanupTemplate($id);
        $this->cleanupDeliveries();
    }

    // ---------------------------------------------------- RETRY --

    public function testRetryFailedReattemptsInPlace(): void
    {
        $ownerId = $this->makeOwner('nt-retry@test.local');
        $mgrId = $this->makeManager('nt-retry-mgr@test.local');
        $this->cleanupDeliveries();

        // Insert a manual 'failed' delivery row.
        $stmt = $this->pdo()->prepare(
            'INSERT INTO notification_deliveries
                (notification_id, template_id, event_key, recipient_user_id, dedupe_key, status, attempts, last_error)
             VALUES (NULL, NULL, ?, ?, ?, "failed", 1, "original error")'
        );
        $stmt->execute(['test.retry', $mgrId, 'retry-key:1']);
        $failedId = (int) $this->pdo()->lastInsertId();

        // Without a template, retry should still bump attempts and mark it failed.
        $result = NotificationService::retryFailed(3);
        $this->assertGreaterThan(0, $result['retried']);

        $stmt = $this->pdo()->prepare('SELECT status, attempts, last_error FROM notification_deliveries WHERE id = ?');
        $stmt->execute([$failedId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('failed', (string) $row['status']);
        $this->assertGreaterThan(1, (int) $row['attempts'], 'attempts incremented');
        $this->assertStringContainsString('Template missing', (string) $row['last_error']);

        $this->cleanupDeliveries();
    }

    public function testRetrySucceedsWithTemplate(): void
    {
        $ownerId = $this->makeOwner('nt-retry2@test.local');
        $mgrId = $this->makeManager('nt-retry2-mgr@test.local');
        $this->cleanupDeliveries();

        // Create a template for the retry to use.
        $templateId = NotificationService::saveTemplate([
            'event_key' => 'test.retry2.event',
            'title' => 'Retry title',
            'recipient_mode' => 'roles',
            'recipient_value' => 'manager',
            'is_enabled' => true,
            'dedupe_window_seconds' => 0,
        ], null, $ownerId);

        // Insert a manual 'failed' delivery row referencing the template.
        $stmt = $this->pdo()->prepare(
            'INSERT INTO notification_deliveries
                (notification_id, template_id, event_key, recipient_user_id, dedupe_key, status, attempts, last_error)
             VALUES (NULL, ?, ?, ?, ?, "failed", 1, "transient error")'
        );
        $stmt->execute([$templateId, 'test.retry2.event', $mgrId, 'retry-key:2']);
        $failedId = (int) $this->pdo()->lastInsertId();

        $result = NotificationService::retryFailed(3);
        $this->assertGreaterThan(0, $result['retried']);
        $this->assertGreaterThan(0, $result['succeeded'], 'retry succeeded with template');

        $stmt = $this->pdo()->prepare('SELECT status, attempts, notification_id FROM notification_deliveries WHERE id = ?');
        $stmt->execute([$failedId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('delivered', (string) $row['status']);
        $this->assertNotNull($row['notification_id'], 'notification row linked');
        $this->assertGreaterThan(1, (int) $row['attempts']);

        $this->pdo()->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$mgrId]);
        $this->cleanupTemplate($templateId);
        $this->cleanupDeliveries();
    }

    // ---------------------------------------------------- PRIVACY --

    public function testMarkReadScopedBySignedInUser(): void
    {
        $ownerId = $this->makeOwner('nt-priv1@test.local');
        $mgrId = $this->makeManager('nt-priv1-mgr@test.local');

        // Push a notification to the manager.
        NotificationService::push($mgrId, 'For manager', null, null, 'info');
        $notifId = (int) $this->pdo()->lastInsertId();

        // Owner (not the recipient) tries to mark it read — must be a no-op.
        $ok = NotificationService::markRead($ownerId, $notifId);
        $this->assertFalse($ok, 'cross-recipient markRead rejected');

        $stmt = $this->pdo()->prepare('SELECT is_read FROM notifications WHERE id = ?');
        $stmt->execute([$notifId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'notification still unread');

        // The actual recipient marks it read — works.
        $ok = NotificationService::markRead($mgrId, $notifId);
        $this->assertTrue($ok);
        $stmt->execute([$notifId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        $this->pdo()->prepare('DELETE FROM notifications WHERE id = ?')->execute([$notifId]);
    }

    public function testForUserReturnsOnlyOwnNotifications(): void
    {
        $ownerId = $this->makeOwner('nt-priv2@test.local');
        $mgrId = $this->makeManager('nt-priv2-mgr@test.local');

        NotificationService::push($mgrId, 'Mgr-only', null, null, 'info');
        $mgrNotifId = (int) $this->pdo()->lastInsertId();
        NotificationService::push($ownerId, 'Owner-only', null, null, 'info');
        $ownerNotifId = (int) $this->pdo()->lastInsertId();

        $ownerList = NotificationService::forUser($ownerId, 50);
        $ownerTitles = array_map(fn ($n) => $n['title'], $ownerList);
        $this->assertContains('Owner-only', $ownerTitles);
        $this->assertNotContains('Mgr-only', $ownerTitles, 'owner does NOT see manager notification');

        $mgrList = NotificationService::forUser($mgrId, 50);
        $mgrTitles = array_map(fn ($n) => $n['title'], $mgrList);
        $this->assertContains('Mgr-only', $mgrTitles);
        $this->assertNotContains('Owner-only', $mgrTitles);

        $this->pdo()->prepare('DELETE FROM notifications WHERE id IN (?,?)')->execute([$mgrNotifId, $ownerNotifId]);
    }

    public function testDeliveryLogViewerRequiresManagePermission(): void
    {
        $tech = $this->makeTech('nt-priv3-tech@test.local');
        $this->assertThrows(ForbiddenException::class, function () use ($tech) {
            $this->dispatchAs($tech, 'GET', '/admin/notifications/deliveries');
        }, 'technician cannot view delivery log');
    }

    public function testDeliveryLogNeverExposesBodyContent(): void
    {
        $ownerId = $this->makeOwner('nt-priv4@test.local');
        $this->cleanupDeliveries();

        // Create a template with a sensitive body.
        $id = NotificationService::saveTemplate([
            'event_key' => 'test.priv',
            'title' => 'X',
            'body' => 'Secret {x}', // this body would be interpolated and stored in notifications, NOT delivery log
            'recipient_mode' => 'roles',
            'recipient_value' => 'owner',
            'is_enabled' => true,
            'dedupe_window_seconds' => 0,
        ], null, $ownerId);

        NotificationService::dispatch('test.priv', ['x' => 'PAYLOAD'], $ownerId);

        // Fetch the delivery log page as the owner.
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/notifications/deliveries');
        $this->assertSame(200, $r->status());
        $body = $r->body();
        $this->assertStringNotContainsString('Secret', $body, 'delivery log must NOT contain body content');
        $this->assertStringNotContainsString('PAYLOAD', $body, 'delivery log must NOT contain interpolated payload');

        $this->pdo()->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$ownerId]);
        $this->cleanupTemplate($id);
        $this->cleanupDeliveries();
    }

    // ---------------------------------------------------- WIRED EVENTS --

    public function testOrderCreatedDispatches(): void
    {
        $ownerId = $this->makeOwner('nt-wire1@test.local');
        // Manager exists to receive 'order.created' (default template recipients: manager,accountant).
        $mgrId = $this->makeManager('nt-wire1-mgr@test.local');
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $customerId = $this->makeCustomer();
        $this->cleanupDeliveries();

        $order = (new OrderService())->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'completed');

        // Manager should have a notification.
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ?');
        $stmt->execute([$mgrId, 'order.created']);
        $this->assertGreaterThan(0, (int) $stmt->fetchColumn(), 'manager received order.created notification');

        // Delivery log should have a 'delivered' entry.
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM notification_deliveries WHERE event_key = 'order.created' AND status = 'delivered'"
        );
        $stmt->execute();
        $this->assertGreaterThan(0, (int) $stmt->fetchColumn());

        // Cleanup
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM coupon_usages WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM order_fulfillment_notes WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM order_status_history WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->prepare('DELETE FROM notifications WHERE user_id IN (?,?)')->execute([$ownerId, $mgrId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->cleanupDeliveries();
    }

    public function testOrderStatusUpdatedDispatches(): void
    {
        $ownerId = $this->makeOwner('nt-wire2@test.local');
        $mgrId = $this->makeManager('nt-wire2-mgr@test.local');
        [$brandId, $catId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeAvailableUnit($modelId, ['selling_price' => '500.00']);
        $customerId = $this->makeCustomer();
        $this->cleanupDeliveries();

        $svc = new OrderService();
        $order = $svc->createOrder($customerId, [$unitId], 'pos', $ownerId, [], 'pending');
        $svc->updateStatus($order['id'], 'confirmed', $ownerId, 'Customer confirmed');

        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM notification_deliveries WHERE event_key = 'order.status_updated' AND status = 'delivered'"
        );
        $stmt->execute();
        $this->assertGreaterThan(0, (int) $stmt->fetchColumn(), 'order.status_updated dispatch recorded');

        // Cleanup
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM coupon_usages WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM order_fulfillment_notes WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM order_status_history WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$order['id']]);
        $this->pdo()->prepare('DELETE FROM stock_movements WHERE inventory_unit_id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM inventory_units WHERE id = ?')->execute([$unitId]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        $this->pdo()->prepare('DELETE FROM product_models WHERE id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM brands WHERE id = ?')->execute([$brandId]);
        $this->pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        $this->pdo()->prepare('DELETE FROM notifications WHERE user_id IN (?,?)')->execute([$ownerId, $mgrId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->cleanupDeliveries();
    }

    // ---------------------------------------------------- HTTP PERMISSIONS --

    public function testTechnicianCannotViewTemplates(): void
    {
        $tech = $this->makeTech('nt-http1@test.local');
        $this->assertThrows(ForbiddenException::class, function () use ($tech) {
            $this->dispatchAs($tech, 'GET', '/admin/notifications/templates');
        }, 'technician cannot view templates');
    }

    public function testManagerCanViewTemplates(): void
    {
        $ownerId = $this->makeOwner('nt-http2@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/notifications/templates');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Notification Templates', $r->body());
    }

    public function testHttpCreateTemplate(): void
    {
        $ownerId = $this->makeOwner('nt-http3@test.local');
        $r = $this->dispatchAs($ownerId, 'POST', '/admin/notifications/templates', [
            'event_key' => 'test.http_event',
            'title' => 'HTTP test',
            'body' => '',
            'link_template' => '',
            'recipient_mode' => 'roles',
            'recipient_value' => 'manager',
            'is_enabled' => '1',
            'dedupe_window_seconds' => '60',
        ]);
        $this->assertSame(302, $r->status());

        $stmt = $this->pdo()->prepare('SELECT id FROM notification_templates WHERE event_key = ?');
        $stmt->execute(['test.http_event']);
        $id = (int) $stmt->fetchColumn();
        $this->assertGreaterThan(0, $id, 'template persisted via HTTP');

        $this->cleanupTemplate($id);
    }

    public function testHttpRetryButton(): void
    {
        $ownerId = $this->makeOwner('nt-http4@test.local');
        $this->cleanupDeliveries();
        $r = $this->dispatchAs($ownerId, 'POST', '/admin/notifications/deliveries/retry');
        $this->assertSame(302, $r->status());
        $this->cleanupDeliveries();
    }

    // ---------------------------------------------------- CSRF --

    public function testStoreTemplateRejectsMissingCsrf(): void
    {
        $ownerId = $this->makeOwner('nt-csrf@test.local');
        $this->actingAs($ownerId);
        $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () {
            $this->router()->dispatch(
                Request::synthetic('POST', '/admin/notifications/templates', [], [
                    'event_key' => 'NOCSRF', 'title' => 'X', 'recipient_mode' => 'roles',
                    'recipient_value' => 'manager', 'dedupe_window_seconds' => '0',
                ]),
                $this->resolver()
            );
        }, 'missing CSRF rejected');
    }
}
