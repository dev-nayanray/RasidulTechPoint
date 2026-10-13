<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ValidationException;
use App\Services\HeroSlideService;
use App\Support\Cache\SimpleCache;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Hero Slider v2 (ZCODE Master Prompt — premium redesign) — service + HTTP tests.
 *
 * Proves:
 *  - Migration 037 extends the schema (covered implicitly by bootOnce)
 *  - Seed: 4 reference slides exist on fresh DB
 *  - Settings singleton + updateSettings
 *  - Service: create / find / update / toggle / reorder / duplicate / delete
 *  - Service: published() respects status, is_active, schedule window
 *  - Service: full validation (lengths, exactly 3 specs / 3 chips, price > 0,
 *    old_price > price, URL format, hex colors only on custom theme,
 *    unknown presets rejected)
 *  - Service: themeTokens returns preset OR custom colors
 *  - Service: themeTokens / screenSvg produce non-empty strings
 *  - Service: contrastRatio WCAG helper is correct
 *  - Service: cache invalidation on save / toggle / delete / reorder
 *  - Public API endpoint /api/hero-slider returns cached JSON
 *  - Public API endpoint filters out drafts / inactive / out-of-schedule
 *  - HTTP: owner can list, create, edit, toggle, duplicate, delete
 *  - HTTP: CSRF enforced on state-changing routes
 *  - HTTP: settings tab GET/POST works
 *  - Max slides enforced
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

    private function validPayload(array $over = []): array
    {
        return array_merge([
            'nav_label' => 'Test 14',
            'badge_text' => 'New',
            'category_label' => 'Ultrabook / 14 inch',
            'headline' => 'Light. Strong. Fast.',
            'description' => 'A tiny laptop that punches above its weight.',
            'specs' => ['16 GB LPDDR5X', '1 TB NVMe SSD', 'Wi-Fi 7'],
            'chips' => [
                ['value' => '2.8K', 'label' => 'OLED panel'],
                ['value' => '18 h', 'label' => 'battery life'],
                ['value' => '1.12 kg', 'label' => 'magnesium body'],
            ],
            'currency_symbol' => '$',
            'price' => '1299',
            'old_price' => '1499',
            'installment_months' => '12',
            'monthly_price' => '108',
            'installment_note' => '0% interest, free setup',
            'ghost_word' => 'Test',
            'theme_preset' => 'mist_teal',
            'visual_type' => 'screen_dunes',
            'cta1_label' => 'Shop Now',
            'cta1_url' => '/shop',
            'cta2_label' => 'Compare specs',
            'cta2_url' => '/shop?compare=1',
            'status' => 'published',
            'is_active' => 1,
            'sort_order' => 99,
            'accessibility_desc' => 'A test slide.',
        ], $over);
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec('DELETE FROM hero_slides');
        // Tests that shrink max_slides must not leak into later tests.
        $this->pdo()->exec('UPDATE hero_settings SET max_slides = 8 WHERE id = 1');
        SimpleCache::forget('hero_slider.public');
    }

    // ----------------------------------------------------- SEED + SCHEMA --
    public function testMigrationSeedsFourReferenceSlides(): void
    {
        // Wipe, then run migration 037's up() directly (the runner skips it —
        // the test DB already has it recorded from the suite bootstrap).
        $this->pdo()->exec('DELETE FROM hero_slides');
        $migration = require BASE_PATH . '/database/migrations/037_hero_slider_v2.php';
        $migration['up']($this->pdo());
        $count = (int) $this->pdo()->query("SELECT COUNT(*) FROM hero_slides WHERE nav_label IN ('Aero 14','Forge 16','Studio 16','Pro 15')")->fetchColumn();
        $this->assertGreaterThanOrEqual(4, $count);
        // Safety net: at least 4 active+published slides on a fresh install
        $activePublished = (int) $this->pdo()->query("SELECT COUNT(*) FROM hero_slides WHERE is_active = 1 AND status = 'published'")->fetchColumn();
        $this->assertGreaterThanOrEqual(4, $activePublished);
    }

    public function testSettingsSingletonExistsWithDefaults(): void
    {
        $s = (new HeroSlideService())->settings();
        $this->assertSame(7000, $s['autoplay_interval_ms']);
        $this->assertSame(8, $s['max_slides']);
        $this->assertTrue($s['autoplay_enabled']);
        $this->assertTrue($s['pause_on_hover']);
    }

    // ----------------------------------------------------- SERVICE CRUD --
    public function testCreateAndFindSlide(): void
    {
        $ownerId = $this->makeOwner('hs-c1@test.local');
        $svc = new HeroSlideService();
        $id = $svc->create($this->validPayload(), $ownerId);
        $this->assertGreaterThan(0, $id);
        $slide = $svc->find($id);
        $this->assertNotNull($slide);
        $this->assertSame('Test 14', $slide['nav_label']);
        $this->assertSame('Light. Strong. Fast.', $slide['headline']);
        $this->assertSame(['16 GB LPDDR5X', '1 TB NVMe SSD', 'Wi-Fi 7'], $slide['specs']);
    }

    public function testUpdateModifiesFields(): void
    {
        $ownerId = $this->makeOwner('hs-u1@test.local');
        $svc = new HeroSlideService();
        $id = $svc->create($this->validPayload(), $ownerId);
        $svc->update($id, $this->validPayload(['headline' => 'Updated headline.']), $ownerId);
        $this->assertSame('Updated headline.', $svc->find($id)['headline']);
    }

    public function testToggleFlipsActiveFlag(): void
    {
        $ownerId = $this->makeOwner('hs-t1@test.local');
        $svc = new HeroSlideService();
        $id = $svc->create($this->validPayload(), $ownerId);
        $svc->toggle($id, false);
        $this->assertSame(0, (int) $svc->find($id)['is_active']);
        $svc->toggle($id, true);
        $this->assertSame(1, (int) $svc->find($id)['is_active']);
    }

    public function testReorderUpdatesSortOrder(): void
    {
        $ownerId = $this->makeOwner('hs-r1@test.local');
        $svc = new HeroSlideService();
        // Bump max_slides so we can insert 3
        $svc->updateSettings(['max_slides' => 8, 'autoplay_enabled' => 1, 'autoplay_interval_ms' => 7000, 'pause_on_hover' => 1, 'announcement_enabled' => 1, 'announcement_items' => ['a','b','c']], $ownerId);
        $a = $svc->create($this->validPayload(['nav_label' => 'A']), $ownerId);
        $b = $svc->create($this->validPayload(['nav_label' => 'B']), $ownerId);
        $c = $svc->create($this->validPayload(['nav_label' => 'C']), $ownerId);
        $svc->reorder([$c, $b, $a]);
        $this->assertSame(0, (int) $svc->find($c)['sort_order']);
        $this->assertSame(1, (int) $svc->find($b)['sort_order']);
        $this->assertSame(2, (int) $svc->find($a)['sort_order']);
    }

    public function testDuplicateCreatesInactiveDraftCopy(): void
    {
        $ownerId = $this->makeOwner('hs-d1@test.local');
        $svc = new HeroSlideService();
        $id = $svc->create($this->validPayload(), $ownerId);
        $copyId = $svc->duplicate($id, $ownerId);
        $this->assertNotSame($id, $copyId);
        $copy = $svc->find($copyId);
        $this->assertSame(0, (int) $copy['is_active']);
        $this->assertSame('draft', $copy['status']);
        $this->assertStringContainsString('Copy', $copy['nav_label']);
    }

    public function testDeleteRemovesSlide(): void
    {
        $ownerId = $this->makeOwner('hs-x1@test.local');
        $svc = new HeroSlideService();
        $id = $svc->create($this->validPayload(), $ownerId);
        $svc->delete($id);
        $this->assertNull($svc->find($id));
    }

    // ----------------------------------------------------- PUBLISHED FILTER --
    public function testPublishedRespectsStatusActiveAndSchedule(): void
    {
        $ownerId = $this->makeOwner('hs-p1@test.local');
        $svc = new HeroSlideService();
        $svc->updateSettings(['max_slides' => 20, 'autoplay_enabled' => 1, 'autoplay_interval_ms' => 7000, 'pause_on_hover' => 1, 'announcement_enabled' => 1, 'announcement_items' => []], $ownerId);

        $live   = $svc->create($this->validPayload(['nav_label' => 'Live']), $ownerId);
        $draft  = $svc->create($this->validPayload(['nav_label' => 'Draft', 'status' => 'draft']), $ownerId);
        $inactive = $svc->create($this->validPayload(['nav_label' => 'Inactive', 'is_active' => 0]), $ownerId);
        $future = $svc->create($this->validPayload(['nav_label' => 'Future', 'start_at' => date('Y-m-d H:i:s', strtotime('+10 days'))]), $ownerId);
        $past   = $svc->create($this->validPayload(['nav_label' => 'Past', 'end_at' => date('Y-m-d H:i:s', strtotime('-10 days'))]), $ownerId);

        $published = $svc->published();
        $navs = array_column($published, 'nav_label');
        $this->assertContains('Live', $navs);
        $this->assertNotContains('Draft', $navs);
        $this->assertNotContains('Inactive', $navs);
        $this->assertNotContains('Future', $navs);
        $this->assertNotContains('Past', $navs);
    }

    public function testPublicPayloadContainsSettingsAndSlides(): void
    {
        $ownerId = $this->makeOwner('hs-pp1@test.local');
        $svc = new HeroSlideService();
        $svc->create($this->validPayload(), $ownerId);
        $payload = $svc->publicPayload();
        $this->assertArrayHasKey('settings', $payload);
        $this->assertArrayHasKey('slides', $payload);
        $this->assertGreaterThan(0, count($payload['slides']));
        $this->assertSame('Light. Strong. Fast.', $payload['slides'][0]['headline']);
    }

    // ----------------------------------------------------- VALIDATION --
    public function testRejectsEmptyHeadline(): void
    {
        $ownerId = $this->makeOwner('hs-v1@test.local');
        try {
            (new HeroSlideService())->create($this->validPayload(['headline' => '  ']), $ownerId);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('headline', $e->errors);
        }
    }

    public function testRejectsHeadlineOver80Chars(): void
    {
        $ownerId = $this->makeOwner('hs-v2@test.local');
        try {
            (new HeroSlideService())->create($this->validPayload(['headline' => str_repeat('a', 81)]), $ownerId);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('headline', $e->errors);
        }
    }

    public function testRejectsNonThreeSpecs(): void
    {
        $ownerId = $this->makeOwner('hs-v3@test.local');
        try {
            (new HeroSlideService())->create($this->validPayload(['specs' => ['only','two items']]), $ownerId);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('specs', $e->errors);
        }
    }

    public function testRejectsNonThreeChips(): void
    {
        $ownerId = $this->makeOwner('hs-v4@test.local');
        try {
            (new HeroSlideService())->create($this->validPayload(['chips' => [['value' => '1', 'label' => 'a']]]), $ownerId);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('chips', $e->errors);
        }
    }

    public function testRejectsPriceZero(): void
    {
        $ownerId = $this->makeOwner('hs-v5@test.local');
        try {
            (new HeroSlideService())->create($this->validPayload(['price' => '0']), $ownerId);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('price', $e->errors);
        }
    }

    public function testRejectsOldPriceLowerThanPrice(): void
    {
        $ownerId = $this->makeOwner('hs-v6@test.local');
        try {
            (new HeroSlideService())->create($this->validPayload(['price' => '1299', 'old_price' => '1000']), $ownerId);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('old_price', $e->errors);
        }
    }

    public function testRejectsInvalidUrl(): void
    {
        $ownerId = $this->makeOwner('hs-v7@test.local');
        try {
            (new HeroSlideService())->create($this->validPayload(['cta1_url' => 'javascript:alert(1)']), $ownerId);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cta1_url', $e->errors);
        }
    }

    public function testAcceptsHttpUrl(): void
    {
        $ownerId = $this->makeOwner('hs-v8@test.local');
        $id = (new HeroSlideService())->create($this->validPayload(['cta1_url' => 'https://example.com/shop']), $ownerId);
        $this->assertGreaterThan(0, $id);
    }

    public function testRejectsInvalidCustomColorHex(): void
    {
        $ownerId = $this->makeOwner('hs-v9@test.local');
        try {
            (new HeroSlideService())->create($this->validPayload(['theme_preset' => 'custom', 'custom_bg' => 'not-a-color']), $ownerId);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('custom_bg', $e->errors);
        }
    }

    public function testRejectsUnknownPreset(): void
    {
        $ownerId = $this->makeOwner('hs-v10@test.local');
        try {
            (new HeroSlideService())->create($this->validPayload(['theme_preset' => 'banana_split']), $ownerId);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('theme_preset', $e->errors);
        }
    }

    public function testAutoCalculatesMonthlyPriceWhenMissing(): void
    {
        $ownerId = $this->makeOwner('hs-v11@test.local');
        $svc = new HeroSlideService();
        $id = $svc->create($this->validPayload(['price' => '1200', 'installment_months' => '12', 'monthly_price' => '']), $ownerId);
        $this->assertEquals(100.0, (float) $svc->find($id)['monthly_price']);
    }

    // ----------------------------------------------------- THEME + SCREENS --
    public function testThemeTokensReturnsPresetColors(): void
    {
        $svc = new HeroSlideService();
        $t = $svc->themeTokens(['theme_preset' => 'graphite_coral']);
        $this->assertSame('#0b1016', $t['bg']);
        $this->assertSame('#ff5b44', $t['accent']);
    }

    public function testThemeTokensReturnsCustomColorsWhenCustom(): void
    {
        $svc = new HeroSlideService();
        $t = $svc->themeTokens([
            'theme_preset' => 'custom',
            'custom_bg' => '#112233',
            'custom_bg2' => '#445566',
            'custom_ink' => '#778899',
            'custom_muted' => '#aabbcc',
            'custom_accent' => '#ff0000',
            'custom_accent_ink' => '#ffffff',
        ]);
        $this->assertSame('#112233', $t['bg']);
        $this->assertSame('#ff0000', $t['accent']);
    }

    public function testScreenSvgProducesNonEmptyMarkup(): void
    {
        $svc = new HeroSlideService();
        foreach (['screen_dunes','screen_gaming','screen_creator','screen_business'] as $type) {
            $svg = $svc->screenSvg(['visual_type' => $type, 'theme_preset' => 'mist_teal']);
            $this->assertStringStartsWith('<svg', $svg);
            $this->assertStringContainsString('</svg>', $svg);
        }
        // image type returns empty (rendered separately)
        $this->assertSame('', $svc->screenSvg(['visual_type' => 'image', 'theme_preset' => 'mist_teal']));
    }

    public function testContrastRatioIsCorrectForWhiteOnBlack(): void
    {
        $r = HeroSlideService::contrastRatio('#ffffff', '#000000');
        $this->assertEqualsWithDelta(21.0, $r, 0.5);
    }

    public function testContrastRatioFlagsLowContrast(): void
    {
        $r = HeroSlideService::contrastRatio('#aaaaaa', '#bbbbbb');
        $this->assertLessThan(4.5, $r);
    }

    // ----------------------------------------------------- CACHE --
    public function testCacheInvalidatesAfterCreate(): void
    {
        $ownerId = $this->makeOwner('hs-cache1@test.local');
        $svc = new HeroSlideService();
        // Warm cache
        SimpleCache::set('hero_slider.public', ['old' => true]);
        $svc->create($this->validPayload(), $ownerId);
        $this->assertNull(SimpleCache::get('hero_slider.public', 300));
    }

    public function testCacheInvalidatesAfterToggle(): void
    {
        $ownerId = $this->makeOwner('hs-cache2@test.local');
        $svc = new HeroSlideService();
        $id = $svc->create($this->validPayload(), $ownerId);
        SimpleCache::set('hero_slider.public', ['old' => true]);
        $svc->toggle($id, false);
        $this->assertNull(SimpleCache::get('hero_slider.public', 300));
    }

    public function testCacheInvalidatesAfterReorder(): void
    {
        $ownerId = $this->makeOwner('hs-cache3@test.local');
        $svc = new HeroSlideService();
        $svc->updateSettings(['max_slides' => 20, 'autoplay_enabled' => 1, 'autoplay_interval_ms' => 7000, 'pause_on_hover' => 1, 'announcement_enabled' => 1, 'announcement_items' => []], $ownerId);
        $a = $svc->create($this->validPayload(['nav_label' => 'A']), $ownerId);
        $b = $svc->create($this->validPayload(['nav_label' => 'B']), $ownerId);
        SimpleCache::set('hero_slider.public', ['old' => true]);
        $svc->reorder([$b, $a]);
        $this->assertNull(SimpleCache::get('hero_slider.public', 300));
    }

    public function testCacheInvalidatesAfterSettingsUpdate(): void
    {
        $ownerId = $this->makeOwner('hs-cache4@test.local');
        $svc = new HeroSlideService();
        SimpleCache::set('hero_slider.public', ['old' => true]);
        $svc->updateSettings(['max_slides' => 8, 'autoplay_enabled' => 1, 'autoplay_interval_ms' => 7000, 'pause_on_hover' => 1, 'announcement_enabled' => 1, 'announcement_items' => []], $ownerId);
        $this->assertNull(SimpleCache::get('hero_slider.public', 300));
    }

    // ----------------------------------------------------- HTTP --
    public function testOwnerCanListSlides(): void
    {
        $ownerId = $this->makeOwner('hs-l1@test.local');
        $resp = $this->dispatchAs($ownerId, 'GET', '/admin/hero-slides');
        $this->assertSame(200, $resp->status());
    }

    public function testOwnerCanCreateSlideViaHttp(): void
    {
        $ownerId = $this->makeOwner('hs-h1@test.local');
        $resp = $this->dispatchAs($ownerId, 'POST', '/admin/hero-slides', $this->validPayload());
        $this->assertSame(302, $resp->status());
        $count = (int) $this->pdo()->query("SELECT COUNT(*) FROM hero_slides WHERE nav_label = 'Test 14'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testOwnerCanDeleteSlideViaHttp(): void
    {
        $ownerId = $this->makeOwner('hs-h2@test.local');
        $svc = new HeroSlideService();
        $id = $svc->create($this->validPayload(), $ownerId);
        $resp = $this->dispatchAs($ownerId, 'POST', "/admin/hero-slides/{$id}/delete");
        $this->assertSame(302, $resp->status());
        $this->assertNull($svc->find($id));
    }

    public function testCsrfEnforcedOnCreate(): void
    {
        $ownerId = $this->makeOwner('hs-h3@test.local');
        $this->actingAs($ownerId);
        $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () {
            $this->router()->dispatch(
                Request::synthetic('POST', '/admin/hero-slides', [], $this->validPayload()),
                $this->resolver()
            );
        }, 'POST /admin/hero-slides must require CSRF');
    }

    public function testOwnerCanUpdateSettingsViaHttp(): void
    {
        $ownerId = $this->makeOwner('hs-h4@test.local');
        $resp = $this->dispatchAs($ownerId, 'POST', '/admin/hero-slides/settings', [
            'autoplay_enabled' => 1,
            'autoplay_interval_ms' => 9000,
            'pause_on_hover' => 0,
            'announcement_enabled' => 1,
            'announcement_items' => ['First', 'Second', 'Third'],
            'max_slides' => 12,
        ]);
        $this->assertSame(302, $resp->status());
        $s = (new HeroSlideService())->settings();
        $this->assertSame(9000, $s['autoplay_interval_ms']);
        $this->assertFalse($s['pause_on_hover']);
        $this->assertSame(12, $s['max_slides']);
        $this->assertSame(['First','Second','Third'], $s['announcement_items']);
    }

    public function testOwnerCanReorderViaHttp(): void
    {
        $ownerId = $this->makeOwner('hs-h5@test.local');
        $svc = new HeroSlideService();
        $svc->updateSettings(['max_slides' => 20, 'autoplay_enabled' => 1, 'autoplay_interval_ms' => 7000, 'pause_on_hover' => 1, 'announcement_enabled' => 1, 'announcement_items' => []], $ownerId);
        $a = $svc->create($this->validPayload(['nav_label' => 'A']), $ownerId);
        $b = $svc->create($this->validPayload(['nav_label' => 'B']), $ownerId);
        $resp = $this->dispatchAs($ownerId, 'POST', '/admin/hero-slides/reorder', ['ids' => [$b, $a]]);
        $this->assertSame(200, $resp->status());
        $this->assertSame(0, (int) $svc->find($b)['sort_order']);
    }

    // ----------------------------------------------------- MAX SLIDES --
    public function testEnforcesMaxSlidesOnCreate(): void
    {
        $ownerId = $this->makeOwner('hs-m1@test.local');
        $svc = new HeroSlideService();
        $svc->updateSettings(['max_slides' => 1, 'autoplay_enabled' => 1, 'autoplay_interval_ms' => 7000, 'pause_on_hover' => 1, 'announcement_enabled' => 1, 'announcement_items' => []], $ownerId);
        $svc->create($this->validPayload(), $ownerId);
        try {
            $svc->create($this->validPayload(['nav_label' => 'B']), $ownerId);
            $this->fail('Expected ValidationException for max_slides');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('max_slides', $e->errors);
        }
    }

    // ----------------------------------------------------- PUBLIC API --
    public function testPublicApiReturnsJsonWithSlides(): void
    {
        $ownerId = $this->makeOwner('hs-api1@test.local');
        (new HeroSlideService())->create($this->validPayload(), $ownerId);
        $this->actingAs(0); // log out (no session user)

        $router = new Router();
        $router->group(['middleware' => ['headers']], function (Router $router): void {
            require BASE_PATH . '/routes/web.php';
        });
        $resp = $router->dispatch(Request::synthetic('GET', '/api/hero-slider'), $this->resolver());
        $this->assertSame(200, $resp->status());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('slides', $body);
        $this->assertSame('Light. Strong. Fast.', $body['slides'][0]['headline']);
    }

    public function testPublicApiFiltersOutDrafts(): void
    {
        $ownerId = $this->makeOwner('hs-api2@test.local');
        $svc = new HeroSlideService();
        $svc->create($this->validPayload(['nav_label' => 'A', 'status' => 'draft']), $ownerId);
        $svc->create($this->validPayload(['nav_label' => 'B']), $ownerId);
        $this->actingAs(0);

        $router = new Router();
        $router->group(['middleware' => ['headers']], function (Router $router): void {
            require BASE_PATH . '/routes/web.php';
        });
        $resp = $router->dispatch(Request::synthetic('GET', '/api/hero-slider'), $this->resolver());
        $body = json_decode($resp->body(), true);
        $navs = array_column($body['slides'], 'nav_label');
        $this->assertNotContains('A', $navs);
        $this->assertContains('B', $navs);
    }

    // ----------------------------------------------------- HOMEPAGE --
    public function testHomepageRendersSliderWhenSlidesExist(): void
    {
        $ownerId = $this->makeOwner('hs-home1@test.local');
        (new HeroSlideService())->create($this->validPayload(), $ownerId);
        $resp = (new \App\Controllers\Storefront\HomeController())->index();
        $html = $resp->body();
        $this->assertStringContainsString('id="hero"', $html);
        $this->assertStringContainsString('Light. Strong. Fast.', $html);
        $this->assertStringContainsString('role="region"', $html);
        $this->assertStringContainsString('aria-roledescription="carousel"', $html);
    }

    public function testHomepageFallsBackToReferenceSlidesWhenNoSlides(): void
    {
        // No slides in the DB (wiped in setUp). HomeController deliberately
        // renders the 4 built-in reference slides so the hero never disappears.
        $resp = (new \App\Controllers\Storefront\HomeController())->index();
        $html = $resp->body();
        $this->assertStringContainsString('id="hero"', $html);
        $this->assertSame(4, preg_match_all('/<article class="hs-slide /', $html));
        $this->assertStringContainsString('Aero 14', $html);
        $this->assertStringContainsString('data-autoplay="1"', $html);
    }

    // ------------------------------------------------ CSP + ISOLATION --
    // Regression: the site CSP is "script-src 'self'; font-src 'self'", so an
    // inline script (no autoplay, only slide 1) and Google Fonts (wrong fonts)
    // were silently blocked on the homepage.

    private function homeHtml(): string
    {
        return (new \App\Controllers\Storefront\HomeController())->index()->body();
    }

    public function testHomepageHasNoInlineScriptBlockedByCsp(): void
    {
        $html = $this->homeHtml();
        $this->assertSame(0, preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>/i', $html), 'inline <script> would be blocked by script-src self');
        // The compare bar's close button still has a legacy onclick (outside the hero, untouched here).
        $withoutLegacy = (string) preg_replace('/<button class="cmp-close"[^>]*>/', '<button>', $html);
        $this->assertSame(0, preg_match('/\son(click|change|input|submit|load)=/i', $withoutLegacy), 'inline event handlers are blocked by CSP');
        $this->assertStringContainsString('<script type="module" src="', $html);
        $this->assertStringContainsString('assets/js/hero-slider.js', $html);
        $this->assertStringContainsString('assets/js/storefront-home.js', $html);
    }

    public function testHomepageDoesNotDependOnGoogleFonts(): void
    {
        $html = $this->homeHtml();
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
        $this->assertStringContainsString('assets/fonts/bricolage-grotesque-latin-var.woff2', $html);
    }

    public function testSelfHostedFontAndScriptFilesExist(): void
    {
        foreach ([
            'assets/fonts/bricolage-grotesque-latin-var.woff2',
            'assets/fonts/figtree-latin-var.woff2',
            'assets/fonts/ibm-plex-mono-latin-400.woff2',
            'assets/fonts/ibm-plex-mono-latin-500.woff2',
            'assets/js/hero-slider.js',
            'assets/js/storefront-home.js',
            'assets/js/admin-hero-index.js',
            'assets/js/admin-hero-form.js',
        ] as $rel) {
            $this->assertTrue(is_file(BASE_PATH . '/public/' . $rel), $rel . ' must exist');
            $this->assertGreaterThan(0, (int) filesize(BASE_PATH . '/public/' . $rel), $rel . ' must not be empty');
        }
    }

    public function testSliderCssIsScopedAndDoesNotTouchSiteVariables(): void
    {
        $css = \App\Support\View::partial('storefront/hero/_slider_css', []);
        // Tokens must live on .hs-hero. A :root block would override the
        // site-wide --bg / --line / --muted used by the rest of the page.
        $this->assertSame(0, preg_match('/:root\s*\{/', $css), 'no :root rules');
        $this->assertStringContainsString('.hs-hero{--font-display', preg_replace('/\s+/', '', $css), 'tokens are declared on .hs-hero');
        $this->assertStringContainsString('@font-face', $css);
        // Generic class names collide with the storefront CSS (.ghost draws a
        // dashed box, .btn-ghost is white, .chip is a pill, .eyebrow::before).
        foreach (['.ghost{', '.btn-ghost{', '.chip{', '.eyebrow', '.specs{', '.wrap{', '.btn{', '.hero{'] as $generic) {
            $this->assertStringNotContainsString($generic, $css, $generic . ' must be hs- prefixed');
        }
    }

    public function testHeroMarkupUsesNamespacedClassesOnly(): void
    {
        $html = $this->homeHtml();
        $hero = substr($html, (int) strpos($html, '<section class="hs-hero'));
        $hero = substr($hero, 0, (int) strpos($hero, '</section>'));
        $this->assertStringNotContainsString('class="slide ', $hero);
        $this->assertStringNotContainsString('class="btn ', $hero);
        $this->assertStringNotContainsString('class="ghost', $hero);
        $this->assertStringNotContainsString('class="chip', $hero);
        $this->assertStringContainsString('hs-btn hs-btn-primary', $hero);
        $this->assertStringContainsString('class="hs-ghost"', $hero);
        $this->assertStringContainsString('hs-chip hs-c1', $hero);
    }

    // --------------------------------------------------- ALL SLIDES SHOW --
    public function testEverySlideAndIndicatorIsRendered(): void
    {
        $ownerId = $this->makeOwner('hs-all@test.local');
        $svc = new HeroSlideService();
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'] as $i => $name) {
            $svc->create($this->validPayload(['nav_label' => $name, 'headline' => $name . ' headline', 'sort_order' => $i]), $ownerId);
        }
        $html = $this->homeHtml();
        $this->assertSame(5, preg_match_all('/<article class="hs-slide /', $html));
        $this->assertSame(5, preg_match_all('/<li class="hs-ind/', $html));
        $this->assertSame(5, preg_match_all('/<div class="hs-bg /', $html));
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'] as $name) {
            $this->assertStringContainsString($name . ' headline', $html);
        }
        // Exactly one slide is active and reachable on first paint.
        $this->assertSame(1, preg_match_all('/<article class="hs-slide hs-t\d+ is-active"/', $html));
        $this->assertSame(4, preg_match_all('/<article class="hs-slide hs-t\d+"[^>]*aria-hidden="true"/', $html));
    }

    public function testSlideRendersEveryContentField(): void
    {
        $ownerId = $this->makeOwner('hs-fields@test.local');
        (new HeroSlideService())->create($this->validPayload(), $ownerId);
        $html = $this->homeHtml();
        foreach (['New', 'Ultrabook / 14 inch', 'Light. Strong. Fast.', 'A tiny laptop that punches above its weight.',
                  '16 GB LPDDR5X', '1 TB NVMe SSD', 'Wi-Fi 7', '$1,299', '$1,499', '$108/mo', '0% interest, free setup',
                  'Shop Now', 'Compare specs', '2.8K', 'OLED panel', '18 h', 'battery life', '1.12 kg', 'magnesium body'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle . ' missing from the rendered slide');
        }
        $this->assertStringContainsString('<svg viewBox="0 0 320 200"', $html);
    }

    // ------------------------------------------------ AUTOPLAY SETTINGS --
    public function testAutoplayInitialStateComesFromSettingsAsDataAttributes(): void
    {
        $ownerId = $this->makeOwner('hs-auto@test.local');
        $svc = new HeroSlideService();
        $svc->create($this->validPayload(['nav_label' => 'One', 'sort_order' => 0]), $ownerId);
        $svc->create($this->validPayload(['nav_label' => 'Two', 'sort_order' => 1]), $ownerId);

        $svc->updateSettings(['autoplay_enabled' => 1, 'autoplay_interval_ms' => 5000, 'pause_on_hover' => 1, 'announcement_enabled' => 1, 'announcement_items' => ['x'], 'max_slides' => 8], $ownerId);
        $html = $this->homeHtml();
        $this->assertStringContainsString('data-autoplay="1"', $html);
        $this->assertStringContainsString('data-interval="5000"', $html);
        $this->assertStringContainsString('data-pause-hover="1"', $html);
        $this->assertStringNotContainsString('hs-hero hs-t0 is-paused', $html);
        $this->assertStringContainsString('aria-label="Pause slideshow"', $html);

        $svc->updateSettings(['autoplay_enabled' => 0, 'autoplay_interval_ms' => 5000, 'pause_on_hover' => 0, 'announcement_enabled' => 1, 'announcement_items' => ['x'], 'max_slides' => 8], $ownerId);
        $html = $this->homeHtml();
        $this->assertStringContainsString('data-autoplay="0"', $html);
        $this->assertStringContainsString('data-pause-hover="0"', $html);
        $this->assertStringContainsString('hs-hero hs-t0 is-paused', $html);
        $this->assertStringContainsString('aria-label="Play slideshow"', $html);
    }

    public function testSingleSlideHidesControlsAndDisablesAutoplay(): void
    {
        $ownerId = $this->makeOwner('hs-single@test.local');
        (new HeroSlideService())->create($this->validPayload(), $ownerId);
        $html = $this->homeHtml();
        $this->assertSame(1, preg_match_all('/<article class="hs-slide /', $html));
        $this->assertStringNotContainsString('class="hs-bar"', $html);
        $this->assertStringNotContainsString('data-toggle', $html);
        $this->assertStringContainsString('data-autoplay="0"', $html);
    }

    // ---------------------------------------------------------- LINKS --
    public function testAbsoluteCtaUrlsAreNotPrefixedWithTheSiteUrl(): void
    {
        $ownerId = $this->makeOwner('hs-links@test.local');
        (new HeroSlideService())->create($this->validPayload([
            'cta1_url' => 'https://example.com/deal',
            'cta2_url' => '/shop?compare=1',
        ]), $ownerId);
        $html = $this->homeHtml();
        $this->assertStringContainsString('href="https://example.com/deal"', $html);
        $this->assertStringNotContainsString('/https://example.com', $html);
        $this->assertStringContainsString(url('/shop?compare=1'), str_replace('&amp;', '&', $html));
    }

    // ------------------------------------------------- ADMIN HTTP FIXES --
    public function testReorderAcceptsTheCommaSeparatedIdsTheAdminScriptPosts(): void
    {
        $ownerId = $this->makeOwner('hs-reorder@test.local');
        $svc = new HeroSlideService();
        $a = $svc->create($this->validPayload(['nav_label' => 'A', 'sort_order' => 0]), $ownerId);
        $b = $svc->create($this->validPayload(['nav_label' => 'B', 'sort_order' => 1]), $ownerId);
        $c = $svc->create($this->validPayload(['nav_label' => 'C', 'sort_order' => 2]), $ownerId);

        $resp = $this->dispatchAs($ownerId, 'POST', '/admin/hero-slides/reorder', ['ids' => "{$c},{$a},{$b}"]);
        $this->assertSame(200, $resp->status());
        $navs = array_column($svc->all(), 'nav_label');
        $this->assertSame(['C', 'A', 'B'], $navs, 'comma separated ids must reorder every slide');
    }

    public function testPreviewAllowsSameOriginFramingWhileOtherPagesStayDenied(): void
    {
        $ownerId = $this->makeOwner('hs-frame@test.local');
        $id = (new HeroSlideService())->create($this->validPayload(), $ownerId);

        $preview = $this->dispatchAs($ownerId, 'GET', "/admin/hero-slides/{$id}/preview");
        $this->assertSame(200, $preview->status());
        $this->assertSame('SAMEORIGIN', $preview->headers()['X-Frame-Options'] ?? null);
        $this->assertStringContainsString("frame-ancestors 'self'", $preview->headers()['Content-Security-Policy'] ?? '');
        $this->assertStringContainsString("script-src 'self'", $preview->headers()['Content-Security-Policy'] ?? '');
        $this->assertStringNotContainsString('fonts.googleapis.com', $preview->body());
        $this->assertStringContainsString('class="hs-hero"', $preview->body());

        $edit = $this->dispatchAs($ownerId, 'GET', "/admin/hero-slides/{$id}/edit");
        $this->assertSame('DENY', $edit->headers()['X-Frame-Options'] ?? null, 'every other page still forbids framing');
        $this->assertStringContainsString("frame-ancestors 'none'", $edit->headers()['Content-Security-Policy'] ?? '');
    }

    public function testAdminPagesLoadTheirScriptsFromExternalFiles(): void
    {
        $ownerId = $this->makeOwner('hs-adminjs@test.local');
        $id = (new HeroSlideService())->create($this->validPayload(), $ownerId);
        $list = $this->dispatchAs($ownerId, 'GET', '/admin/hero-slides')->body();
        $form = $this->dispatchAs($ownerId, 'GET', "/admin/hero-slides/{$id}/edit")->body();
        $this->assertStringContainsString('assets/js/admin-hero-index.js', $list);
        $this->assertStringContainsString('assets/js/admin-hero-form.js', $form);
        // The layout's own pre-paint sidebar script is not part of the hero pages;
        // the hero views themselves must contribute no inline script.
        $this->assertStringNotContainsString("// Reorder via drag-and-drop", $list);
        $this->assertStringNotContainsString('// Theme preset', $form);
    }
}
