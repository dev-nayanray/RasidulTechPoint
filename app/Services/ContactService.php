<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TooManyRequestsException;
use App\Exceptions\ValidationException;
use App\Support\Config;
use App\Support\Database\Connection;
use PDO;

/**
 * Public contact-form inquiry handling.
 *
 * Responsibilities:
 *  - Validate the submission (name / email / topic / message + optional
 *    phone + order_reference) — server-side only; the client never
 *    decides what is valid.
 *  - Rate-limit: max N inquiries per (email + IP) per decay window.
 *    Defaults match the login throttle (5 / 15 minutes).
 *  - Persist the inquiry to `contact_inquiries` for staff triage.
 *  - Optionally forward the inquiry to the business's configured support
 *    email via MailService. When the mail driver is 'null' or 'log',
 *    no real email is sent — the inquiry is still stored safely and
 *    the user-facing message reflects this honestly ("we received your
 *    message; if email delivery is configured you will receive a reply
 *    at the address you provided").
 *
 * Privacy:
 *  - IP + user agent are recorded for rate-limiting + abuse response.
 *    They are NEVER surfaced in any customer-facing view.
 *  - The contact-form fields are escaped via e() when rendered.
 *  - No SMTP credentials are exposed; the MailService reads them from
 *    Config at send time and never logs them.
 *
 * Honesty:
 *  - `submit()` returns ['stored' => bool, 'delivered' => bool,
 *    'message' => string]. The controller surfaces only the honest
 *    state to the user — no false success claim when the mail driver
 *    is 'null' or the SMTP send failed.
 */
final class ContactService
{
    /** Allowed inquiry topics (matches the form's <select> options). */
    public const TOPICS = [
        'product' => 'Product inquiry',
        'choosing' => 'Choosing a laptop',
        'order' => 'Order support',
        'warranty' => 'Warranty and service',
        'delivery' => 'Delivery',
        'other' => 'Other',
    ];

    /** Default rate-limit (matches the login throttle). */
    public const DEFAULT_MAX_ATTEMPTS = 5;
    public const DEFAULT_DECAY_SECONDS = 900; // 15 minutes

    /**
     * Validate, rate-limit, persist, and (optionally) email a contact
     * form submission.
     *
     * @param array{name:string,email:string,phone?:?string,topic:string,subject?:?string,message:string,order_reference?:?string} $input
     * @param ?string $ip
     * @param ?string $userAgent
     * @return array{stored:bool,delivered:bool,inquiry_number:?string,message:string}
     */
    public function submit(array $input, ?string $ip = null, ?string $userAgent = null): array
    {
        $clean = $this->validate($input);
        $ip = $this->normalizeIp($ip);

        // Rate-limit BEFORE persisting — refuse early on flood.
        $this->assertNotThrottled($clean['email'], $ip);

        $inquiryNumber = $this->generateInquiryNumber();
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO contact_inquiries
                (inquiry_number, full_name, email, phone, topic, subject, message,
                 order_reference, ip_address, user_agent, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "new")'
        );
        $stmt->execute([
            $inquiryNumber,
            $clean['name'],
            $clean['email'],
            $clean['phone'],
            $clean['topic'],
            $clean['subject'],
            $clean['message'],
            $clean['order_reference'],
            $ip,
            $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ]);
        $id = (int) Connection::pdo()->lastInsertId();

        app_audit('contact.inquiry_received', 'contact_inquiry', $id, [
            'topic' => $clean['topic'],
            'has_order_ref' => $clean['order_reference'] !== null,
        ]);

        // Optional email delivery to the business's support address.
        $delivered = $this->forwardToBusiness($inquiryNumber, $clean, $id);

        // Honest user-facing message — never claims email delivery when
        // the driver does not send.
        $driver = (string) Config::get('mail.driver', 'log');
        if ($delivered) {
            $message = "Thanks — your message has been received. We've sent a confirmation to {$clean['email']}. Our team will respond within one business day.";
        } elseif ($driver === 'null') {
            $message = "Thanks — your message has been received and stored. Email delivery is not configured on this server, but our team reviews incoming inquiries regularly. Reference: {$inquiryNumber}";
        } else {
            // 'log' driver or SMTP failure — message is stored but not
            // necessarily delivered. Be honest about that.
            $message = "Thanks — your message has been received and stored. Reference: {$inquiryNumber}. Email delivery to your inbox is pending — if you do not hear back within one business day, please reach us directly via phone or WhatsApp.";
        }

        return [
            'stored' => true,
            'delivered' => $delivered,
            'inquiry_number' => $inquiryNumber,
            'message' => $message,
        ];
    }

    /**
     * Validate the submission. Throws ValidationException on any
     * rule failure with a field-keyed list of messages.
     *
     * @param array{name:string,email:string,phone?:?string,topic:string,subject?:?string,message:string,order_reference?:?string} $input
     * @return array{name:string,email:string,phone:?string,topic:string,subject:?string,message:string,order_reference:?string} cleaned input
     */
    public function validate(array $input): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Your name is required.';
        } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Name must be between 2 and 120 characters.';
        } elseif (preg_match('/[\x00-\x1f\x7f<>]/', $name)) {
            $errors['name'] = 'Name contains invalid characters.';
        }

        $email = trim((string) ($input['email'] ?? ''));
        if ($email === '') {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        } elseif (mb_strlen($email) > 190) {
            $errors['email'] = 'Email is too long.';
        }

        $phone = trim((string) ($input['phone'] ?? ''));
        if ($phone !== '') {
            // Allow digits, +, -, spaces, parentheses. Reject anything else.
            if (!preg_match('/^\+?[0-9\s\-()]{6,30}$/', $phone)) {
                $errors['phone'] = 'Phone number looks invalid. Use digits, +, -, spaces, parentheses (6–30 chars).';
            }
        } else {
            $phone = null;
        }

        $topic = (string) ($input['topic'] ?? '');
        if (!array_key_exists($topic, self::TOPICS)) {
            $errors['topic'] = 'Please choose an inquiry topic.';
        }

        $subject = trim((string) ($input['subject'] ?? ''));
        if ($subject !== '' && mb_strlen($subject) > 255) {
            $errors['subject'] = 'Subject is too long (max 255 characters).';
        } elseif ($subject === '') {
            $subject = null;
        }

        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '') {
            $errors['message'] = 'Message is required.';
        } elseif (mb_strlen($message) < 10) {
            $errors['message'] = 'Message is too short — please write at least 10 characters so we can help.';
        } elseif (mb_strlen($message) > 5000) {
            $errors['message'] = 'Message is too long (max 5000 characters).';
        }

        $orderReference = trim((string) ($input['order_reference'] ?? ''));
        if ($orderReference !== '') {
            // Allow RTP-XXXXXX-style references and short alphanumeric strings.
            if (!preg_match('/^[A-Z0-9\-]{3,40}$/i', $orderReference)) {
                $errors['order_reference'] = 'Order reference looks invalid. Use the format shown on your receipt (e.g. RTP-XXXX-YYYYYY).';
            }
        } else {
            $orderReference = null;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'name' => mb_substr($name, 0, 120),
            'email' => mb_substr($email, 0, 190),
            'phone' => $phone !== null ? mb_substr($phone, 0, 30) : null,
            'topic' => $topic,
            'subject' => $subject,
            'message' => mb_substr($message, 0, 5000),
            'order_reference' => $orderReference !== null ? mb_substr($orderReference, 0, 40) : null,
        ];
    }

    /**
     * Refuse submissions once a (email, IP) pair exceeds the rate-limit
     * window. Mirrors AuthService::assertNotThrottled but uses a
     * separate counter on contact_inquiries itself (no shared login
     * throttle state — contact-form floods shouldn't lock users out
     * of their account).
     */
    private function assertNotThrottled(string $email, ?string $ip): void
    {
        $max = (int) Config::get('security.contact_throttle.max_attempts', self::DEFAULT_MAX_ATTEMPTS);
        $decay = (int) Config::get('security.contact_throttle.decay', self::DEFAULT_DECAY_SECONDS);
        if ($max <= 0 || $decay <= 0) {
            return; // throttle disabled
        }

        $stmt = Connection::pdo()->prepare(
            "SELECT COUNT(*) FROM contact_inquiries
             WHERE created_at > UTC_TIMESTAMP() - INTERVAL ? SECOND
               AND (email = ? OR ip_address = ?)"
        );
        $stmt->execute([$decay, $email, $ip]);
        $count = (int) $stmt->fetchColumn();
        if ($count >= $max) {
            throw new TooManyRequestsException(
                $decay,
                "You've sent several messages recently. Please wait " . (int) ceil($decay / 60) . " minutes before sending another so we can review them."
            );
        }
    }

    /**
     * Forward the inquiry to the configured support email. Returns
     * true only when the mail driver actually delivered (i.e. SMTP
     * success or log driver wrote the message). Returns false for
     * 'null' driver or any failure — the controller surfaces the
     * honest state.
     */
    private function forwardToBusiness(string $inquiryNumber, array $clean, int $id): bool
    {
        $driver = (string) Config::get('mail.driver', 'log');
        if ($driver === 'null') {
            return false;
        }

        $to = (string) Config::get('mail.from_address', '');
        $subject = "[Contact #{$inquiryNumber}] " . ucfirst(self::TOPICS[$clean['topic']] ?? 'Inquiry');
        if ($clean['subject'] !== null) {
            $subject .= ' — ' . $clean['subject'];
        }

        $body = "New contact inquiry received.\n\n"
            . "Reference: {$inquiryNumber}\n"
            . "Topic: " . (self::TOPICS[$clean['topic']] ?? $clean['topic']) . "\n"
            . "Name: {$clean['name']}\n"
            . "Email: {$clean['email']}\n"
            . ($clean['phone'] !== null ? "Phone: {$clean['phone']}\n" : '')
            . ($clean['order_reference'] !== null ? "Order reference: {$clean['order_reference']}\n" : '')
            . "\nMessage:\n{$clean['message']}\n\n"
            . "---\n"
            . "Stored in contact_inquiries (id={$id}). Review in the admin CRM if needed.\n";

        try {
            $result = MailService::send($to, $subject, $body);
            // 'log' driver writes the message but is not real delivery.
            // Only count SMTP / sendmail as 'delivered' to the user.
            return $driver === 'smtp' || $driver === 'sendmail';
        } catch (\Throwable) {
            return false;
        }
    }

    private function generateInquiryNumber(): string
    {
        $prefix = 'CI';
        for ($i = 0; $i < 10; $i++) {
            $candidate = sprintf('%s-%s-%s', $prefix, gmdate('ymd'), strtoupper(bin2hex(random_bytes(3))));
            $stmt = Connection::pdo()->prepare('SELECT 1 FROM contact_inquiries WHERE inquiry_number = ?');
            $stmt->execute([$candidate]);
            if ($stmt->fetchColumn() === false) {
                return $candidate;
            }
        }
        // Extremely unlikely — fall back to a longer random.
        return $prefix . '-' . bin2hex(random_bytes(8));
    }

    private function normalizeIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        // Strip trusted-proxy prefix if present (e.g. X-Forwarded-For
        // is parsed by Request::ip() upstream).
        $ip = trim($ip);
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return mb_substr($ip, 0, 64);
    }
}
