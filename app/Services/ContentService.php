<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Content management: blocks, banners, pages, FAQs, SEO metadata.
 *
 * Content management is separate from application configuration
 * (settings table). Content lives in content_blocks/banners/
 * content_pages/faqs/seo_metadata; app config lives in settings.
 *
 * Safe HTML handling:
 *  - The CMS stores raw HTML. Only authorized staff (content.manage)
 *    can edit it.
 *  - The storefront renders block/page HTML as trusted content authored
 *    by an authorized admin.
 *  - Image uploads use finfo MIME detection, 4 MiB max, random
 *    filenames, .htaccess disabling PHP execution.
 */
final class ContentService
{
    private const UPLOAD_DIR = 'uploads/banners';
    private const MAX_FILE_SIZE = 4 * 1024 * 1024;
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    // ---------------------------------------------------- BLOCKS --

    public function findBlock(string $key): ?array
    {
        $stmt = Connection::pdo()->prepare('SELECT * FROM content_blocks WHERE block_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function publishedBlock(string $key): ?string
    {
        $stmt = Connection::pdo()->prepare('SELECT body FROM content_blocks WHERE block_key = ? AND is_published = 1');
        $stmt->execute([$key]);
        $body = $stmt->fetchColumn();
        return $body === false ? null : (string) $body;
    }

    public function allBlocks(): array
    {
        return Connection::pdo()->query('SELECT * FROM content_blocks ORDER BY block_key')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsertBlock(string $key, string $title, string $body, bool $isPublished, int $updatedBy): int
    {
        if (trim($key) === '') {
            throw new ValidationException(['block_key' => 'Block key is required.']);
        }
        $body = self::sanitizeHtml($body);
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO content_blocks (block_key, title, body, is_published, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), is_published = VALUES(is_published), updated_by = VALUES(updated_by)'
        );
        $stmt->execute([$key, $title, $body, $isPublished ? 1 : 0, $updatedBy]);
        return (int) Connection::pdo()->lastInsertId() ?: (int) Connection::pdo()->query('SELECT id FROM content_blocks WHERE block_key = ' . Connection::pdo()->quote($key))->fetchColumn();
    }

    // ---------------------------------------------------- BANNERS --

    public function allBanners(): array
    {
        return Connection::pdo()->query('SELECT * FROM banners ORDER BY display_order, id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function publishedBanners(string $position = 'hero'): array
    {
        $stmt = Connection::pdo()->prepare('SELECT * FROM banners WHERE position = ? AND is_published = 1 ORDER BY display_order, id');
        $stmt->execute([$position]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createBanner(array $d, int $createdBy): int
    {
        if (trim($d['title'] ?? '') === '') {
            throw new ValidationException(['title' => 'Banner title is required.']);
        }
        if (empty($d['image_path'])) {
            throw new ValidationException(['image_path' => 'Banner image is required.']);
        }
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO banners (title, subtitle, image_path, link_url, position, is_published, display_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $d['title'], $d['subtitle'] ?? null, $d['image_path'],
            $d['link_url'] ?? null, $d['position'] ?? 'hero',
            !empty($d['is_published']) ? 1 : 0, (int) ($d['display_order'] ?? 0), $createdBy,
        ]);
        return (int) Connection::pdo()->lastInsertId();
    }

    public function deleteBanner(int $id): void
    {
        Connection::pdo()->prepare('DELETE FROM banners WHERE id = ?')->execute([$id]);
    }

    // ---------------------------------------------------- PAGES --

    public function findPage(string $slug): ?array
    {
        $stmt = Connection::pdo()->prepare('SELECT * FROM content_pages WHERE slug = ? AND is_published = 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function allPages(): array
    {
        return Connection::pdo()->query('SELECT * FROM content_pages ORDER BY slug')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createPage(array $d, int $createdBy): int
    {
        if (trim($d['slug'] ?? '') === '') {
            throw new ValidationException(['slug' => 'Page slug is required.']);
        }
        if (trim($d['title'] ?? '') === '') {
            throw new ValidationException(['title' => 'Page title is required.']);
        }
        $d['body'] = self::sanitizeHtml($d['body'] ?? '');
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO content_pages (slug, title, body, meta_title, meta_description, is_published, display_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $d['slug'], $d['title'], $d['body'] ?? '',
            $d['meta_title'] ?? null, $d['meta_description'] ?? null,
            !empty($d['is_published']) ? 1 : 0, (int) ($d['display_order'] ?? 0), $createdBy,
        ]);
        return (int) Connection::pdo()->lastInsertId();
    }

    public function updatePage(int $id, array $d): void
    {
        $d['body'] = self::sanitizeHtml($d['body'] ?? '');
        $stmt = Connection::pdo()->prepare(
            'UPDATE content_pages SET slug = ?, title = ?, body = ?, meta_title = ?, meta_description = ?, is_published = ?, display_order = ? WHERE id = ?'
        );
        $stmt->execute([
            $d['slug'], $d['title'], $d['body'] ?? '',
            $d['meta_title'] ?? null, $d['meta_description'] ?? null,
            !empty($d['is_published']) ? 1 : 0, (int) ($d['display_order'] ?? 0), $id,
        ]);
    }

    public function deletePage(int $id): void
    {
        Connection::pdo()->prepare('DELETE FROM content_pages WHERE id = ?')->execute([$id]);
    }

    // ---------------------------------------------------- FAQs --

    public function publishedFaqs(?string $category = null): array
    {
        if ($category !== null && $category !== '') {
            $stmt = Connection::pdo()->prepare('SELECT * FROM faqs WHERE is_published = 1 AND category = ? ORDER BY display_order, id');
            $stmt->execute([$category]);
        } else {
            $stmt = Connection::pdo()->query('SELECT * FROM faqs WHERE is_published = 1 ORDER BY display_order, id');
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function allFaqs(): array
    {
        return Connection::pdo()->query('SELECT * FROM faqs ORDER BY display_order, id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createFaq(array $d): int
    {
        if (trim($d['question'] ?? '') === '') {
            throw new ValidationException(['question' => 'Question is required.']);
        }
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO faqs (question, answer, category, is_published, display_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $d['question'], $d['answer'] ?? '',
            $d['category'] ?? null,
            !empty($d['is_published']) ? 1 : 0, (int) ($d['display_order'] ?? 0),
        ]);
        return (int) Connection::pdo()->lastInsertId();
    }

    public function deleteFaq(int $id): void
    {
        Connection::pdo()->prepare('DELETE FROM faqs WHERE id = ?')->execute([$id]);
    }

    // ---------------------------------------------------- SEO --

    public function findSeo(string $pageKey): ?array
    {
        $stmt = Connection::pdo()->prepare('SELECT * FROM seo_metadata WHERE page_key = ?');
        $stmt->execute([$pageKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function upsertSeo(string $pageKey, array $d): void
    {
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO seo_metadata (page_key, meta_title, meta_description, og_title, og_description, og_image_path, canonical_url, extra_head_tags)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE meta_title = VALUES(meta_title), meta_description = VALUES(meta_description),
                 og_title = VALUES(og_title), og_description = VALUES(og_description), og_image_path = VALUES(og_image_path),
                 canonical_url = VALUES(canonical_url), extra_head_tags = VALUES(extra_head_tags)'
        );
        $stmt->execute([
            $pageKey, $d['meta_title'] ?? null, $d['meta_description'] ?? null,
            $d['og_title'] ?? null, $d['og_description'] ?? null,
            $d['og_image_path'] ?? null, $d['canonical_url'] ?? null,
            $d['extra_head_tags'] ?? null,
        ]);
    }

    // ---------------------------------------------------- IMAGE UPLOAD --

    public function saveBannerImage(?array $file): ?string
    {
        if ($file === null || !isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['image' => 'Upload failed with error code ' . $file['error'] . '.']);
        }
        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new ValidationException(['image' => 'File exceeds the 4 MiB limit.']);
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, self::ALLOWED_MIMES, true)) {
            throw new ValidationException(['image' => 'Invalid file type. Allowed: JPEG, PNG, WebP, GIF.']);
        }
        $ext = match ($realMime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            default => 'bin',
        };
        $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
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
        return self::UPLOAD_DIR . '/' . $safeName;
    }

    /**
     * Sanitize HTML content to prevent stored XSS.
     *
     * Strips <script>, <iframe>, <object>, <embed>, <style> tags,
     * removes all on* event handler attributes, and removes
     * javascript: URIs from href/src attributes. Uses PHP's built-in
     * DOMDocument — no external library required.
     *
     * This is applied at WRITE TIME (upsertBlock, createPage, updatePage)
     * so the trusted-HTML invariant is enforced even if RBAC or session
     * theft bypasses the editor authorization boundary.
     */
    public static function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        // Quick pre-strip of obvious attack vectors before DOM parsing.
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is', '', $html);
        $html = preg_replace('/<object\b[^>]*>.*?<\/object>/is', '', $html);
        $html = preg_replace('/<embed\b[^>]*>/is', '', $html);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->encoding = 'UTF-8';
        // Suppress warnings from malformed HTML.
        libxml_use_internal_errors(true);
        // Wrap in a charset meta to force UTF-8 parsing.
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFMT);
        libxml_clear_errors();
        if (!$loaded) {
            // If DOMDocument fails to parse, escape everything as a fallback.
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        // Tags to remove entirely (including their children).
        $removeTags = ['script', 'iframe', 'object', 'embed', 'style', 'link', 'meta', 'base', 'form'];
        foreach ($removeTags as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            $toRemove = [];
            foreach ($nodes as $node) {
                $toRemove[] = $node;
            }
            foreach ($toRemove as $node) {
                $parent = $node->parentNode;
                if ($parent) {
                    $parent->removeChild($node);
                }
            }
        }

        // Walk all elements and remove dangerous attributes.
        $xpath = new \DOMXPath($dom);
        $allElements = $xpath->query('//*');
        foreach ($allElements as $el) {
            if (!($el instanceof \DOMElement)) continue;
            $attrsToRemove = [];
            foreach ($el->attributes as $attr) {
                $name = strtolower($attr->name);
                $value = $attr->value;
                // Remove on* event handlers.
                if (str_starts_with($name, 'on')) {
                    $attrsToRemove[] = $attr->name;
                    continue;
                }
                // Remove javascript:iten URIs from href/src.
                if (in_array($name, ['href', 'src', 'action', 'formaction', 'data', 'xlink:href'], true)) {
                    $trimmed = trim(preg_replace('/\s+/', '', $value));
                    if (preg_match('/^javascript:/i', $trimmed) || preg_match('/^data:text\/html/i', $trimmed)) {
                        $attrsToRemove[] = $attr->name;
                    }
                }
            }
            foreach ($attrsToRemove as $name) {
                $el->removeAttribute($name);
            }
        }

        return $dom->saveHTML();
    }
}
