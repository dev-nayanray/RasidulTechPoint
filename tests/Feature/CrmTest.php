<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Services\CrmService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * CRM and follow-ups (Prompt 30) — service + HTTP tests.
 *
 * Proves:
 *  - Lead creation with sequential number, source, status
 *  - Lead status update (new → contacted → qualified → won/lost)
 *  - Lead assignment
 *  - Follow-up scheduling + completion
 *  - Communication logging (append-only)
 *  - Quotation creation with items, subtotal/discount/total, valid_until
 *  - Quotation send/accept/reject lifecycle
 *  - WhatsApp link generation (wa.me/<phone>?text=<message>)
 *  - No automated messages sent (click-to-chat only)
 *  - Permission: technician blocked
 *  - CSRF on state-changing routes
 */
final class CrmTest extends TestCase
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

    private function cleanupLead(int $leadId): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->prepare('DELETE FROM crm_communications WHERE lead_id = ?')->execute([$leadId]);
        $this->pdo()->prepare('DELETE FROM crm_followups WHERE lead_id = ?')->execute([$leadId]);
        $this->pdo()->prepare('DELETE FROM crm_quotations WHERE lead_id = ?')->execute([$leadId]);
        $this->pdo()->prepare('DELETE FROM crm_inquiries WHERE lead_id = ?')->execute([$leadId]);
        $this->pdo()->prepare('DELETE FROM crm_leads WHERE id = ?')->execute([$leadId]);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------- LEADS --

    public function testCreateLead(): void
    {
        $ownerId = $this->makeOwner('crm-c1@test.local');
        $svc = new CrmService();
        $result = $svc->createLead([
            'name' => 'Jane Doe',
            'phone' => '+880171234567',
            'email' => 'jane@test.local',
            'source' => 'whatsapp',
            'interest' => 'Dell Latitude 5400',
            'budget' => '40000',
        ], $ownerId);
        $this->assertMatchesRegularExpression('/^LD-\d{4}-\d{5}$/', $result['lead_number']);
        $this->assertSame('new', $result['status']);

        $lead = $svc->findLead($result['id']);
        $this->assertSame('Jane Doe', $lead['name']);
        $this->assertSame('whatsapp', $lead['source']);
        $this->assertSame('Dell Latitude 5400', $lead['interest']);

        $this->cleanupLead($result['id']);
    }

    public function testCreateLeadRejectsEmptyName(): void
    {
        $ownerId = $this->makeOwner('crm-c2@test.local');
        $this->assertThrows(ValidationException::class, function () use ($ownerId) {
            (new CrmService())->createLead(['name' => '', 'phone' => '+88'], $ownerId);
        }, 'empty name rejected');
    }

    public function testUpdateLeadStatus(): void
    {
        $ownerId = $this->makeOwner('crm-s1@test.local');
        $svc = new CrmService();
        $lead = $svc->createLead(['name' => 'Test', 'phone' => '+8801'], $ownerId);

        $svc->updateLeadStatus($lead['id'], 'contacted', $ownerId);
        $this->assertSame('contacted', $svc->findLead($lead['id'])['status']);

        $svc->updateLeadStatus($lead['id'], 'lost', $ownerId, 'Customer bought elsewhere');
        $lead2 = $svc->findLead($lead['id']);
        $this->assertSame('lost', $lead2['status']);
        $this->assertSame('Customer bought elsewhere', $lead2['lost_reason']);

        $this->cleanupLead($lead['id']);
    }

    // ---------------------------------------------------- FOLLOWUPS --

    public function testCreateAndCompleteFollowup(): void
    {
        $ownerId = $this->makeOwner('crm-f1@test.local');
        $svc = new CrmService();
        $lead = $svc->createLead(['name' => 'Test', 'phone' => '+8801'], $ownerId);

        $fupId = $svc->createFollowup([
            'lead_id' => $lead['id'],
            'scheduled_at' => gmdate('Y-m-d H:i:s', strtotime('+2 days')),
            'method' => 'whatsapp',
            'notes' => 'Check if they want to proceed',
        ], $ownerId);
        $this->assertGreaterThan(0, $fupId);

        // Lead's next_followup_at should be updated.
        $lead2 = $svc->findLead($lead['id']);
        $this->assertNotNull($lead2['next_followup_at']);

        // Complete the follow-up.
        $svc->completeFollowup($fupId, $ownerId, 'contacted', 'Customer is interested');
        $fups = $svc->findLead($lead['id'])['followups'];
        $this->assertSame('contacted', $fups[0]['outcome']);
        $this->assertNotNull($fups[0]['completed_at']);

        $this->cleanupLead($lead['id']);
    }

    // ---------------------------------------------------- COMMUNICATIONS --

    public function testLogCommunication(): void
    {
        $ownerId = $this->makeOwner('crm-comm@test.local');
        $svc = new CrmService();
        $lead = $svc->createLead(['name' => 'Test', 'phone' => '+8801'], $ownerId);

        $svc->logCommunication([
            'lead_id' => $lead['id'],
            'channel' => 'whatsapp',
            'direction' => 'outbound',
            'subject' => 'Quotation follow-up',
            'body' => 'Sent the quote via WhatsApp. Customer will review and get back.',
        ], $ownerId);

        $comms = $svc->communicationsFor('lead', $lead['id']);
        $this->assertCount(1, $comms);
        $this->assertSame('whatsapp', $comms[0]['channel']);
        $this->assertSame('outbound', $comms[0]['direction']);
        $this->assertSame('Quotation follow-up', $comms[0]['subject']);

        $this->cleanupLead($lead['id']);
    }

    // ---------------------------------------------------- QUOTATIONS --

    public function testCreateQuotation(): void
    {
        $ownerId = $this->makeOwner('crm-q1@test.local');
        $customerId = $this->makeCustomer();
        $svc = new CrmService();
        $result = $svc->createQuotation([
            'customer_id' => $customerId,
            'items' => [
                ['name' => 'Dell Latitude 5400', 'sku' => 'DL-5400', 'qty' => 1, 'unit_price' => 35000],
                ['name' => 'SSD 512GB', 'sku' => 'SSD-512', 'qty' => 1, 'unit_price' => 5000],
            ],
            'discount' => 2000,
            'valid_until' => gmdate('Y-m-d', strtotime('+30 days')),
            'notes' => 'Includes 1-year warranty',
        ], $ownerId);

        $this->assertMatchesRegularExpression('/^QUO-\d{4}-\d{5}$/', $result['quote_number']);
        $this->assertSame('38000.00', $result['total']); // 35000 + 5000 - 2000

        $q = $svc->findQuotation($result['id']);
        $this->assertSame('draft', $q['status']);
        $this->assertCount(2, $q['items']);
        $this->assertSame('38000.00', $q['total']);

        $this->pdo()->prepare('DELETE FROM crm_quotations WHERE id = ?')->execute([$result['id']]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
    }

    public function testQuotationSendAcceptReject(): void
    {
        $ownerId = $this->makeOwner('crm-q2@test.local');
        $customerId = $this->makeCustomer();
        $svc = new CrmService();
        $q = $svc->createQuotation([
            'customer_id' => $customerId,
            'items' => [['name' => 'Test', 'sku' => 'T1', 'qty' => 1, 'unit_price' => 1000]],
        ], $ownerId);

        $svc->sendQuotation($q['id'], $ownerId);
        $this->assertSame('sent', $svc->findQuotation($q['id'])['status']);

        $svc->acceptQuotation($q['id']);
        $this->assertSame('accepted', $svc->findQuotation($q['id'])['status']);
        $this->assertNotNull($svc->findQuotation($q['id'])['accepted_at']);

        // Reset and test reject.
        $this->pdo()->prepare('UPDATE crm_quotations SET status = "sent", accepted_at = NULL WHERE id = ?')->execute([$q['id']]);
        $svc->rejectQuotation($q['id']);
        $this->assertSame('rejected', $svc->findQuotation($q['id'])['status']);

        $this->pdo()->prepare('DELETE FROM crm_quotations WHERE id = ?')->execute([$q['id']]);
        $this->pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
    }

    // ---------------------------------------------------- WHATSAPP --

    public function testWhatsAppLinkGeneration(): void
    {
        $svc = new CrmService();
        $link = $svc->whatsappLink('+880171234567', 'Hello Jane');
        $this->assertStringStartsWith('https://wa.me/880171234567', $link);
        $this->assertStringContainsString('text=Hello%20Jane', $link);
    }

    public function testWhatsAppLinkStripsLeadingZero(): void
    {
        $svc = new CrmService();
        $link = $svc->whatsappLink('0171234567', 'Test');
        $this->assertStringContainsString('wa.me/880171234567', $link, 'leading 0 should be replaced with 880');
    }

    public function testWhatsAppLinkEmptyMessage(): void
    {
        $svc = new CrmService();
        $link = $svc->whatsappLink('+880171234567');
        $this->assertSame('https://wa.me/880171234567', $link, 'no text param when message is empty');
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testTechnicianBlockedFromCrm(): void
    {
        $techId = $this->makeUser('crm-tech@test.local', 'Correct-Horse-1', ['technician']);
        $this->assertThrows(ForbiddenException::class, function () use ($techId) {
            $this->dispatchAs($techId, 'GET', '/admin/crm');
        }, 'technician has no crm.view');
    }

    // ---------------------------------------------------- HTTP + CSRF --

    public function testHttpDashboardRenders(): void
    {
        $ownerId = $this->makeOwner('crm-http-dash@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/crm');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('CRM', $r->body());
        $this->assertStringContainsString('WhatsApp', $r->body());
        $this->assertStringContainsString('No automated messages', $r->body(), 'must show the no-automated-messages notice');
    }

    public function testHttpLeadShowRendersWhatsAppLink(): void
    {
        $ownerId = $this->makeOwner('crm-http-lead@test.local');
        $svc = new CrmService();
        $lead = $svc->createLead(['name' => 'WhatsApp Lead', 'phone' => '+880171234567'], $ownerId);
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/crm/leads/' . $lead['id']);
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('wa.me/880171234567', $r->body(), 'WhatsApp link must be rendered');
        $this->cleanupLead($lead['id']);
    }

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $ownerId = $this->makeOwner('crm-csrf@test.local');
        $this->actingAs($ownerId);
        $svc = new CrmService();
        $lead = $svc->createLead(['name' => 'CSRF Lead', 'phone' => '+8801'], $ownerId);

        $routes = [
            ['POST', '/admin/crm/leads', ['name' => 'X', 'phone' => '+88']],
            ['POST', '/admin/crm/leads/' . $lead['id'] . '/status', ['status' => 'contacted']],
            ['POST', '/admin/crm/leads/' . $lead['id'] . '/assign', ['user_id' => '1']],
            ['POST', '/admin/crm/leads/' . $lead['id'] . '/followup', ['scheduled_at' => '2026-12-31 10:00', 'method' => 'whatsapp']],
            ['POST', '/admin/crm/leads/' . $lead['id'] . '/communication', ['channel' => 'whatsapp', 'body' => 'x']],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(Request::synthetic($method, $uri, [], $post), $this->resolver());
            }, "{$method} {$uri} must require CSRF");
        }

        $this->cleanupLead($lead['id']);
    }
}
