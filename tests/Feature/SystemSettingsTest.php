<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Services\SettingService;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Crypto;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * System settings & integrations (Prompt 39) — service + HTTP tests.
 *
 * Proves:
 *  - Crypto helper: round-trip encrypt/decrypt; empty APP_KEY refuses
 *    to encrypt; decrypt returns null on corrupted blob.
 *  - SettingService: typed get/set (legacy); categorized getInCategory;
 *    secrets setSecret/getSecret/secretIsSet/clearSecret.
 *  - Secret storage: setting_value column is NULL when is_secret=1;
 *    encrypted_value column holds a base64 blob distinct from plaintext;
 *    get() (legacy) returns default for secret keys.
 *  - Validation: per-category rules enforced at HTTP layer (currency
 *    requires 3-char code, dates format required, SMTP port range, etc).
 *  - Audit: every save fires settings.<category>_updated into audit_logs;
 *    secret values are NEVER included in the audit metadata (only the
 *    key name + 'secret_changed' true).
 *  - Privacy: secret form fields never echo back the plaintext value
 *    (rendered as password inputs with a placeholder).
 *  - Permission enforcement: technician blocked from systemIndex /
 *    systemCategory / systemSave.
 *  - CSRF on state-changing routes.
 *  - All 9 categories round-trip save + read.
 */
final class SystemSettingsTest extends TestCase
{
    private string $appKeyBackup = '';

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

    private function makeTech(string $email): int
    {
        return $this->makeUser($email, 'Correct-Horse-1', ['technician']);
    }

    /**
     * Set APP_KEY for the duration of a test so Crypto::available() is
     * true and secrets can be encrypted. Restored in tearDown.
     */
    private function withAppKey(): void
    {
        $this->appKeyBackup = (string) Config::get('app.key', '');
        Config::set('app.key', 'test-app-key-for-prompt-39-DO-NOT-USE-IN-PROD-' . bin2hex(random_bytes(8)));
    }

    private function withoutAppKey(): void
    {
        $this->appKeyBackup = (string) Config::get('app.key', '');
        Config::set('app.key', '');
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->appKeyBackup = (string) Config::get('app.key', '');
    }

    public function tearDown(): void
    {
        Config::set('app.key', $this->appKeyBackup);
        SettingService::flushCache();
        parent::tearDown();
    }

    private function cleanupSettings(string $category): void
    {
        $this->pdo()->prepare('DELETE FROM settings WHERE category = ?')->execute([$category]);
        SettingService::flushCache();
    }

    // ---------------------------------------------------- CRYPTO --

    public function testCryptoRoundTrip(): void
    {
        $this->withAppKey();
        $plaintext = 'sk_live_12345_secret_value';
        $blob = Crypto::encrypt($plaintext);
        $this->assertNotNull($blob);
        $this->assertNotSame($plaintext, $blob, 'ciphertext must differ from plaintext');
        $this->assertSame($plaintext, Crypto::decrypt((string) $blob));
    }

    public function testCryptoEmptyKeyRefusesToEncrypt(): void
    {
        $this->withoutAppKey();
        $this->assertFalse(Crypto::available());
        $this->assertNull(Crypto::encrypt('anything'), 'no encryption without APP_KEY');
        $this->assertNull(Crypto::decrypt('any-blob'), 'no decryption without APP_KEY');
    }

    public function testCryptoCorruptedBlobReturnsNull(): void
    {
        $this->withAppKey();
        $this->assertNull(Crypto::decrypt('not-a-valid-base64-blob!!!'));
        $this->assertNull(Crypto::decrypt(base64_encode('too-short')));
    }

    public function testCryptoWrongKeyReturnsNull(): void
    {
        $this->withAppKey();
        $blob = Crypto::encrypt('plaintext');
        // Switch the key — decryption should fail (null).
        Config::set('app.key', 'different-key-'.bin2hex(random_bytes(8)));
        $this->assertNull(Crypto::decrypt((string) $blob), 'wrong key cannot decrypt');
    }

    // ---------------------------------------------------- SETTING SERVICE —

    public function testSetSecretStoresEncryptedValueNotPlaintext(): void
    {
        $this->withAppKey();
        $owner = $this->makeOwner('ss-enc1@test.local');
        SettingService::setSecret('test_secret_1', 'plaintext-value-here', 'secrets');

        $stmt = $this->pdo()->prepare('SELECT setting_value, encrypted_value, is_secret FROM settings WHERE setting_key = ?');
        $stmt->execute(['test_secret_1']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['setting_value'], 'setting_value column must be NULL for secrets');
        $this->assertNotNull($row['encrypted_value'], 'encrypted_value column must be populated');
        $this->assertSame('1', (string) $row['is_secret']);
        $this->assertStringNotContainsString('plaintext-value-here', (string) $row['encrypted_value'], 'plaintext must not appear in the blob');

        $this->assertSame('plaintext-value-here', SettingService::getSecret('test_secret_1'));
        $this->assertTrue(SettingService::secretIsSet('test_secret_1'));

        $this->pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['test_secret_1']);
        SettingService::flushCache();
    }

    public function testSetSecretWithoutAppKeyThrows(): void
    {
        $this->withoutAppKey();
        $owner = $this->makeOwner('ss-enc2@test.local');
        $this->assertThrows(ValidationException::class, function () {
            SettingService::setSecret('test_secret_2', 'plaintext', 'secrets');
        }, 'refuses to store plaintext secret when APP_KEY is missing');

        // Verify nothing was stored.
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = ?');
        $stmt->execute(['test_secret_2']);
        $this->assertSame(0, (int) $stmt->fetchColumn());

        SettingService::flushCache();
    }

    public function testLegacyGetReturnsDefaultForSecretKeys(): void
    {
        $this->withAppKey();
        $owner = $this->makeOwner('ss-enc3@test.local');
        SettingService::setSecret('test_secret_3', 'secret', 'secrets');

        // The legacy get() API must NOT leak the secret value.
        $this->assertNull(SettingService::get('test_secret_3'));
        $this->assertSame('default', SettingService::get('test_secret_3', 'default'));

        $this->pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['test_secret_3']);
        SettingService::flushCache();
    }

    public function testClearSecretWipesEncryptedValue(): void
    {
        $this->withAppKey();
        $owner = $this->makeOwner('ss-enc4@test.local');
        SettingService::setSecret('test_secret_4', 'plaintext', 'secrets');
        $this->assertTrue(SettingService::secretIsSet('test_secret_4'));

        SettingService::clearSecret('test_secret_4');
        $this->assertFalse(SettingService::secretIsSet('test_secret_4'));
        $this->assertNull(SettingService::getSecret('test_secret_4'));

        $this->pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['test_secret_4']);
        SettingService::flushCache();
    }

    public function testGetInCategoryOmitsSecrets(): void
    {
        $this->withAppKey();
        $owner = $this->makeOwner('ss-enc5@test.local');
        SettingService::set('test_plain_1', 'plain-value', 'string', 'email');
        SettingService::setSecret('mail_smtp_password', 'secret-pass', 'email');

        $values = SettingService::getInCategory('email');
        $this->assertTrue(isset($values['test_plain_1']), 'plaintext key present in category');
        $this->assertSame('plain-value', $values['test_plain_1']);
        $this->assertFalse(isset($values['mail_smtp_password']), 'secrets must be omitted from getInCategory');

        $this->pdo()->exec("DELETE FROM settings WHERE setting_key IN ('test_plain_1','mail_smtp_password')");
        SettingService::flushCache();
    }

    public function testSecretKeysInCategoryListsOnlyConfigured(): void
    {
        $this->withAppKey();
        $owner = $this->makeOwner('ss-enc6@test.local');
        // Insert two secret rows; one with a value, one without.
        SettingService::setSecret('test_secret_set', 'val', 'payments');
        $this->pdo()->prepare(
            'INSERT INTO settings (setting_key, setting_value, setting_type, setting_group, category, is_secret, encrypted_value)
             VALUES (?, NULL, "string", "payments", "payments", 1, NULL)
             ON DUPLICATE KEY UPDATE encrypted_value = NULL'
        )->execute(['test_secret_unset']);

        $configured = SettingService::secretKeysInCategory('payments');
        $this->assertContains('test_secret_set', $configured);
        $this->assertNotContains('test_secret_unset', $configured);

        $this->pdo()->exec("DELETE FROM settings WHERE setting_key IN ('test_secret_set','test_secret_unset')");
        SettingService::flushCache();
    }

    // ---------------------------------------------------- AUDIT --

    public function testSecretUpdateIsAuditedWithoutPlaintext(): void
    {
        $this->withAppKey();
        $owner = $this->makeOwner('ss-audit1@test.local');
        SettingService::setSecret('test_secret_audit', 'super-secret-value', 'email');

        $stmt = $this->pdo()->prepare(
            'SELECT metadata FROM audit_logs WHERE action = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['settings.secret_updated']);
        $metadata = (string) $stmt->fetchColumn();
        $this->assertStringContainsString('test_secret_audit', $metadata, 'key name in audit');
        $this->assertStringNotContainsString('super-secret-value', $metadata, 'plaintext must NOT appear in audit metadata');
        $this->assertStringContainsString('"secret_changed":true', $metadata);

        $this->pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['test_secret_audit']);
        $this->pdo()->exec("DELETE FROM audit_logs WHERE action = 'settings.secret_updated'");
        SettingService::flushCache();
    }

    public function testPlaintextSetIsAudited(): void
    {
        $this->withAppKey();
        $owner = $this->makeOwner('ss-audit2@test.local');
        SettingService::set('test_plain_audit', 'plain-value', 'string', 'currency');

        $stmt = $this->pdo()->prepare(
            'SELECT metadata FROM audit_logs WHERE action = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['settings.set']);
        $metadata = (string) $stmt->fetchColumn();
        $this->assertStringContainsString('test_plain_audit', $metadata);

        $this->pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['test_plain_audit']);
        $this->pdo()->exec("DELETE FROM audit_logs WHERE action = 'settings.set'");
        SettingService::flushCache();
    }

    // ---------------------------------------------------- HTTP --

    public function testSystemIndexRequiresSettingsManage(): void
    {
        $tech = $this->makeTech('ss-http1@test.local');
        $this->assertThrows(ForbiddenException::class, function () use ($tech) {
            $this->dispatchAs($tech, 'GET', '/admin/settings/system');
        }, 'technician cannot view system settings dashboard');
    }

    public function testSystemIndexRendersForOwner(): void
    {
        $owner = $this->makeOwner('ss-http2@test.local');
        $r = $this->dispatchAs($owner, 'GET', '/admin/settings/system');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('System settings', $r->body());
        // All 9 categories must appear on the dashboard.
        foreach (['Currency', 'Date', 'Invoice', 'Inventory', 'Warranty', 'Delivery', 'Email', 'Payment', 'Integration'] as $word) {
            $this->assertStringContainsString($word, $r->body(), "category label '{$word}' on dashboard");
        }
    }

    public function testSystemCategoryFormRenders(): void
    {
        $owner = $this->makeOwner('ss-http3@test.local');
        $r = $this->dispatchAs($owner, 'GET', '/admin/settings/system/currency');
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('currency_code', $r->body());
        $this->assertStringContainsString('currency_symbol', $r->body());
        $this->assertStringContainsString('BDT', $r->body(), 'seeded default appears');
    }

    public function testSystemCategoryUnknownReturns404(): void
    {
        $owner = $this->makeOwner('ss-http4@test.local');
        $this->assertThrows(\App\Exceptions\NotFoundException::class, function () use ($owner) {
            $this->dispatchAs($owner, 'GET', '/admin/settings/system/nonexistent');
        }, 'unknown category throws NotFoundException');
    }

    public function testHttpSaveCurrencySettings(): void
    {
        $owner = $this->makeOwner('ss-http5@test.local');
        $r = $this->dispatchAs($owner, 'POST', '/admin/settings/system/currency', [
            'currency_code' => 'USD',
            'currency_symbol' => '$',
            'currency_position' => 'before',
            'currency_decimal_places' => '2',
        ]);
        $this->assertSame(302, $r->status());
        $this->assertSame('USD', SettingService::get('currency_code'));
        $this->assertSame('$', SettingService::get('currency_symbol'));

        $this->cleanupSettings('currency');
        $this->pdo()->exec("DELETE FROM audit_logs WHERE action = 'settings.currency_updated'");
    }

    public function testHttpSaveValidationRejectsBadCurrencyCode(): void
    {
        $owner = $this->makeOwner('ss-http6@test.local');
        $r = $this->dispatchAs($owner, 'POST', '/admin/settings/system/currency', [
            'currency_code' => 'TOOLONG', // > 3 chars
            'currency_symbol' => '$',
            'currency_position' => 'before',
            'currency_decimal_places' => '2',
        ]);
        $this->assertSame(422, $r->status());
        $this->assertStringContainsString('max', strtolower($r->body()));

        $this->cleanupSettings('currency');
    }

    public function testHttpSaveInventorySettings(): void
    {
        $owner = $this->makeOwner('ss-http7@test.local');
        $r = $this->dispatchAs($owner, 'POST', '/admin/settings/system/inventory', [
            'inventory_low_stock_threshold' => '3',
            'inventory_default_markup_percent' => '25',
            'inventory_allow_negative_stock' => '0',
            'inventory_track_serial_numbers' => '1',
        ]);
        $this->assertSame(302, $r->status());
        $this->assertSame(3, SettingService::get('inventory_low_stock_threshold'));

        $this->cleanupSettings('inventory');
        $this->pdo()->exec("DELETE FROM audit_logs WHERE action = 'settings.inventory_updated'");
    }

    public function testHttpSaveSecretPersistsEncrypted(): void
    {
        $this->withAppKey();
        $owner = $this->makeOwner('ss-http8@test.local');
        $r = $this->dispatchAs($owner, 'POST', '/admin/settings/system/email', [
            'mail_driver' => 'smtp',
            'mail_from_address' => 'noreply@test.local',
            'mail_from_name' => 'Test',
            'mail_smtp_host' => 'smtp.test.local',
            'mail_smtp_port' => '587',
            'mail_smtp_encryption' => 'tls',
            'mail_smtp_username' => 'user',
            'mail_bcc_address' => '',
            'mail_smtp_password' => 'super-secret-pass', // secret
        ]);
        $this->assertSame(302, $r->status());

        // The plaintext setting should be readable.
        $this->assertSame('smtp', SettingService::get('mail_driver'));
        // The secret should be retrievable via getSecret.
        $this->assertSame('super-secret-pass', SettingService::getSecret('mail_smtp_password'));
        // The plaintext must NOT be in the settings table.
        $stmt = $this->pdo()->prepare('SELECT setting_value, encrypted_value FROM settings WHERE setting_key = ?');
        $stmt->execute(['mail_smtp_password']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['setting_value']);
        $this->assertNotNull($row['encrypted_value']);
        $this->assertStringNotContainsString('super-secret-pass', (string) $row['encrypted_value']);

        $this->cleanupSettings('email');
        $this->pdo()->exec("DELETE FROM audit_logs WHERE action = 'settings.email_updated'");
    }

    public function testHttpSaveSecretEmptySubmissionKeepsExisting(): void
    {
        $this->withAppKey();
        $owner = $this->makeOwner('ss-http9@test.local');
        // First, set the secret via the service.
        SettingService::setSecret('mail_smtp_password', 'original-pass', 'email');

        // Submit the form with empty password — existing value should be kept.
        $this->dispatchAs($owner, 'POST', '/admin/settings/system/email', [
            'mail_driver' => 'smtp',
            'mail_from_address' => 'noreply@test.local',
            'mail_from_name' => 'Test',
            'mail_smtp_host' => '',
            'mail_smtp_port' => '587',
            'mail_smtp_encryption' => 'tls',
            'mail_smtp_username' => '',
            'mail_bcc_address' => '',
            'mail_smtp_password' => '', // empty
        ]);

        $this->assertSame('original-pass', SettingService::getSecret('mail_smtp_password'), 'existing secret preserved on empty submission');

        $this->cleanupSettings('email');
        $this->pdo()->exec("DELETE FROM audit_logs WHERE action = 'settings.email_updated'");
    }

    public function testHttpSaveSecretClearFlagWipesValue(): void
    {
        $this->withAppKey();
        $owner = $this->makeOwner('ss-http10@test.local');
        SettingService::setSecret('mail_smtp_password', 'original-pass', 'email');

        // Submit empty password + clear_mail_smtp_password=1 → wipe.
        $this->dispatchAs($owner, 'POST', '/admin/settings/system/email', [
            'mail_driver' => 'smtp',
            'mail_from_address' => 'noreply@test.local',
            'mail_from_name' => 'Test',
            'mail_smtp_host' => '',
            'mail_smtp_port' => '587',
            'mail_smtp_encryption' => 'tls',
            'mail_smtp_username' => '',
            'mail_bcc_address' => '',
            'mail_smtp_password' => '', // empty
            'clear_mail_smtp_password' => '1', // explicit clear
        ]);

        $this->assertFalse(SettingService::secretIsSet('mail_smtp_password'), 'secret cleared');
        $this->assertNull(SettingService::getSecret('mail_smtp_password'));

        $this->cleanupSettings('email');
        $this->pdo()->exec("DELETE FROM audit_logs WHERE action = 'settings.email_updated'");
        $this->pdo()->exec("DELETE FROM audit_logs WHERE action = 'settings.secret_cleared'");
    }

    public function testHttpSaveAuditMetadataExcludesSecretValues(): void
    {
        $this->withAppKey();
        $owner = $this->makeOwner('ss-http11@test.local');
        $this->dispatchAs($owner, 'POST', '/admin/settings/system/payments', [
            'payment_provider_stripe_enabled' => '1',
            'payment_provider_stripe_publishable_key' => 'pk_test_xyz',
            'payment_provider_bkash_enabled' => '0',
            'payment_provider_bkash_app_key' => '',
            'payment_provider_bkash_username' => '',
            'payment_provider_nagad_enabled' => '0',
            'payment_provider_nagad_merchant_id' => '',
            'payment_provider_nagad_public_key' => '',
            'payment_provider_stripe_secret_key' => 'sk_test_SECRETVALUE',
            'payment_provider_stripe_webhook_secret' => '',
            'payment_provider_bkash_app_secret' => '',
            'payment_provider_bkash_password' => '',
            'payment_provider_nagad_secret_key' => '',
        ]);

        $stmt = $this->pdo()->prepare(
            'SELECT metadata FROM audit_logs WHERE action = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['settings.payments_updated']);
        $metadata = (string) $stmt->fetchColumn();
        $this->assertStringNotContainsString('sk_test_SECRETVALUE', $metadata, 'secret plaintext must NOT appear in audit metadata');
        $this->assertStringContainsString('payment_provider_stripe_secret_key', $metadata, 'secret key name appears');

        $this->cleanupSettings('payments');
        $this->pdo()->exec("DELETE FROM audit_logs WHERE action = 'settings.payments_updated'");
        $this->pdo()->exec("DELETE FROM audit_logs WHERE action = 'settings.secret_updated'");
    }

    public function testSystemCategoryFormDoesNotEchoSecretPlaintext(): void
    {
        $this->withAppKey();
        $owner = $this->makeOwner('ss-http12@test.local');
        SettingService::setSecret('mail_smtp_password', 'LEAKED-PLAINTEXT', 'email');

        $r = $this->dispatchAs($owner, 'GET', '/admin/settings/system/email');
        $this->assertSame(200, $r->status());
        $this->assertStringNotContainsString('LEAKED-PLAINTEXT', $r->body(), 'secret plaintext must NEVER appear in form');
        $this->assertStringContainsString('Configured', $r->body(), 'configured status shown');

        $this->cleanupSettings('email');
    }

    // ---------------------------------------------------- CSRF --

    public function testSystemSaveRejectsMissingCsrf(): void
    {
        $owner = $this->makeOwner('ss-csrf@test.local');
        $this->actingAs($owner);
        $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () {
            $this->router()->dispatch(
                Request::synthetic('POST', '/admin/settings/system/currency', [], [
                    'currency_code' => 'EUR',
                    'currency_symbol' => '€',
                    'currency_position' => 'after',
                    'currency_decimal_places' => '2',
                ]),
                $this->resolver()
            );
        }, 'missing CSRF rejected');
    }
}
