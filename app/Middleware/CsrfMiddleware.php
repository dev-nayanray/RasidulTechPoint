<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\CsrfTokenMismatchException;
use App\Support\Csrf;
use App\Support\Middleware;
use App\Support\Request;
use App\Support\Response;

/**
 * Rejects state-changing requests without a valid synchronizer token.
 * GET/HEAD/OPTIONS are safe methods and pass through.
 */
final class CsrfMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->realMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $submitted = $request->input('_token')
            ?? $request->header('x-csrf-token')
            ?? (json_decode($request->rawBody() ?: 'null', true)['_token'] ?? null);

        if (!Csrf::verify(is_string($submitted) ? $submitted : null)) {
            throw new CsrfTokenMismatchException();
        }

        return $next($request);
    }
}
