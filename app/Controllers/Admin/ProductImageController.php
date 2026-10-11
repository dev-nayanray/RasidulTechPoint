<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\CatalogRepository;
use App\Services\AuditService;
use App\Support\Config;
use App\Support\Database\Connection;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;
use PDO;

/**
 * Product image manager: secure uploads, ordering, primary selection,
 * and safe deletion.
 *
 * Security:
 *  - MIME type validated via finfo (never trusts client Content-Type)
 *  - Size enforced from config/security.php (default 4 MiB)
 *  - Randomized filenames (bin2hex(random_bytes(16)) + extension)
 *  - Stored in public/uploads/products/ (served) with .htaccess that
 *    disables PHP execution and forces download for non-image types
 *  - Deletion removes both the DB record AND the file from disk
 *  - Only one primary image per model (setting primary unsets others)
 */
final class ProductImageController
{
    private const UPLOAD_DIR = 'uploads/products';
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Upload one or more images for a product model.
     */
    public function upload(string $id, Request $request): Response
    {
        $catalog = new CatalogRepository();
        $model = $catalog->findModel((int) $id);
        if ($model === null) {
            throw new NotFoundException('Product model not found.');
        }

        $file = $request->file('image');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash_error('No file selected.');
            return Response::redirect(url('/admin/products/' . $id));
        }

        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            flash_error('Upload failed (error code ' . (int) $file['error'] . ').');
            return Response::redirect(url('/admin/products/' . $id));
        }

        // Size check
        $maxBytes = (int) Config::get('security.uploads.max_bytes', 4 * 1024 * 1024);
        if (($file['size'] ?? 0) > $maxBytes) {
            flash_error('Image must be at most ' . round($maxBytes / 1024 / 1024, 1) . ' MiB.');
            return Response::redirect(url('/admin/products/' . $id));
        }

        // Real MIME detection (never trust client Content-Type)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, self::ALLOWED_MIMES, true)) {
            flash_error('Image must be JPEG, PNG, or WebP.');
            return Response::redirect(url('/admin/products/' . $id));
        }

        // Safe randomized filename
        $ext = match ($realMime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };
        $safeName = 'product-' . bin2hex(random_bytes(16)) . '.' . $ext;
        $destDir = BASE_PATH . '/public/' . self::UPLOAD_DIR;
        if (!is_dir($destDir)) {
            mkdir($destDir, 0770, true);
        }
        $destPath = $destDir . '/' . $safeName;

        if (is_uploaded_file($file['tmp_name'])) {
            move_uploaded_file($file['tmp_name'], $destPath);
        } else {
            rename($file['tmp_name'], $destPath);
        }

        // Determine sort_order (append to end)
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_model_id = ?');
        $stmt->execute([$id]);
        $sortOrder = (int) $stmt->fetchColumn();

        // If this is the first image, make it primary
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_model_id = ?');
        $countStmt->execute([$id]);
        $isFirst = (int) $countStmt->fetchColumn() === 0;

        $stmt = $pdo->prepare(
            'INSERT INTO product_images (product_model_id, file_path, is_primary, sort_order)
             VALUES (?, ?, ?, ?)'
        );
        $filePath = self::UPLOAD_DIR . '/' . $safeName;
        $stmt->execute([(int) $id, $filePath, $isFirst ? 1 : 0, $sortOrder]);
        $imageId = (int) $pdo->lastInsertId();

        AuditService::record('product_image.uploaded', 'product_image', $imageId, [
            'product_model_id' => (int) $id,
            'file_path' => $filePath,
            'is_primary' => $isFirst,
        ], $request);

        flash_success('Image uploaded.');
        return Response::redirect(url('/admin/products/' . $id));
    }

    /**
     * Set an image as the primary for its model (unsets all others).
     */
    public function setPrimary(string $imageId, Request $request): Response
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare('SELECT id, product_model_id FROM product_images WHERE id = ?');
        $stmt->execute([$imageId]);
        $image = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($image === false) {
            throw new NotFoundException('Image not found.');
        }

        $modelId = (int) $image["product_model_id"];

        Connection::transaction(function (PDO $pdo) use ($imageId, $modelId) {
            $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_model_id = ?')
                ->execute([$modelId]);
            $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ?')
                ->execute([$imageId]);
        });

        AuditService::record('product_image.set_primary', 'product_image', (int) $imageId, [
            'product_model_id' => $modelId,
        ], $request);

        flash_success('Primary image updated.');
        return Response::redirect(url('/admin/products/' . $modelId));
    }

    /**
     * Delete an image: remove the file from disk + the DB record.
     */
    public function destroy(string $imageId, Request $request): Response
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare('SELECT id, product_model_id, file_path, is_primary FROM product_images WHERE id = ?');
        $stmt->execute([$imageId]);
        $image = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($image === false) {
            throw new NotFoundException('Image not found.');
        }

        $modelId = (int) $image["product_model_id"];
        $filePath = (string) $image['file_path'];
        $wasPrimary = (int) $image['is_primary'] === 1;

        Connection::transaction(function (PDO $pdo) use ($imageId, $modelId, $wasPrimary) {
            $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);

            // If the deleted image was primary, promote the next one.
            if ($wasPrimary) {
                $pdo->prepare(
                    'UPDATE product_images SET is_primary = 1
                     WHERE product_model_id = ? AND id = (
                         SELECT id FROM (
                             SELECT id FROM product_images WHERE product_model_id = ?
                             ORDER BY sort_order ASC, id ASC LIMIT 1
                         ) AS t
                     )'
                )->execute([$modelId, $modelId]);
            }
        });

        // Remove the file from disk.
        $fullPath = BASE_PATH . '/public/' . $filePath;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }

        AuditService::record('product_image.deleted', 'product_image', (int) $imageId, [
            'product_model_id' => $modelId,
            'file_path' => $filePath,
            'was_primary' => $wasPrimary,
        ], $request);

        flash_success('Image deleted.');
        return Response::redirect(url('/admin/products/' . $modelId));
    }

    /**
     * Reorder images via up/down movement.
     */
    public function reorder(string $imageId, Request $request): Response
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare('SELECT id, product_model_id, sort_order FROM product_images WHERE id = ?');
        $stmt->execute([$imageId]);
        $image = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($image === false) {
            throw new NotFoundException('Image not found.');
        }

        $modelId = (int) $image["product_model_id"];
        $direction = (string) $request->input('direction', 'up');

        // Find the adjacent image in the requested direction.
        if ($direction === 'up') {
            $adjStmt = $pdo->prepare(
                'SELECT id, sort_order FROM product_images
                 WHERE product_model_id = ? AND sort_order < ?
                 ORDER BY sort_order DESC LIMIT 1'
            );
        } else {
            $adjStmt = $pdo->prepare(
                'SELECT id, sort_order FROM product_images
                 WHERE product_model_id = ? AND sort_order > ?
                 ORDER BY sort_order ASC LIMIT 1'
            );
        }
        $adjStmt->execute([$modelId, (int) $image['sort_order']]);
        $adjacent = $adjStmt->fetch(PDO::FETCH_ASSOC);

        if ($adjacent === false) {
            // Already at the boundary.
            return Response::redirect(url('/admin/products/' . $modelId));
        }

        // Swap sort_order values.
        Connection::transaction(function (PDO $pdo) use ($image, $adjacent) {
            $pdo->prepare('UPDATE product_images SET sort_order = ? WHERE id = ?')
                ->execute([$adjacent['sort_order'], $image['id']]);
            $pdo->prepare('UPDATE product_images SET sort_order = ? WHERE id = ?')
                ->execute([$image['sort_order'], $adjacent['id']]);
        });

        AuditService::record('product_image.reordered', 'product_image', (int) $imageId, [
            'product_model_id' => $modelId,
            'direction' => $direction,
        ], $request);

        return Response::redirect(url('/admin/products/' . $modelId));
    }
}
