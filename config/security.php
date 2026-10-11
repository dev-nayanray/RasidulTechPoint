<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'session' => [
        'name' => Env::get('SESSION_NAME', 'rtp_session'),
        'lifetime' => Env::int('SESSION_LIFETIME', 7200),
        'secure' => Env::bool('SESSION_SECURE', false),
        'http_only' => Env::bool('SESSION_HTTPONLY', true),
    ],

    'login_throttle' => [
        'max_attempts' => Env::int('LOGIN_MAX_ATTEMPTS', 5),
        'decay' => Env::int('LOGIN_DECAY_SECONDS', 900), // window in seconds
    ],

    'headers' => [
        'x_frame_options' => 'DENY',
        'x_content_type_options' => 'nosniff',
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'permissions_policy' => 'camera=(), microphone=(), geolocation=()',
        // CSP: styles must be 'unsafe-inline' for Tailwind utility interplay
        // in a few dynamic elements; scripts are restricted to same-origin.
        'content_security_policy' => "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
            . "script-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
    ],

    'uploads' => [
        'max_bytes' => Env::int('UPLOAD_MAX_BYTES', 4 * 1024 * 1024),
        'allowed_image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],
];
