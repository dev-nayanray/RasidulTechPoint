<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Exceptions\TooManyRequestsException;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

final class LoginController
{
    public function show(): Response
    {
        return Response::html(View::render('auth/login', [
            'pageTitle' => 'Sign in',
        ], layout: 'layouts/guest'));
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);

        try {
            $user = AuthService::attempt($data['email'], $data['password'], $request);
        } catch (TooManyRequestsException $e) {
            flash_error($e->getMessage());
            Session::flashInput($request->post());
            return Response::redirect(url('/login'));
        }

        if ($user === null) {
            // Generic message: never reveals whether the email exists.
            flash_error('These credentials do not match our records.');
            Session::flashInput($request->post());
            return Response::redirect(url('/login'));
        }

        if (AuthService::can('dashboard.view')) {
            // /login is the customer page — staff sign in at the management
            // console entry point instead. The attempt is undone so the
            // customer page never opens a staff session.
            AuditService::record('auth.staff_redirected_to_console', 'user', $user['id'], [], $request);
            AuthService::deauthenticate();
            flash_error('Staff accounts must sign in from the management console login page.');
            Session::flashInput($request->post());
            return Response::redirect(url('/rtp-admin'));
        }

        AuditService::record('auth.login', 'user', $user['id'], [], $request);

        $intended = Session::get('intended');
        Session::forget('intended');
        flash_success('Welcome back, ' . $user['name'] . '.');

        $target = is_string($intended) && str_starts_with($intended, '/')
            ? url($intended)
            : url('/account');

        return Response::redirect($target);
    }

    /**
     * Management console entry point. The slug is deliberately not the
     * obvious /admin/login — it is the semi-secret staff door.
     */
    public function adminShow(): Response
    {
        return Response::html(View::render('auth/admin-login', [
            'pageTitle' => 'Management console',
        ], layout: 'layouts/guest'));
    }

    public function adminStore(Request $request): Response
    {
        $data = $this->validated($request);

        try {
            $user = AuthService::attempt($data['email'], $data['password'], $request);
        } catch (TooManyRequestsException $e) {
            flash_error($e->getMessage());
            Session::flashInput($request->post());
            return Response::redirect(url('/rtp-admin'));
        }

        if ($user === null) {
            flash_error('These credentials do not match our records.');
            Session::flashInput($request->post());
            return Response::redirect(url('/rtp-admin'));
        }

        if (!AuthService::can('dashboard.view')) {
            // Customer credentials are valid but have no console access.
            // Same generic error as a wrong password: the console page must
            // not confirm whether an account exists or what role it has.
            AuditService::record('auth.console_login_rejected', 'user', $user['id'], [], $request);
            Logger::security('Non-staff login rejected on management console', [
                'user_id' => $user['id'],
                'ip' => $request->ip(),
                'correlation_id' => $request->correlationId(),
            ]);
            AuthService::deauthenticate();
            flash_error('These credentials do not match our records.');
            Session::flashInput($request->post());
            return Response::redirect(url('/rtp-admin'));
        }

        AuditService::record('auth.login', 'user', $user['id'], [], $request);

        $intended = Session::get('intended');
        Session::forget('intended');
        flash_success('Welcome back, ' . $user['name'] . '.');

        $target = is_string($intended) && str_starts_with($intended, '/')
            ? url($intended)
            : url('/admin/dashboard');

        return Response::redirect($target);
    }

    public function destroy(Request $request): Response
    {
        $wasStaff = AuthService::can('dashboard.view');
        if (AuthService::check()) {
            AuditService::record('auth.logout', 'user', AuthService::id(), [], $request);
        }
        AuthService::logout();
        flash_success('You have been signed out.');
        return Response::redirect(url($wasStaff ? '/rtp-admin' : '/login'));
    }

    /** @return array{email:string,password:string} */
    private function validated(Request $request): array
    {
        return Validator::make($request->post(), [
            'email' => 'required|email|max:190',
            'password' => 'required|string|max:500',
        ], ['email' => 'Email address', 'password' => 'Password'])->validate();
    }
}
