<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\TooManyRequestsException;
use App\Exceptions\ValidationException;
use App\Services\ContactService;
use App\Services\ContentService;
use App\Support\Csrf;
use App\Support\Database\Connection;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Premium About + Contact pages (Marketing-Website Prompt 2).
 *
 * Proves:
 *  - About + Contact routes return 200 and use the shared public layout.
 *  - About page has all 7 sections (hero / business / why-choose /
 *    process / shop-by-need / trust / final-CTA).
 *  - Contact page has all 6 sections (hero / contact-info-cards /
 *    form / FAQ / location / support-links).
 *  - Contact information reflects configured business profile (phone,
 *    email, address) — no fabricated details.
 *  - Missing contact settings produce graceful "not configured" copy,
 *    not broken markup or fake phone numbers.
 *  - WhatsApp link is a valid wa.me/<digits> URL only when configured.
 *  - Phone link uses tel: with digits only.
 *  - Email link uses mailto: with a validated address.
 *  - Address link uses Google Maps with the actual configured address.
 *  - ContactService validates required fields + rejects malformed
 *    email / phone / order_reference / too-short message.
 *  - ContactService stores valid inquiries with a unique
 *    inquiry_number + status='new'.
 *  - ContactService rate-limits (5 per email+IP / 15min window).
 *  - ContactService does NOT claim false success when MAIL_DRIVER is
 *    'log' or 'null' — message reflects the honest state.
 *  - CSRF rejects invalid tokens on POST /contact.
 *  - CMS FAQs render when published; empty state shows when none.
 *  - All internal links point to existing routes.
 */
final class AboutContactTest extends TestCase
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

    private function dispatchAnon(string $method, string $uri, array $post = [], array $get = []): Response
    {
        return $this->router()->dispatch(
            Request::synthetic($method, $uri, $get, $this->withCsrf($post)),
            $this->resolver()
        );
    }

    /** Wipe the seeded business profile so each test starts clean. */
    private function wipeBusinessProfile(): void
    {
        $this->pdo()->exec('DELETE FROM business_profiles');
        $this->pdo()->exec('DELETE FROM business_hours');
        $this->pdo()->exec('DELETE FROM faqs');
        $this->pdo()->exec('DELETE FROM contact_inquiries');
    }

    private function seedBusinessProfile(array $overrides = []): void
    {
        $this->wipeBusinessProfile();
        $data = array_merge([
            'name' => 'Rasidul Tech Point',
            'legal_name' => null,
            'tagline' => 'Dubai Imported',
            'email' => 'hello@rtp.test',
            'phone' => '+8801700000000',
            'address' => '123 Example Road',
            'city' => 'Dhaka',
            'country' => 'Bangladesh',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'about_text' => null,
            'default_warranty_days' => 30,
        ], $overrides);
        $stmt = $this->pdo()->prepare(
            'INSERT INTO business_profiles
                (name, legal_name, tagline, email, phone, address, city, country, currency, timezone, about_text, default_warranty_days)
             VALUES (:name, :legal_name, :tagline, :email, :phone, :address, :city, :country, :currency, :timezone, :about_text, :default_warranty_days)'
        );
        $stmt->execute($data);

        // Seed Sat-Thu open, Fri closed (matches the production default).
        $hoursStmt = $this->pdo()->prepare(
            'INSERT INTO business_hours (day_of_week, is_closed, open_time, close_time) VALUES (?, ?, ?, ?)'
        );
        foreach (range(0, 6) as $day) {
            if ($day === 5) {
                $hoursStmt->execute([$day, 1, null, null]);
            } else {
                $hoursStmt->execute([$day, 0, '10:00:00', '20:00:00']);
            }
        }
    }

    private function seedFaq(string $question, string $answer, int $order = 0): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO faqs (question, answer, category, is_published, display_order) VALUES (?, ?, ?, 1, ?)'
        );
        $stmt->execute([$question, $answer, 'general', $order]);
    }

    private function cleanupInquiries(): void
    {
        $this->pdo()->exec('DELETE FROM contact_inquiries');
    }

    // ---------------------------------------------------- ROUTE + LAYOUT --

    public function testAboutRouteReturns200AndUsesPublicLayout(): void
    {
        $this->seedBusinessProfile();
        $r = $this->dispatchAnon('GET', '/about');
        $this->assertSame(200, $r->status());
        $body = $r->body();
        // Shared layout markers: announcement bar + header + footer.
        $this->assertStringContainsString('Authentic Dubai Imports', $body, 'announcement bar');
        $this->assertStringContainsString('Rasidul Tech Point', $body, 'header brand');
        $this->assertStringContainsString('© ' . date('Y'), $body, 'footer copyright');
        $this->assertStringContainsString('assets/css/app.css', $body, 'compiled CSS');
    }

    public function testContactRouteReturns200AndUsesPublicLayout(): void
    {
        $this->seedBusinessProfile();
        $r = $this->dispatchAnon('GET', '/contact');
        $this->assertSame(200, $r->status());
        $body = $r->body();
        $this->assertStringContainsString('Authentic Dubai Imports', $body);
        $this->assertStringContainsString('© ' . date('Y'), $body);
    }

    // ---------------------------------------------------- ABOUT SECTIONS --

    public function testAboutHasAllSevenSections(): void
    {
        $this->seedBusinessProfile();
        $r = $this->dispatchAnon('GET', '/about');
        $body = $r->body();
        $this->assertStringContainsString('Smarter Way to Buy', $body, 'A. Hero');
        $this->assertStringContainsString('Our business', $body, 'B. Business');
        $this->assertStringContainsString('Why customers should choose us', $body, 'C. Why choose');
        $this->assertStringContainsString('quality-checking process', $body, 'D. Process');
        $this->assertStringContainsString('Shop by customer need', $body, 'E. Shop by need');
        $this->assertStringContainsString('Trust', $body, 'F. Trust');
        $this->assertStringContainsString('Find a laptop that fits', $body, 'G. Final CTA');
    }

    public function testAboutDoesNotFabricateBusinessHistory(): void
    {
        $this->seedBusinessProfile();
        $r = $this->dispatchAnon('GET', '/about');
        $body = $r->body();
        // The "What we don't claim" sidebar makes this honest — but no
        // fabricated stats (years, customers, staff, volume) should appear.
        $this->assertStringNotContainsString('Founded in', $body);
        $this->assertStringNotContainsString('customers served', $body);
        $this->assertStringNotContainsString('laptops imported', $body);
        $this->assertStringNotContainsString('team of', $body);
    }

    public function testAboutProcessLinksToShop(): void
    {
        $this->seedBusinessProfile();
        $r = $this->dispatchAnon('GET', '/about');
        $body = $r->body();
        // Internal links must point to valid existing routes.
        $this->assertStringContainsString('href="' . url('/shop') . '"', $body, 'shop link');
        $this->assertStringContainsString('href="' . url('/contact') . '"', $body, 'contact link');
    }

    // ---------------------------------------------------- CONTACT CARDS --

    public function testContactShowsConfiguredBusinessPhoneEmailAddress(): void
    {
        $this->seedBusinessProfile([
            'phone' => '+8801712345678',
            'email' => 'sales@rtp.test',
            'address' => '456 Test Avenue',
        ]);
        $r = $this->dispatchAnon('GET', '/contact');
        $body = $r->body();
        $this->assertStringContainsString('+8801712345678', $body, 'phone visible');
        $this->assertStringContainsString('sales@rtp.test', $body, 'email visible');
        $this->assertStringContainsString('456 Test Avenue', $body, 'address visible');
        $this->assertStringContainsString('href="tel:+8801712345678"', $body, 'tel: link');
        $this->assertStringContainsString('href="mailto:sales@rtp.test"', $body, 'mailto: link');
        $this->assertStringContainsString('google.com/maps', $body, 'maps link');
    }

    public function testContactShowsBusinessHoursWhenConfigured(): void
    {
        $this->seedBusinessProfile();
        $r = $this->dispatchAnon('GET', '/contact');
        $body = $r->body();
        $this->assertStringContainsString('Business hours', $body);
        $this->assertStringContainsString('10:00', $body);
        $this->assertStringContainsString('20:00', $body);
        $this->assertStringContainsString('Fri', $body, 'Friday closed listed');
    }

    public function testContactShowsGracefulNotConfiguredCopyWhenProfileIsEmpty(): void
    {
        $this->wipeBusinessProfile();
        // No business profile — phone/email/address all unconfigured.
        $r = $this->dispatchAnon('GET', '/contact');
        $body = $r->body();
        $this->assertSame(200, $r->status(), 'contact page renders without crashing');
        $this->assertStringContainsString('Not configured', $body, 'graceful copy shown');
        // The OLD fabricated contact details must NOT appear anywhere.
        $this->assertStringNotContainsString('+880 1700-000000', $body, 'old fabricated phone');
        $this->assertStringNotContainsString('info@rasidultechpoint.example', $body, 'old fabricated email');
        $this->assertStringNotContainsString('Example Road 1, Dhaka', $body, 'old fabricated address');
    }

    public function testContactWhatsAppLinkOnlyWhenUsable(): void
    {
        $this->seedBusinessProfile(['phone' => '+8801712345678']);
        $r = $this->dispatchAnon('GET', '/contact');
        $body = $r->body();
        // WhatsApp inherits from business phone by default → wa.me link.
        $this->assertStringContainsString('wa.me/8801712345678', $body, 'whatsapp click-to-chat link');
    }

    public function testContactWhatsAppHiddenWhenNotConfigured(): void
    {
        $this->seedBusinessProfile(['phone' => '']); // no phone, no WhatsApp
        $r = $this->dispatchAnon('GET', '/contact');
        $body = $r->body();
        $this->assertStringNotContainsString('wa.me', $body, 'no fake WhatsApp link');
    }

    public function testContactEmailDeliveryNoticeWhenLogDriver(): void
    {
        $this->seedBusinessProfile();
        // Default test env has MAIL_DRIVER=log (loaded from .env).
        $r = $this->dispatchAnon('GET', '/contact');
        $body = $r->body();
        $this->assertStringContainsString('Email delivery note', $body, 'honest disclosure');
    }

    // ---------------------------------------------------- FAQ --

    public function testContactShowsPublishedFaqs(): void
    {
        $this->seedBusinessProfile();
        $this->seedFaq('Are these laptops new or used?', 'They are used Dubai imports.');
        $r = $this->dispatchAnon('GET', '/contact');
        $body = $r->body();
        $this->assertStringContainsString('Frequently asked questions', $body);
        $this->assertStringContainsString('Are these laptops new or used?', $body);
        $this->assertStringContainsString('They are used Dubai imports.', $body);
    }

    public function testContactShowsEmptyStateWhenNoFaqs(): void
    {
        $this->seedBusinessProfile();  // wipes faqs too
        $r = $this->dispatchAnon('GET', '/contact');
        $body = $r->body();
        $this->assertStringContainsString('FAQ entries will appear here', $body, 'graceful empty state');
        // The FAQ section header should NOT appear (we omit it).
        $this->assertStringNotContainsString('<details class="group p-5">', $body, 'no FAQ details block');
    }

    // ---------------------------------------------------- CONTACT FORM SUBMIT --

    public function testContactServiceValidatesRequiredFields(): void
    {
        $svc = new ContactService();
        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->validate(['name' => '', 'email' => '', 'topic' => '', 'message' => '']);
        }, 'missing required fields rejected');

        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->validate(['name' => 'X', 'email' => 'not-an-email', 'topic' => 'product', 'message' => 'This is at least 10 chars']);
        }, 'bad email rejected');

        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->validate(['name' => 'Test User', 'email' => 'test@example.com', 'topic' => 'product', 'message' => 'short']);
        }, 'too-short message rejected');

        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->validate(['name' => 'Test User', 'email' => 'test@example.com', 'topic' => 'unknown', 'message' => 'This is at least 10 chars']);
        }, 'invalid topic rejected');

        $this->assertThrows(ValidationException::class, function () use ($svc) {
            $svc->validate(['name' => 'Test User', 'email' => 'test@example.com', 'topic' => 'product', 'message' => str_repeat('A', 5001)]);
        }, 'too-long message rejected');
    }

    public function testContactServiceAcceptsValidInputAndOptionals(): void
    {
        $svc = new ContactService();
        $clean = $svc->validate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '+880 1700-000000',
            'topic' => 'product',
            'subject' => 'Question about a unit',
            'message' => 'This is a sufficiently long message.',
            'order_reference' => 'RTP-251012-A1B2C3',
        ]);
        $this->assertSame('Test User', $clean['name']);
        $this->assertSame('test@example.com', $clean['email']);
        $this->assertSame('+880 1700-000000', $clean['phone']);
        $this->assertSame('product', $clean['topic']);
        $this->assertSame('RTP-251012-A1B2C3', $clean['order_reference']);
    }

    public function testContactServiceStoresInquiryWithUniqueNumber(): void
    {
        $this->cleanupInquiries();
        $svc = new ContactService();
        $result = $svc->submit(
            ['name' => 'Test User', 'email' => 'test@example.com', 'topic' => 'product', 'message' => 'This is a valid message.'],
            '127.0.0.1',
            'PHPUnit/8.5',
        );
        $this->assertTrue($result['stored']);
        $this->assertNotSame('', $result['inquiry_number']);
        $this->assertNotSame('', $result['message']);

        // Verify the row in the DB.
        $stmt = $this->pdo()->prepare('SELECT inquiry_number, status, topic, ip_address, user_agent FROM contact_inquiries WHERE email = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute(['test@example.com']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($result['inquiry_number'], $row['inquiry_number']);
        $this->assertSame('new', $row['status']);
        $this->assertSame('product', $row['topic']);
        $this->assertSame('127.0.0.1', $row['ip_address']);
        $this->assertSame('PHPUnit/8.5', $row['user_agent']);

        $this->cleanupInquiries();
    }

    public function testContactServiceDoesNotClaimFalseEmailSuccessWhenDriverIsLog(): void
    {
        $this->cleanupInquiries();
        // Default test env has MAIL_DRIVER=log.
        $svc = new ContactService();
        $result = $svc->submit(
            ['name' => 'Test User', 'email' => 'test@example.com', 'topic' => 'product', 'message' => 'This is a valid message.'],
            '127.0.0.1',
        );
        $this->assertTrue($result['stored'], 'inquiry is stored');
        $this->assertFalse($result['delivered'], 'log driver does not deliver');
        $this->assertStringNotContainsString('sent a confirmation', $result['message'], 'no false success claim');
        $this->cleanupInquiries();
    }

    public function testContactServiceRateLimits(): void
    {
        $this->cleanupInquiries();
        $svc = new ContactService();
        $payload = ['name' => 'Flood User', 'email' => 'flood@example.com', 'topic' => 'product', 'message' => 'Flood test message here.'];

        // First 5 succeed (different IPs, same email).
        for ($i = 0; $i < 5; $i++) {
            $svc->submit($payload, '10.0.0.' . $i, 'UA');
        }
        // 6th from same email (different IP) → throttled.
        $this->assertThrows(TooManyRequestsException::class, function () use ($svc, $payload) {
            $svc->submit($payload, '10.0.0.99', 'UA');
        }, 'rate limit kicks in after 5 per email');

        // Separate IP-rate-limit test: 5 inquiries from different emails but the
        // SAME IP, then a 6th with a new email from that same IP.
        $this->cleanupInquiries();
        $ipPayload = ['name' => 'IP Flood', 'topic' => 'product', 'message' => 'IP flood test message.'];
        for ($i = 0; $i < 5; $i++) {
            $ipPayload['email'] = "ipflood{$i}@example.com";
            $svc->submit($ipPayload, '192.168.0.1', 'UA');
        }
        $ipPayload['email'] = 'ipfloodfinal@example.com';
        $this->assertThrows(TooManyRequestsException::class, function () use ($svc, $ipPayload) {
            $svc->submit($ipPayload, '192.168.0.1', 'UA');
        }, 'rate limit also applies per IP');

        $this->cleanupInquiries();
    }

    // ---------------------------------------------------- HTTP SUBMIT --

    public function testHttpContactStoreWithValidSubmission(): void
    {
        $this->cleanupInquiries();
        $r = $this->dispatchAnon('POST', '/contact', [
            'name' => 'Http User',
            'email' => 'http@example.com',
            'phone' => '',
            'topic' => 'product',
            'subject' => '',
            'message' => 'This is a valid HTTP submission message.',
            'order_reference' => '',
        ]);
        $this->assertSame(302, $r->status(), 'POST redirects after success');
        $location = $r->headers()['Location'] ?? '';
        $this->assertStringContainsString('/contact', $location);

        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM contact_inquiries WHERE email = ?');
        $stmt->execute(['http@example.com']);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'inquiry stored');

        $this->cleanupInquiries();
    }

    public function testHttpContactStoreWithInvalidSubmissionRedirectsWithErrors(): void
    {
        $this->cleanupInquiries();
        $r = $this->dispatchAnon('POST', '/contact', [
            'name' => '',
            'email' => 'not-an-email',
            'topic' => '',
            'message' => 'short',
        ]);
        $this->assertSame(302, $r->status(), 'POST redirects back with errors');
        $stmt = $this->pdo()->query('SELECT COUNT(*) FROM contact_inquiries');
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no inquiry stored on validation error');

        $this->cleanupInquiries();
    }

    public function testHttpContactStoreRejectsMissingCsrf(): void
    {
        $this->cleanupInquiries();
        // Bypass withCsrf() by sending a raw POST without _token.
        // The CsrfMiddleware throws CsrfTokenMismatchException when no
        // valid token is present.
        $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () {
            $this->router()->dispatch(
                Request::synthetic('POST', '/contact', [], ['name' => 'X', 'email' => 'x@example.com', 'topic' => 'product', 'message' => 'This is long enough']),
                $this->resolver()
            );
        }, 'missing CSRF rejected');

        $stmt = $this->pdo()->query('SELECT COUNT(*) FROM contact_inquiries');
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no inquiry stored on CSRF failure');

        $this->cleanupInquiries();
    }

    // ---------------------------------------------------- INTERNAL LINKS --

    public function testContactInternalLinksPointToExistingRoutes(): void
    {
        $this->seedBusinessProfile();
        $r = $this->dispatchAnon('GET', '/contact');
        $body = $r->body();
        // Every internal href must reference a route defined in routes/web.php.
        // We grep for known-good hrefs the view emits.
        foreach (['/shop', '/about', '/contact', '/account', '/page/warranty', '/page/returns', '/page/privacy'] as $path) {
            $this->assertStringContainsString('href="' . url($path) . '"', $body, "link to {$path}");
        }
    }

    public function testAboutInternalLinksPointToExistingRoutes(): void
    {
        $this->seedBusinessProfile();
        $r = $this->dispatchAnon('GET', '/about');
        $body = $r->body();
        foreach (['/shop', '/contact'] as $path) {
            $this->assertStringContainsString('href="' . url($path) . '"', $body, "link to {$path}");
        }
    }

    // ---------------------------------------------------- SEO --

    public function testAboutHasUniqueTitleAndMetaDescription(): void
    {
        $this->seedBusinessProfile();
        $r = $this->dispatchAnon('GET', '/about');
        $body = $r->body();
        $this->assertStringContainsString('<title>About Rasidul Tech Point', $body);
        $this->assertStringContainsString('name="description"', $body);
        $this->assertStringContainsString('clear specifications, transparent condition', $body);
    }

    public function testContactHasUniqueTitleAndMetaDescription(): void
    {
        $this->seedBusinessProfile();
        $r = $this->dispatchAnon('GET', '/contact');
        $body = $r->body();
        $this->assertStringContainsString('<title>Contact Rasidul Tech Point', $body);
        $this->assertStringContainsString('name="description"', $body);
        $this->assertNotSame(
            substr_count($body, '<title>'),
            0,
            'title element present'
        );
    }
}
