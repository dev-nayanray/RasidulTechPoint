<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Config;
use App\Support\Middleware;
use App\Support\Request;
use App\Support\Response;

/**
 * Applies baseline security headers to every response.
 */
final class SecurityHeadersMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        $headers = Config::get('security.headers', []);
        foreach ([
            'x_frame_options' => 'X-Frame-Options',
            'x_content_type_options' => 'X-Content-Type-Options',
            'referrer_policy' => 'Referrer-Policy',
            'permissions_policy' => 'Permissions-Policy',
            'content_security_policy' => 'Content-Security-Policy',
        ] as $key => $header) {
            if (!empty($headers[$key])) {
                $response->header($header, (string) $headers[$key]);
            }
        }

        if ($request->isSecure()) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
