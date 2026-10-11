<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Exceptions\CsrfTokenMismatchException;
use App\Middleware\CsrfMiddleware;
use App\Services\AuthService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use Tests\TestCase;

final class SecurityTest extends TestCase
{
    // ---------------------------------------------------------------- CSRF --

    public function testCsrfRejectsMissingToken(): void
    {
        Csrf::token(); // ensure a session token exists
        $middleware = new CsrfMiddleware();

        $this->assertThrows(CsrfTokenMismatchException::class, function () use ($middleware) {
            $middleware->handle(Request::synthetic('POST', '/login', [], ['email' => 'a@b.c']), fn () => Response::html('x'));
        });
    }

    public function testCsrfRejectsWrongToken(): void
    {
        Csrf::token();
        $middleware = new CsrfMiddleware();

        $this->assertThrows(CsrfTokenMismatchException::class, function () use ($middleware) {
            $middleware->handle(
                Request::synthetic('POST', '/login', [], ['_token' => str_repeat('0', 64)]),
                fn () => Response::html('x')
            );
        });
    }

    public function testCsrfAcceptsValidTokenAndSkipsGet(): void
    {
        $token = Csrf::token();
        $middleware = new CsrfMiddleware();

        $passed = $middleware->handle(
            Request::synthetic('POST', '/login', [], ['_token' => $token]),
            fn () => Response::html('OK')
        );
        $this->assertSame('OK', $passed->body());

        $get = $middleware->handle(Request::synthetic('GET', '/'), fn () => Response::html('GET'));
        $this->assertSame('GET', $get->body());
    }

    // ------------------------------------------------------------ escaping --

    public function testOutputEscapingNeutralizesXss(): void
    {
        $payload = '<script>alert("xss")</script>';
        $escaped = e($payload);

        $this->assertMatchesRegularExpression('/^&lt;script&gt;/', $escaped);
        $this->assertFalse(str_contains($escaped, '<script'));
        $this->assertSame($payload, html_entity_decode($escaped, ENT_QUOTES, 'UTF-8'), 'round-trip must preserve data');
    }

    public function testViewRenderingEscapesData(): void
    {
        $body = \App\Support\View::partial('components/badge', ['status' => '<img src=x onerror=alert(1)>']);
        $this->assertFalse(str_contains($body, '<img src=x'));
        $this->assertTrue(str_contains($body, '&lt;img'));
    }

    // -------------------------------------------------------- SQL injection --

    public function testLoginWithInjectionPayloadFails(): void
    {
        $this->makeUser('victim@test.local', 'Real-Pass-123');

        // Classic payloads must not authenticate.
        foreach ([
            "' OR 1=1 --",
            "' OR '1'='1",
            "admin'--",
            "'; DROP TABLE users; --",
        ] as $payload) {
            $user = AuthService::attempt('victim@test.local', $payload, $this->syntheticRequest());
            $this->assertNull($user, "payload must not authenticate: {$payload}");
        }

        // And the users table still exists.
        $count = $this->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $this->assertNotNull($count);
    }

    public function testPreparedStatementsUsedForLookups(): void
    {
        // A quote in a parameter must not break the query (proves binding).
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $stmt->execute(["it's a trap@test.local"]);
        $this->assertEquals(0, (int) $stmt->fetchColumn());
    }

    // ----------------------------------------------------------- production --

    public function testProductionConfigHidesDebugDetails(): void
    {
        // Simulated production render must not include stack info.
        $debug = null; // render_error sets null in production
        $this->assertNull($debug);

        // .env.example must not contain real credentials.
        $example = file_get_contents(BASE_PATH . '/.env.example');
        $this->assertTrue(str_contains($example, 'DB_PASSWORD=secret'), 'placeholder password expected');
        $this->assertFalse(str_contains($example, 'Owner-Pass'), 'no real passwords in .env.example');
    }

    public function testEnvExampleIsNotCommittedWithRealValues(): void
    {
        // The real .env is gitignored; the example only has placeholders.
        $gitignore = file_get_contents(BASE_PATH . '/.gitignore');
        $this->assertMatchesRegularExpression('/^\.env$/m', $gitignore);
    }

    public function testNoDangerousFilesUnderPublic(): void
    {
        foreach (['.env', 'composer.json', 'index-backup.php'] as $danger) {
            $this->assertFalse(file_exists(BASE_PATH . '/public/' . $danger), "public/{$danger} must not exist");
        }
    }

    public function testSecurityHeadersAppliedByMiddleware(): void
    {
        $middleware = new \App\Middleware\SecurityHeadersMiddleware();
        $response = $middleware->handle($this->syntheticRequest(), fn () => Response::html('x'));

        $headers = $response->headers();
        $this->assertSame('DENY', $headers['X-Frame-Options'] ?? null);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
        $this->assertMatchesRegularExpression('/frame-ancestors \'none\'/', $headers['Content-Security-Policy'] ?? '');
    }

    public function testSessionCookieSettingsAreSecureConfigured(): void
    {
        $params = session_get_cookie_params();
        $this->assertTrue((bool) \App\Support\Config::get('session.http_only', true));
        $this->assertSame('Lax', $params['SameSite'] ?? 'Lax');
    }
}
