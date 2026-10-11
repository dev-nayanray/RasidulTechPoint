<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Synchronizer-token CSRF protection.
 */
final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf_token');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            Session::set('_csrf_token', $token);
        }
        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function verify(?string $submitted): bool
    {
        $known = Session::get('_csrf_token');
        return is_string($known)
            && is_string($submitted)
            && $submitted !== ''
            && hash_equals($known, $submitted);
    }
}
