<?php

declare(strict_types=1);

namespace App\Controllers\Storefront;

use App\Exceptions\TooManyRequestsException;
use App\Exceptions\ValidationException;
use App\Services\AuditService;
use App\Services\ContactService;
use App\Services\ContentService;
use App\Services\SettingService;
use App\Support\Config;
use App\Support\Database\Connection;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Storefront content pages: About + Contact.
 *
 * Both pages are rendered with the shared `layouts/public.php` shell
 * (announcement bar + premium header + footer). Content (hero / FAQ)
 * is pulled from the CMS when available; otherwise the inline copy
 * ships as the default. No fabricated contact details, customer
 * testimonials, or business history.
 *
 * Contact form submissions go through ContactService: validation,
 * rate-limit, persistent storage in `contact_inquiries`, and optional
 * email forward (only when MAIL_DRIVER is SMTP/sendmail — log/null
 * drivers store the message but do not deliver).
 */
final class PageController
{
    public function about(): Response
    {
        $content = new ContentService();

        return Response::html(View::render('storefront/about', [
            'pageTitle' => 'About',
            'metaTitle' => 'About Rasidul Tech Point — Dubai Imported Used Laptops',
            'metaDescription' => 'Rasidul Tech Point helps customers explore Dubai-imported used laptops with clear specifications, transparent condition information, and prices suited to different budgets.',
            // CMS-managed blocks — admin can override the inline copy.
            'heroBlock' => $content->publishedBlock('about_hero') ?? '',
            'businessBlock' => $content->publishedBlock('about_business') ?? '',
            'whyChooseBlock' => $content->publishedBlock('about_why_choose') ?? '',
            'processBlock' => $content->publishedBlock('about_process') ?? '',
            'trustBlock' => $content->publishedBlock('about_trust') ?? '',
            'finalCtaBlock' => $content->publishedBlock('about_final_cta') ?? '',
        ]));
    }

    public function contact(): Response
    {
        $content = new ContentService();
        $business = $this->businessProfile();
        $hours = $this->businessHoursSummary();

        // WhatsApp: dedicated setting (contact_whatsapp). Falls back to
        // business_profiles.phone when set; never fabricated.
        $whatsapp = (string) SettingService::get('contact_whatsapp', '');
        if ($whatsapp === '' && !empty($business['phone'])) {
            // Only inherit from the business profile when the admin has
            // not opted out (i.e. the phone IS a WhatsApp-capable number).
            $inherit = (string) SettingService::get('contact_whatsapp_inherits_phone', '1');
            if ($inherit === '1') {
                $whatsapp = (string) $business['phone'];
            }
        }
        $whatsappDigits = $whatsapp !== '' ? preg_replace('/[^0-9]/', '', $whatsapp) : '';
        $whatsappUsable = strlen($whatsappDigits) >= 8 && strlen($whatsappDigits) <= 15;

        // Email: from business_profiles (set by the seeder or admin).
        $email = (string) ($business['email'] ?? '');
        $emailValid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

        // Address: composed from address + city + country when any are set.
        $addressParts = array_filter([
            $business['address'] ?? null,
            $business['city'] ?? null,
            $business['country'] ?? null,
        ]);
        $address = implode(', ', $addressParts);

        // Inquiry topics — drive both the <select> + the form validation.
        $topics = ContactService::TOPICS;

        // Published FAQs from the CMS (Prompt 36). Empty list is fine.
        $faqs = $content->publishedFaqs();

        // Old input for re-populating the form on validation error.
        // Session::flashInput stores up to 30 keys; we read individual
        // fields in the view via Session::old('field', '').
        $old = [
            'name' => Session::old('name', ''),
            'email' => Session::old('email', ''),
            'phone' => Session::old('phone', ''),
            'topic' => Session::old('topic', ''),
            'subject' => Session::old('subject', ''),
            'message' => Session::old('message', ''),
            'order_reference' => Session::old('order_reference', ''),
        ];

        return Response::html(View::render('storefront/contact', [
            'pageTitle' => 'Contact',
            'metaTitle' => 'Contact Rasidul Tech Point — Sales, Warranty & Order Support',
            'metaDescription' => 'Need help choosing a laptop, understanding its condition, or asking about an order? Get in touch with Rasidul Tech Point.',
            'business' => $business,
            'hours' => $hours,
            'whatsapp' => $whatsapp,
            'whatsappUsable' => $whatsappUsable,
            'email' => $email,
            'emailValid' => $emailValid,
            'address' => $address,
            'topics' => $topics,
            'faqs' => $faqs,
            'old' => $old,
            'errors' => [],
            // Indicates whether email delivery is real (SMTP) or
            // store-only (log/null). The view shows this honestly.
            'emailDeliveryConfigured' => in_array(
                (string) Config::get('mail.driver', 'log'),
                ['smtp', 'sendmail'],
                true,
            ),
        ]));
    }

    /**
     * Handle a contact form submission. Validates, rate-limits, stores
     * in contact_inquiries, and optionally forwards via email. The
     * honest outcome is surfaced to the user — no false success claim
     * when email delivery is not configured.
     */
    public function contactStore(Request $request): Response
    {
        $input = [
            'name' => (string) $request->input('name', ''),
            'email' => (string) $request->input('email', ''),
            'phone' => (string) $request->input('phone', ''),
            'topic' => (string) $request->input('topic', ''),
            'subject' => (string) $request->input('subject', ''),
            'message' => (string) $request->input('message', ''),
            'order_reference' => (string) $request->input('order_reference', ''),
        ];

        try {
            $svc = new ContactService();
            $result = $svc->submit($input, $request->ip(), $request->userAgent());
            flash_success($result['message']);
            AuditService::record('contact.submitted', 'contact_inquiry', null, [
                'topic' => $input['topic'],
                'stored' => $result['stored'],
                'delivered' => $result['delivered'],
                'inquiry_number' => $result['inquiry_number'],
            ], $request);
            return Response::redirect(url('/contact#contact-form'));
        } catch (ValidationException $e) {
            Session::flashInput($input);
            foreach ($e->errors as $field => $msg) {
                flash_error("{$field}: {$msg}");
            }
            return Response::redirect(url('/contact#contact-form'));
        } catch (TooManyRequestsException $e) {
            Session::flashInput($input);
            flash_error($e->getMessage());
            return Response::redirect(url('/contact#contact-form'));
        }
    }

    /**
     * Read the business profile (set by the seeder + admin settings).
     * Single row — keyed by id=1.
     */
    private function businessProfile(): array
    {
        $stmt = Connection::pdo()->prepare('SELECT * FROM business_profiles ORDER BY id LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : [];
    }

    /**
     * Summarize business_hours into a human-readable string. Sunday=0,
     * Saturday=6. Returns null when no hours are configured (so the
     * view can omit the section cleanly).
     */
    private function businessHoursSummary(): ?string
    {
        $rows = Connection::pdo()->query('SELECT * FROM business_hours ORDER BY day_of_week')->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $open = [];
        $closed = [];
        foreach ($rows as $r) {
            $day = $dayNames[(int) $r['day_of_week']] ?? '?';
            if ((int) $r['is_closed'] === 1) {
                $closed[] = $day;
            } else {
                $openTime = substr((string) $r['open_time'], 0, 5);
                $closeTime = substr((string) $r['close_time'], 0, 5);
                $open[] = "{$day} {$openTime}–{$closeTime}";
            }
        }
        $bits = [];
        if ($open !== []) $bits[] = 'Open: ' . implode(', ', $open);
        if ($closed !== []) $bits[] = 'Closed: ' . implode(', ', $closed);
        return implode(' · ', $bits);
    }
}
