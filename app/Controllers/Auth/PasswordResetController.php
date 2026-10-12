<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Exceptions\ValidationException;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\MailService;
use App\Services\PasswordResetService;
use App\Support\Config;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

final class PasswordResetController
{
    public function requestForm(): Response
    {
        return Response::html(View::render('auth/forgot-password', [
            'pageTitle' => 'Forgot password',
        ], layout: 'layouts/guest'));
    }

    public function sendResetEmail(Request $request): Response
    {
        $data = Validator::make($request->post(), [
            'email' => 'required|email|max:190',
        ], ['email' => 'Email address'])->validate();

        $result = (new PasswordResetService())->createToken($data['email']);

        // Identical flash/response either way — no account existence disclosure.
        $devHint = '';
        if ($result['token'] !== null) {
            $link = url('/reset-password?token=' . urlencode($result['token']));
            MailService::send(
                $result['user']['email'],
                'Reset your Rasidul Tech Point password',
                "Hello {$result['user']['name']},\n\nUse the link below to reset your password. "
                . "It expires in 60 minutes and can be used once:\n\n{$link}\n\n"
                . "If you did not request this, you can ignore this email.",
                $link
            );
            AuditService::record('auth.password_reset_requested', 'user', $result['user']['id'], [], $request);

            // Local-only testing convenience: expose the link when the log
            // mail driver runs in local env (production never shows this).
            if (Config::get('app.env') === 'local' && Config::get('mail.driver') === 'log') {
                $devHint = ' DEV: ' . $link;
            }
        }

        flash_success('If an account exists for that email, a reset link has been sent.' . $devHint);

        return Response::redirect(url('/login'));
    }

    public function resetForm(Request $request): Response
    {
        return Response::html(View::render('auth/reset-password', [
            'pageTitle' => 'Reset password',
            'token' => (string) $request->query('token', ''),
        ], layout: 'layouts/guest'));
    }

    public function updatePassword(Request $request): Response
    {
        try {
            $data = Validator::make($request->post(), [
                'token' => 'required|string|max:128',
                'password' => 'required|string|min:10|confirmed|max:500',
            ], ['password' => 'New password'])->validate();
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/reset-password?token=' . urlencode((string) $request->input('token', ''))));
        }

        try {
            $account = (new PasswordResetService())->resetPassword($data['token'], $data['password']);
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/forgot-password'));
        }

        AuditService::record('auth.password_reset_completed', null, null, [
            'account' => $account['email'],
        ], $request);

        // Send the user to the login page that matches their account so a
        // staff member is not bounced by the customer-only /login form.
        $stmt = \App\Support\Database\Connection::pdo()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([strtolower(trim((string) $account['email']))]);
        $userId = (int) $stmt->fetchColumn();
        $login = $userId !== 0 && AuthService::isStaff($userId) ? '/rtp-admin' : '/login';

        flash_success('Your password has been reset. You can sign in now.');
        return Response::redirect(url($login));
    }
}
