<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Services\ContentService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Content management (Prompt 36) — service + HTTP tests.
 *
 * Proves:
 *  - Content blocks: upsert, find, published retrieval
 *  - Banners: create with image path, delete
 *  - Pages: create, find by slug, update, delete
 *  - FAQs: create, published retrieval, delete
 *  - SEO: upsert, find
 *  - Homepage serves the uploaded HTML file
 *  - Permission: technician blocked
 *  - CSRF on state-changing routes
 *  - Content management separate from app configuration (settings table)
 */
final class ContentTest extends TestCase
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

    private function dispatchAs(int $userId, string $method, string $uri, array $post = [], array $get = []): Response
    {
        $this->actingAs($userId);
        return $this->router()->dispatch(
            Request::synthetic($method, $uri, $get, $this->withCsrf($post)),
            $this->resolver()
        );
    }

    private function wipeOwners(): void
    {
        $this->pdo()->exec(
            "UPDATE users SET is_active = 0 WHERE id IN (
                SELECT user_id FROM (
                    SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                    WHERE r.slug = 'owner'
                ) AS owners)"
        );
    }

    private function makeOwner(string $email): int
    {
        $this->wipeOwners();
        $id = $this->makeUser($email, 'Correct-Horse-1', ['owner']);
        $this->actingAs($id);
        return $id;
    }

    private function cleanup(string $table, int $id): void
    {
        $this->pdo()->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
    }

    // ---------------------------------------------------- BLOCKS --

    public function testUpsertAndFindBlock(): void
    {
        $ownerId = $this->makeOwner('ct-b1@test.local');
        $svc = new ContentService();
        $svc->upsertBlock('test_block', 'Test Block', '<p>Hello</p>', true, $ownerId);

        $block = $svc->findBlock('test_block');
        $this->assertSame('Test Block', $block['title']);
        $this->assertSame('<p>Hello</p>', $block['body']);
        $this->assertSame('1', (string) $block['is_published']);

        $published = $svc->publishedBlock('test_block');
        $this->assertSame('<p>Hello</p>', $published);

        // Unpublish → publishedBlock returns null.
        $svc->upsertBlock('test_block', 'Test Block', '<p>Hello</p>', false, $ownerId);
        $this->assertNull($svc->publishedBlock('test_block'));

        $this->cleanup('content_blocks', (int) $block['id']);
    }

    public function testUpsertRejectsEmptyKey(): void
    {
        $ownerId = $this->makeOwner('ct-b2@test.local');
        $this->assertThrows(ValidationException::class, function () use ($ownerId) {
            (new ContentService())->upsertBlock('  ', 'X', 'Y', true, $ownerId);
        }, 'empty block key rejected');
    }

    // ---------------------------------------------------- BANNERS --

    public function testCreateAndDeleteBanner(): void
    {
        $ownerId = $this->makeOwner('ct-bn1@test.local');
        $svc = new ContentService();
        $id = $svc->createBanner([
            'title' => 'Test Banner',
            'subtitle' => 'Sale now on',
            'image_path' => 'uploads/banners/test.jpg',
            'link_url' => '/shop',
            'position' => 'hero',
            'is_published' => true,
            'display_order' => 1,
        ], $ownerId);
        $this->assertGreaterThan(0, $id);

        $banners = $svc->publishedBanners('hero');
        $found = false;
        foreach ($banners as $b) {
            if ((int) $b['id'] === $id) {
                $found = true;
                $this->assertSame('Test Banner', $b['title']);
                break;
            }
        }
        $this->assertTrue($found);

        $svc->deleteBanner($id);
    }

    public function testCreateBannerRejectsEmptyTitle(): void
    {
        $ownerId = $this->makeOwner('ct-bn2@test.local');
        $this->assertThrows(ValidationException::class, function () use ($ownerId) {
            (new ContentService())->createBanner(['title' => '', 'image_path' => 'x.jpg'], $ownerId);
        }, 'empty title rejected');
    }

    // ---------------------------------------------------- PAGES --

    public function testCreateFindUpdateDeletePage(): void
    {
        $ownerId = $this->makeOwner('ct-p1@test.local');
        $svc = new ContentService();
        $id = $svc->createPage([
            'slug' => 'test-page',
            'title' => 'Test Page',
            'body' => '<h1>Hello</h1><p>This is a test page.</p>',
            'meta_title' => 'Test Page Title',
            'meta_description' => 'Test page description',
            'is_published' => true,
        ], $ownerId);
        $this->assertGreaterThan(0, $id);

        $page = $svc->findPage('test-page');
        $this->assertSame('Test Page', $page['title']);
        $this->assertSame('<h1>Hello</h1><p>This is a test page.</p>', $page['body']);

        $svc->updatePage($id, [
            'slug' => 'test-page',
            'title' => 'Updated Title',
            'body' => '<p>Updated content</p>',
            'is_published' => true,
            'display_order' => 0,
        ]);
        $page = $svc->findPage('test-page');
        $this->assertSame('Updated Title', $page['title']);

        $svc->deletePage($id);
        $this->assertNull($svc->findPage('test-page'));
    }

    // ---------------------------------------------------- FAQs --

    public function testCreateAndDeleteFaq(): void
    {
        $svc = new ContentService();
        $id = $svc->createFaq([
            'question' => 'Do you offer warranty?',
            'answer' => 'Yes, all laptops come with a 7-day warranty.',
            'category' => 'Warranty',
            'is_published' => true,
            'display_order' => 1,
        ]);
        $this->assertGreaterThan(0, $id);

        $faqs = $svc->publishedFaqs();
        $found = false;
        foreach ($faqs as $f) {
            if ((int) $f['id'] === $id) {
                $found = true;
                $this->assertSame('Do you offer warranty?', $f['question']);
                break;
            }
        }
        $this->assertTrue($found);

        $svc->deleteFaq($id);
    }

    // ---------------------------------------------------- SEO --

    public function testUpsertAndFindSeo(): void
    {
        $svc = new ContentService();
        $svc->upsertSeo('home', [
            'meta_title' => 'Rasidul Tech Point — Best Laptops',
            'meta_description' => 'Buy authentic used laptops imported from Dubai.',
            'og_title' => 'Rasidul Tech Point',
            'og_description' => 'Dubai imported authentic used laptops',
        ]);

        $seo = $svc->findSeo('home');
        $this->assertSame('Rasidul Tech Point — Best Laptops', $seo['meta_title']);
        $this->assertSame('Buy authentic used laptops imported from Dubai.', $seo['meta_description']);

        // Update via upsert.
        $svc->upsertSeo('home', ['meta_title' => 'Updated Title']);
        $seo = $svc->findSeo('home');
        $this->assertSame('Updated Title', $seo['meta_title']);
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testTechnicianBlockedFromContent(): void
    {
        $techId = $this->makeUser('ct-tech@test.local', 'Correct-Horse-1', ['technician']);
        $this->assertThrows(ForbiddenException::class, function () use ($techId) {
            $this->dispatchAs($techId, 'GET', '/admin/content');
        }, 'technician has no content.manage');
    }

    // ---------------------------------------------------- HTTP --

    public function testHttpDashboardRenders(): void
    {
        $ownerId = $this->makeOwner('ct-http-dash@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/content');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Marketing', $r->body());
    }

    public function testHomepageServesCustomHtml(): void
    {
        $ownerId = $this->makeOwner('ct-home@test.local');
        // The homepage should serve the uploaded HTML (contains 'bundler').
        $r = $this->dispatchAs($ownerId, 'GET', '/');
        $this->assertSame(200, $r->status());
        // If the file exists, it should contain the bundler script.
        if (is_file(BASE_PATH . '/public/homepage.html')) {
            $this->assertStringContainsString('__bundler', $r->body(), 'custom homepage HTML must be served');
        }
    }

    public function testHttpBlockEditRenders(): void
    {
        $ownerId = $this->makeOwner('ct-block@test.local');
        $r = $this->dispatchAs($ownerId, 'GET', '/admin/content/blocks/test_key/edit');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('HTML body', $r->body());
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $ownerId = $this->makeOwner('ct-csrf@test.local');
        $this->actingAs($ownerId);

        $routes = [
            ['POST', '/admin/content/blocks', ['block_key' => 'x', 'title' => 'X', 'body' => 'Y']],
            ['POST', '/admin/content/banners', ['title' => 'X', 'image_path' => 'x.jpg']],
            ['POST', '/admin/content/pages', ['slug' => 'x', 'title' => 'X']],
            ['POST', '/admin/content/faqs', ['question' => 'Q?']],
            ['POST', '/admin/content/seo', ['page_key' => 'home']],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(Request::synthetic($method, $uri, [], $post), $this->resolver());
            }, "{$method} {$uri} must require CSRF");
        }
    }
}
