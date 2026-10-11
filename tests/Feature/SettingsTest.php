<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Services\SettingService;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Business profile settings — HTTP-level tests.
 *
 * Proves:
 *  - profile validation (name required, email format, warranty range)
 *  - profile persistence (INSERT + UPDATE paths)
 *  - logo upload: valid JPEG → stored; invalid MIME → rejected;
 *    oversized → rejected
 *  - business hours: all 7 days upserted; closed flag clears times
 *  - invoice settings: prefix, next_number, tax_rate, footer, terms
 *    persisted via SettingService
 *  - about_text persisted
 *  - audit trail for profile, logo, business hours, invoice changes
 *  - permission enforcement (manager blocked)
 *  - CSRF on the settings POST route
 */
final class SettingsTest extends TestCase
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

    private function dispatchAs(int $userId, string $method, string $uri, array $post = [], array $files = []): Response
    {
        $this->actingAs($userId);
        return $this->router()->dispatch(
            Request::synthetic($method, $uri, [], $this->withCsrf($post), [], [], $files),
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

    /** Create a minimal valid JPEG file on disk for upload testing. */
    private function makeTestImage(string $path): void
    {
        // Minimal JPEG: SOI (FF D8) + APP0 (FF E0) + JFIF header + EOI (FF D9).
        // This is enough for finfo to detect as image/jpeg — no GD needed.
        $data = "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xff\xd9";
        file_put_contents($path, $data);
    }

    /** Create a plain text file that looks like a script for MIME-rejection testing. */
    private function makeTestTextFile(string $path): void
    {
        file_put_contents($path, '<?php echo "XSS"; ?>');
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testManagerBlockedFromSettings(): void
    {
        $managerId = $this->makeUser('settings-mgr@test.local', 'Correct-Horse-1', ['manager']);
        $this->assertThrows(ForbiddenException::class, function () use ($managerId) {
            $this->dispatchAs($managerId, 'GET', '/admin/settings');
        }, 'manager (no settings.manage) must not view settings');

        $this->assertThrows(ForbiddenException::class, function () use ($managerId) {
            $this->dispatchAs($managerId, 'POST', '/admin/settings', ['name' => 'X']);
        }, 'manager must not update settings');
    }

    // ---------------------------------------------------- PROFILE --

    public function testOwnerCanViewSettings(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('settings-view@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/settings');
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Business profile/', $response->body());
        $this->assertMatchesRegularExpression('/Business hours/', $response->body());
        $this->assertMatchesRegularExpression('/Invoice settings/', $response->body());
        $this->assertMatchesRegularExpression('/Business logo/', $response->body());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testProfileValidationRejectsEmptyName(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('settings-empty-name@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/settings', [
            'name' => '', // required
        ]);
        $this->assertSame(422, $response->status(), 'empty name must fail validation');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testProfileUpdatePersistsFields(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('settings-update@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/settings', [
            'name' => 'Rasidul Tech Point Updated',
            'legal_name' => 'RTP LLC',
            'tagline' => 'Best Laptops',
            'email' => 'updated@rtp.test',
            'phone' => '+8801799999999',
            'address' => 'New Road 42',
            'city' => 'Chittagong',
            'country' => 'Bangladesh',
            'about_text' => 'We sell quality laptops.',
            'default_warranty_days' => 60,
            'invoice_prefix' => 'INV',
            'invoice_next_number' => 1,
            'invoice_tax_name' => 'VAT',
            'invoice_tax_rate' => '0',
            'invoice_footer_text' => '',
            'invoice_payment_terms' => '',
        ]);

        $this->assertSame(302, $response->status());

        // Verify the profile was updated.
        $profile = $this->pdo()->query('SELECT * FROM business_profiles ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Rasidul Tech Point Updated', $profile['name']);
        $this->assertSame('updated@rtp.test', $profile['email']);
        $this->assertSame('We sell quality laptops.', $profile['about_text']);
        $this->assertSame(60, (int) $profile['default_warranty_days']);

        // Verify audit trail.
        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'settings.business_profile_updated' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('settings.business_profile_updated', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- LOGO UPLOAD --

    public function testValidLogoUploadIsStored(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('settings-logo@test.local', 'Correct-Horse-1', ['owner']);

        $tmpFile = tempnam(sys_get_temp_dir(), 'logo_test_');
        $this->makeTestImage($tmpFile);

        $files = [
            'logo' => [
                'name' => 'logo.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ];

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/settings', [
            'name' => 'Logo Test Business',
            'invoice_prefix' => 'INV',
            'invoice_next_number' => 1,
            'invoice_tax_name' => 'VAT',
            'invoice_tax_rate' => '0',
        ], $files);

        $this->assertSame(302, $response->status(), 'valid logo upload must redirect');

        // Verify logo_path was set.
        $profile = $this->pdo()->query('SELECT logo_path FROM business_profiles ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($profile['logo_path']);
        $this->assertStringStartsWith('uploads/logos/', $profile['logo_path']);
        $this->assertTrue(file_exists(BASE_PATH . '/public/' . $profile['logo_path']), 'logo file must exist on disk');

        // Verify audit trail includes logo_changed=true.
        $audit = $this->pdo()->prepare("SELECT metadata FROM audit_logs WHERE action = 'settings.business_profile_updated' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $meta = json_decode((string) $audit->fetchColumn(), true);
        $this->assertTrue($meta['logo_changed']);

        // Cleanup the uploaded file.
        @unlink(BASE_PATH . '/public/' . $profile['logo_path']);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testInvalidMimeTypeLogoRejected(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('settings-bad-mime@test.local', 'Correct-Horse-1', ['owner']);

        // Snapshot the current logo_path (may have been set by a previous
        // test — the DB is shared across test methods).
        $before = $this->pdo()->query('SELECT logo_path FROM business_profiles ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $beforeLogo = $before['logo_path'] ?? null;

        $tmpFile = tempnam(sys_get_temp_dir(), 'logo_bad_');
        $this->makeTestTextFile($tmpFile);

        $files = [
            'logo' => [
                'name' => 'evil.php.jpg',
                'type' => 'image/jpeg', // client claims JPEG
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ];

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/settings', [
            'name' => 'Bad MIME Test',
            'invoice_prefix' => 'INV',
            'invoice_next_number' => 1,
            'invoice_tax_name' => 'VAT',
            'invoice_tax_rate' => '0',
        ], $files);

        $this->assertSame(422, $response->status(), 'non-image file must be rejected');

        // Verify logo_path did NOT change (the upload was rejected, so the
        // existing logo_path — if any — must be untouched).
        $after = $this->pdo()->query('SELECT logo_path FROM business_profiles ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($beforeLogo, $after['logo_path'] ?? null, 'logo_path must NOT change on rejected upload');

        // Cleanup.
        @unlink($tmpFile);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    public function testOversizedLogoRejected(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('settings-big-logo@test.local', 'Correct-Horse-1', ['owner']);

        // Create a fake file entry with an exaggerated size.
        $tmpFile = tempnam(sys_get_temp_dir(), 'logo_big_');
        $this->makeTestImage($tmpFile);

        $files = [
            'logo' => [
                'name' => 'huge.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 10 * 1024 * 1024, // 10 MiB — over the 4 MiB limit
            ],
        ];

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/settings', [
            'name' => 'Big Logo Test',
            'invoice_prefix' => 'INV',
            'invoice_next_number' => 1,
            'invoice_tax_name' => 'VAT',
            'invoice_tax_rate' => '0',
        ], $files);

        $this->assertSame(422, $response->status(), 'oversized file must be rejected');

        // Cleanup.
        @unlink($tmpFile);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- BUSINESS HOURS --

    public function testBusinessHoursUpsert(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('settings-hours@test.local', 'Correct-Horse-1', ['owner']);

        $hours = [];
        foreach (range(0, 6) as $day) {
            $hours[$day] = [
                'is_closed' => $day === 5 ? 1 : 0, // Friday closed
                'open_time' => '09:00',
                'close_time' => '21:00',
                'notes' => $day === 6 ? 'Weekend hours' : '',
            ];
        }

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/settings', [
            'name' => 'Hours Test',
            'business_hours' => $hours,
            'invoice_prefix' => 'INV',
            'invoice_next_number' => 1,
            'invoice_tax_name' => 'VAT',
            'invoice_tax_rate' => '0',
        ]);

        $this->assertSame(302, $response->status());

        // Verify business_hours were updated.
        $bh = $this->pdo()->query('SELECT * FROM business_hours ORDER BY day_of_week')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(7, $bh);
        // Friday (day 5) must be closed.
        $this->assertSame(1, (int) $bh[5]['is_closed']);
        $this->assertNull($bh[5]['open_time']);
        $this->assertNull($bh[5]['close_time']);
        // Saturday (day 6) must have notes.
        $this->assertSame('Weekend hours', $bh[6]['notes']);
        // Monday (day 1) must be open 09:00-21:00.
        $this->assertSame(0, (int) $bh[1]['is_closed']);
        $this->assertSame('09:00:00', $bh[1]['open_time']);
        $this->assertSame('21:00:00', $bh[1]['close_time']);

        // Verify audit trail.
        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'settings.business_hours_updated' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('settings.business_hours_updated', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- INVOICE SETTINGS --

    public function testInvoiceSettingsPersist(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('settings-invoice@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/settings', [
            'name' => 'Invoice Test',
            'invoice_prefix' => 'RTP',
            'invoice_next_number' => 42,
            'invoice_tax_name' => 'SD',
            'invoice_tax_rate' => '7.5',
            'invoice_footer_text' => 'Thank you for shopping with RTP!',
            'invoice_payment_terms' => 'Net 15',
        ]);

        $this->assertSame(302, $response->status());

        // Verify settings were persisted via SettingService.
        $this->assertSame('RTP', SettingService::get('invoice_prefix'));
        $this->assertSame(42, SettingService::get('invoice_next_number'));
        $this->assertSame('SD', SettingService::get('invoice_tax_name'));
        $this->assertSame('7.5', SettingService::get('invoice_tax_rate'));
        $this->assertSame('Thank you for shopping with RTP!', SettingService::get('invoice_footer_text'));
        $this->assertSame('Net 15', SettingService::get('invoice_payment_terms'));

        // Verify audit trail.
        $audit = $this->pdo()->prepare("SELECT metadata FROM audit_logs WHERE action = 'settings.invoice_updated' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $meta = json_decode((string) $audit->fetchColumn(), true);
        $this->assertSame('RTP', $meta['prefix']);
        $this->assertSame(42, $meta['next_number']);
        $this->assertSame('SD', $meta['tax_name']);
        $this->assertSame(7.5, (float) $meta['tax_rate']);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CSRF --

    public function testSettingsPostRequiresCsrf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('settings-csrf@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($ownerId);

        $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () {
            $this->router()->dispatch(
                Request::synthetic('POST', '/admin/settings', [], ['name' => 'X']),
                $this->resolver()
            );
        }, 'POST /admin/settings must require CSRF token');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- ABOUT TEXT --

    public function testAboutTextPersisted(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('settings-about@test.local', 'Correct-Horse-1', ['owner']);

        $aboutText = 'Rasidul Tech Point imports authentic used laptops from Dubai. Every unit is tested and quality-checked before sale.';

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/settings', [
            'name' => 'About Test',
            'about_text' => $aboutText,
            'invoice_prefix' => 'INV',
            'invoice_next_number' => 1,
            'invoice_tax_name' => 'VAT',
            'invoice_tax_rate' => '0',
        ]);

        $this->assertSame(302, $response->status());

        $profile = $this->pdo()->query('SELECT about_text FROM business_profiles ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($aboutText, $profile['about_text']);

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CURRENCY --

    public function testCurrencyIsFixedToBdt(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('settings-currency@test.local', 'Correct-Horse-1', ['owner']);

        $response = $this->dispatchAs($ownerId, 'GET', '/admin/settings');
        $this->assertSame(200, $response->status());
        $this->assertMatchesRegularExpression('/Currency is fixed to BDT/', $response->body(), 'the view must document that currency is locked to BDT');

        // Verify the business_profiles schema enforces BDT.
        $profile = $this->pdo()->query('SELECT currency FROM business_profiles ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        if ($profile) {
            $this->assertSame('BDT', $profile['currency']);
        }

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ownerId]);
    }
}
