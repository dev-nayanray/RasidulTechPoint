<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\NotFoundException;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Public CMS pages + FAQ (Marketing-Website final phase).
 *
 * Proves:
 *  - /faq renders (CMS entries when published, honest empty state when none).
 *  - /page/{slug} renders every seeded policy page (warranty, returns,
 *    shipping, inspection-process, privacy, terms) — the footer links
 *    that previously pointed nowhere now resolve.
 *  - Unknown, invalid, unpublished, or empty-body slugs are a clean 404.
 *  - Every internal link on the storefront layout resolves to a real route.
 */
final class PublicPagesTest extends TestCase
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

    private function get(string $uri): Response
    {
        return $this->router()->dispatch(Request::synthetic('GET', $uri), $this->resolver());
    }

    // ------------------------------------------------------------- FAQ --

    public function testFaqPageRenders(): void
    {
        $r = $this->get('/faq');
        $this->assertSame(200, $r->status());
        $body = $r->body();
        $this->assertStringContainsString('Frequently asked questions', $body);
        // Either published entries render, or the honest empty state does.
        $this->assertTrue(
            str_contains($body, 'details') || str_contains($body, 'FAQ entries will appear here'),
            'FAQ list or empty state must render'
        );
        $this->assertStringContainsString('href="' . url('/contact') . '"', $body, 'contact CTA present');
    }

    // ---------------------------------------------------- CMS PAGES --

    public function testSeededPolicyPagesRender(): void
    {
        $expected = [
            'warranty' => 'Warranty &amp; Service',
            'returns' => 'Returns &amp; Exchanges',
            'shipping' => 'Shipping &amp; Delivery',
            'inspection-process' => 'Laptop Inspection &amp; Quality Check',
            'privacy' => 'Privacy Policy',
            'terms' => 'Terms &amp; Conditions',
        ];
        foreach ($expected as $slug => $title) {
            $r = $this->get('/page/' . $slug);
            $this->assertSame(200, $r->status(), "/page/{$slug} must render");
            $this->assertStringContainsString($title, $r->body(), "/page/{$slug} shows its title");
            $this->assertStringContainsString('Contact Rasidul Tech Point', $r->body(), "/page/{$slug} has contact CTA");
        }
    }

    public function testUnknownSlugIsNotFound(): void
    {
        $this->assertThrows(NotFoundException::class, function () {
            $this->get('/page/this-page-does-not-exist');
        }, 'unknown slug → 404');
    }

    public function testInvalidSlugPatternIsNotFound(): void
    {
        $this->assertThrows(NotFoundException::class, function () {
            $this->get('/page/INVALID_Slug!');
        }, 'slug must match [a-z0-9-]+');
    }

    public function testUnpublishedPageIsNotFound(): void
    {
        $this->pdo()->exec("DELETE FROM content_pages WHERE slug = 'pub-test-unpublished'");
        $this->pdo()->exec(
            "INSERT INTO content_pages (slug, title, body, is_published, display_order)
             VALUES ('pub-test-unpublished', 'Hidden Page', '<p>draft</p>', 0, 99)"
        );
        try {
            $this->assertThrows(NotFoundException::class, function () {
                $this->get('/page/pub-test-unpublished');
            }, 'unpublished page must not leak publicly');
        } finally {
            $this->pdo()->exec("DELETE FROM content_pages WHERE slug = 'pub-test-unpublished'");
        }
    }

    public function testEmptyBodyPageIsNotFound(): void
    {
        $this->pdo()->exec("DELETE FROM content_pages WHERE slug = 'pub-test-empty'");
        $this->pdo()->exec(
            "INSERT INTO content_pages (slug, title, body, is_published, display_order)
             VALUES ('pub-test-empty', 'Empty Page', '   ', 1, 99)"
        );
        try {
            $this->assertThrows(NotFoundException::class, function () {
                $this->get('/page/pub-test-empty');
            }, 'published page with no body must 404, not render blank');
        } finally {
            $this->pdo()->exec("DELETE FROM content_pages WHERE slug = 'pub-test-empty'");
        }
    }

    // ------------------------------------------------- LINK INTEGRITY --

    public function testFooterAndNavLinksResolveToRealRoutes(): void
    {
        $body = $this->get('/')->body();
        preg_match_all('/href="' . preg_quote(url('/'), '/') . '([^"?#]*)[^"]*"/', $body, $m);
        $paths = array_unique($m[1]);
        $this->assertNotSame([], $paths, 'home page must contain internal links');

        foreach ($paths as $path) {
            $path = '/' . trim($path, '/');
            if (str_starts_with($path, '/assets/')) {
                continue; // static files served by the web server, not routes
            }
            try {
                $response = $this->get($path);
                $status = $response->status();
            } catch (NotFoundException $e) {
                $status = 404;
            }
            $this->assertNotSame(404, $status, "home page links to {$path} but the route does not exist");
        }
    }
}
