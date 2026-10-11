<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\TooManyRequestsException;
use App\Services\AuthService;
use App\Support\Session;
use App\Services\PasswordResetService;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    public function testSuccessfulLogin(): void
    {
        $id = $this->makeUser('auth-ok@test.local', 'Correct-Horse-1');
        // give a back-office role so roles load
        $this->pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')
            ->execute([$id, $this->roleId('manager')]);

        $user = AuthService::attempt('auth-ok@test.local', 'Correct-Horse-1', $this->syntheticRequest());
        $this->assertNotNull($user);
        $this->assertSame($id, $user['id']);
        $this->assertTrue(AuthService::check());
        $this->assertContains('manager', AuthService::rolesFor($id));
    }

    public function testInvalidPasswordFails(): void
    {
        $this->makeUser('auth-bad@test.local', 'Correct-Horse-1');
        $user = AuthService::attempt('auth-bad@test.local', 'wrong-password', $this->syntheticRequest());
        $this->assertNull($user);
        $this->assertFalse(AuthService::check());
    }

    public function testUnknownEmailFailsIdentically(): void
    {
        $user = AuthService::attempt('ghost@nowhere.test', 'whatever-pass', $this->syntheticRequest());
        $this->assertNull($user);
    }

    public function testDeactivatedAccountCannotLogin(): void
    {
        $id = $this->makeUser('inactive@test.local', 'Correct-Horse-1');
        $this->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);

        $user = AuthService::attempt('inactive@test.local', 'Correct-Horse-1', $this->syntheticRequest());
        $this->assertNull($user);
    }

    public function testLoginThrottlingKicksIn(): void
    {
        $email = 'throttle@test.local';
        $request = $this->syntheticRequest();

        for ($i = 0; $i < 5; $i++) {
            AuthService::attempt($email, 'wrong', $request);
        }

        $this->assertThrows(TooManyRequestsException::class, function () use ($email, $request) {
            AuthService::attempt($email, 'wrong', $request);
        }, '6th attempt within the window must be throttled');
    }

    public function testSuccessfulAttemptResetsFailureCount(): void
    {
        $email = 'recover@test.local';
        $request = $this->syntheticRequest();
        $this->makeUser($email, 'Right-Pass-123');

        // four failures — below the limit
        for ($i = 0; $i < 4; $i++) {
            AuthService::attempt($email, 'wrong', $request);
        }
        // success records a successful attempt
        $user = AuthService::attempt($email, 'Right-Pass-123', $request);
        $this->assertNotNull($user);
    }

    public function testLogoutInvalidatesSession(): void
    {
        $id = $this->makeUser('logout@test.local', 'Correct-Horse-1');
        AuthService::attempt('logout@test.local', 'Correct-Horse-1', $this->syntheticRequest());
        $this->assertTrue(AuthService::check());

        AuthService::logout();
        $this->assertFalse(AuthService::check());
        $this->assertNull(Session::get('user_id'));
        $this->assertSame([], $this->fakeSession);
    }

    public function testPasswordResetLifecycle(): void
    {
        $id = $this->makeUser('reset@test.local', 'Old-Password-123');
        $service = new PasswordResetService();

        $result = $service->createToken('reset@test.local');
        $this->assertNotNull($result['token']);

        // Token works once.
        $account = $service->resetPassword($result['token'], 'New-Password-456');
        $this->assertSame('reset@test.local', $account['email']);

        // New password verifies.
        $hash = $this->pdo()->prepare('SELECT password FROM users WHERE id = ?');
        $hash->execute([$id]);
        $this->assertTrue(password_verify('New-Password-456', (string) $hash->fetchColumn()));

        // Reuse of the same token fails.
        $this->assertThrows(\App\Exceptions\ValidationException::class,
            fn () => $service->resetPassword($result['token'], 'Another-Pass-789'));
    }

    public function testPasswordResetUnknownEmailReturnsNullToken(): void
    {
        $result = (new PasswordResetService())->createToken('missing@nowhere.test');
        $this->assertNull($result['token']);
        $this->assertNull($result['user']);
    }

    public function testExpiredTokenRejected(): void
    {
        $id = $this->makeUser('expiry@test.local', 'Old-Password-123');
        $service = new PasswordResetService();
        $result = $service->createToken('expiry@test.local');

        // Force expiry.
        $this->pdo()->prepare('UPDATE password_reset_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE user_id = ?')
            ->execute([$id]);

        $this->assertThrows(\App\Exceptions\ValidationException::class,
            fn () => $service->resetPassword($result['token'], 'New-Password-456'));
    }
}
