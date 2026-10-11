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

    public function testGuestMiddlewareRoutesStaffToConsole(): void
    {
        $r = $this->router();
        $r->group(['middleware' => ['guest']], function (Router $r): void {
            $r->get('/login', fn () => Response::html('FORM'));
        });

        $staffId = $this->makeUser('router-staff@test.local', roleSlugs: ['manager']);
        $this->actingAs($staffId);

        $response = $r->dispatch(Request::synthetic('GET', '/login'), $this->resolver());
        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/admin/dashboard$#', $response->headers()['Location'] ?? '');
    }

    public function testGuestMiddlewareRoutesCustomerToAccount(): void
    {
        $r = $this->router();
        $r->group(['middleware' => ['guest']], function (Router $r): void {
            $r->get('/login', fn () => Response::html('FORM'));
        });

        $customerId = $this->makeUser('router-customer@test.local', roleSlugs: ['customer']);
        $this->actingAs($customerId);

        $response = $r->dispatch(Request::synthetic('GET', '/login'), $this->resolver());
        $this->assertSame(302, $response->status());
        $this->assertMatchesRegularExpression('#/account$#', $response->headers()['Location'] ?? '',
            'customers must never be bounced into the admin console');
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

    public function testEveryImplementedNavItemResolvesToARegisteredRoute(): void
    {
        // Build the real application router.
        $router = new Router();
        $router->group(['middleware' => ['headers']], function (Router $router): void {
            require BASE_PATH . '/routes/web.php';
        });
        $regexes = array_map(
            fn ($r) => $r['regex'],
            (new \ReflectionProperty($router, 'routes'))->getValue($router)
        );

        foreach (RTP_ADMIN_NAV as $section => $entries) {
            foreach ($entries as [$permission, $label, $href, $icon, $implemented]) {
                if (!$implemented) {
                    continue; // "SOON" items intentionally have no route
                }
                $matched = false;
                foreach ($regexes as $regex) {
                    if (preg_match($regex, $href)) {
                        $matched = true;
                        break;
                    }
                }
                $this->assertTrue($matched, "nav item '{$label}' → {$href} must match a registered route");
            }
        }
    }

    public function testAdminBreadcrumbsDeriveFromNavConfig(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/users/42/edit';
        $crumbs = admin_breadcrumbs('Edit Karim');

        $this->assertSame('Dashboard', $crumbs[0][0]);
        $this->assertSame('/admin/dashboard', $crumbs[0][1]);
        $this->assertSame('Staff & Roles', $crumbs[1][0]);
        $this->assertSame('Staff Users', $crumbs[2][0]);
        $this->assertSame('/admin/users', $crumbs[2][1]);
        $this->assertSame('Edit Karim', $crumbs[3][0]);
        $this->assertNull($crumbs[3][1]);

        $_SERVER['REQUEST_URI'] = '/admin/dashboard';
        $crumbs = admin_breadcrumbs('Dashboard');
        $this->assertCount(1, $crumbs, 'the dashboard itself renders no trail');

        $_SERVER['REQUEST_URI'] = '/shop';
        $this->assertSame([], admin_breadcrumbs('Shop'), 'non-admin paths produce no admin breadcrumbs');
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
