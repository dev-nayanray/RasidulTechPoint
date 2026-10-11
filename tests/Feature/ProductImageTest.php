<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ForbiddenException;
use App\Support\Csrf;
use App\Support\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\MiddlewareResolver;
use Tests\TestCase;

/**
 * Product media manager — HTTP-level tests.
 *
 * Proves:
 *  - upload valid JPEG → stored with random filename + DB record + audit
 *  - first uploaded image is automatically primary
 *  - invalid MIME (PHP text file) → rejected, no DB record
 *  - oversized file → rejected
 *  - set primary → only one primary at a time
 *  - delete image → file removed from disk + DB record removed + audit
 *  - delete primary → next image promoted to primary
 *  - reorder up/down → sort_order swapped
 *  - .htaccess exists in upload directory (prevents script execution)
 *  - permission enforcement
 *  - CSRF on all state-changing routes
 */
final class ProductImageTest extends TestCase
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

    private function makeTestImage(string $path): void
    {
        $data = "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xff\xd9";
        file_put_contents($path, $data);
    }

    private function makeTestTextFile(string $path): void
    {
        file_put_contents($path, '<?php echo "XSS"; ?>');
    }

    // ---------------------------------------------------- PERMISSIONS --

    public function testCashierBlockedFromUploadingImages(): void
    {
        $cashierId = $this->makeUser('img-cashier@test.local', 'Correct-Horse-1', ['sales_cashier']);
        [, , $modelId] = $this->makeCatalog();

        $this->assertThrows(ForbiddenException::class, function () use ($cashierId, $modelId) {
            $this->dispatchAs($cashierId, 'POST', '/admin/products/' . $modelId . '/images', []);
        }, 'cashier (no products.update) must not upload images');
    }

    // ---------------------------------------------------- UPLOAD --

    public function testValidImageUploadStoredWithDbRecord(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('img-upload@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();

        $tmpFile = tempnam(sys_get_temp_dir(), 'img_test_');
        $this->makeTestImage($tmpFile);

        $files = [
            'image' => [
                'name' => 'product.jpg', 'type' => 'image/jpeg',
                'tmp_name' => $tmpFile, 'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ];

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/products/' . $modelId . '/images', [], $files);
        $this->assertSame(302, $response->status());

        // Verify DB record.
        $stmt = $this->pdo()->prepare('SELECT id, file_path, is_primary, sort_order FROM product_images WHERE product_model_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$modelId]);
        $img = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertTrue($img !== false);
        $this->assertStringStartsWith('uploads/products/product-', $img['file_path']);
        $this->assertSame(1, (int) $img['is_primary'], 'first image must be primary');
        $this->assertSame(0, (int) $img['sort_order']);
        $this->assertTrue(file_exists(BASE_PATH . '/public/' . $img['file_path']), 'file must exist on disk');

        // Verify audit.
        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'product_image.uploaded' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('product_image.uploaded', $audit->fetchColumn());

        // Cleanup.
        @unlink(BASE_PATH . '/public/' . $img['file_path']);
        $this->pdo()->prepare('DELETE FROM product_images WHERE id = ?')->execute([$img['id']]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testInvalidMimeTypeRejected(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('img-bad-mime@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();

        $tmpFile = tempnam(sys_get_temp_dir(), 'img_bad_');
        $this->makeTestTextFile($tmpFile);

        $files = [
            'image' => [
                'name' => 'evil.php.jpg', 'type' => 'image/jpeg',
                'tmp_name' => $tmpFile, 'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ];

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/products/' . $modelId . '/images', [], $files);
        $this->assertSame(302, $response->status(), 'invalid MIME must redirect (not 200)');

        // Verify no DB record was created.
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM product_images WHERE product_model_id = ?');
        $count->execute([$modelId]);
        $this->assertSame(0, (int) $count->fetchColumn(), 'no image record must be created for invalid MIME');

        @unlink($tmpFile);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testOversizedFileRejected(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('img-big@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();

        $tmpFile = tempnam(sys_get_temp_dir(), 'img_big_');
        $this->makeTestImage($tmpFile);

        $files = [
            'image' => [
                'name' => 'huge.jpg', 'type' => 'image/jpeg',
                'tmp_name' => $tmpFile, 'error' => UPLOAD_ERR_OK,
                'size' => 10 * 1024 * 1024, // 10 MiB > 4 MiB limit
            ],
        ];

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/products/' . $modelId . '/images', [], $files);
        $this->assertSame(302, $response->status(), 'oversized file must redirect');

        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM product_images WHERE product_model_id = ?');
        $count->execute([$modelId]);
        $this->assertSame(0, (int) $count->fetchColumn(), 'no image record for oversized file');

        @unlink($tmpFile);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- PRIMARY --

    public function testSetPrimaryUnsetsOthers(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('img-primary@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();

        // Insert two images directly.
        $stmt = $this->pdo()->prepare('INSERT INTO product_images (product_model_id, file_path, is_primary, sort_order) VALUES (?, ?, ?, ?)');
        $stmt->execute([$modelId, 'uploads/products/test-a.jpg', 1, 0]);
        $imgA = (int) $this->pdo()->lastInsertId();
        $stmt->execute([$modelId, 'uploads/products/test-b.jpg', 0, 1]);
        $imgB = (int) $this->pdo()->lastInsertId();

        // Set B as primary.
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/products/images/' . $imgB . '/primary');
        $this->assertSame(302, $response->status());

        // Verify only B is primary.
        $stmt = $this->pdo()->prepare('SELECT is_primary FROM product_images WHERE id = ?');
        $stmt->execute([$imgA]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'A must no longer be primary');
        $stmt->execute([$imgB]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'B must be primary');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM product_images WHERE id IN (?, ?)')->execute([$imgA, $imgB]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- DELETE --

    public function testDeleteRemovesFileAndDbRecord(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('img-del@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();

        // Create a real file on disk.
        $filePath = 'uploads/products/test-delete-' . bin2hex(random_bytes(8)) . '.jpg';
        $fullPath = BASE_PATH . '/public/' . $filePath;
        file_put_contents($fullPath, 'test');

        // Insert DB record.
        $stmt = $this->pdo()->prepare('INSERT INTO product_images (product_model_id, file_path, is_primary, sort_order) VALUES (?, ?, 1, 0)');
        $stmt->execute([$modelId, $filePath]);
        $imgId = (int) $this->pdo()->lastInsertId();

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/products/images/' . $imgId . '/delete');
        $this->assertSame(302, $response->status());

        // Verify DB record removed.
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM product_images WHERE id = ?');
        $stmt->execute([$imgId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'DB record must be removed');

        // Verify file removed from disk.
        $this->assertFalse(file_exists($fullPath), 'file must be deleted from disk');

        // Verify audit.
        $audit = $this->pdo()->prepare("SELECT action FROM audit_logs WHERE action = 'product_image.deleted' ORDER BY id DESC LIMIT 1");
        $audit->execute();
        $this->assertSame('product_image.deleted', $audit->fetchColumn());

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    public function testDeletePrimaryPromotesNextImage(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('img-del-pri@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();

        $stmt = $this->pdo()->prepare('INSERT INTO product_images (product_model_id, file_path, is_primary, sort_order) VALUES (?, ?, ?, ?)');
        $stmt->execute([$modelId, 'uploads/products/pri-a.jpg', 1, 0]);
        $imgA = (int) $this->pdo()->lastInsertId();
        $stmt->execute([$modelId, 'uploads/products/pri-b.jpg', 0, 1]);
        $imgB = (int) $this->pdo()->lastInsertId();

        // Delete the primary image (A).
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/products/images/' . $imgA . '/delete');
        $this->assertSame(302, $response->status());

        // B should now be primary.
        $stmt = $this->pdo()->prepare('SELECT is_primary FROM product_images WHERE id = ?');
        $stmt->execute([$imgB]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'next image must be promoted to primary');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imgB]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- REORDER --

    public function testReorderUpSwapsSortOrder(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('img-reorder@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();

        $stmt = $this->pdo()->prepare('INSERT INTO product_images (product_model_id, file_path, is_primary, sort_order) VALUES (?, ?, ?, ?)');
        $stmt->execute([$modelId, 'uploads/products/r-a.jpg', 1, 0]);
        $imgA = (int) $this->pdo()->lastInsertId();
        $stmt->execute([$modelId, 'uploads/products/r-b.jpg', 0, 1]);
        $imgB = (int) $this->pdo()->lastInsertId();

        // Move B up (should swap with A).
        $response = $this->dispatchAs($ownerId, 'POST', '/admin/products/images/' . $imgB . '/reorder', ['direction' => 'up']);
        $this->assertSame(302, $response->status());

        // Verify sort_order swapped.
        $stmt = $this->pdo()->prepare('SELECT sort_order FROM product_images WHERE id = ?');
        $stmt->execute([$imgA]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'A should have sort_order=1 after B moved up');
        $stmt->execute([$imgB]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'B should have sort_order=0 after moving up');

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM product_images WHERE id IN (?, ?)')->execute([$imgA, $imgB]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- .HTACCESS --

    public function testHtaccessPreventsScriptExecution(): void
    {
        $this->assertTrue(file_exists(BASE_PATH . '/public/uploads/.htaccess'), 'uploads/.htaccess must exist');
        $this->assertTrue(file_exists(BASE_PATH . '/public/uploads/products/.htaccess'), 'uploads/products/.htaccess must exist');
        $this->assertTrue(file_exists(BASE_PATH . '/public/uploads/brands/.htaccess'), 'uploads/brands/.htaccess must exist');
        $this->assertTrue(file_exists(BASE_PATH . '/public/uploads/logos/.htaccess'), 'uploads/logos/.htaccess must exist');

        $content = file_get_contents(BASE_PATH . '/public/uploads/products/.htaccess');
        $this->assertStringContainsString('php', strtolower($content), '.htaccess must disable PHP execution');
        $this->assertStringContainsString('Require all denied', $content, '.htaccess must deny script files');
    }

    // ---------------------------------------------------- FILENAME RANDOMIZATION --

    public function testFilenameIsRandomized(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('img-rand@test.local', 'Correct-Horse-1', ['owner']);
        [, , $modelId] = $this->makeCatalog();

        $tmpFile = tempnam(sys_get_temp_dir(), 'img_rand_');
        $this->makeTestImage($tmpFile);

        $files = [
            'image' => [
                'name' => 'my-product-photo-2024.jpg', // client-provided name
                'type' => 'image/jpeg',
                'tmp_name' => $tmpFile, 'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ];

        $response = $this->dispatchAs($ownerId, 'POST', '/admin/products/' . $modelId . '/images', [], $files);
        $this->assertSame(302, $response->status());

        $stmt = $this->pdo()->prepare('SELECT file_path FROM product_images WHERE product_model_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$modelId]);
        $filePath = (string) $stmt->fetchColumn();

        // The stored filename must NOT contain the client-provided name.
        $this->assertStringNotContainsString('my-product-photo', $filePath, 'client filename must NOT be used');
        $this->assertStringStartsWith('uploads/products/product-', $filePath, 'must use the safe prefix');
        $this->assertStringEndsWith('.jpg', $filePath, 'must end with the correct extension');

        // Cleanup.
        @unlink(BASE_PATH . '/public/' . $filePath);
        $this->pdo()->prepare('DELETE FROM product_images WHERE product_model_id = ?')->execute([$modelId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }

    // ---------------------------------------------------- CSRF --

    public function testAllStateChangingRoutesRequireCsrf(): void
    {
        $this->wipeOwners();
        $ownerId = $this->makeUser('img-csrf@test.local', 'Correct-Horse-1', ['owner']);
        $this->actingAs($ownerId);
        [, , $modelId] = $this->makeCatalog();

        $stmt = $this->pdo()->prepare('INSERT INTO product_images (product_model_id, file_path, is_primary, sort_order) VALUES (?, ?, 1, 0)');
        $stmt->execute([$modelId, 'uploads/products/csrf-test.jpg']);
        $imgId = (int) $this->pdo()->lastInsertId();

        $routes = [
            ['POST', '/admin/products/' . $modelId . '/images', []],
            ['POST', '/admin/products/images/' . $imgId . '/primary', []],
            ['POST', '/admin/products/images/' . $imgId . '/delete', []],
            ['POST', '/admin/products/images/' . $imgId . '/reorder', ['direction' => 'up']],
        ];

        foreach ($routes as [$method, $uri, $post]) {
            $this->assertThrows(\App\Exceptions\CsrfTokenMismatchException::class, function () use ($method, $uri, $post) {
                $this->router()->dispatch(
                    Request::synthetic($method, $uri, [], $post),
                    $this->resolver()
                );
            }, "{$method} {$uri} must require CSRF");
        }

        // Cleanup.
        $this->pdo()->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imgId]);
        $this->pdo()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    }
}
