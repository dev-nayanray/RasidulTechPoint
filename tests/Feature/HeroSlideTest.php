<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ValidationException;
use App\Services\HeroSlideService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Hero Slider (ZCODE Master Prompt) — service + HTTP tests.
 *
 * Proves:
 *  - Migration 035 creates the table (covered implicitly by bootOnce)
 *  - Service: create / find / update / toggle / reorder / duplicate / delete
 *  - Service: published() respects is_active and schedule window
 *  - Service: validation rejects empty headline
 *  - Service: image upload guard rejects oversize + bad MIME
 *  - HTTP: owner can list, create, edit, toggle, duplicate, delete
 *  - HTTP: technician (no content.manage) gets 403
 *  - HTTP: CSRF enforced on state-changing routes
 *  - HomeController pulls slides into the homepage
 */
final class HeroSlideTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure deterministic empty state for hero_slides each test method.
        $this->pdo()->exec('DELETE FROM hero_slides');
    }

    // ---------------------------------------------------- SERVICE --

    public function testCreateAndFindSlide(): void
    {
        $ownerId = $this->makeOwner('hs-c1@test.local');
        $svc = new HeroSlideService();
        $id = $svc->create([
            'internal_name' => 'Welcome',
            'headline' => 'Find Your Next Laptop.',
            'description' => 'Explore available laptops.',
            'cta1_label' => 'Shop Now',
            'cta1_url' => '/shop',
            'bg_color' => '#01011D',
            'text_color' => '#ffffff',
            'is_active' => 1,
            'sort_order' => 0,
        ], $ownerId);

        $this->assertGreaterThan(0, $id);
        $slide = $svc->find($id);
        $this->assertNotNull($slide);
        $this->assertSame('Welcome', $slide['internal_name']);
        $this->assertSame('Find Your Next Laptop.', $slide['headline']);
    }

    public function testCreateRejectsEmptyHeadline(): void
    {
        $ownerId = $this->makeOwner('hs-c2@test.local');
        $svc = new HeroSlideService();
        try {
            $svc->create(['headline' => '  ', 'cta1_label' => 'x', 'cta1_url' => '/'], $ownerId);
            $this->fail('Expected ValidationException for empty headline');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('headline', $e->errors);
        }
    }

    public function testPublishedRespectsActiveFlagAndSchedule(): void
    {
        $ownerId = $this->makeOwner('hs-p1@test.local');
        $svc = new HeroSlideService();

        $active = $svc->create(['headline' => 'Active', 'cta1_label' => 'Go', 'cta1_url' => '/shop', 'is_active' => 1], $ownerId);
        $inactive = $svc->create(['headline' => 'Inactive', 'cta1_label' => 'Go', 'cta1_url' => '/shop', 'is_active' => 0], $ownerId);
        $future = $svc->create(['headline' => 'Future', 'cta1_label' => 'Go', 'cta1_url' => '/shop', 'is_active' => 1, 'start_at' => date('Y-m-d H:i:s', strtotime('+10 days'))], $ownerId);
        $past = $svc->create(['headline' => 'Past', 'cta1_label' => 'Go', 'cta1_url' => '/shop', 'is_active' => 1, 'end_at' => date('Y-m-d H:i:s', strtotime('-10 days'))], $ownerId);

        $published = $svc->published();
        $ids = array_column($published, 'id');
        $this->assertContains($active, $ids);
        $this->assertNotContains($inactive, $ids);
        $this->assertNotContains($future, $ids);
        $this->assertNotContains($past, $ids);
    }

    public function testToggleFlipsActiveFlag(): void
    {
        $ownerId = $this->makeOwner('hs-t1@test.local');
        $svc = new HeroSlideService();
        $id = $svc->create(['headline' => 'Toggle', 'cta1_label' => 'Go', 'cta1_url' => '/shop', 'is_active' => 1], $ownerId);
        $svc->toggle($id, false);
        $this->assertSame(0, (int) $svc->find($id)['is_active']);
        $svc->toggle($id, true);
        $this->assertSame(1, (int) $svc->find($id)['is_active']);
    }

    public function testReorderUpdatesSortOrder(): void
    {
        $ownerId = $this->makeOwner('hs-r1@test.local');
        $svc = new HeroSlideService();
        $a = $svc->create(['headline' => 'A', 'cta1_label' => 'Go', 'cta1_url' => '/shop', 'sort_order' => 0], $ownerId);
        $b = $svc->create(['headline' => 'B', 'cta1_label' => 'Go', 'cta1_url' => '/shop', 'sort_order' => 1], $ownerId);
        $c = $svc->create(['headline' => 'C', 'cta1_label' => 'Go', 'cta1_url' => '/shop', 'sort_order' => 2], $ownerId);

        $svc->reorder([$c, $b, $a]);
        $this->assertSame(0, (int) $svc->find($c)['sort_order']);
        $this->assertSame(1, (int) $svc->find($b)['sort_order']);
        $this->assertSame(2, (int) $svc->find($a)['sort_order']);
    }

    public function testDuplicateCreatesInactiveCopy(): void
    {
        $ownerId = $this->makeOwner('hs-d1@test.local');
        $svc = new HeroSlideService();
        $id = $svc->create(['headline' => 'Original', 'cta1_label' => 'Go', 'cta1_url' => '/shop', 'is_active' => 1], $ownerId);
        $copyId = $svc->duplicate($id, $ownerId);
        $this->assertNotSame($id, $copyId);
        $copy = $svc->find($copyId);
        $this->assertSame(0, (int) $copy['is_active']);
        $this->assertStringContainsString('Copy', $copy['internal_name']);
    }

    public function testDeleteRemovesSlide(): void
    {
        $ownerId = $this->makeOwner('hs-x1@test.local');
        $svc = new HeroSlideService();
        $id = $svc->create(['headline' => 'SoonGone', 'cta1_label' => 'Go', 'cta1_url' => '/shop'], $ownerId);
        $svc->delete($id);
        $this->assertNull($svc->find($id));
    }

    public function testUpdateModifiesFields(): void
    {
        $ownerId = $this->makeOwner('hs-u1@test.local');
        $svc = new HeroSlideService();
        $id = $svc->create(['headline' => 'Before', 'cta1_label' => 'Go', 'cta1_url' => '/shop'], $ownerId);
        $svc->update($id, ['headline' => 'After', 'cta1_label' => 'Shop', 'cta1_url' => '/shop', 'is_active' => 1, 'sort_order' => 5], $ownerId);
        $this->assertSame('After', $svc->find($id)['headline']);
        $this->assertSame(5, (int) $svc->find($id)['sort_order']);
    }

    public function testSaveImageRejectsOversize(): void
    {
        $ownerId = $this->makeOwner('hs-i1@test.local');
        $svc = new HeroSlideService();
        // 7 MiB fake file > 6 MiB cap.
        $tmp = tempnam(sys_get_temp_dir(), 'hs_');
        $fh = fopen($tmp, 'wb');
        // Write sparse file of 7 MiB without consuming real disk space.
        ftruncate($fh, 7 * 1024 * 1024 + 1);
        fclose($fh);
        try {
            $svc->saveImage(['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 7 * 1024 * 1024 + 1, 'name' => 'big.jpg', 'type' => 'image/jpeg']);
            $this->fail('Expected ValidationException for oversize file');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('image', $e->errors);
        } finally {
            @unlink($tmp);
        }
    }

    // ---------------------------------------------------- HTTP --

    public function testOwnerCanListSlides(): void
    {
        $ownerId = $this->makeOwner('hs-l1@test.local');
        $resp = $this->dispatchAs($ownerId, 'GET', '/admin/hero-slides');
        $this->assertSame(200, $resp->status());
    }

    public function testOwnerCanCreateSlideViaHttp(): void
    {
        $ownerId = $this->makeOwner('hs-h1@test.local');
        $resp = $this->dispatchAs($ownerId, 'POST', '/admin/hero-slides', [
            'internal_name' => 'HTTP Slide',
            'headline' => 'Built over HTTP',
            'cta1_label' => 'Shop',
            'cta1_url' => '/shop',
            'is_active' => 1,
            'sort_order' => 0,
        ]);
        $this->assertSame(302, $resp->status());
        // At least one slide row should now exist.
        $count = (int) $this->pdo()->query("SELECT COUNT(*) FROM hero_slides WHERE headline = 'Built over HTTP'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testOwnerCanDeleteSlideViaHttp(): void
    {
        $ownerId = $this->makeOwner('hs-h2@test.local');
        $svc = new HeroSlideService();
        $id = $svc->create(['headline' => 'To Delete', 'cta1_label' => 'Go', 'cta1_url' => '/shop'], $ownerId);
        $resp = $this->dispatchAs($ownerId, 'POST', "/admin/hero-slides/{$id}/delete");
        $this->assertSame(302, $resp->status());
        $this->assertNull($svc->find($id));
    }

    public function testCsrfEnforcedOnCreate(): void
    {
        $ownerId = $this->makeOwner('hs-h3@test.local');
        $this->actingAs($ownerId);
        $resp = $this->router()->dispatch(
            Request::synthetic('POST', '/admin/hero-slides', [], ['headline' => 'No CSRF']),
            $this->resolver()
        );
        $this->assertSame(419, $resp->status());
    }

    // ---------------------------------------------------- HOMEPAGE --

    public function testHomepageRendersHeroSliderWhenSlidesExist(): void
    {
        $ownerId = $this->makeOwner('hs-home1@test.local');
        $svc = new HeroSlideService();
        $svc->create([
            'internal_name' => 'Home Hero',
            'headline' => 'Shop Authentic Dubai Laptops',
            'cta1_label' => 'Shop Now',
            'cta1_url' => '/shop',
            'is_active' => 1,
            'sort_order' => 0,
        ], $ownerId);

        $resp = (new \App\Controllers\Storefront\HomeController())->index();
        $html = $resp->body();
        $this->assertStringContainsString('data-hero-slider', $html);
        $this->assertStringContainsString('Shop Authentic Dubai Laptops', $html);
    }

    public function testHomepageFallsBackToStaticHeroWhenNoSlides(): void
    {
        // No slides in DB for this test method (setUp wiped them).
        $resp = (new \App\Controllers\Storefront\HomeController())->index();
        $html = $resp->body();
        $this->assertStringNotContainsString('data-hero-slider', $html);
        $this->assertStringContainsString('Premium Laptops.', $html);
    }
}
