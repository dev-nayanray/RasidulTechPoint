<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\Logger;

/**
 * Mail abstraction. Only a log driver ships with the foundation: messages
 * are appended to storage/logs/mail.log (outside the public directory).
 *
 * In LOCAL development the forgot-password flow additionally returns the
 * reset link to the controller which may display it for testing — this is
 * hard-disabled in production by APP_ENV check.
 */
final class MailService
{
    /** @return array{sent:bool,dev_link:?string} */
    public static function send(string $to, string $subject, string $body, ?string $devLink = null): array
    {
        $driver = (string) Config::get('mail.driver', 'log');
        $isLocal = Config::get('app.env') === 'local';

        if ($driver === 'null') {
            return ['sent' => false, 'dev_link' => null];
        }

        $message = "To: {$to}\nSubject: {$subject}\nDate: " . gmdate('c')
            . "\n\n{$body}\n\n---\n";

        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        file_put_contents($dir . '/mail.log', $message, FILE_APPEND | LOCK_EX);

        // Safe local-only testing convenience for password reset links.
        $exposeLink = $devLink !== null && $isLocal && Config::get('mail.driver') === 'log';

        return ['sent' => true, 'dev_link' => $exposeLink ? $devLink : null];
    }
}
