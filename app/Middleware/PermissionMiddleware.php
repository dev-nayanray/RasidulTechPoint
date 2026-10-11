<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\ForbiddenException;
use App\Services\AuthService;
use App\Support\Middleware;
use App\Support\Request;
use App\Support\Response;

/**
 * permission:name  — require a single permission.
 * permission:a|b   — require ANY of the listed permissions.
 *
 * Authorization is ALWAYS enforced server-side here (and again inside
 * services for sensitive operations); hiding navigation is never security.
 */
final class PermissionMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $required = array_filter(explode('|', (string) ($this->argument ?? '')));
        if ($required === []) {
            throw new ForbiddenException('No permission configured for this route.');
        }

        foreach ($required as $permission) {
            if (AuthService::can($permission)) {
                return $next($request);
            }
        }

        app_audit('permission.denied', 'route', null, [
            'required' => implode('|', $required),
            'path' => $request->path(),
        ], $request);

        if ($request->wantsJson()) {
            return Response::json(['error' => 'Forbidden.'], 403);
        }

        throw new ForbiddenException(
            'You do not have permission to access this area (' . implode(' or ', $required) . ').'
        );
    }
}
