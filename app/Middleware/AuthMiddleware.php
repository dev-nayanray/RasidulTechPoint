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
            // Already signed in: staff land on the console, customers on their account.
            $target = AuthService::can('dashboard.view')
                ? url('/admin/dashboard')
                : url('/account');
            return Response::redirect($target);
        }
        if (!$this->guest && !$checking) {
            if ($request->wantsJson()) {
                return Response::json(['error' => 'Unauthenticated.'], 401);
            }
            Session::set('intended', $request->path());
            // Console URLs send their guests to the console login; everything
            // else uses the customer login page.
            $login = str_starts_with($request->path(), '/admin')
                ? '/rtp-admin'
                : '/login';
            return Response::redirect(url($login));
        }

        return $next($request);
    }
}
