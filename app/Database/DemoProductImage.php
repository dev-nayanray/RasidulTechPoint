<?php

declare(strict_types=1);

namespace App\Database;

/**
 * Draws the demo-catalog product illustrations locally with GD.
 *
 * Why generated images: every demo product needs a guaranteed-working,
 * license-clean picture. These are original vector-style illustrations
 * drawn by this class (not photos of real units), brand-tinted so each
 * product is visually distinguishable. The storefront alt text labels
 * them as illustrative.
 *
 * Two variants per product:
 *   1 = open laptop (front view)   → primary image
 *   2 = closed laptop (top view)   → gallery image
 */
final class DemoProductImage
{
    public const WIDTH = 1200;
    public const HEIGHT = 900;

    /** Brand accent palettes: [lid, lidDark, screen, base, backdrop, backdrop2] */
    private const PALETTES = [
        'dell'     => ['#2f6fd8', '#1d4fa8', '#0e1c30', '#c8d2de', '#e8eef8', '#f7fafe'],
        'hp'       => ['#0f6fde', '#0a4fa5', '#0c1a2e', '#c9d3df', '#e7eef9', '#f7fafe'],
        'lenovo'   => ['#d8342f', '#a82320', '#1a1214', '#cfd6de', '#f9ebea', '#fdf6f5'],
        'asus'     => ['#1a2d6e', '#101c48', '#0b1226', '#c4ccd8', '#e9ecf8', '#f8f9fe'],
        'acer'     => ['#1f7a4d', '#155c39', '#0c1f16', '#c6d0d6', '#e8f4ed', '#f7fcf9'],
        'apple'    => ['#3a3f47', '#24272c', '#111318', '#d2d6dc', '#eef0f3', '#fafbfc'],
        'microsoft'=> ['#0d6efd', '#0a53c0', '#0a1626', '#c8d2de', '#e8eefd', '#f7f9fe'],
        'msi'      => ['#b01818', '#7d1010', '#1a0d0d', '#c9ced6', '#f8ecec', '#fdf7f7'],
        'toshiba'  => ['#c0173c', '#8d0f2c', '#1c0d13', '#ccd2da', '#f9ecf0', '#fdf6f8'],
        'samsung'  => ['#1428a0', '#0d1a72', '#0a1128', '#c5cedb', '#eaedf9', '#f8f9fe'],
        'generic'  => ['#2563eb', '#1d4fd8', '#0e1a2e', '#c9d3df', '#e8eef8', '#f7fafe'],
    ];

    /**
     * Render one illustration to $absolutePath (PNG). Returns true when the
     * file was written and verifies as a real PNG of the expected size.
     */
    public static function render(string $absolutePath, string $brandKey, string $seed, int $variant): bool
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;
        $im = imagecreatetruecolor($w, $h);
        if ($im === false) {
            return false;
        }
        imagesavealpha($im, false);

        $palette = self::PALETTES[strtolower($brandKey)] ?? self::PALETTES['generic'];
        [$lid, $lidDark, $screen, $base, $back1, $back2] = array_map([self::class, 'rgb'], $palette);

        // Deterministic per-product accent so the same product always gets
        // the same picture (idempotent regeneration).
        $rng = crc32($seed . '|' . $variant);
        $accent = self::rgb(self::ACCENTS[$rng % count(self::ACCENTS)]);
        $accent2 = self::rgb(self::ACCENTS[($rng >> 8) % count(self::ACCENTS)]);

        self::backdrop($im, $w, $h, $back1, $back2, $rng);

        if ($variant === 2) {
            self::drawClosedLaptop($im, $w, $h, $lid, $lidDark, $base, $accent, $rng);
        } else {
            self::drawOpenLaptop($im, $w, $h, $lid, $lidDark, $screen, $base, $accent, $accent2, $rng);
        }

        $ok = imagepng($im, $absolutePath, 6);
        imagedestroy($im);
        if (!$ok) {
            return false;
        }

        // Verify the written file is genuinely a full-size PNG.
        $info = @getimagesize($absolutePath);
        return is_array($info)
            && ($info[2] ?? 0) === IMAGETYPE_PNG
            && (int) $info[0] === $w && (int) $info[1] === $h;
    }

    private const ACCENTS = ['#7AFB01', '#38bdf8', '#f59e0b', '#34d399', '#f472b6', '#a78bfa', '#facc15'];

    private static function backdrop($im, int $w, int $h, array $b1, array $b2, int $rng): void
    {
        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            $color = imagecolorallocate(
                $im,
                (int) round($b1[0] + ($b2[0] - $b1[0]) * $t),
                (int) round($b1[1] + ($b2[1] - $b1[1]) * $t),
                (int) round($b1[2] + ($b2[2] - $b1[2]) * $t),
            );
            imageline($im, 0, $y, $w, $y, $color);
        }
        // Soft dotted texture, deterministic.
        $dot = imagecolorallocate($im, 175, 190, 208);
        for ($i = 0; $i < 340; $i++) {
            $r1 = self::rand($rng, $i, 1);
            $r2 = self::rand($rng, $i, 2);
            imagesetpixel($im, (int) ($r1 * $w), (int) ($r2 * $h), $dot);
            imageellipse($im, (int) ($r1 * $w), (int) ($r2 * $h), 2, 2, $dot);
        }
    }

    private static function drawOpenLaptop($im, int $w, int $h, array $lid, array $lidDark, array $screen, array $base, array $accent, array $accent2, int $rng): void
    {
        $cx = (int) ($w / 2);

        // ---- Floor shadow
        $shadow = imagecolorallocatealpha($im, 20, 36, 58, 78);
        imagefilledellipse($im, $cx, (int) ($h * 0.87), (int) ($w * 0.62), (int) ($h * 0.075), $shadow);

        // ---- Lid (screen section): rounded rect
        $lidX = (int) ($w * 0.20);
        $lidY = (int) ($h * 0.115);
        $lidW = (int) ($w * 0.60);
        $lidH = (int) ($h * 0.585);
        self::roundedRect($im, $lidX, $lidY, $lidX + $lidW, $lidY + $lidH, 26, self::alloc($im, $lid));
        imagerectangle($im, $lidX, $lidY, $lidX + $lidW, $lidY + $lidH, self::alloc($im, $lidDark));

        // ---- Screen inside lid
        $pad = (int) ($lidW * 0.045);
        $sx = $lidX + $pad;
        $sy = $lidY + $pad;
        $sw = $lidW - 2 * $pad;
        $sh = $lidH - 2 * $pad - (int) ($lidH * 0.035);
        self::roundedRect($im, $sx, $sy, $sx + $sw, $sy + $sh, 10, self::alloc($im, $screen));

        // Screen wallpaper: vertical accent wash, strictly inside the screen
        // rect (drawn line-by-line so nothing can spill past the bezel).
        $steps = max(2, $sh);
        for ($i = 0; $i < $steps; $i++) {
            $t = $i / ($steps - 1); // 0 top → 1 bottom
            $mix = 1 - $t; // stronger accent at the top
            $c = imagecolorallocate(
                $im,
                (int) round($screen[0] * (1 - 0.45 * $mix) + $accent[0] * 0.45 * $mix),
                (int) round($screen[1] * (1 - 0.45 * $mix) + $accent[1] * 0.45 * $mix),
                (int) round($screen[2] * (1 - 0.45 * $mix) + $accent[2] * 0.45 * $mix),
            );
            imageline($im, $sx, $sy + $i, $sx + $sw, $sy + $i, $c);
        }

        // Glow bar + window cards on screen (UI hint).
        imagefilledrectangle($im, $sx + (int) ($sw * 0.10), $sy + (int) ($sh * 0.14), $sx + (int) ($sw * 0.34), $sy + (int) ($sh * 0.17), self::alloc($im, $accent));
        $card = imagecolorallocatealpha($im, 255, 255, 255, 52);
        imagefilledrectangle($im, $sx + (int) ($sw * 0.10), $sy + (int) ($sh * 0.26), $sx + (int) ($sw * 0.46), $sy + (int) ($sh * 0.52), $card);
        imagefilledrectangle($im, $sx + (int) ($sw * 0.52), $sy + (int) ($sh * 0.26), $sx + (int) ($sw * 0.88), $sy + (int) ($sh * 0.52), $card);
        imagefilledrectangle($im, $sx + (int) ($sw * 0.10), $sy + (int) ($sh * 0.60), $sx + (int) ($sw * 0.68), $sy + (int) ($sh * 0.65), $card);
        imagefilledrectangle($im, $sx + (int) ($sw * 0.10), $sy + (int) ($sh * 0.72), $sx + (int) ($sw * 0.52), $sy + (int) ($sh * 0.77), $card);
        // Status dot on the wallpaper.
        imagefilledellipse($im, $sx + (int) ($sw * 0.90), $sy + (int) ($sh * 0.15), 18, 18, self::alloc($im, $accent));

        // Camera dot centered in the top bezel strip (between lid edge and screen).
        imagefilledellipse($im, $cx, (int) (($lidY + $sy) / 2), 8, 8, self::alloc($im, [90, 105, 125]));

        // ---- Base (keyboard deck) in perspective.
        $baseTopY = $lidY + $lidH;
        $baseH = (int) ($h * 0.085);
        $baseHalfTop = (int) ($lidW / 2);
        $baseHalfBottom = (int) ($lidW * 0.62);
        $botY = $baseTopY + $baseH;
        $c = self::alloc($im, $base);
        imagefilledpolygon($im, [
            $cx - $baseHalfTop, $baseTopY,
            $cx + $baseHalfTop, $baseTopY,
            $cx + $baseHalfBottom, $botY,
            $cx - $baseHalfBottom, $botY,
        ], $c);
        // Edge line + keyboard rows + trackpad.
        $kb = imagecolorallocatealpha($im, 120, 135, 155, 70);
        for ($row = 0; $row < 3; $row++) {
            $y0 = $baseTopY + (int) ($baseH * (0.22 + $row * 0.17));
            $spread = $baseHalfTop + (int) (($baseHalfBottom - $baseHalfTop) * (0.22 + $row * 0.17));
            imagefilledpolygon($im, [$cx - $spread + 6, $y0, $cx + $spread - 6, $y0, $cx + $spread - 2, $y0 + 8, $cx - $spread + 2, $y0 + 8], $kb);
        }
        $tpW = (int) ($w * 0.13);
        imagefilledpolygon($im, [$cx - $tpW, $botY - 4, $cx + $tpW, $botY - 4, $cx + (int) ($tpW * 1.12), $botY, $cx - (int) ($tpW * 1.12), $botY], self::alloc($im, $lidDark));

        // ---- Floating spec chips (deterministic picks, echo the storefront cards).
        $chips = [
            ['CPU', (int) ($w * 0.09), (int) ($h * 0.24)],
            ['RAM', (int) ($w * 0.055), (int) ($h * 0.46)],
            ['SSD', (int) ($w * 0.84), (int) ($h * 0.38)],
        ];
        foreach ($chips as $i => [$label, $chipX, $chipY]) {
            $cw = 132;
            $ch = 56;
            $white = imagecolorallocatealpha($im, 255, 255, 255, 26);
            self::roundedRect($im, $chipX, $chipY, $chipX + $cw, $chipY + $ch, 14, $white);
            imagerectangle($im, $chipX, $chipY, $chipX + $cw, $chipY + $ch, imagecolorallocatealpha($im, 20, 36, 58, 90));
            imagefilledellipse($im, $chipX + 24, $chipY + 28, 12, 12, $i === 0 ? self::alloc($im, $accent) : self::alloc($im, $accent2));
        }
    }

    private static function drawClosedLaptop($im, int $w, int $h, array $lid, array $lidDark, array $base, array $accent, int $rng): void
    {
        $cx = (int) ($w / 2);

        $shadow = imagecolorallocatealpha($im, 20, 36, 58, 78);
        imagefilledellipse($im, $cx, (int) ($h * 0.80), (int) ($w * 0.56), (int) ($h * 0.06), $shadow);

        // Closed lid seen from the top, slightly rotated look via offsets.
        $lw = (int) ($w * 0.66);
        $lh = (int) ($h * 0.44);
        $lx = $cx - (int) ($lw / 2);
        $ly = (int) ($h * 0.26);
        self::roundedRect($im, $lx, $ly, $lx + $lw, $ly + $lh, 34, self::alloc($im, $lid));
        imagerectangle($im, $lx, $ly, $lx + $lw, $ly + $lh, self::alloc($im, $lidDark));

        // Subtle brushed-metal sheen.
        $sheen = imagecolorallocatealpha($im, 255, 255, 255, 110);
        imagefilledpolygon($im, [
            $lx + (int) ($lw * 0.12), $ly + $lh,
            $lx + (int) ($lw * 0.30), $ly,
            $lx + (int) ($lw * 0.42), $ly,
            $lx + (int) ($lw * 0.24), $ly + $lh,
        ], $sheen);

        // Brand dot near the hinge edge.
        imagefilledellipse($im, $cx, $ly + $lh - 26, 20, 20, self::alloc($im, $accent));

        // Hinge bar below.
        $hbY = $ly + $lh + 6;
        $c = self::alloc($im, $base);
        imagefilledpolygon($im, [
            $lx + 10, $hbY,
            $lx + $lw - 10, $hbY,
            $lx + $lw + 26, $hbY + 26,
            $lx - 26, $hbY + 26,
        ], $c);
    }

    private static function roundedRect($im, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledellipse($im, $x1 + $r, $y1 + $r, 2 * $r, 2 * $r, $color);
        imagefilledellipse($im, $x2 - $r, $y1 + $r, 2 * $r, 2 * $r, $color);
        imagefilledellipse($im, $x1 + $r, $y2 - $r, 2 * $r, 2 * $r, $color);
        imagefilledellipse($im, $x2 - $r, $y2 - $r, 2 * $r, 2 * $r, $color);
    }

    private static function alloc($im, array $rgb): int
    {
        return imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $hex) ?: '2563eb';
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            $hex = str_pad($hex, 6, '0');
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function rand(int $rng, int $i, int $salt): float
    {
        return (crc32($rng . ':' . $i . ':' . $salt) % 10000) / 10000;
    }
}
