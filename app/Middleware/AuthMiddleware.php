<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\ForbiddenException;
use App\Services\AuthService;
use App\Support\Middleware;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * auth  — only authenticated users may proceed.
 * guest — only unauthenticated visitors may proceed (login/register pages).
 */
final class AuthMiddleware extends Middleware
{
    public function __construct(private readonly bool $guest = false)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $checking = AuthService::check();

        if ($this->guest && $checking) {
            return Response::redirect(url('/admin/dashboard'));
        }
        if (!$this->guest && !$checking) {
            if ($request->wantsJson()) {
                return Response::json(['error' => 'Unauthenticated.'], 401);
            }
            Session::set('intended', $request->path());
            return Response::redirect(url('/login'));
        }

        return $next($request);
    }
}
