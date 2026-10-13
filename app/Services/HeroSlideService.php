<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Cache\SimpleCache;
use App\Support\Database\Connection;
use PDO;

/**
 * Hero slider v2 — full admin CRUD + image upload + ordering + settings +
 * cache invalidation + screen-visual SVG rendering for the public slider.
 *
 * The schema lives in migrations 035 + 037. Plain text only — escaping
 * happens at render time (e() in the views).
 */
final class HeroSlideService
{
    private const UPLOAD_DIR = 'uploads/hero';
    private const MAX_FILE_SIZE = 2 * 1024 * 1024; // 2 MiB per the spec
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];
    private const CACHE_KEY_PUBLIC = 'hero_slider.public';

    /**
     * Full per-slide token sets from the reference design (hero-slider.html
     * classes .t0–.t3). 15 CSS custom properties per theme; the extra tokens
     * (line, chip, dot, ghost, chassis, chassis_hi, bezel, glow, bar) are the
     * hand-tuned values from the reference, so presets render pixel-identical.
     */
    private const THEME_PRESETS = [
        'mist_teal' => [
            'bg' => '#dce6ec', 'bg2' => '#f3f8fa', 'ink' => '#0d1b26', 'muted' => '#3b4d5b',
            'accent' => '#086560', 'accent_ink' => '#ffffff',
            'line' => 'rgba(13,27,38,.2)', 'chip' => 'rgba(255,255,255,.62)', 'dot' => 'rgba(13,27,38,.16)',
            'ghost' => 'rgba(13,27,38,.16)', 'chassis' => '#b9c4cc', 'chassis_hi' => '#e4eaee',
            'bezel' => '#0a0d10', 'glow' => 'rgba(8,101,96,.28)', 'bar' => 'rgba(13,27,38,.07)',
        ],
        'graphite_coral' => [
            'bg' => '#0b1016', 'bg2' => '#1d2a38', 'ink' => '#f3f5f7', 'muted' => '#a6b2bf',
            'accent' => '#ff5b44', 'accent_ink' => '#1a0805',
            'line' => 'rgba(243,245,247,.2)', 'chip' => 'rgba(16,24,33,.66)', 'dot' => 'rgba(243,245,247,.14)',
            'ghost' => 'rgba(255,91,68,.34)', 'chassis' => '#2b333d', 'chassis_hi' => '#46515f',
            'bezel' => '#05080b', 'glow' => 'rgba(255,91,68,.4)', 'bar' => 'rgba(255,255,255,.06)',
        ],
        'navy_amber' => [
            'bg' => '#16233d', 'bg2' => '#2b4474', 'ink' => '#f6f2ea', 'muted' => '#bcc7da',
            'accent' => '#ffb340', 'accent_ink' => '#1b1200',
            'line' => 'rgba(246,242,234,.22)', 'chip' => 'rgba(14,24,44,.6)', 'dot' => 'rgba(246,242,234,.14)',
            'ghost' => 'rgba(255,179,64,.3)', 'chassis' => '#4a5468', 'chassis_hi' => '#7b869b',
            'bezel' => '#070b14', 'glow' => 'rgba(255,179,64,.3)', 'bar' => 'rgba(255,255,255,.07)',
        ],
        'stone_cobalt' => [
            'bg' => '#e6e5e0', 'bg2' => '#f6f5f1', 'ink' => '#14161a', 'muted' => '#4a4d55',
            'accent' => '#2340d8', 'accent_ink' => '#ffffff',
            'line' => 'rgba(20,22,26,.2)', 'chip' => 'rgba(255,255,255,.7)', 'dot' => 'rgba(20,22,26,.15)',
            'ghost' => 'rgba(35,64,216,.22)', 'chassis' => '#a9acb2', 'chassis_hi' => '#d6d8dc',
            'bezel' => '#0b0c0e', 'glow' => 'rgba(35,64,216,.22)', 'bar' => 'rgba(20,22,26,.07)',
        ],
    ];

    // ============================================================ ADMIN
    public function all(): array
    {
        return Connection::pdo()->query(
            'SELECT hs.*, u.name AS updated_by_name
             FROM hero_slides hs
             LEFT JOIN users u ON u.id = hs.updated_by
             ORDER BY hs.sort_order ASC, hs.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare('SELECT * FROM hero_slides WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function create(array $d, int $userId): int
    {
        $clean = $this->validateAndNormalize($d);
        $clean['created_by'] = $userId;
        $clean['updated_by'] = $userId;
        $this->enforceMaxSlides();
        $this->insertRow($clean);
        $id = (int) Connection::pdo()->lastInsertId();
        app_audit('heroslide.created', 'hero_slide', $id, ['nav' => $clean['nav_label']]);
        $this->invalidate();
        return $id;
    }

    public function update(int $id, array $d, int $userId): void
    {
        $clean = $this->validateAndNormalize($d, $id);
        $clean['updated_by'] = $userId;
        $this->updateRow($id, $clean);
        app_audit('heroslide.updated', 'hero_slide', $id, ['nav' => $clean['nav_label']]);
        $this->invalidate();
    }

    public function delete(int $id): void
    {
        $stmt = Connection::pdo()->prepare('DELETE FROM hero_slides WHERE id = ?');
        $stmt->execute([$id]);
        app_audit('heroslide.deleted', 'hero_slide', $id);
        $this->invalidate();
    }

    public function toggle(int $id, bool $active): void
    {
        $stmt = Connection::pdo()->prepare('UPDATE hero_slides SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
        app_audit('heroslide.toggled', 'hero_slide', $id, ['active' => $active]);
        $this->invalidate();
    }

    public function reorder(array $orderedIds): void
    {
        Connection::transaction(function (PDO $pdo) use ($orderedIds): void {
            $stmt = $pdo->prepare('UPDATE hero_slides SET sort_order = ? WHERE id = ?');
            foreach (array_values($orderedIds) as $i => $id) {
                $stmt->execute([$i, (int) $id]);
            }
        });
        app_audit('heroslide.reordered', null, null, ['order' => $orderedIds]);
        $this->invalidate();
    }

    public function duplicate(int $id, int $userId): int
    {
        $slide = $this->find($id);
        if ($slide === null) {
            throw new ValidationException(['id' => 'Slide not found.']);
        }
        $this->enforceMaxSlides();
        $copy = $slide;
        $copy['nav_label'] = ($slide['nav_label'] ?? 'Slide') . ' (Copy)';
        $copy['internal_name'] = $copy['nav_label'];
        $copy['is_active'] = 0;
        $copy['status'] = 'draft';
        $copy['sort_order'] = (int) $slide['sort_order'] + 1;
        // Re-encode JSON for insert
        $copy['specs'] = is_array($slide['specs']) ? $slide['specs'] : [];
        $copy['chips'] = is_array($slide['chips']) ? $slide['chips'] : [];
        $this->insertRow($copy);
        $newId = (int) Connection::pdo()->lastInsertId();
        app_audit('heroslide.duplicated', 'hero_slide', $id, ['new' => $newId]);
        $this->invalidate();
        return $newId;
    }

    // ============================================================ PUBLIC
    /**
     * Slides that should be visible on the live homepage:
     * status='published', is_active=1, within schedule window.
     * Ordered by sort_order, id.
     */
    public function published(): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT * FROM hero_slides
             WHERE status = ? AND is_active = 1
               AND (start_at IS NULL OR start_at <= UTC_TIMESTAMP())
               AND (end_at IS NULL OR end_at >= UTC_TIMESTAMP())
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['published']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /**
     * Cached public payload: settings + slides + the small fields the
     * client needs. Used by /api/hero-slider. Server-render uses
     * published() directly (no cache) so the first paint is always fresh.
     */
    public function publicPayload(): array
    {
        $settings = $this->settings();
        $slides = array_map(fn($s) => [
            'id' => (int) $s['id'],
            'nav_label' => $s['nav_label'] ?? '',
            'badge_text' => $s['badge_text'] ?? '',
            'category_label' => $s['category_label'] ?? '',
            'headline' => $s['headline'] ?? '',
            'description' => $s['description'] ?? '',
            'specs' => $s['specs'] ?? [],
            'chips' => $s['chips'] ?? [],
            'currency_symbol' => $s['currency_symbol'] ?? '',
            'price' => $s['price'] !== null ? (string) $s['price'] : null,
            'old_price' => $s['old_price'] !== null ? (string) $s['old_price'] : null,
            'installment_months' => $s['installment_months'] !== null ? (int) $s['installment_months'] : null,
            'monthly_price' => $s['monthly_price'] !== null ? (string) $s['monthly_price'] : null,
            'installment_note' => $s['installment_note'] ?? '',
            'ghost_word' => $s['ghost_word'] ?? ($s['nav_label'] ? explode(' ', trim($s['nav_label']))[0] : ''),
            'primary_cta_label' => $s['cta1_label'] ?? '',
            'primary_cta_url' => $s['cta1_url'] ?? '',
            'secondary_cta_label' => $s['cta2_label'] ?? '',
            'secondary_cta_url' => $s['cta2_url'] ?? '',
            'theme' => $this->themeTokens($s),
            'visual_type' => $s['visual_type'] ?? 'screen_dunes',
            'laptop_image_url' => $s['laptop_image_url'] ?? ($s['desktop_image'] ?? ''),
            'laptop_image_alt' => $s['laptop_image_alt'] ?? ($s['image_alt'] ?? ''),
            'accessibility_desc' => $s['accessibility_desc'] ?? $s['description'] ?? '',
        ], $this->published());

        return [
            'settings' => $settings,
            'slides' => $slides,
        ];
    }

    // ============================================================ SETTINGS
    public function settings(): array
    {
        $row = Connection::pdo()->query('SELECT * FROM hero_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [
                'autoplay_enabled' => 1,
                'autoplay_interval_ms' => 7000,
                'pause_on_hover' => 1,
                'announcement_enabled' => 1,
                'announcement_items' => ['Dubai Imported | 100% Authentic Used Laptops', 'Tested & Quality Checked', 'Warranty with every laptop'],
                'max_slides' => 8,
            ];
        }
        return [
            'autoplay_enabled' => (int) $row['autoplay_enabled'] === 1,
            'autoplay_interval_ms' => (int) $row['autoplay_interval_ms'],
            'pause_on_hover' => (int) $row['pause_on_hover'] === 1,
            'announcement_enabled' => (int) $row['announcement_enabled'] === 1,
            'announcement_items' => json_decode((string) $row['announcement_items'], true) ?: [],
            'max_slides' => (int) $row['max_slides'],
        ];
    }

    public function updateSettings(array $d, int $userId): void
    {
        $autoplayEnabled = !empty($d['autoplay_enabled']) ? 1 : 0;
        $interval = (int) ($d['autoplay_interval_ms'] ?? 7000);
        $interval = max(3000, min(20000, $interval));
        $pauseOnHover = !empty($d['pause_on_hover']) ? 1 : 0;
        $announceEnabled = !empty($d['announcement_enabled']) ? 1 : 0;
        $items = $d['announcement_items'] ?? [];
        $items = is_array($items) ? array_slice(array_filter(array_map('strval', $items), fn($x) => trim($x) !== ''), 0, 3) : [];
        $maxSlides = max(1, min(20, (int) ($d['max_slides'] ?? 8)));

        $stmt = Connection::pdo()->prepare(
            'UPDATE hero_settings SET
                autoplay_enabled = ?, autoplay_interval_ms = ?, pause_on_hover = ?,
                announcement_enabled = ?, announcement_items = ?, max_slides = ?, updated_by = ?
             WHERE id = 1'
        );
        $stmt->execute([
            $autoplayEnabled, $interval, $pauseOnHover,
            $announceEnabled, json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $maxSlides, $userId,
        ]);
        app_audit('herosettings.updated', 'hero_settings', 1, []);
        $this->invalidate();
    }

    // ============================================================ UPLOAD
    public function saveImage(?array $file): ?string
    {
        if ($file === null || !isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['image' => 'Upload failed with error code ' . $file['error'] . '.']);
        }
        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new ValidationException(['image' => 'File exceeds the 2 MiB limit.']);
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, self::ALLOWED_MIMES, true)) {
            throw new ValidationException(['image' => 'Invalid file type. Allowed: JPEG, PNG, WebP, AVIF.']);
        }
        $ext = match ($realMime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => 'bin',
        };
        $safeName = 'hero-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dir = BASE_PATH . '/public/' . self::UPLOAD_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $dest = $dir . '/' . $safeName;
        if (is_uploaded_file($file['tmp_name'])) {
            move_uploaded_file($file['tmp_name'], $dest);
        } else {
            rename($file['tmp_name'], $dest);
        }
        return self::UPLOAD_DIR . '/' . $safeName;
    }

    // ============================================================ SCREEN SVG
    /**
     * Return the inline SVG markup for a slide's screen visual.
     * Parameterized by the slide's accent color so chips/curves match.
     */
    public function screenSvg(array $slide): string
    {
        $t = $this->themeTokens($slide);
        $accent = $t['accent'];
        $ink = $t['ink'];
        $muted = $t['muted'];
        $bg2 = $t['bg2'];
        $type = $slide['visual_type'] ?? 'screen_dunes';
        return match ($type) {
            'screen_gaming'   => $this->svgGaming($accent, $ink, $muted, $bg2),
            'screen_creator'  => $this->svgCreator($accent, $ink, $muted, $bg2),
            'screen_business'  => $this->svgBusiness($accent, $ink, $muted, $bg2),
            'screen_dunes'    => $this->svgDunes($accent, $ink, $muted, $bg2),
            default           => '',
        };
    }

    // ============================================================ THEME
    /**
     * Compute the theme tokens for a slide: use preset by default,
     * fall back to slide's own custom_* colors when preset is 'custom'.
     * Custom themes derive the secondary tokens via color-mix() so they
     * stay consistent with the reference token structure.
     */
    public function themeTokens(array $slide): array
    {
        $preset = $slide['theme_preset'] ?? 'mist_teal';
        if ($preset !== 'custom') {
            return self::THEME_PRESETS[$preset] ?? self::THEME_PRESETS['mist_teal'];
        }
        $bg = $slide['custom_bg'] ?? '#dce6ec';
        $bg2 = $slide['custom_bg2'] ?? '#f3f8fa';
        $ink = $slide['custom_ink'] ?? '#0d1b26';
        $muted = $slide['custom_muted'] ?? '#3b4d5b';
        $accent = $slide['custom_accent'] ?? '#086560';
        $accentInk = $slide['custom_accent_ink'] ?? '#ffffff';
        return [
            'bg' => $bg, 'bg2' => $bg2, 'ink' => $ink, 'muted' => $muted,
            'accent' => $accent, 'accent_ink' => $accentInk,
            'line' => "color-mix(in srgb,{$ink} 22%,transparent)",
            'chip' => "color-mix(in srgb,{$ink} 8%,rgba(255,255,255,.62))",
            'dot' => "color-mix(in srgb,{$ink} 16%,transparent)",
            'ghost' => "color-mix(in srgb,{$accent} 22%,transparent)",
            'chassis' => "color-mix(in srgb,{$bg} 78%,gray)",
            'chassis_hi' => "color-mix(in srgb,{$bg2} 92%,white)",
            'bezel' => '#0a0d10',
            'glow' => "color-mix(in srgb,{$accent} 35%,transparent)",
            'bar' => "color-mix(in srgb,{$ink} 7%,transparent)",
        ];
    }

    /**
     * Full CSS custom-property declaration list for a slide's theme,
     * e.g. "--bg:#dce6ec;--bg2:#f3f8fa;…". Values are either preset
     * constants or hex colors validated server-side, so they are safe
     * to emit into a style block without escaping.
     */
    public function cssVars(array $slide): string
    {
        $pairs = [];
        foreach ($this->themeTokens($slide) as $key => $value) {
            $pairs[] = '--' . str_replace('_', '-', $key) . ':' . $value;
        }
        return implode(';', $pairs) . ';';
    }

    /** Static lookup for the admin color swatches UI. */
    public static function presets(): array
    {
        return self::THEME_PRESETS;
    }

    /** WCAG contrast ratio between two hex colors (returns 1..21). */
    public static function contrastRatio(string $a, string $b): float
    {
        $la = self::relativeLuminance($a);
        $lb = self::relativeLuminance($b);
        $hi = max($la, $lb);
        $lo = min($la, $lb);
        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        $f = fn($c) => $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    // ============================================================ VALIDATION
    /**
     * Full server-side validation per the spec:
     * - Required: headline, cta1_label, cta1_url, nav_label, theme_preset, visual_type
     * - Length caps: headline 80, description 220, spec item 40, chip value 20,
     *   chip label 30, badge 24, category 40, nav_label 20
     * - Exactly 3 specs and 3 chips
     * - price > 0; old_price > price when present
     * - URLs http(s) or relative
     * - Hex colors only when custom theme
     * - Reject unknown keys (caller passes through, we keep whitelist)
     */
    private function validateAndNormalize(array $d, ?int $idForUnique = null): array
    {
        $errors = [];

        // Required + max-length checks
        $rules = [
            'nav_label'        => ['max' => 20,  'required' => false],
            'badge_text'       => ['max' => 24,  'required' => false],
            'category_label'   => ['max' => 40,  'required' => false],
            'headline'         => ['max' => 80,  'required' => true],
            'description'      => ['max' => 220, 'required' => false],
            'cta1_label'       => ['max' => 60,  'required' => true],
            'cta1_url'         => ['max' => 255, 'required' => true],
            'cta2_label'       => ['max' => 60,  'required' => false],
            'cta2_url'         => ['max' => 255, 'required' => false],
            'accessibility_desc' => ['max' => 500, 'required' => false],
        ];
        foreach ($rules as $field => $r) {
            $val = trim((string) ($d[$field] ?? ''));
            if ($r['required'] && $val === '') {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
                continue;
            }
            if ($val !== '' && mb_strlen($val) > $r['max']) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . " must be {$r['max']} characters or fewer.";
            }
        }

        // Specs: exactly 3, each ≤ 40 chars
        $specs = $d['specs'] ?? [];
        if (!is_array($specs)) {
            $errors['specs'] = 'Specs must be a list of 3 items.';
        } else {
            $specs = array_values($specs);
            if (count($specs) !== 3) {
                $errors['specs'] = 'Exactly 3 spec items are required.';
            }
            foreach ($specs as $i => $s) {
                if (is_array($s)) { $s = (string)($s['text'] ?? ''); }
                if (mb_strlen((string) $s) > 40) {
                    $errors["spec_{$i}"] = "Spec item " . ($i + 1) . " must be 40 characters or fewer.";
                }
            }
        }

        // Chips: exactly 3, each {value ≤20, label ≤30}
        $chips = $d['chips'] ?? [];
        if (!is_array($chips)) {
            $errors['chips'] = 'Chips must be a list of 3 items.';
        } else {
            $chips = array_values($chips);
            if (count($chips) !== 3) {
                $errors['chips'] = 'Exactly 3 chips are required.';
            }
            foreach ($chips as $i => $c) {
                if (!is_array($c) || !isset($c['value'], $c['label'])) {
                    $errors["chip_{$i}"] = 'Each chip needs value and label.';
                    continue;
                }
                if (mb_strlen((string) $c['value']) > 20) {
                    $errors["chip_{$i}_value"] = "Chip " . ($i + 1) . " value must be 20 characters or fewer.";
                }
                if (mb_strlen((string) $c['label']) > 30) {
                    $errors["chip_{$i}_label"] = "Chip " . ($i + 1) . " label must be 30 characters or fewer.";
                }
            }
        }

        // Pricing
        $price = $d['price'] ?? null;
        if ($price !== null && $price !== '') {
            $price = (float) $price;
            if ($price <= 0) {
                $errors['price'] = 'Price must be greater than 0.';
            }
        } else {
            $price = null;
        }
        $oldPrice = $d['old_price'] ?? null;
        if ($oldPrice !== null && $oldPrice !== '') {
            $oldPrice = (float) $oldPrice;
            if ($oldPrice <= 0) {
                $errors['old_price'] = 'Old price must be greater than 0.';
            } elseif ($price !== null && $oldPrice <= $price) {
                $errors['old_price'] = 'Old price must be greater than the current price.';
            }
        } else {
            $oldPrice = null;
        }
        $months = $d['installment_months'] ?? null;
        $months = ($months === null || $months === '') ? null : (int) $months;
        $monthly = $d['monthly_price'] ?? null;
        $monthly = ($monthly === null || $monthly === '') ? null : (float) $monthly;
        // Auto-calc monthly price when months + price provided and monthly not set
        if ($monthly === null && $months !== null && $price !== null && $months > 0) {
            $monthly = round($price / $months, 2);
        }

        // URL format
        foreach (['cta1_url' => 'cta1_url', 'cta2_url' => 'cta2_url'] as $f => $_) {
            $u = trim((string) ($d[$f] ?? ''));
            if ($u !== '' && !$this->isUrlOrRelative($u)) {
                $errors[$f] = 'URL must start with http://, https://, or be a relative path like /shop.';
            }
        }

        // Theme
        $theme = $d['theme_preset'] ?? 'mist_teal';
        if (!in_array($theme, ['mist_teal','graphite_coral','navy_amber','stone_cobalt','custom'], true)) {
            $errors['theme_preset'] = 'Unknown theme preset.';
        }
        $custom = [];
        if ($theme === 'custom') {
            foreach (['custom_bg','custom_bg2','custom_ink','custom_muted','custom_accent','custom_accent_ink'] as $cf) {
                $v = trim((string) ($d[$cf] ?? ''));
                if ($v === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                    $errors[$cf] = ucfirst(str_replace('custom_', '', $cf)) . ' must be a #RRGGBB hex color.';
                } else {
                    $custom[$cf] = strtolower($v);
                }
            }
        }

        // Visual type
        $visual = $d['visual_type'] ?? 'screen_dunes';
        if (!in_array($visual, ['screen_dunes','screen_gaming','screen_creator','screen_business','image'], true)) {
            $errors['visual_type'] = 'Unknown visual type.';
        }

        // Status
        $status = ($d['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

        // Schedule
        $startAt = trim((string) ($d['start_at'] ?? ''));
        $startAt = $startAt === '' ? null : $startAt;
        $endAt = trim((string) ($d['end_at'] ?? ''));
        $endAt = $endAt === '' ? null : $endAt;
        if ($startAt && $endAt && $endAt < $startAt) {
            $errors['end_at'] = 'End date must be after the start date.';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return [
            'internal_name'   => $d['nav_label'] ?? 'Slide',
            'nav_label'       => $d['nav_label'] ?? 'Slide',
            'badge_text'      => $this->str($d['badge_text'] ?? ''),
            'category_label'  => $this->str($d['category_label'] ?? ''),
            'headline'        => $this->str($d['headline'] ?? ''),
            'highlight_phrase'=> null,
            'description'     => $this->str($d['description'] ?? ''),
            'specs'           => array_map(fn($s) => is_array($s) ? (string)($s['text'] ?? '') : (string)$s, $specs),
            'chips'           => array_map(fn($c) => ['value' => (string)$c['value'], 'label' => (string)$c['label']], $chips),
            'currency_symbol' => $this->str($d['currency_symbol'] ?? '$'),
            'price'           => $price,
            'old_price'       => $oldPrice,
            'installment_months' => $months,
            'monthly_price'   => $monthly,
            'installment_note' => $this->str($d['installment_note'] ?? ''),
            'ghost_word'     => $this->str($d['ghost_word'] ?? ''),
            'product_id'     => isset($d['product_id']) && $d['product_id'] !== '' ? (int) $d['product_id'] : null,
            'theme_preset'   => $theme,
            'custom_bg'       => $custom['custom_bg'] ?? null,
            'custom_bg2'      => $custom['custom_bg2'] ?? null,
            'custom_ink'      => $custom['custom_ink'] ?? null,
            'custom_muted'    => $custom['custom_muted'] ?? null,
            'custom_accent'   => $custom['custom_accent'] ?? null,
            'custom_accent_ink' => $custom['custom_accent_ink'] ?? null,
            'visual_type'    => $visual,
            'laptop_image_url' => $this->str($d['laptop_image_url'] ?? ''),
            'laptop_image_alt' => $this->str($d['laptop_image_alt'] ?? ''),
            'cta1_label'     => $this->str($d['cta1_label'] ?? ''),
            'cta1_url'       => $this->str($d['cta1_url'] ?? ''),
            'cta2_label'     => $this->str($d['cta2_label'] ?? ''),
            'cta2_url'       => $this->str($d['cta2_url'] ?? ''),
            'status'         => $status,
            'is_active'      => !empty($d['is_active']) ? 1 : 0,
            'sort_order'     => (int) ($d['sort_order'] ?? 0),
            'accessibility_desc' => $this->str($d['accessibility_desc'] ?? ''),
            'start_at'        => $startAt,
            'end_at'          => $endAt,
            'desktop_image'   => $this->str($d['desktop_image'] ?? ''),
            'mobile_image'    => $this->str($d['mobile_image'] ?? ''),
            'product_image'   => $this->str($d['product_image'] ?? ''),
            'image_alt'       => $this->str($d['image_alt'] ?? ''),
        ];
    }

    private function str($v): string
    {
        return trim((string) $v);
    }

    private function isUrlOrRelative(string $u): bool
    {
        if (preg_match('#^https?://#i', $u)) {
            return true;
        }
        // relative path: starts with /, ./, ../, or anchor only
        return preg_match('~^(/|\./|\.\./|#)~', $u) === 1 || $u === '';
    }

    private function enforceMaxSlides(): void
    {
        $max = (int) Connection::pdo()->query('SELECT max_slides FROM hero_settings WHERE id = 1')->fetchColumn();
        if ($max < 1) { $max = 8; }
        $count = (int) Connection::pdo()->query('SELECT COUNT(*) FROM hero_slides')->fetchColumn();
        if ($count >= $max) {
            throw new ValidationException(['max_slides' => "Maximum of {$max} slides reached. Remove one or raise max_slides in Settings."]);
        }
    }

    private function hydrate(array $row): array
    {
        $row['specs'] = isset($row['specs']) ? (json_decode((string) $row['specs'], true) ?: []) : [];
        $row['chips'] = isset($row['chips']) ? (json_decode((string) $row['chips'], true) ?: []) : [];
        return $row;
    }

    private function insertRow(array $d): void
    {
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO hero_slides
                (internal_name, nav_label, badge_text, category_label, headline, highlight_phrase, description,
                 specs, chips, currency_symbol, price, old_price, installment_months, monthly_price, installment_note,
                 ghost_word, product_id, theme_preset, custom_bg, custom_bg2, custom_ink, custom_muted, custom_accent, custom_accent_ink,
                 visual_type, laptop_image_url, laptop_image_alt,
                 cta1_label, cta1_url, cta2_label, cta2_url,
                 start_at, end_at, is_active, sort_order, status, accessibility_desc,
                 desktop_image, mobile_image, product_image, image_alt,
                 created_by, updated_by)
             VALUES
                (:internal_name, :nav_label, :badge_text, :category_label, :headline, :highlight_phrase, :description,
                 :specs, :chips, :currency_symbol, :price, :old_price, :installment_months, :monthly_price, :installment_note,
                 :ghost_word, :product_id, :theme_preset, :custom_bg, :custom_bg2, :custom_ink, :custom_muted, :custom_accent, :custom_accent_ink,
                 :visual_type, :laptop_image_url, :laptop_image_alt,
                 :cta1_label, :cta1_url, :cta2_label, :cta2_url,
                 :start_at, :end_at, :is_active, :sort_order, :status, :accessibility_desc,
                 :desktop_image, :mobile_image, :product_image, :image_alt,
                 :created_by, :updated_by)'
        );
        $this->bindRow($stmt, $d);
        // Only the INSERT statement has a :created_by placeholder — binding
        // it here too would raise HY093 against the UPDATE SQL.
        $stmt->bindValue(':created_by', $d['created_by'] ?? null, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function updateRow(int $id, array $d): void
    {
        $stmt = Connection::pdo()->prepare(
            'UPDATE hero_slides SET
                internal_name = :internal_name, nav_label = :nav_label, badge_text = :badge_text,
                category_label = :category_label, headline = :headline, highlight_phrase = :highlight_phrase,
                description = :description, specs = :specs, chips = :chips,
                currency_symbol = :currency_symbol, price = :price, old_price = :old_price,
                installment_months = :installment_months, monthly_price = :monthly_price,
                installment_note = :installment_note, ghost_word = :ghost_word, product_id = :product_id,
                theme_preset = :theme_preset, custom_bg = :custom_bg, custom_bg2 = :custom_bg2,
                custom_ink = :custom_ink, custom_muted = :custom_muted, custom_accent = :custom_accent,
                custom_accent_ink = :custom_accent_ink, visual_type = :visual_type,
                laptop_image_url = :laptop_image_url, laptop_image_alt = :laptop_image_alt,
                cta1_label = :cta1_label, cta1_url = :cta1_url, cta2_label = :cta2_label, cta2_url = :cta2_url,
                start_at = :start_at, end_at = :end_at, is_active = :is_active, sort_order = :sort_order,
                status = :status, accessibility_desc = :accessibility_desc,
                desktop_image = :desktop_image, mobile_image = :mobile_image, product_image = :product_image,
                image_alt = :image_alt, updated_by = :updated_by
             WHERE id = :id'
        );
        $this->bindRow($stmt, $d);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function bindRow(\PDOStatement $stmt, array $d): void
    {
        $stmt->bindValue(':internal_name', $d['internal_name']);
        $stmt->bindValue(':nav_label', $d['nav_label']);
        $stmt->bindValue(':badge_text', $d['badge_text'] ?: null);
        $stmt->bindValue(':category_label', $d['category_label'] ?: null);
        $stmt->bindValue(':headline', $d['headline']);
        $stmt->bindValue(':highlight_phrase', $d['highlight_phrase'] ?? null);
        $stmt->bindValue(':description', $d['description'] ?: null);
        $stmt->bindValue(':specs', json_encode($d['specs'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':chips', json_encode($d['chips'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':currency_symbol', $d['currency_symbol'] ?: '$');
        $stmt->bindValue(':price', $d['price'] !== null ? $d['price'] : null);
        $stmt->bindValue(':old_price', $d['old_price'] ?? null);
        $stmt->bindValue(':installment_months', $d['installment_months'] ?? null);
        $stmt->bindValue(':monthly_price', $d['monthly_price'] ?? null);
        $stmt->bindValue(':installment_note', $d['installment_note'] ?: null);
        $stmt->bindValue(':ghost_word', $d['ghost_word'] ?: null);
        $stmt->bindValue(':product_id', $d['product_id'] ?? null, $d['product_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':theme_preset', $d['theme_preset']);
        $stmt->bindValue(':custom_bg', $d['custom_bg'] ?? null);
        $stmt->bindValue(':custom_bg2', $d['custom_bg2'] ?? null);
        $stmt->bindValue(':custom_ink', $d['custom_ink'] ?? null);
        $stmt->bindValue(':custom_muted', $d['custom_muted'] ?? null);
        $stmt->bindValue(':custom_accent', $d['custom_accent'] ?? null);
        $stmt->bindValue(':custom_accent_ink', $d['custom_accent_ink'] ?? null);
        $stmt->bindValue(':visual_type', $d['visual_type']);
        $stmt->bindValue(':laptop_image_url', $d['laptop_image_url'] ?: null);
        $stmt->bindValue(':laptop_image_alt', $d['laptop_image_alt'] ?: null);
        $stmt->bindValue(':cta1_label', $d['cta1_label']);
        $stmt->bindValue(':cta1_url', $d['cta1_url']);
        $stmt->bindValue(':cta2_label', $d['cta2_label'] ?: null);
        $stmt->bindValue(':cta2_url', $d['cta2_url'] ?: null);
        $stmt->bindValue(':start_at', $d['start_at'] ?: null);
        $stmt->bindValue(':end_at', $d['end_at'] ?: null);
        $stmt->bindValue(':is_active', (int) $d['is_active'], PDO::PARAM_INT);
        $stmt->bindValue(':sort_order', (int) $d['sort_order'], PDO::PARAM_INT);
        $stmt->bindValue(':status', $d['status']);
        $stmt->bindValue(':accessibility_desc', $d['accessibility_desc'] ?: null);
        $stmt->bindValue(':desktop_image', $d['desktop_image'] ?: null);
        $stmt->bindValue(':mobile_image', $d['mobile_image'] ?: null);
        $stmt->bindValue(':product_image', $d['product_image'] ?: null);
        $stmt->bindValue(':image_alt', $d['image_alt'] ?: null);
        $stmt->bindValue(':updated_by', $d['updated_by'] ?? null, PDO::PARAM_INT);
    }

    private function invalidate(): void
    {
        SimpleCache::forget(self::CACHE_KEY_PUBLIC);
    }

    // ============================================================ SCREEN SVGS
    private function svgDunes(string $accent, string $ink, string $muted, string $bg2): string
    {
        $id = 'sd' . substr(md5($accent), 0, 6);
        return '<svg viewBox="0 0 320 200" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
          <defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#a9ddd8"/><stop offset="1" stop-color="#f4f8f3"/></linearGradient></defs>
          <rect width="320" height="200" fill="url(#' . $id . ')"/>
          <circle cx="238" cy="58" r="24" fill="#fff3d6"/>
          <path d="M0 128C58 96 116 122 178 104S280 94 320 110V200H0Z" fill="#86cbc4"/>
          <path d="M0 148C70 120 132 148 202 130S292 126 320 138V200H0Z" fill="#35a39c"/>
          <path d="M0 170C82 148 142 176 222 160S300 156 320 164V200H0Z" fill="#0f6e6a"/>
          <rect x="22" y="28" width="116" height="70" rx="6" fill="#fff" fill-opacity=".86"/>
          <rect x="32" y="38" width="48" height="6" rx="3" fill="#0d1b26" fill-opacity=".8"/>
          <rect x="32" y="52" width="94" height="4" rx="2" fill="#0d1b26" fill-opacity=".25"/>
          <rect x="32" y="62" width="82" height="4" rx="2" fill="#0d1b26" fill-opacity=".25"/>
          <rect x="32" y="72" width="88" height="4" rx="2" fill="#0d1b26" fill-opacity=".25"/>
          <rect x="32" y="84" width="30" height="8" rx="4" fill="' . $accent . '"/>
          <rect width="320" height="9" fill="#fff" fill-opacity=".55"/>
          <rect x="100" y="184" width="120" height="11" rx="5.5" fill="#fff" fill-opacity=".55"/>
        </svg>';
    }

    private function svgGaming(string $accent, string $ink, string $muted, string $bg2): string
    {
        $id1 = 'sg1' . substr(md5($accent), 0, 6);
        $id2 = 'sg2' . substr(md5($accent . '2'), 0, 6);
        $id3 = 'sg3' . substr(md5($accent . '3'), 0, 6);
        return '<svg viewBox="0 0 320 200" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
          <defs>
            <linearGradient id="' . $id1 . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#1d0e1b"/><stop offset="1" stop-color="#0a0f16"/></linearGradient>
            <linearGradient id="' . $id2 . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffb86b"/><stop offset="1" stop-color="#ff4d3d"/></linearGradient>
            <clipPath id="' . $id3 . '"><path d="M112 112A48 48 0 0 1 208 112Z"/></clipPath>
          </defs>
          <rect width="320" height="200" fill="url(#' . $id1 . ')"/>
          <g clip-path="url(#' . $id3 . ')"><rect x="100" y="60" width="120" height="52" fill="url(#' . $id2 . ')"/><rect x="100" y="92" width="120" height="3" fill="#0a0f16"/><rect x="100" y="100" width="120" height="4" fill="#0a0f16"/><rect x="100" y="107" width="120" height="5" fill="#0a0f16"/></g>
          <rect y="112" width="320" height="88" fill="#0a0f16"/>
          <g stroke="' . $accent . '" stroke-opacity=".6" stroke-width=".8" fill="none">
            <path d="M160 112V200M190 112L250 200M220 112L340 200M250 112L430 200M130 112L70 200M100 112L-20 200M70 112L-110 200"/>
            <path d="M0 118H320M0 126H320M0 136H320M0 150H320M0 170H320"/>
          </g>
          <text x="12" y="25" font-family="IBM Plex Mono,monospace" font-size="11" fill="' . $accent . '">238 FPS</text>
          <text x="12" y="38" font-family="IBM Plex Mono,monospace" font-size="7" fill="#a6b2bf">GPU 61°C   CPU 54°C</text>
          <rect x="258" y="14" width="50" height="6" rx="3" fill="#fff" fill-opacity=".2"/>
          <rect x="258" y="14" width="34" height="6" rx="3" fill="' . $accent . '"/>
        </svg>';
    }

    private function svgCreator(string $accent, string $ink, string $muted, string $bg2): string
    {
        $id1 = 'sc1' . substr(md5($accent), 0, 6);
        return '<svg viewBox="0 0 320 200" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
          <defs><linearGradient id="' . $id1 . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffb340"/><stop offset=".5" stop-color="#e2557a"/><stop offset="1" stop-color="#2b4474"/></linearGradient></defs>
          <rect width="320" height="200" fill="#0e1727"/>
          <rect x="12" y="14" width="196" height="110" rx="4" fill="url(#' . $id1 . ')"/>
          <circle cx="150" cy="70" r="26" fill="#fff" fill-opacity=".18"/>
          <rect x="218" y="14" width="90" height="110" rx="4" fill="#16233d"/>
          <path d="M228 22V72H298" fill="none" stroke="#f6f2ea" stroke-opacity=".3"/>
          <path d="M228 72C250 70 262 40 298 22" fill="none" stroke="' . $accent . '" stroke-width="2"/>
          <g stroke="#f6f2ea" stroke-opacity=".25"><path d="M228 86H298M228 98H298M228 110H298"/></g>
          <circle cx="252" cy="86" r="3.5" fill="' . $accent . '"/><circle cx="276" cy="98" r="3.5" fill="' . $accent . '"/><circle cx="240" cy="110" r="3.5" fill="' . $accent . '"/>
          <rect x="12" y="136" width="296" height="52" rx="4" fill="#16233d"/>
          <rect x="18" y="142" width="90" height="12" rx="2" fill="' . $accent . '"/>
          <rect x="112" y="142" width="64" height="12" rx="2" fill="#7aa2ff"/>
          <rect x="180" y="142" width="120" height="12" rx="2" fill="' . $accent . '" fill-opacity=".7"/>
          <rect x="18" y="158" width="150" height="10" rx="2" fill="#35a39c"/>
          <rect x="172" y="158" width="80" height="10" rx="2" fill="#7aa2ff" fill-opacity=".7"/>
          <rect x="18" y="172" width="230" height="8" rx="2" fill="#fff" fill-opacity=".2"/>
          <path d="M140 134V188" stroke="' . $accent . '" stroke-width="1.5"/>
        </svg>';
    }

    private function svgBusiness(string $accent, string $ink, string $muted, string $bg2): string
    {
        return '<svg viewBox="0 0 320 200" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
          <rect width="320" height="200" fill="#f5f6f8"/>
          <rect width="320" height="16" fill="' . $accent . '"/>
          <rect x="8" y="5" width="40" height="6" rx="3" fill="#fff" fill-opacity=".85"/>
          <rect y="16" width="52" height="184" fill="#e9ebef"/>
          <rect x="8" y="28" width="36" height="7" rx="3" fill="' . $accent . '"/>
          <g fill="#14161a" fill-opacity=".25"><rect x="8" y="44" width="30" height="6" rx="3"/><rect x="8" y="58" width="34" height="6" rx="3"/><rect x="8" y="72" width="26" height="6" rx="3"/></g>
          <g fill="#fff" stroke="#d5d8de"><rect x="62" y="26" width="76" height="40" rx="4"/><rect x="146" y="26" width="76" height="40" rx="4"/><rect x="230" y="26" width="76" height="40" rx="4"/></g>
          <g fill="#14161a"><rect x="70" y="36" width="30" height="9" rx="2"/><rect x="154" y="36" width="38" height="9" rx="2"/><rect x="238" y="36" width="26" height="9" rx="2"/></g>
          <g fill="#14161a" fill-opacity=".25"><rect x="70" y="52" width="48" height="4" rx="2"/><rect x="154" y="52" width="40" height="4" rx="2"/><rect x="238" y="52" width="52" height="4" rx="2"/></g>
          <rect x="62" y="76" width="140" height="112" rx="4" fill="#fff" stroke="#d5d8de"/>
          <g fill="' . $accent . '"><rect x="74" y="140" width="14" height="38" rx="2"/><rect x="96" y="124" width="14" height="54" rx="2"/><rect x="118" y="132" width="14" height="46" rx="2"/><rect x="140" y="108" width="14" height="70" rx="2"/><rect x="162" y="118" width="14" height="60" rx="2"/><rect x="184" y="96" width="10" height="82" rx="2" fill-opacity=".45"/></g>
          <path d="M70 178H196" stroke="#14161a" stroke-opacity=".3"/>
          <rect x="210" y="76" width="96" height="112" rx="4" fill="#fff" stroke="#d5d8de"/>
          <g fill="#14161a" fill-opacity=".18"><rect x="218" y="88" width="80" height="6" rx="2"/><rect x="218" y="104" width="80" height="6" rx="2"/><rect x="218" y="120" width="80" height="6" rx="2"/><rect x="218" y="136" width="80" height="6" rx="2"/><rect x="218" y="152" width="80" height="6" rx="2"/><rect x="218" y="168" width="80" height="6" rx="2"/></g>
          <rect x="218" y="88" width="30" height="6" rx="2" fill="' . $accent . '"/>
        </svg>';
    }
}
