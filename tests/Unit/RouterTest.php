<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\NotFoundException;
use App\Support\MiddlewareResolver;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use Tests\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        return new Router();
    }

    public function testStaticRouteMatches(): void
    {
        $r = $this->router();
        $r->get('/shop', fn () => Response::html('SHOP'));

        $response = $r->dispatch(Request::synthetic('GET', '/shop'), $this->resolver());
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/SHOP/', $response->body());
    }

    public function testRouteParameters(): void
    {
        $r = $this->router();
        $r->get('/shop/{slug}', fn (Request $req, array $params) => Response::html('GOT:' . $params['slug']));

        $response = $r->dispatch(Request::synthetic('GET', '/shop/dell-latitude'), $this->resolver());
        $this->assertMatchesRegularExpression('/GOT:dell-latitude/', $response->body());
    }

    public function testConstrainedParameters(): void
    {
        $r = $this->router();
        $r->get('/users/{id:\d+}', fn (Request $req, array $params) => Response::html('ID:' . $params['id']));

        $ok = $r->dispatch(Request::synthetic('GET', '/users/42'), $this->resolver());
        $this->assertMatchesRegularExpression('/ID:42/', $ok->body());

        $this->assertThrows(NotFoundException::class, fn () =>
            $r->dispatch(Request::synthetic('GET', '/users/abc'), $this->resolver()));
    }

    public function testMethodMismatchIs404(): void
    {
        $r = $this->router();
        $r->get('/only-get', fn () => Response::html('ok'));
        $this->assertThrows(NotFoundException::class, fn () =>
            $r->dispatch(Request::synthetic('POST', '/only-get'), $this->resolver()));
    }

    public function testMethodSpoofing(): void
    {
        $r = $this->router();
        $r->patch('/thing', fn () => Response::html('PATCHED'));

        $response = $r->dispatch(
            Request::synthetic('POST', '/thing', [], ['_method' => 'PATCH']),
            $this->resolver()
        );
        $this->assertMatchesRegularExpression('/PATCHED/', $response->body());
    }

    public function testTrailingSlashNormalization(): void
    {
        $r = $this->router();
        $r->get('/shop', fn () => Response::html('SHOP'));
        $response = $r->dispatch(Request::synthetic('GET', '/shop/'), $this->resolver());
        $this->assertSame(200, $response->status());
    }

    public function testGroupsApplyPrefixAndMiddleware(): void
    {
        $r = $this->router();
        $r->group(['prefix' => 'admin', 'middleware' => ['auth']], function (Router $r): void {
            $r->get('/dashboard', fn () => Response::html('DASH'));
        });

        // Without a session the auth middleware must intercept.
        $response = $r->dispatch(Request::synthetic('GET', '/admin/dashboard'), $this->resolver());
        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/login$#', $response->headers()['Location'] ?? '');
    }

    public function testPathSanitizationRejectsTraversal(): void
    {
        $request = Request::synthetic('GET', '/..%2f..%2fsecret');
        $this->assertSame('/', $request->path());
    }

    public function testUnknownRouteIs404(): void
    {
        $this->assertThrows(NotFoundException::class, fn () =>
            $this->router()->dispatch(Request::synthetic('GET', '/nothing'), $this->resolver()));
    }

    private function resolver(): MiddlewareResolver
    {
        $resolver = new MiddlewareResolver();
        $resolver->register('auth', fn () => new \App\Middleware\AuthMiddleware(guest: false));
        $resolver->register('guest', fn () => new \App\Middleware\AuthMiddleware(guest: true));
        $resolver->register('csrf', fn () => new \App\Middleware\CsrfMiddleware());
        $resolver->register('headers', fn () => new \App\Middleware\SecurityHeadersMiddleware());
        $resolver->register('permission', fn () => new \App\Middleware\PermissionMiddleware());
        return $resolver;
    }
}
