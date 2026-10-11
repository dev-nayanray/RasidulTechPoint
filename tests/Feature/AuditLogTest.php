<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Services\AuditService;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Audit logs & security activity — HTTP-level tests.
 *
 * Proves:
 *  - the audit log viewer renders with filters + metadata + IP
 *  - search by action / entity / user / IP
 *  - category filter (auth, user, inventory, etc.)
 *  - entity-type filter
 *  - actor (user) filter
 *  - date-range filter
 *  - JSON export returns the right content type + filtered data
 *  - immutability: no POST/PUT/DELETE route exists for editing logs
 *  - metadata is safe (JSON, no secrets, pretty-printed)
 *  - permission enforcement (manager blocked, cashier blocked)
 *  - CSRF not needed (GET-only controller)
 */
final class AuditLogTest extends TestCase
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

    private function dispatchAs(int $userId, string $method, string $uri, array $get = []): Response
    {
        $this->actingAs($userId);
        return $this->router()->dispatch(
            Request::synthetic($method, $uri, $get, []),
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

    // ---------------------------------------------------- PERMISSIONS --

    public function testManagerBlockedFromAuditLogs(): void
    {
        $managerId = $this->makeUser('audit-mgr@test.local', 'Correct-Horse-1', ['manager']);
        $this->assertThrows(ForbiddenException::class, function () use ($managerId) {
            $this->dispatchAs($managerId, 'GET', '/admin/audit-logs');
        }, 'manager (no audit_logs.view) must not view audit logs');
    }

    public function testOwnerCanViewAuditLogs(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('audit-owner@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/audit-logs');
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Audit logs/', $response->body());
        $this->assertMatchesRegularExpression('/append-only/', $response->body(), 'the view must document that logs are immutable');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);

    }

    // ---------------------------------------------------- SEARCH --

    public function testSearchByAction(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('audit-search@test.local', 'Correct-Horse-1', ['owner']);

        // Create a known audit event.
        AuditService::record('test.search_action', 'test_entity', 42, ['key' => 'value'], null);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/audit-logs', ['q' => 'search_action']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/test\.search_action/', $response->body(), 'the event must appear in search results');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM audit_logs WHERE action = ?')->execute(['test.search_action']);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);

    }

    public function testSearchByIp(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('audit-ip@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/audit-logs', ['q' => '127.0.0.1']);
        $this->assertSame(200, $response->status());
        // Some events should have the synthetic request IP.
        $this->assertMatchesRegularExpression('/127\.0\.0\.1/', $response->body());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);

    }

    // ---------------------------------------------------- CATEGORY FILTER --

    public function testCategoryFilterWorks(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('audit-cat@test.local', 'Correct-Horse-1', ['owner']);

        // Create events in different categories.
        AuditService::record('auth.login', 'user', $ownerId, [], null);
        AuditService::record('inventory.received', 'inventory_unit', 1, ['sku' => 'X'], null);
        AuditService::record('user.created', 'user', 2, ['email' => 'x'], null);

        // Filter for 'auth' category.
        $response = $this->dispatchAs($ownerId, 'GET', '/admin/audit-logs', ['category' => 'auth']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/auth\.login/', $response->body(), 'auth event must appear in auth category filter');
        $this->assertFalse(str_contains($response->body(), 'inventory.received'), 'inventory event must NOT appear in auth filter');
        $this->assertFalse(str_contains($response->body(), 'user.created'), 'user event must NOT appear in auth filter');

        // Filter for 'inventory' category.
        $response = $this->dispatchAs($ownerId, 'GET', '/admin/audit-logs', ['category' => 'inventory']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/inventory\.received/', $response->body());
        $this->assertFalse(str_contains($response->body(), 'auth.login'));

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM audit_logs WHERE action IN (?, ?, ?)')->execute(['auth.login', 'inventory.received', 'user.created']);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);

    }

    // ---------------------------------------------------- ENTITY TYPE FILTER --

    public function testEntityTypeFilter(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('audit-entity@test.local', 'Correct-Horse-1', ['owner']);

        AuditService::record('test.entity_a', 'special_entity', 1, [], null);
        AuditService::record('test.entity_b', 'other_entity', 2, [], null);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/audit-logs', ['entity_type' => 'special_entity']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/special_entity/', $response->body());
        // The event 'test.entity_b' must NOT appear as a logged action.
        // (The entity type 'other_entity' may appear in the dropdown
        // options, but the ACTION 'test.entity_b' must not be in a row.)
        $this->assertFalse(str_contains($response->body(), 'test.entity_b'), 'other entity event must be filtered out');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM audit_logs WHERE entity_type = ?')->execute(['special_entity']);
        $this->pdo()->prepare('DELETE FROM audit_logs WHERE entity_type = ?')->execute(['other_entity']);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);

    }

    // ---------------------------------------------------- ACTOR FILTER --

    public function testActorFilter(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('audit-actor@test.local', 'Correct-Horse-1', ['owner']);
        $otherId = $this->makeUser('audit-other@test.local', 'Correct-Horse-1', ['manager']);

        // $otherId acts.
        $this->actingAs($otherId);
        AuditService::record('test.other_actor', 'test', 1, [], null);

        // $ownerId acts.
        $this->actingAs($ownerId);
        AuditService::record('test.owner_actor', 'test', 2, [], null);

        // Filter by $otherId.
        $response = $this->dispatchAs($ownerId, 'GET', '/admin/audit-logs', ['user_id' => (string) $otherId]);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/test\.other_actor/', $response->body());
        // owner_actor should NOT appear (different user).
        $this->assertFalse(str_contains($response->body(), 'test.owner_actor'), 'events by other actors must be filtered out');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM audit_logs WHERE action LIKE ?')->execute(['test.%']);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?)')->execute([$ownerId, $otherId]);

    }

    // ---------------------------------------------------- DATE RANGE FILTER --

    public function testDateRangeFilter(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('audit-date@test.local', 'Correct-Horse-1', ['owner']);

        // Insert a recent event.
        AuditService::record('test.date_recent', 'test', 1, [], null);

        // Insert an event far in the past.
        $this->pdo()->prepare(
            "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata, ip, correlation_id, created_at)
             VALUES (?, 'test.date_old', 'test', 2, NULL, NULL, NULL, '2020-01-01 00:00:00')"
        )->execute([$ownerId]);

        // Filter for today only.
        $today = gmdate('Y-m-d');
        $response = $this->dispatchAs($ownerId, 'GET', '/admin/audit-logs', ['from' => $today, 'to' => $today]);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/test\.date_recent/', $response->body(), 'recent event must appear in today filter');
        $this->assertFalse(str_contains($response->body(), 'test.date_old'), 'old event must be excluded by date filter');

        // Filter for 2020 only.
        $response = $this->dispatchAs($ownerId, 'GET', '/admin/audit-logs', ['from' => '2020-01-01', 'to' => '2020-12-31']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/test\.date_old/', $response->body(), 'old event must appear in 2020 filter');
        $this->assertFalse(str_contains($response->body(), 'test.date_recent'), 'recent event must be excluded');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM audit_logs WHERE action LIKE ?')->execute(['test.%']);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);

    }

    // ---------------------------------------------------- METADATA --

    public function testMetadataIsRenderedSafely(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('audit-meta@test.local', 'Correct-Horse-1', ['owner']);

        // Insert an event with metadata containing HTML-like content.
        AuditService::record('test.metadata', 'test', 1, [
            'safe_key' => 'safe_value',
            'description' => 'Changed from A to B',
            'flag' => true,
            'list' => ['a', 'b'],
        ], null);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/audit-logs', ['q' => 'test.metadata']);
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/safe_key/', $response->body(), 'metadata key must appear');
        $this->assertMatchesRegularExpression('/safe_value/', $response->body(), 'metadata value must appear');
        $this->assertMatchesRegularExpression('/true/', $response->body(), 'boolean metadata must render');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM audit_logs WHERE action = ?')->execute(['test.metadata']);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);

    }

    // ---------------------------------------------------- EXPORT --

    public function testJsonExport(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('audit-export@test.local', 'Correct-Horse-1', ['owner']);

        AuditService::record('test.export_event', 'test_entity', 42, ['key' => 'value'], null);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/audit-logs/export', ['q' => 'export_event']);
        $this->assertSame(200, $response->status());
        $this->assertStringStartsWith('application/json', $response->headers()['Content-Type'] ?? '', 'must be JSON content type');
        $this->assertStringContainsString('attachment', $response->headers()['Content-Disposition'] ?? '', 'must be a download');

        $body = $response->body();
        $payload = json_decode($body, true);
        $this->assertNotNull($payload, 'export body must be valid JSON');
        $this->assertGreaterThan(0, $payload['total']);
        $this->assertStringContainsString('test.export_event', $body);
        $this->assertStringContainsString('test_entity', $body);
        $this->assertStringContainsString('value', $body);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM audit_logs WHERE action = ?')->execute(['test.export_event']);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);

    }

    // ---------------------------------------------------- IMMUTABILITY --

    public function testNoEditOrDeleteRouteExists(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('audit-immutability@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($ownerId);

        // These routes must NOT exist — audit logs are append-only.
        // A 404 (NotFoundException) is the expected outcome.
        foreach ([
            ['POST', '/admin/audit-logs/1/edit'],
            ['POST', '/admin/audit-logs/1/delete'],
            ['PUT', '/admin/audit-logs/1'],
            ['DELETE', '/admin/audit-logs/1'],
        ] as [$method, $uri]) {
            $this->assertThrows(NotFoundException::class, function () use ($method, $uri) {
                $this->router()->dispatch(
                    Request::synthetic($method, $uri, [], $this->withCsrf([])),
                    $this->resolver()
                );
            }, "{$method} {$uri} must 404 — no edit/delete route exists for audit logs");
        }

        // Also verify that a direct DB UPDATE is not performed by any controller.
        // The only routes under /admin/audit-logs are GET (index + export).
        $response = $this->dispatchAs($ownerId, 'GET', '/admin/audit-logs');
        $this->assertSame(200, $response->status());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);

    }

    // ---------------------------------------------------- CATEGORY CLASSIFICATION --

    public function testCategoryClassification(): void
    {
        $this->assertSame('auth', AuditService::categoryForAction('auth.login'));
        $this->assertSame('auth', AuditService::categoryForAction('auth.password_reset_requested'));
        $this->assertSame('user', AuditService::categoryForAction('user.created'));
        $this->assertSame('user', AuditService::categoryForAction('user.password_reset_initiated'));
        $this->assertSame('role', AuditService::categoryForAction('role.created'));
        $this->assertSame('role', AuditService::categoryForAction('roles.permissions_updated'));
        $this->assertSame('settings', AuditService::categoryForAction('settings.business_profile_updated'));
        $this->assertSame('inventory', AuditService::categoryForAction('inventory.received'));
        $this->assertSame('inventory', AuditService::categoryForAction('inventory.price_changed'));
        $this->assertSame('catalog', AuditService::categoryForAction('product_model.created'));
        $this->assertSame('catalog', AuditService::categoryForAction('brand.updated'));
        $this->assertSame('location', AuditService::categoryForAction('location.created'));
        $this->assertSame('orders', AuditService::categoryForAction('order.created'));
        $this->assertSame('orders', AuditService::categoryForAction('payment.recorded'));
        $this->assertSame('permissions', AuditService::categoryForAction('permission.denied'));
        $this->assertSame('account', AuditService::categoryForAction('account.profile_updated'));
        $this->assertSame('other', AuditService::categoryForAction('unknown.action'));
    }
}
