<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Hero slide management — full admin CRUD + image upload + ordering.
 * All slide content (headline, CTAs, images, colors, animation, scheduling)
 * is stored in `hero_slides` and loaded by HomeController for the public
 * homepage slider.
 */
final class HeroSlideService
{
    private const UPLOAD_DIR = 'uploads/hero';
    private const MAX_FILE_SIZE = 6 * 1024 * 1024;
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function all(): array
    {
        return Connection::pdo()->query(
            'SELECT hs.*, u.name AS created_by_name
             FROM hero_slides hs
             LEFT JOIN users u ON u.id = hs.created_by
             ORDER BY hs.sort_order ASC, hs.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function published(): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT * FROM hero_slides
             WHERE is_active = 1
               AND (start_at IS NULL OR start_at <= UTC_TIMESTAMP())
               AND (end_at IS NULL OR end_at >= UTC_TIMESTAMP())
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare('SELECT * FROM hero_slides WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function create(array $d, int $userId): int
    {
        $this->validate($d);
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO hero_slides
                (internal_name, eyebrow, headline, highlight_phrase, description,
                 cta1_label, cta1_url, cta2_label, cta2_url,
                 desktop_image, mobile_image, product_image, image_alt,
                 bg_color, text_color, content_position, image_position, overlay_strength,
                 transition_style, animation_intensity, auto_rotate, slide_duration,
                 show_nav, show_pagination, start_at, end_at, is_active, sort_order,
                 accessibility_desc, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $d['internal_name'] ?? 'Slide',
            $d['eyebrow'] ?? null,
            $d['headline'],
            $d['highlight_phrase'] ?? null,
            $d['description'] ?? null,
            $d['cta1_label'] ?? 'Shop Now',
            $d['cta1_url'] ?? '/shop',
            ($d['cta2_label'] ?? '') !== '' ? $d['cta2_label'] : null,
            ($d['cta2_url'] ?? '') !== '' ? $d['cta2_url'] : null,
            ($d['desktop_image'] ?? '') !== '' ? $d['desktop_image'] : null,
            ($d['mobile_image'] ?? '') !== '' ? $d['mobile_image'] : null,
            ($d['product_image'] ?? '') !== '' ? $d['product_image'] : null,
            ($d['image_alt'] ?? '') !== '' ? $d['image_alt'] : null,
            $d['bg_color'] ?? '#01011D',
            $d['text_color'] ?? '#ffffff',
            $d['content_position'] ?? 'left',
            $d['image_position'] ?? 'right',
            (int) ($d['overlay_strength'] ?? 0),
            $d['transition_style'] ?? 'fade',
            $d['animation_intensity'] ?? 'normal',
            !empty($d['auto_rotate']) ? 1 : 0,
            (int) ($d['slide_duration'] ?? 5000),
            !empty($d['show_nav']) ? 1 : 0,
            !empty($d['show_pagination']) ? 1 : 0,
            ($d['start_at'] ?? '') !== '' ? $d['start_at'] : null,
            ($d['end_at'] ?? '') !== '' ? $d['end_at'] : null,
            !empty($d['is_active']) ? 1 : 0,
            (int) ($d['sort_order'] ?? 0),
            ($d['accessibility_desc'] ?? '') !== '' ? $d['accessibility_desc'] : null,
            $userId,
            $userId,
        ]);
        $id = (int) Connection::pdo()->lastInsertId();
        app_audit('heroslide.created', 'hero_slide', $id, ['name' => $d['internal_name'] ?? '']);
        return $id;
    }

    public function update(int $id, array $d, int $userId): void
    {
        $this->validate($d);
        $stmt = Connection::pdo()->prepare(
            'UPDATE hero_slides SET
                internal_name = ?, eyebrow = ?, headline = ?, highlight_phrase = ?,
                description = ?, cta1_label = ?, cta1_url = ?, cta2_label = ?, cta2_url = ?,
                desktop_image = ?, mobile_image = ?, product_image = ?, image_alt = ?,
                bg_color = ?, text_color = ?, content_position = ?, image_position = ?,
                overlay_strength = ?, transition_style = ?, animation_intensity = ?,
                auto_rotate = ?, slide_duration = ?, show_nav = ?, show_pagination = ?,
                start_at = ?, end_at = ?, is_active = ?, sort_order = ?,
                accessibility_desc = ?, updated_by = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $d['internal_name'] ?? 'Slide',
            $d['eyebrow'] ?? null,
            $d['headline'],
            $d['highlight_phrase'] ?? null,
            $d['description'] ?? null,
            $d['cta1_label'] ?? 'Shop Now',
            $d['cta1_url'] ?? '/shop',
            ($d['cta2_label'] ?? '') !== '' ? $d['cta2_label'] : null,
            ($d['cta2_url'] ?? '') !== '' ? $d['cta2_url'] : null,
            ($d['desktop_image'] ?? '') !== '' ? $d['desktop_image'] : null,
            ($d['mobile_image'] ?? '') !== '' ? $d['mobile_image'] : null,
            ($d['product_image'] ?? '') !== '' ? $d['product_image'] : null,
            ($d['image_alt'] ?? '') !== '' ? $d['image_alt'] : null,
            $d['bg_color'] ?? '#01011D',
            $d['text_color'] ?? '#ffffff',
            $d['content_position'] ?? 'left',
            $d['image_position'] ?? 'right',
            (int) ($d['overlay_strength'] ?? 0),
            $d['transition_style'] ?? 'fade',
            $d['animation_intensity'] ?? 'normal',
            !empty($d['auto_rotate']) ? 1 : 0,
            (int) ($d['slide_duration'] ?? 5000),
            !empty($d['show_nav']) ? 1 : 0,
            !empty($d['show_pagination']) ? 1 : 0,
            ($d['start_at'] ?? '') !== '' ? $d['start_at'] : null,
            ($d['end_at'] ?? '') !== '' ? $d['end_at'] : null,
            !empty($d['is_active']) ? 1 : 0,
            (int) ($d['sort_order'] ?? 0),
            ($d['accessibility_desc'] ?? '') !== '' ? $d['accessibility_desc'] : null,
            $userId,
            $id,
        ]);
        app_audit('heroslide.updated', 'hero_slide', $id, ['name' => $d['internal_name'] ?? '']);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::pdo()->prepare('DELETE FROM hero_slides WHERE id = ?');
        $stmt->execute([$id]);
        app_audit('heroslide.deleted', 'hero_slide', $id);
    }

    public function toggle(int $id, bool $active): void
    {
        $stmt = Connection::pdo()->prepare('UPDATE hero_slides SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
        app_audit('heroslide.toggled', 'hero_slide', $id, ['active' => $active]);
    }

    public function reorder(array $orderedIds): void
    {
        Connection::transaction(function (PDO $pdo) use ($orderedIds): void {
            $stmt = $pdo->prepare('UPDATE hero_slides SET sort_order = ? WHERE id = ?');
            foreach ($orderedIds as $i => $id) {
                $stmt->execute([$i, (int) $id]);
            }
        });
        app_audit('heroslide.reordered', null, null, ['order' => $orderedIds]);
    }

    public function duplicate(int $id, int $userId): int
    {
        $slide = $this->find($id);
        if ($slide === null) {
            throw new ValidationException(['id' => 'Slide not found.']);
        }
        $d = $slide;
        $d['internal_name'] = ($slide['internal_name'] ?? 'Slide') . ' (Copy)';
        $d['is_active'] = 0;
        $d['sort_order'] = (int) $slide['sort_order'] + 1;
        return $this->create($d, $userId);
    }

    public function saveImage(?array $file): ?string
    {
        if ($file === null || !isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['image' => 'Upload failed with error code ' . $file['error'] . '.']);
        }
        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new ValidationException(['image' => 'File exceeds the 6 MiB limit.']);
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, self::ALLOWED_MIMES, true)) {
            throw new ValidationException(['image' => 'Invalid file type. Allowed: JPEG, PNG, WebP, GIF.']);
        }
        $ext = match ($realMime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };
        $safeName = 'hero-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dir = BASE_PATH . '/public/' . self::UPLOAD_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $dest = $dir . '/' . $safeName;
        if (is_uploaded_file($file['tmp_name'])) {
            move_uploaded_file($file['tmp_name'], $dest);
        } else {
            rename($file['tmp_name'], $dest);
        }
        return self::UPLOAD_DIR . '/' . $safeName;
    }

    private function validate(array $d): void
    {
        $errors = [];
        if (trim($d['headline'] ?? '') === '') {
            $errors['headline'] = 'Headline is required.';
        }
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
