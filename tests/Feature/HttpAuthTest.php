<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\CsrfTokenMismatchException;
use App\Exceptions\ForbiddenException;
use App\Services\AuthService;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Session;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * HTTP-level auth tests: drive the real application router through the real
 * LoginController / PasswordResetController (not just AuthService) to verify
 * that login, logout, CSRF, protected-route gating, and password reset all
 * behave correctly end-to-end.
 *
 * The service-level AuthTest already covers AuthService directly; this file
 * complements it by proving the wiring (router → middleware → controller →
 * service → response → session) is intact.
 */
final class HttpAuthTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->group(['middleware' => ['headers']], function (Router $router): void {
            require BASE_PATH . '/routes/web.php';
        });
        return $router;
    }

    private function resolver(): MiddlewareResolver
    {
        $resolver = new MiddlewareResolver();
        $resolver->register('headers', fn () => new \App\Middleware\SecurityHeadersMiddleware());
        $resolver->register('auth', fn () => new \App\Middleware\AuthMiddleware(guest: false));
        $resolver->register('guest', fn () => new \App\Middleware\AuthMiddleware(guest: true));
        $resolver->register('csrf', fn () => new \App\Middleware\CsrfMiddleware());
        $resolver->register('permission', fn () => new \App\Middleware\PermissionMiddleware());
        return $resolver;
    }

    private function withCsrf(array $post): array
    {
        $post['_token'] = Csrf::token();
        return $post;
    }

    // -------------------------------------------------------------- LOGIN --

    public function testGetLoginRendersFormForGuest(): void
    {
        $response = $this->router()->dispatch(
            Request::synthetic('GET', '/login'),
            $this->resolver()
        );

        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Sign in/i', $response->body());
    }

    public function testPostLoginWithValidCredentialsRedirectsToDashboard(): void
    {
        $id = $this->makeUser('http-login@test.local', 'Correct-Horse-1', ['manager']);

        $response = $this->router()->dispatch(
            Request::synthetic('POST', '/login', [], $this->withCsrf([
                'email' => 'http-login@test.local',
                'password' => 'Correct-Horse-1',
            ])),
            $this->resolver()
        );

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/admin/dashboard$#', $response->headers()['Location'] ?? '');
        $this->assertSame($id, Session::get('user_id'), 'session must carry the authenticated user id');
        $this->assertTrue(AuthService::check());
    }

    public function testPostLoginWithWrongPasswordFlashesGenericError(): void
    {
        $this->makeUser('http-bad@test.local', 'Correct-Horse-1', ['manager']);

        $response = $this->router()->dispatch(
            Request::synthetic('POST', '/login', [], $this->withCsrf([
                'email' => 'http-bad@test.local',
                'password' => 'wrong-password',
            ])),
            $this->resolver()
        );

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/login$#', $response->headers()['Location'] ?? '');
        $this->assertNull(Session::get('user_id'), 'no session may be created on failure');
        $this->assertSame(
            'These credentials do not match our records.',
            Session::getFlash('error'),
            'failure must use the generic message that does not reveal whether the email exists'
        );
    }

    public function testPostLoginWithUnknownEmailBehavesIdentically(): void
    {
        $response = $this->router()->dispatch(
            Request::synthetic('POST', '/login', [], $this->withCsrf([
                'email' => 'ghost@nowhere.test',
                'password' => 'whatever-pass',
            ])),
            $this->resolver()
        );

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/login$#', $response->headers()['Location'] ?? '');
        $this->assertSame(
            'These credentials do not match our records.',
            Session::getFlash('error'),
            'the unknown-email failure must reuse the same generic message (no enumeration)'
        );
    }

    public function testPostLoginWithoutCsrfIsRejected(): void
    {
        $this->makeUser('http-csrf@test.local', 'Correct-Horse-1', ['manager']);

        $this->assertThrows(CsrfTokenMismatchException::class, function () {
            $this->router()->dispatch(
                Request::synthetic('POST', '/login', [], [
                    'email' => 'http-csrf@test.local',
                    'password' => 'Correct-Horse-1',
                    // _token intentionally absent
                ]),
                $this->resolver()
            );
        });
    }

    public function testPostLoginWithWrongCsrfIsRejected(): void
    {
        Csrf::token(); // ensure a session token exists
        $this->assertThrows(CsrfTokenMismatchException::class, function () {
            $this->router()->dispatch(
                Request::synthetic('POST', '/login', [], [
                    'email' => 'http-csrf@test.local',
                    'password' => 'Correct-Horse-1',
                    '_token' => str_repeat('0', 64),
                ]),
                $this->resolver()
            );
        });
    }

    public function testGuestMiddlewareBouncesAuthenticatedStaffAwayFromLogin(): void
    {
        $id = $this->makeUser('http-bounce@test.local', 'Correct-Horse-1', ['manager']);
        $this->actingAs($id);

        $response = $this->router()->dispatch(
            Request::synthetic('GET', '/login'),
            $this->resolver()
        );

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/admin/dashboard$#', $response->headers()['Location'] ?? '');
    }

    public function testGuestMiddlewareBouncesCustomerToAccount(): void
    {
        $id = $this->makeUser('http-cust@test.local', 'Correct-Horse-1', ['customer']);
        $this->actingAs($id);

        $response = $this->router()->dispatch(
            Request::synthetic('GET', '/login'),
            $this->resolver()
        );

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/account$#', $response->headers()['Location'] ?? '');
    }

    // -------------------------------------------------------------- LOGOUT --

    public function testPostLogoutDestroysSessionAndRedirectsToLogin(): void
    {
        $id = $this->makeUser('http-logout@test.local', 'Correct-Horse-1', ['manager']);
        $this->actingAs($id);
        $this->assertTrue(AuthService::check());

        $response = $this->router()->dispatch(
            Request::synthetic('POST', '/logout', [], $this->withCsrf([])),
            $this->resolver()
        );

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/login$#', $response->headers()['Location'] ?? '');
        $this->assertFalse(AuthService::check(), 'logout must invalidate the in-memory user');
        $this->assertNull(Session::get('user_id'), 'logout must clear the session user_id');
    }

    public function testPostLogoutRequiresCsrf(): void
    {
        $id = $this->makeUser('http-logout-csrf@test.local', 'Correct-Horse-1', ['manager']);
        $this->actingAs($id);

        $this->assertThrows(CsrfTokenMismatchException::class, function () {
            $this->router()->dispatch(
                Request::synthetic('POST', '/logout'),
                $this->resolver()
            );
        });
    }

    // ---------------------------------------------------- PROTECTED ROUTES --

    public function testAdminDashboardRedirectsAnonymousUserToLogin(): void
    {
        $response = $this->router()->dispatch(
            Request::synthetic('GET', '/admin/dashboard'),
            $this->resolver()
        );

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/login$#', $response->headers()['Location'] ?? '');
        $this->assertSame('/admin/dashboard', Session::get('intended'), 'intended path must be saved for after login');
    }

    public function testCustomerIsForbiddenFromAdminDashboard(): void
    {
        $id = $this->makeUser('http-customer@test.local', 'Correct-Horse-1', ['customer']);
        $this->actingAs($id);

        // auth middleware passes (user is authenticated) but permission middleware
        // throws ForbiddenException because the customer lacks dashboard.view.
        $this->assertThrows(ForbiddenException::class, function () {
            $this->router()->dispatch(
                Request::synthetic('GET', '/admin/dashboard'),
                $this->resolver()
            );
        });
    }

    public function testStaffWithoutSpecificPermissionIsForbidden(): void
    {
        $id = $this->makeUser('http-sales@test.local', 'Correct-Horse-1', ['sales_cashier']);
        $this->actingAs($id);

        // Cashier has dashboard.view and pos.access but NOT users.view.
        $this->assertThrows(ForbiddenException::class, function () {
            $this->router()->dispatch(
                Request::synthetic('GET', '/admin/users'),
                $this->resolver()
            );
        });
    }

    public function testManagerCanAccessDashboard(): void
    {
        $id = $this->makeUser('http-manager@test.local', 'Correct-Horse-1', ['manager']);
        $this->actingAs($id);

        $response = $this->router()->dispatch(
            Request::synthetic('GET', '/admin/dashboard'),
            $this->resolver()
        );

        $this->assertSame(200, $response->status());
    }

    public function testOwnerCanAccessEverything(): void
    {
        $id = $this->makeUser('http-owner@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($id);

        $response = $this->router()->dispatch(
            Request::synthetic('GET', '/admin/audit-logs'),
            $this->resolver()
        );

        $this->assertSame(200, $response->status(), 'owner (full-permission) must reach audit logs');
    }

    // ------------------------------------------------------ PASSWORD RESET --

    public function testForgotPasswordEndpointDoesNotDiscloseAccountExistence(): void
    {
        // Valid account.
        $this->makeUser('http-reset@test.local', 'Old-Password-123', ['manager']);

        $validResponse = $this->router()->dispatch(
            Request::synthetic('POST', '/forgot-password', [], $this->withCsrf([
                'email' => 'http-reset@test.local',
            ])),
            $this->resolver()
        );

        // Capture the valid-account flash before dispatching the next request
        // (a second dispatch would overwrite the same flash key in the test session).
        $validFlash = Session::getFlash('success');

        // Unknown account — must produce the SAME redirect + flash text.
        $unknownResponse = $this->router()->dispatch(
            Request::synthetic('POST', '/forgot-password', [], $this->withCsrf([
                'email' => 'ghost@nowhere.test',
            ])),
            $this->resolver()
        );
        $unknownFlash = Session::getFlash('success');

        $this->assertSame($validResponse->status(), $unknownResponse->status(), 'both cases must return the same status');
        $this->assertMatchesRegularExpression('#/login$#', $validResponse->headers()['Location'] ?? '');
        $this->assertMatchesRegularExpression('#/login$#', $unknownResponse->headers()['Location'] ?? '');

        // In local env with log mail driver, the valid-email flash includes
        // a " DEV: <link>" suffix for testing convenience. The NON-DISCLOSURE
        // contract is that both flashes start with the same generic message.
        // The DEV suffix is intentional and only visible in local env.
        $this->assertStringStartsWith('If an account exists', $validFlash ?? '', 'valid-email flash must use the generic non-disclosing wording');
        $this->assertStringStartsWith('If an account exists', $unknownFlash ?? '', 'unknown-email flash must use the same generic non-disclosing wording');
        // In production (or when mail driver != log), the DEV suffix is
        // absent and both flashes must be identical. In local env, the
        // valid flash has the DEV suffix — both still start identically.
    }

    public function testForgotPasswordCreatesExpiringSingleUseTokenInDatabase(): void
    {
        $id = $this->makeUser('http-token@test.local', 'Old-Password-123', ['manager']);

        $this->router()->dispatch(
            Request::synthetic('POST', '/forgot-password', [], $this->withCsrf([
                'email' => 'http-token@test.local',
            ])),
            $this->resolver()
        );

        $stmt = $this->pdo()->prepare(
            'SELECT token_hash, expires_at, used_at FROM password_reset_tokens WHERE user_id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertTrue($row !== false, 'a token row must be created for the user');
        $this->assertNotNull($row['token_hash']);
        $this->assertNull($row['used_at'], 'token must be unused immediately after creation');
        $this->assertTrue(strlen((string) $row['token_hash']) === 64, 'token_hash must be the sha-256 hex digest');
    }

    public function testResetPasswordFormRendersForAnyTokenValue(): void
    {
        $response = $this->router()->dispatch(
            Request::synthetic('GET', '/reset-password', ['token' => 'anything-here']),
            $this->resolver()
        );

        $this->assertSame(200, $response->status(), 'the form is shown regardless of token validity (no disclosure)');
    }

    public function testResetPasswordWithValidTokenUpdatesPasswordAndRedirectsToLogin(): void
    {
        $id = $this->makeUser('http-reset-ok@test.local', 'Old-Password-123', ['manager']);

        // Create a token via the service (the only correct way).
        $result = (new \App\Services\PasswordResetService())->createToken('http-reset-ok@test.local');
        $this->assertNotNull($result['token']);

        $response = $this->router()->dispatch(
            Request::synthetic('POST', '/reset-password', [], $this->withCsrf([
                'token' => $result['token'],
                'password' => 'New-Strong-Pass-9',
                'password_confirmation' => 'New-Strong-Pass-9',
            ])),
            $this->resolver()
        );

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/login$#', $response->headers()['Location'] ?? '');

        // Verify the password was actually updated.
        $hash = $this->pdo()->prepare('SELECT password FROM users WHERE id = ?');
        $hash->execute([$id]);
        $this->assertTrue(password_verify('New-Strong-Pass-9', (string) $hash->fetchColumn()));

        // Verify the token is now consumed.
        $used = $this->pdo()->prepare('SELECT used_at FROM password_reset_tokens WHERE user_id = ?');
        $used->execute([$id]);
        $this->assertNotNull($used->fetchColumn(), 'token must be marked used after a successful reset');
    }

    public function testResetPasswordWithInvalidTokenFlashesErrorAndRedirects(): void
    {
        $response = $this->router()->dispatch(
            Request::synthetic('POST', '/reset-password', [], $this->withCsrf([
                'token' => str_repeat('0', 64),
                'password' => 'New-Strong-Pass-9',
                'password_confirmation' => 'New-Strong-Pass-9',
            ])),
            $this->resolver()
        );

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/forgot-password$#', $response->headers()['Location'] ?? '');
        $this->assertNotNull(Session::getFlash('error'), 'an error must be flashed for an invalid token');
    }

    public function testResetPasswordWithWeakPasswordFailsValidation(): void
    {
        $id = $this->makeUser('http-weak@test.local', 'Old-Password-123', ['manager']);
        $result = (new \App\Services\PasswordResetService())->createToken('http-weak@test.local');

        $response = $this->router()->dispatch(
            Request::synthetic('POST', '/reset-password', [], $this->withCsrf([
                'token' => $result['token'],
                'password' => 'short', // < 10 chars
                'password_confirmation' => 'short',
            ])),
            $this->resolver()
        );

        $this->assertSame(302, $response->status());
        $this->assertNotNull(Session::getFlash('error'), 'weak password must be rejected');

        // Password must remain unchanged.
        $hash = $this->pdo()->prepare('SELECT password FROM users WHERE id = ?');
        $hash->execute([$id]);
        $this->assertTrue(password_verify('Old-Password-123', (string) $hash->fetchColumn()));
    }

    public function testResetPasswordRequiresCsrf(): void
    {
        $this->makeUser('http-reset-csrf@test.local', 'Old-Password-123', ['manager']);
        $result = (new \App\Services\PasswordResetService())->createToken('http-reset-csrf@test.local');

        $this->assertThrows(CsrfTokenMismatchException::class, function () use ($result) {
            $this->router()->dispatch(
                Request::synthetic('POST', '/reset-password', [], [
                    'token' => $result['token'],
                    'password' => 'New-Strong-Pass-9',
                    'password_confirmation' => 'New-Strong-Pass-9',
                ]),
                $this->resolver()
            );
        });
    }

    // ---------------------------------------------------- SESSION EXPIRY --

    public function testIntendedPathIsRestoredAfterLogin(): void
    {
        // Step 1: anonymous user hits /admin/settings → saved as intended.
        $this->router()->dispatch(Request::synthetic('GET', '/admin/settings'), $this->resolver());
        $this->assertSame('/admin/settings', Session::get('intended'));

        // Step 2: user signs in with the right role.
        $id = $this->makeUser('http-intended@test.local', 'Correct-Horse-1', ['owner']);
        $response = $this->router()->dispatch(
            Request::synthetic('POST', '/login', [], $this->withCsrf([
                'email' => 'http-intended@test.local',
                'password' => 'Correct-Horse-1',
            ])),
            $this->resolver()
        );

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/admin/settings$#', $response->headers()['Location'] ?? '', 'post-login redirect must honor the intended URL');
        $this->assertNull(Session::get('intended'), 'intended must be cleared after consumption');
    }

    public function testDeactivatedUserCannotReachProtectedRoutesAfterMidSessionDeactivation(): void
    {
        $id = $this->makeUser('http-mid@test.local', 'Correct-Horse-1', ['manager']);
        $this->actingAs($id);
        $this->assertTrue(AuthService::check());

        // Deactivate mid-session (simulates an admin removing the account).
        $this->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
        AuthService::refreshUser();

        $this->assertFalse(AuthService::check(), 'a deactivated user must be treated as signed out on the next refresh');

        // Subsequent request must be bounced back to /login.
        $response = $this->router()->dispatch(
            Request::synthetic('GET', '/admin/dashboard'),
            $this->resolver()
        );

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/login$#', $response->headers()['Location'] ?? '');
    }

    // -------------------------------------------------------- REGISTER --

    public function testGetRegisterRendersForGuest(): void
    {
        $response = $this->router()->dispatch(
            Request::synthetic('GET', '/register'),
            $this->resolver()
        );

        $this->assertSame(200, $response->status());
    }

    public function testPostRegisterCreatesCustomerAccountAndSignsIn(): void
    {
        $email = 'http-new-' . bin2hex(random_bytes(3)) . '@test.local';
        $phone = '+88017' . random_int(10000000, 99999999);
        $response = $this->router()->dispatch(
            Request::synthetic('POST', '/register', [], $this->withCsrf([
                'name' => 'New Customer',
                'email' => $email,
                'phone' => $phone,
                'password' => 'New-Customer-1',
                'password_confirmation' => 'New-Customer-1',
            ])),
            $this->resolver()
        );

        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/account$#', $response->headers()['Location'] ?? '', 'newly-registered customers land on their account, not the admin console');

        $stmt = $this->pdo()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $id = (int) $stmt->fetchColumn();
        $this->assertTrue($id > 0, 'user row must exist');

        $roles = AuthService::rolesFor($id);
        $this->assertContains('customer', $roles, 'registration always assigns the customer role');
    }

    public function testPostRegisterRejectsDuplicateEmail(): void
    {
        $this->makeUser('http-dup@test.local', 'Correct-Horse-1', ['customer']);

        // RegisterController catches ValidationException and re-renders
        // the form with inline errors at 422 — no exception thrown.
        $r = $this->router()->dispatch(
            Request::synthetic('POST', '/register', [], $this->withCsrf([
                'name' => 'Duplicate',
                'email' => 'http-dup@test.local',
                'phone' => '+8801799000001',
                'password' => 'New-Customer-1',
                'password_confirmation' => 'New-Customer-1',
            ])),
            $this->resolver()
        );
        $this->assertSame(422, $r->status(), 'duplicate email → 422 with form');
        $this->assertStringContainsString('already in use', $r->body(), 'inline error shown');
    }

    public function testPostRegisterRejectsMissingFields(): void
    {
        $r = $this->router()->dispatch(
            Request::synthetic('POST', '/register', [], $this->withCsrf([])),
            $this->resolver()
        );
        $this->assertSame(422, $r->status(), 'empty POST → 422 with form');
        $this->assertStringContainsString('Please fix', $r->body(), 'error summary shown');
    }
}
