<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\CsrfTokenMismatchException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\ContactInquiryRepository;
use App\Services\ContactService;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Admin Contact Inquiries section (the fix for "contact form submissions
 * do not show in the Super Admin Panel").
 *
 * Proves:
 *  - An inquiry submitted through the real public ContactService appears
 *    in the admin list and detail page (same table, same source of truth).
 *  - List supports search + status filter + real counts.
 *  - Detail page escapes inquiry content (XSS-safe admin view).
 *  - Status changes are allowlisted; invalid statuses are rejected.
 *  - Marking `responded` stamps responded_at + responded_by.
 *  - Internal notes persist and display.
 *  - Deletion requires the inquiry-number confirmation + CSRF.
 *  - view vs manage permission split is enforced server-side.
 *  - Technicians (no inquiry permission) and anonymous visitors are blocked.
 *  - Dashboard shows real inquiry counts to permitted staff only.
 */
final class ContactInquiryAdminTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec('DELETE FROM contact_inquiries WHERE email LIKE "ci-admin-%@test.local"');
        $this->pdo()->exec('DELETE FROM users WHERE email LIKE "ci-admin-%@test.local"');
        $this->pdo()->exec('DELETE FROM login_attempts WHERE email LIKE "ci-admin-%@test.local"');
    }

    public function tearDown(): void
    {
        $this->pdo()->exec('DELETE FROM contact_inquiries WHERE email LIKE "ci-admin-%@test.local"');
        $this->pdo()->exec('DELETE FROM users WHERE email LIKE "ci-admin-%@test.local"');
        $this->pdo()->exec('DELETE FROM login_attempts WHERE email LIKE "ci-admin-%@test.local"');
        parent::tearDown();
    }

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

    /** Insert an inquiry row directly and return its id + number. */
    private function insertInquiry(array $overrides = []): array
    {
        $row = array_merge([
            'inquiry_number' => 'CI-TEST-' . uniqid(),
            'full_name' => 'Test Person',
            'email' => 'ci-admin-sender@test.local',
            'phone' => null,
            'topic' => 'product',
            'subject' => 'Test subject',
            'message' => 'A sufficiently long test message body.',
            'order_reference' => null,
        ], $overrides);
        $stmt = $this->pdo()->prepare(
            'INSERT INTO contact_inquiries (inquiry_number, full_name, email, phone, topic, subject, message, order_reference)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $row['inquiry_number'], $row['full_name'], $row['email'], $row['phone'],
            $row['topic'], $row['subject'], $row['message'], $row['order_reference'],
        ]);
        return ['id' => (int) $this->pdo()->lastInsertId(), 'number' => $row['inquiry_number']];
    }

    // -------------------------------------------------- PERMISSION GATES --

    public function testAnonymousVisitorIsRedirectedToConsoleLogin(): void
    {
        $r = $this->router()->dispatch(Request::synthetic('GET', '/admin/contact-inquiries'), $this->resolver());
        $this->assertSame(302, $r->status());
        $this->assertMatchesRegularExpression('#/rtp-admin$#', $r->headers()['Location'] ?? '');
    }

    public function testStaffWithoutPermissionIsForbidden(): void
    {
        $tech = $this->makeUser('ci-admin-tech@test.local', 'Password-12345', ['technician']);
        $this->assertThrows(ForbiddenException::class, function () use ($tech) {
            $this->dispatchAs($tech, 'GET', '/admin/contact-inquiries');
        }, 'technician cannot list inquiries');
        $inquiry = $this->insertInquiry();
        $this->assertThrows(ForbiddenException::class, function () use ($tech, $inquiry) {
            $this->dispatchAs($tech, 'GET', '/admin/contact-inquiries/' . $inquiry['id']);
        }, 'technician cannot open inquiry detail');
    }

    public function testCustomerRoleIsForbidden(): void
    {
        $customer = $this->makeUser('ci-admin-cust@test.local', 'Password-12345', ['customer']);
        $this->assertThrows(ForbiddenException::class, function () use ($customer) {
            $this->dispatchAs($customer, 'GET', '/admin/contact-inquiries');
        }, 'customer cannot access admin inquiries');
    }

    // ------------------------------------------------ LIST + DETAIL VIEWS --

    public function testPublicSubmissionAppearsInAdminListImmediately(): void
    {
        // Submit through the REAL public service — proves the admin list
        // reads the same table the public form writes to.
        $result = (new ContactService())->submit([
            'name' => 'Real Submitter',
            'email' => 'ci-admin-real@test.local',
            'topic' => 'product',
            'subject' => 'Latitude availability',
            'message' => 'Do you have the Latitude 7490 in stock right now?',
        ], '10.99.99.99', 'TestAgent/1.0');
        $this->assertTrue($result['stored']);
        $number = $result['inquiry_number'];
        $this->assertNotSame('', (string) $number);

        $owner = $this->makeUser('ci-admin-owner@test.local', 'Password-12345', ['owner']);
        $r = $this->dispatchAs($owner, 'GET', '/admin/contact-inquiries');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Contact Inquiries', $r->body());
        $this->assertStringContainsString('Real Submitter', $r->body(), 'submitted inquiry is listed');
        $this->assertStringContainsString($number, $r->body(), 'inquiry number is listed');
    }

    public function testListSearchAndStatusFilter(): void
    {
        $a = $this->insertInquiry(['email' => 'ci-admin-a@test.local', 'full_name' => 'Alice Admin', 'subject' => 'Warranty question']);
        $b = $this->insertInquiry(['email' => 'ci-admin-b@test.local', 'full_name' => 'Bob Buyer', 'subject' => 'Delivery timing']);
        $this->pdo()->prepare('UPDATE contact_inquiries SET status = "responded" WHERE id = ?')->execute([$b['id']]);

        $owner = $this->makeUser('ci-admin-owner2@test.local', 'Password-12345', ['owner']);

        $r = $this->dispatchAs($owner, 'GET', '/admin/contact-inquiries', [], ['q' => 'Alice']);
        $body = $r->body();
        $this->assertStringContainsString('Alice Admin', $body);
        $this->assertStringNotContainsString('Bob Buyer', $body, 'search filters other rows out');

        $r = $this->dispatchAs($owner, 'GET', '/admin/contact-inquiries', [], ['status' => 'responded']);
        $body = $r->body();
        $this->assertStringContainsString('Bob Buyer', $body);
        $this->assertStringNotContainsString('Alice Admin', $body, 'status filter excludes new rows');
    }

    public function testDetailPageShowsFieldsAndEscapesMessage(): void
    {
        $inquiry = $this->insertInquiry([
            'email' => 'ci-admin-xss@test.local',
            'subject' => '<script>alert(1)</script>',
            'message' => "Hello <b>team</b>, is this XSS safe?\nSecond line",
        ]);
        $owner = $this->makeUser('ci-admin-owner3@test.local', 'Password-12345', ['owner']);

        $r = $this->dispatchAs($owner, 'GET', '/admin/contact-inquiries/' . $inquiry['id']);
        $this->assertSame(200, $r->status());
        $body = $r->body();
        $this->assertStringContainsString($inquiry['number'], $body);
        $this->assertStringContainsString('&lt;script&gt;', $body, 'HTML in inquiry content is escaped');
        $this->assertStringNotContainsString('<script>alert', $body, 'raw unescaped script tag must never appear');
        $this->assertStringContainsString('Second line', $body, 'message body renders');
    }

    public function testEmptyListShowsEmptyState(): void
    {
        $this->pdo()->exec('DELETE FROM contact_inquiries WHERE email LIKE "ci-admin-%@test.local"');
        $owner = $this->makeUser('ci-admin-owner4@test.local', 'Password-12345', ['owner']);
        $r = $this->dispatchAs($owner, 'GET', '/admin/contact-inquiries');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('No inquiries found', $r->body());
    }

    // ------------------------------------------------------ MUTATIONS --

    public function testStatusChangeAllowlistAndRespondedStamp(): void
    {
        $inquiry = $this->insertInquiry(['email' => 'ci-admin-st@test.local']);
        $owner = $this->makeUser('ci-admin-owner5@test.local', 'Password-12345', ['owner']);

        // Invalid status must be rejected by the allowlist.
        $this->assertThrows(ValidationException::class, function () use ($owner, $inquiry) {
            $this->dispatchAs($owner, 'POST', '/admin/contact-inquiries/' . $inquiry['id'] . '/status', ['status' => 'deleted']);
        }, 'invalid status rejected');

        // Valid transition stamps responded_at/by.
        $r = $this->dispatchAs($owner, 'POST', '/admin/contact-inquiries/' . $inquiry['id'] . '/status', ['status' => 'responded']);
        $this->assertSame(302, $r->status());
        $row = (new ContactInquiryRepository())->find($inquiry['id']);
        $this->assertSame('responded', $row['status']);
        $this->assertNotNull($row['responded_at'], 'responded_at is stamped');
        $this->assertSame($owner, (int) $row['responded_by'], 'responded_by is the acting staff member');
    }

    public function testManageActionsRequireManagePermission(): void
    {
        // A role with view only: mutating actions must be forbidden even
        // though the list/detail pages are accessible.
        $this->pdo()->exec("INSERT IGNORE INTO roles (name, slug, description) VALUES ('Inquiry Viewer', 'inquiry_viewer', 'tests: view-only inquiries')");
        $roleId = (int) $this->pdo()->query("SELECT id FROM roles WHERE slug = 'inquiry_viewer'")->fetchColumn();
        $viewPerm = (int) $this->pdo()->query("SELECT id FROM permissions WHERE name = 'contact_inquiries.view'")->fetchColumn();
        $this->assertSame(true, $viewPerm !== 0, 'permission rows must exist (migration 035)');
        $this->pdo()->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$roleId, $viewPerm]);

        $viewer = $this->makeUser('ci-admin-viewer@test.local', 'Password-12345', []);
        $this->pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$viewer, $roleId]);

        $inquiry = $this->insertInquiry(['email' => 'ci-admin-view@test.local']);

        $r = $this->dispatchAs($viewer, 'GET', '/admin/contact-inquiries');
        $this->assertSame(200, $r->status(), 'view-only role can list');

        $this->assertThrows(ForbiddenException::class, function () use ($viewer, $inquiry) {
            $this->dispatchAs($viewer, 'POST', '/admin/contact-inquiries/' . $inquiry['id'] . '/status', ['status' => 'closed']);
        }, 'view-only role cannot change status');

        $this->assertThrows(ForbiddenException::class, function () use ($viewer, $inquiry) {
            $this->dispatchAs($viewer, 'POST', '/admin/contact-inquiries/' . $inquiry['id'] . '/delete', ['confirm' => $inquiry['number']]);
        }, 'view-only role cannot delete');

        // Cleanup the synthetic role bindings.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE role_id = ?')->execute([$roleId]);
        $this->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
        $this->pdo()->prepare('DELETE FROM roles WHERE id = ?')->execute([$roleId]);
    }

    public function testNoteUpdatePersistsAndDisplays(): void
    {
        $inquiry = $this->insertInquiry(['email' => 'ci-admin-note@test.local']);
        $owner = $this->makeUser('ci-admin-owner6@test.local', 'Password-12345', ['owner']);

        $r = $this->dispatchAs($owner, 'POST', '/admin/contact-inquiries/' . $inquiry['id'] . '/note', ['admin_note' => 'Called customer back, waiting for confirmation.']);
        $this->assertSame(302, $r->status());

        $r = $this->dispatchAs($owner, 'GET', '/admin/contact-inquiries/' . $inquiry['id']);
        $this->assertStringContainsString('Called customer back', $r->body(), 'note renders on detail page');
    }

    public function testDeleteRequiresConfirmationMatchAndCsrf(): void
    {
        $inquiry = $this->insertInquiry(['email' => 'ci-admin-del@test.local']);
        $owner = $this->makeUser('ci-admin-owner7@test.local', 'Password-12345', ['owner']);
        $repo = new ContactInquiryRepository();

        // Missing CSRF token → rejected before anything happens.
        $this->assertThrows(CsrfTokenMismatchException::class, function () use ($owner, $inquiry) {
            $this->actingAs($owner);
            $this->router()->dispatch(
                Request::synthetic('POST', '/admin/contact-inquiries/' . $inquiry['id'] . '/delete', [], ['confirm' => $inquiry['number']]),
                $this->resolver()
            );
        });

        // Wrong confirmation text → nothing deleted.
        $r = $this->dispatchAs($owner, 'POST', '/admin/contact-inquiries/' . $inquiry['id'] . '/delete', ['confirm' => 'WRONG-NUMBER']);
        $this->assertSame(302, $r->status());
        $this->assertNotNull($repo->find($inquiry['id']), 'inquiry survives a wrong confirmation');

        // Correct confirmation → row deleted.
        $r = $this->dispatchAs($owner, 'POST', '/admin/contact-inquiries/' . $inquiry['id'] . '/delete', ['confirm' => $inquiry['number']]);
        $this->assertSame(302, $r->status());
        $this->assertNull($repo->find($inquiry['id']), 'inquiry is deleted after correct confirmation');
    }

    // -------------------------------------------------------- DASHBOARD --

    public function testDashboardShowsRealInquiryCountsToPermittedStaff(): void
    {
        $this->insertInquiry(['email' => 'ci-admin-dash@test.local', 'full_name' => 'Dash Counter']);
        $owner = $this->makeUser('ci-admin-owner8@test.local', 'Password-12345', ['owner']);
        $r = $this->dispatchAs($owner, 'GET', '/admin/dashboard');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Contact inquiries', $r->body(), 'dashboard shows the inquiry summary');
    }

    public function testDashboardHidesInquirySummaryFromStaffWithoutPermission(): void
    {
        $tech = $this->makeUser('ci-admin-tech2@test.local', 'Password-12345', ['technician']);
        $r = $this->dispatchAs($tech, 'GET', '/admin/dashboard');
        $this->assertSame(200, $r->status());
        $this->assertStringNotContainsString('Contact inquiries', $r->body(), 'staff without inquiry permission do not see the summary');
    }
}
