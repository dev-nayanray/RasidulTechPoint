<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/**
 * Downloads REAL brand logos and REAL laptop photos (freely licensed media
 * from Wikimedia Commons) and relinks the demo catalog images to them,
 * replacing the locally generated placeholder illustrations.
 *
 * LOCAL DEV ONLY (guarded by bin/demo-catalog.php).
 *
 * Sources are stable Wikimedia Commons endpoints (Special:FilePath), every
 * download is validated (HTTP 200 + real MIME via finfo + minimum pixel
 * size), and per-file attribution (author/license/source URL) is recorded in
 * storage/demo-media-manifest.json so licenses can be honoured later.
 */
final class DemoMediaImporter
{
    private const UA = 'RasidulTechPoint-Dev/1.0 (local development testing; rasidultechpoint@gmail.com)';
    private const BRANDS_DIR = 'uploads/brands';
    private const PRODUCTS_DIR = 'uploads/products';
    private const PHOTOS_PER_BRAND = 4;
    private const MIN_W = 500;
    private const MIN_H = 350;

    /** Verified Wikimedia Commons logo files (SVGs served as PNG via ?width=). */
    private const LOGO_FILES = [
        'dell' => 'Dell_logo_2016.svg',
        'hp' => 'HP_logo_2012.svg',
        'lenovo' => 'Lenovo_logo_2015.svg',
        'asus' => 'Asus_blue_logo.svg',
        'acer' => 'Acer-Logo_2001.svg',
        'apple' => 'Apple_logo_black.svg',
        'microsoft' => 'Microsoft_logo_(2012).svg',
        'msi' => 'Micro-Star_International_logo.svg',
        'toshiba' => 'Toshiba_logo.svg',
        'samsung' => 'Samsung_Logo.svg',
    ];

    /** Search terms per brand for real laptop photos on Commons. */
    private const PHOTO_QUERIES = [
        'dell' => 'Dell Latitude laptop',
        'hp' => 'HP EliteBook laptop',
        'lenovo' => 'ThinkPad laptop',
        'asus' => 'ASUS laptop',
        'acer' => 'Acer Aspire laptop',
        'apple' => 'MacBook laptop',
        'microsoft' => 'Surface Laptop',
        'msi' => 'MSI gaming laptop',
        'toshiba' => 'Toshiba Satellite laptop',
        'samsung' => 'Samsung laptop computer',
    ];

    /** Titles containing these words are skipped (not product photos). */
    private const TITLE_DENYLIST = '/booth|person|people|team|ceremon|conference|meeting|staff|portrait|winner|logo|icon|map|chart|diagram|box |package|unboxing/i';

    /**
     * @param callable(string): void $out
     * @return array<string,mixed> summary
     */
    public function run(PDO $pdo, callable $out): array
    {
        $stats = [
            'logos_ok' => 0, 'logos_failed' => [],
            'photos_ok' => 0, 'photos_failed' => [],
            'products_relinked' => 0, 'old_files_deleted' => 0, 'old_files_missing' => 0,
        ];

        $manifest = ['downloaded_at' => gmdate('c'), 'source' => 'Wikimedia Commons', 'files' => []];

        // ---- 1) Brand logos -------------------------------------------------
        $this->ensureDir(self::BRANDS_DIR);
        $updLogo = $pdo->prepare('UPDATE brands SET logo_path = ? WHERE slug = ?');
        $findBrand = $pdo->prepare('SELECT id FROM brands WHERE slug = ?');
        foreach (self::LOGO_FILES as $slug => $file) {
            $findBrand->execute([$slug]);
            if ($findBrand->fetchColumn() === false) {
                continue; // brand not present in this DB — nothing to do
            }
            $rel = self::BRANDS_DIR . '/' . $slug . '-logo.png';
            $abs = BASE_PATH . '/public/' . $rel;
            $url = 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode($file) . '?width=500';
            if (!$this->download($url, $abs, ['image/png'], 100, 40)) {
                $stats['logos_failed'][] = $slug;
                $out("  logo FAILED: {$slug} ({$file})");
                continue;
            }
            $updLogo->execute([$rel, $slug]);
            $manifest['files'][$rel] = $this->meta('logo', "File:{$file}", $file);
            $stats['logos_ok']++;
        }

        // ---- 2) Real laptop photos per brand --------------------------------
        $this->ensureDir(self::PRODUCTS_DIR);
        $pools = []; // brand slug => list of relative paths
        foreach (self::PHOTO_QUERIES as $slug => $query) {
            $titles = $this->searchCommons($query, self::PHOTOS_PER_BRAND * 4);
            $got = 0;
            foreach ($titles as $title) {
                if ($got >= self::PHOTOS_PER_BRAND) {
                    break;
                }
                if (preg_match(self::TITLE_DENYLIST, $title) === 1) {
                    continue;
                }
                $rel = self::PRODUCTS_DIR . '/demo-real-' . $slug . '-' . ($got + 1) . '.jpg';
                $abs = BASE_PATH . '/public/' . $rel;
                $url = 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode($title) . '?width=1200';
                if (!$this->download($url, $abs, ['image/jpeg', 'image/png'])) {
                    continue; // try the next candidate
                }
                $pools[$slug][] = $rel;
                $manifest['files'][$rel] = $this->meta('laptop photo', $title, $title);
                $got++;
            }
            if ($got === 0) {
                $stats['photos_failed'][] = $slug;
                $out("  photos FAILED: {$slug} — no usable Commons photo found (products keep current images)");
            } else {
                $stats['photos_ok'] += $got;
                $out("  photos: {$slug} × {$got}");
            }
        }

        // ---- 3) Relink demo product images to the real photos ---------------
        if ($pools !== []) {
            $rows = $pdo->query(
                "SELECT pi.id, pi.sort_order, pi.file_path, pm.id AS model_id, b.slug AS brand_slug, pm.name
                 FROM product_images pi
                 JOIN product_models pm ON pm.id = pi.product_model_id
                 JOIN brands b ON b.id = pm.brand_id
                 WHERE pm.is_demo = 1 AND pm.deleted_at IS NULL
                 ORDER BY pm.id ASC, pi.sort_order ASC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $brandCounters = [];
            $oldFiles = [];
            $updImg = $pdo->prepare('UPDATE product_images SET file_path = ?, alt = ? WHERE id = ?');
            foreach ($rows as $row) {
                $pool = $pools[$row['brand_slug']] ?? null;
                if ($pool === null) {
                    continue; // brand without photos keeps its existing image
                }
                $brandCounters[$row['brand_slug']] = ($brandCounters[$row['brand_slug']] ?? 0) + 1;
                $pick = $pool[$brandCounters[$row['brand_slug']] % count($pool)];
                $alt = 'Illustrative photo of a ' . $row['name'] . ' — real laptop photo, not the exact unit';
                $updImg->execute([$pick, $alt, (int) $row['id']]);
                $stats['products_relinked']++;
                // Old generated file cleanup list (only the placeholder pattern).
                if (preg_match('#/demo-[^/]+-\d+\.png$#', (string) $row['file_path']) === 1) {
                    $oldFiles[(string) $row['file_path']] = true;
                }
            }

            foreach (array_keys($oldFiles) as $rel) {
                $abs = BASE_PATH . '/public/' . $rel;
                if (is_file($abs)) {
                    @unlink($abs);
                    $stats['old_files_deleted']++;
                } else {
                    $stats['old_files_missing']++;
                }
            }
        }

        // ---- 4) Attribution manifest ----------------------------------------
        $manifestPath = BASE_PATH . '/storage/demo-media-manifest.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Ask Commons for author/license of the files we used (best effort).
        $this->enrichManifestWithLicenses($manifestPath);

        return $stats;
    }

    /** Commons full-text search restricted to the File namespace. */
    private function searchCommons(string $query, int $limit): array
    {
        $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
            'action' => 'query',
            'list' => 'search',
            'srsearch' => $query . ' filetype:bitmap',
            'srnamespace' => 6,
            'srlimit' => $limit,
            'format' => 'json',
        ]);
        $body = $this->httpGet($url);
        if ($body === null) {
            return [];
        }
        $data = json_decode($body, true);
        $titles = [];
        foreach ($data['query']['search'] ?? [] as $r) {
            $title = (string) ($r['title'] ?? '');
            if ($title !== '' && preg_match('/\.(jpe?g|png)$/i', $title) === 1) {
                $titles[] = $title;
            }
        }
        return $titles;
    }

    /**
     * Download with validation: HTTP 200, real MIME in $allowedMimes
     * (finfo, not headers), and minimum pixel dimensions.
     */
    private function download(string $url, string $destAbs, array $allowedMimes, int $minW = self::MIN_W, int $minH = self::MIN_H): bool
    {
        $body = $this->httpGet($url);
        if ($body === null || strlen($body) < 1024) {
            return false;
        }
        $tmp = $destAbs . '.tmp';
        if (file_put_contents($tmp, $body) === false) {
            return false;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!in_array($mime, $allowedMimes, true)) {
            @unlink($tmp);
            return false;
        }
        $size = @getimagesize($tmp);
        if (!is_array($size) || $size[0] < $minW || $size[1] < $minH) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $destAbs)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    private function httpGet(string $url, int $timeout = 25): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? (string) $body : null;
    }

    private function ensureDir(string $rel): void
    {
        $abs = BASE_PATH . '/public/' . $rel;
        if (!is_dir($abs)) {
            mkdir($abs, 0770, true);
        }
    }

    private function meta(string $kind, string $title, string $page): array
    {
        return [
            'kind' => $kind,
            'commons_title' => $title,
            'source_url' => 'https://commons.wikimedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $page)),
        ];
    }

    /** Best-effort license/author lookup for every file in the manifest. */
    private function enrichManifestWithLicenses(string $manifestPath): void
    {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            return;
        }
        $titles = [];
        foreach ($manifest['files'] ?? [] as $info) {
            $titles[] = $info['commons_title'];
        }
        $titles = array_values(array_unique($titles));
        foreach (array_chunk($titles, 20) as $chunk) {
            $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
                'action' => 'query',
                'titles' => implode('|', $chunk),
                'prop' => 'imageinfo',
                'iiprop' => 'extmetadata',
                'iiextmetadatafilter' => 'Artist|LicenseShortName|LicenseUrl',
                'format' => 'json',
            ]);
            $body = $this->httpGet($url);
            if ($body === null) {
                continue;
            }
            $data = json_decode($body, true);
            foreach ($data['query']['pages'] ?? [] as $page) {
                $title = (string) ($page['title'] ?? '');
                $md = $page['imageinfo'][0]['extmetadata'] ?? null;
                if ($md === null) {
                    continue;
                }
                $strip = static function ($v): string {
                    return trim(strip_tags((string) ($v['value'] ?? '')));
                };
                foreach ($manifest['files'] as $relPath => $info) {
                    if (($info['commons_title'] ?? '') === $title) {
                        $manifest['files'][$relPath]['author'] = $strip($md['Artist'] ?? null);
                        $manifest['files'][$relPath]['license'] = $strip($md['LicenseShortName'] ?? null);
                        if (!empty($md['LicenseUrl']['value'])) {
                            $manifest['files'][$relPath]['license_url'] = $strip($md['LicenseUrl']);
                        }
                    }
                }
            }
        }
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
