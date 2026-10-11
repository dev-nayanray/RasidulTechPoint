<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Exceptions\TooManyRequestsException;
use App\Services\AuditService;
use App\Services\AuthService;
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
        $data = Validator::make($request->post(), [
            'email' => 'required|email|max:190',
            'password' => 'required|string|max:500',
        ], ['email' => 'Email address', 'password' => 'Password'])->validate();

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

        AuditService::record('auth.login', 'user', $user['id'], [], $request);

        $intended = Session::get('intended');
        Session::forget('intended');
        flash_success('Welcome back, ' . $user['name'] . '.');

        $target = is_string($intended) && str_starts_with($intended, '/')
            ? url($intended)
            : (AuthService::hasRole('customer') && !AuthService::can('dashboard.view')
                ? url('/account')
                : url('/admin/dashboard'));

        return Response::redirect($target);
    }

    public function destroy(Request $request): Response
    {
        if (AuthService::check()) {
            AuditService::record('auth.logout', 'user', AuthService::id(), [], $request);
        }
        AuthService::logout();
        flash_success('You have been signed out.');
        return Response::redirect(url('/login'));
    }
}
