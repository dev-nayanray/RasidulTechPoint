<?php

declare(strict_types=1);

namespace App\Database\Seeders;

use App\Database\DemoProductImage;
use App\Services\InventoryService;
use App\Support\Database\Connection;
use PDO;

/**
 * DEVELOPMENT-ONLY demo catalog: 100+ laptop models with locally generated
 * illustrative images and demo inventory units, for storefront/admin testing.
 *
 * Guarantees:
 *  - Refuses to run outside APP_ENV=local.
 *  - Idempotent: re-running inserts nothing new (models matched by slug,
 *    units by SKU, image files regenerated only if missing on disk).
 *  - Never touches non-demo rows: a slug that already belongs to a real
 *    (is_demo = 0) product is skipped with a warning, never updated.
 *  - All demo rows are flagged is_demo = 1, units use DEMO- SKUs, NULL
 *    serials, and a DEMO supplier reference so they can never be confused
 *    with real stock (purge via bin/demo-catalog.php purge).
 *
 * Catalog facts are plausible reference configurations of real model
 * families; descriptions say "demo catalog record" so nothing is presented
 * as a verified physical unit.
 */
final class DemoCatalogSeeder
{
    private const IMAGE_DIR = 'uploads/products';

    /** @var array<int, array{brand:string,cat:string,name:string,cpu:string,ram:int,st:string,cap:int,gpu:string,disp:float,res:string,os:string,price:int,grade:string}> */
    private ?array $catalog = null;

    /**
     * Seeder-runner entry point (bin/seed.php --demo). The dedicated CLI
     * (bin/demo-catalog.php) passes an output callback and uses the stats.
     *
     * @param callable(string): void|null $out
     * @return array<string,int> summary counters
     */
    public function run(PDO $pdo, ?callable $out = null): array
    {
        if ((string) \App\Support\Config::get('app.env') !== 'local') {
            throw new \RuntimeException('DemoCatalogSeeder may only run in the local environment.');
        }
        $say = static function (string $m) use ($out): void {
            if ($out !== null) {
                $out($m);
            }
        };

        $stats = [
            'models_inserted' => 0, 'models_skipped' => 0, 'models_conflict' => 0, 'models_failed' => 0,
            'units_inserted' => 0, 'units_skipped' => 0,
            'images_linked' => 0, 'images_regenerated' => 0, 'images_failed' => 0,
        ];

        $brandIds = $this->ensureBrands($pdo);
        $catIds = $this->ensureCategories($pdo);
        $inventory = InventoryService::forSeeding();

        $findModel = $pdo->prepare('SELECT id, is_demo FROM product_models WHERE slug = ?');
        $findUnit = $pdo->prepare('SELECT id FROM inventory_units WHERE sku = ?');
        $findImg = $pdo->prepare('SELECT id, file_path FROM product_images WHERE product_model_id = ? AND sort_order = ?');

        $skuSeq = 1;
        foreach ($this->catalog() as $i => $item) {
            $slug = \str_slug($item['name']);

            try {
                $modelId = $this->upsertModel($pdo, $findModel, $brandIds, $catIds, $item, $slug, $stats, $say);
                if ($modelId === null) {
                    $skuSeq += ($i % 3 === 0) ? 2 : 1; // keep SKU sequence deterministic
                    continue;
                }

                // ---- Images (2 variants: primary open view + gallery closed view)
                foreach ([1 => 'primary open view', 2 => 'gallery closed view'] as $variant => $label) {
                    $relPath = self::IMAGE_DIR . '/demo-' . $slug . '-' . $variant . '.png';
                    $absPath = BASE_PATH . '/public/' . $relPath;

                    $findImg->execute([$modelId, $variant - 1]);
                    $imgRow = $findImg->fetch(PDO::FETCH_ASSOC);

                    // The model already has imagery that is NOT the generated
                    // placeholder (e.g. real photos linked by `demo-catalog.php
                    // media`). Respect it — never relink or regenerate.
                    if ($imgRow !== false
                        && (string) $imgRow['file_path'] !== $relPath
                        && str_contains((string) $imgRow['file_path'], '/demo-')) {
                        continue;
                    }

                    if (!is_file($absPath)) {
                        if (DemoProductImage::render($absPath, $item['brand'], $slug, $variant)) {
                            $stats[$imgRow === false ? 'images_linked' : 'images_regenerated']++;
                        } else {
                            $stats['images_failed']++;
                            $say("  image FAILED [{$item['name']} v{$variant}] — product kept, image missing");
                            continue;
                        }
                    } elseif ($imgRow === false) {
                        $stats['images_linked']++;
                    }

                    if ($imgRow === false) {
                        $pdo->prepare('INSERT INTO product_images (product_model_id, file_path, alt, is_primary, sort_order) VALUES (?,?,?,?,?)')
                            ->execute([
                                $modelId,
                                $relPath,
                                'Illustrative demo image of the ' . $item['name'] . ' (not an actual unit photo)',
                                $variant === 1 ? 1 : 0,
                                $variant - 1,
                            ]);
                    }
                }

                // ---- Demo inventory units (deterministic count: every 3rd model gets 2)
                $unitCount = ($i % 3 === 0) ? 2 : 1;
                for ($u = 0; $u < $unitCount; $u++) {
                    $sku = sprintf('DEMO-%04d', $skuSeq++);
                    $findUnit->execute([$sku]);
                    if ($findUnit->fetchColumn() !== false) {
                        $stats['units_skipped']++;
                        continue;
                    }
                    $grade = $u === 0 ? $item['grade'] : $this->secondGrade($item['grade']);
                    $price = $u === 0
                        ? $item['price']
                        : (int) round($item['price'] * ($grade === 'A' ? 1.08 : ($grade === 'C' ? 0.9 : 1.0)));
                    $cost = (int) round($price * 0.76);
                    $landed = (int) round($price * 0.05);

                    // is_demo flag on the unit (column added by migration 038).
                    $unitId = $inventory->receiveUnitForSeeding([
                        'sku' => $sku,
                        'serial_number' => null,
                        'product_model_id' => $modelId,
                        'condition_grade' => $grade,
                        'purchase_cost' => $cost,
                        'landed_cost' => $landed,
                        'selling_price' => $price,
                        'supplier_ref' => 'DEMO CATALOG (local testing)',
                        'purchase_ref' => 'DEMO-2026-CATALOG',
                        'notes' => 'Demo data generated by DemoCatalogSeeder — not a real unit.',
                    ], 'available', null);
                    $pdo->prepare('UPDATE inventory_units SET is_demo = 1 WHERE id = ?')->execute([$unitId]);
                    $stats['units_inserted']++;
                }
            } catch (\Throwable $e) {
                $stats['models_failed']++;
                $say("  FAILED [{$item['name']}]: " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Find-or-insert one product model. Returns the model id, or null when
     * skipped (already present, or the slug belongs to a REAL product —
     * which is never modified).
     */
    private function upsertModel(
        PDO $pdo,
        \PDOStatement $findModel,
        array $brandIds,
        array $catIds,
        array $item,
        string $slug,
        array &$stats,
        callable $say,
    ): ?int {
        $findModel->execute([$slug]);
        $existing = $findModel->fetch(PDO::FETCH_ASSOC);

        if ($existing !== false) {
            if ((int) $existing['is_demo'] !== 1) {
                $stats['models_conflict']++;
                $say("  SKIP conflict [{$item['name']}]: slug '{$slug}' already belongs to a REAL product — left untouched");
                return null;
            }
            $stats['models_skipped']++;
            return (int) $existing['id'];
        }

        $desc = "Demo catalog record for local testing. {$item['name']} — {$item['cpu']}, "
            . $item['ram'] . ' GB RAM, ' . $item['cap'] . ' GB ' . $item['st'] . ', '
            . number_format($item['disp'], 1) . '" display. Illustrative data, not a verified physical unit.';
        $pdo->prepare(
            'INSERT INTO product_models
                (brand_id, category_id, name, slug, processor, ram_gb, storage_type, storage_capacity_gb,
                 graphics, display_size_inches, resolution, operating_system, description,
                 seo_title, seo_description, is_published, is_demo)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)'
        )->execute([
            $brandIds[$item['brand']], $catIds[$item['cat']], $item['name'], $slug,
            $item['cpu'], $item['ram'], $item['st'], $item['cap'], $item['gpu'],
            $item['disp'], $item['res'], $item['os'], $desc,
            $item['name'] . ' in Bangladesh | Rasidul Tech Point',
            "Used {$item['name']} — demo listing. Tested and quality checked.",
            1,
        ]);
        $stats['models_inserted']++;
        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,int> slug => id (existing rows reused, never renamed) */
    private function ensureBrands(PDO $pdo): array
    {
        $brands = [
            'Dell' => 'dell', 'HP' => 'hp', 'Lenovo' => 'lenovo', 'Asus' => 'asus',
            'Acer' => 'acer', 'Apple' => 'apple', 'Microsoft' => 'microsoft',
            'MSI' => 'msi', 'Toshiba' => 'toshiba', 'Samsung' => 'samsung',
        ];
        $find = $pdo->prepare('SELECT id FROM brands WHERE slug = ?');
        $ins = $pdo->prepare('INSERT INTO brands (name, slug, description) VALUES (?, ?, ?)');
        $ids = [];
        foreach ($brands as $name => $slug) {
            $find->execute([$slug]);
            $id = $find->fetchColumn();
            if ($id === false) {
                $ins->execute([$name, $slug, "{$name} laptops"]);
                $id = $pdo->lastInsertId();
            }
            $ids[$slug] = (int) $id;
        }
        return $ids;
    }

    /** @return array<string,int> slug => id */
    private function ensureCategories(PDO $pdo): array
    {
        $cats = [
            'Business Laptops' => 'business-laptops',
            'Student Laptops' => 'student-laptops',
            'Budget Laptops' => 'budget-laptops',
            'Ultrabooks' => 'ultrabooks',
            'Premium Laptops' => 'premium-laptops',
            'Performance Laptops' => 'performance-laptops',
            'Gaming Laptops' => 'gaming-laptops',
            'Workstations' => 'workstations',
            'MacBooks' => 'macbooks',
        ];
        $find = $pdo->prepare('SELECT id FROM categories WHERE slug = ?');
        $ins = $pdo->prepare('INSERT INTO categories (name, slug, description, sort_order) VALUES (?, ?, ?, ?)');
        $ids = [];
        $sort = 10;
        foreach ($cats as $name => $slug) {
            $find->execute([$slug]);
            $id = $find->fetchColumn();
            if ($id === false) {
                $ins->execute([$name, $slug, "{$name} category", $sort]);
                $id = $pdo->lastInsertId();
            }
            $ids[$slug] = (int) $id;
            $sort += 10;
        }
        return $ids;
    }

    private function secondGrade(string $first): string
    {
        return match ($first) {
            'A' => 'B',
            'B' => 'C',
            default => 'B',
        };
    }

    /**
     * The demo catalog. Tuples:
     * [brand, category, name, cpu, ram, storageType, capacity, gpu, display, resolution, os, priceBDT, grade]
     *
     * @return list<array{brand:string,cat:string,name:string,cpu:string,ram:int,st:string,cap:int,gpu:string,disp:float,res:string,os:string,price:int,grade:string}>
     */
    private function catalog(): array
    {
        $w11p = 'Windows 11 Pro';
        $w11h = 'Windows 11 Home';
        $w10p = 'Windows 10 Pro';
        $macos = 'macOS';

        $rows = [
            // ---------- Dell business (Latitude / Vostro) ----------
            ['dell', 'business-laptops', 'Dell Latitude 5290', 'Intel Core i5-8350U', 8, 'SSD', 256, 'Intel UHD 620', 12.3, '1920x1280', $w11p, 38500, 'B'],
            ['dell', 'business-laptops', 'Dell Latitude 5400', 'Intel Core i5-8265U', 8, 'SSD', 256, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 36500, 'B'],
            ['dell', 'business-laptops', 'Dell Latitude 5410', 'Intel Core i5-10310U', 8, 'SSD', 256, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 45000, 'B'],
            ['dell', 'business-laptops', 'Dell Latitude 5411', 'Intel Core i5-10400H', 16, 'SSD', 512, 'Intel UHD 630', 14.0, '1920x1080', $w11p, 54000, 'A'],
            ['dell', 'business-laptops', 'Dell Latitude 5420', 'Intel Core i5-1145G7', 16, 'SSD', 256, 'Intel Iris Xe', 14.0, '1920x1080', $w11p, 57500, 'A'],
            ['dell', 'business-laptops', 'Dell Latitude 5430', 'Intel Core i5-1235U', 16, 'SSD', 512, 'Intel Iris Xe', 14.0, '1920x1080', $w11p, 69000, 'A'],
            ['dell', 'business-laptops', 'Dell Latitude 7280', 'Intel Core i5-7300U', 8, 'SSD', 256, 'Intel HD 620', 12.5, '1920x1080', $w10p, 30500, 'B'],
            ['dell', 'business-laptops', 'Dell Latitude 7390', 'Intel Core i5-8350U', 8, 'SSD', 256, 'Intel UHD 620', 13.3, '1920x1080', $w11p, 36000, 'B'],
            ['dell', 'business-laptops', 'Dell Latitude 7400', 'Intel Core i7-8665U', 16, 'SSD', 512, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 52000, 'A'],
            ['dell', 'business-laptops', 'Dell Latitude 7410', 'Intel Core i7-10610U', 16, 'SSD', 512, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 58500, 'A'],
            ['dell', 'business-laptops', 'Dell Latitude 7420', 'Intel Core i7-1185G7', 16, 'SSD', 512, 'Intel Iris Xe', 14.0, '1920x1080', $w11p, 72500, 'A'],
            ['dell', 'business-laptops', 'Dell Latitude 9410', 'Intel Core i7-10610U', 16, 'SSD', 512, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 64500, 'B'],
            ['dell', 'business-laptops', 'Dell Vostro 3490', 'Intel Core i5-10210U', 8, 'SSD', 256, 'Intel UHD 620', 14.0, '1920x1080', $w11h, 34000, 'C'],
            ['dell', 'business-laptops', 'Dell Vostro 3510', 'Intel Core i3-1115G4', 8, 'SSD', 256, 'Intel UHD 620', 15.6, '1920x1080', $w11h, 31000, 'B'],

            // ---------- Dell premium / XPS ----------
            ['dell', 'premium-laptops', 'Dell XPS 13 9360', 'Intel Core i7-7500U', 8, 'SSD', 256, 'Intel HD 620', 13.3, '1920x1080', $w11p, 55000, 'B'],
            ['dell', 'premium-laptops', 'Dell XPS 13 9370', 'Intel Core i7-8550U', 16, 'SSD', 512, 'Intel UHD 620', 13.3, '3840x2160', $w11p, 72000, 'A'],
            ['dell', 'premium-laptops', 'Dell XPS 13 9300', 'Intel Core i7-1065G7', 16, 'SSD', 512, 'Intel Iris Plus', 13.4, '1920x1200', $w11p, 89000, 'A'],
            ['dell', 'premium-laptops', 'Dell XPS 13 9310', 'Intel Core i7-1165G7', 16, 'SSD', 512, 'Intel Iris Xe', 13.4, '1920x1200', $w11p, 98500, 'A'],
            ['dell', 'premium-laptops', 'Dell XPS 15 9570', 'Intel Core i7-8750H', 16, 'SSD', 512, 'NVIDIA GTX 1050 Ti', 15.6, '3840x2160', $w11p, 92000, 'B'],
            ['dell', 'performance-laptops', 'Dell XPS 15 9500', 'Intel Core i7-10750H', 16, 'SSD', 1024, 'NVIDIA GTX 1650 Ti', 15.6, '3840x2160', $w11p, 125000, 'A'],

            // ---------- Dell workstations ----------
            ['dell', 'workstations', 'Dell Precision 3520', 'Intel Core i5-7440HQ', 8, 'SSD', 256, 'NVIDIA Quadro M620', 15.6, '1920x1080', $w10p, 62000, 'C'],
            ['dell', 'workstations', 'Dell Precision 3540', 'Intel Core i5-8265U', 16, 'SSD', 512, 'AMD Radeon Pro WX 2100', 15.6, '1920x1080', $w11p, 81000, 'B'],
            ['dell', 'workstations', 'Dell Precision 5520', 'Intel Xeon E3-1505M v6', 16, 'SSD', 512, 'NVIDIA Quadro M1200', 15.6, '3840x2160', $w11p, 105000, 'B'],
            ['dell', 'workstations', 'Dell Precision 7520', 'Intel Xeon E3-1535M v6', 32, 'SSD', 1024, 'NVIDIA Quadro P3000', 17.3, '1920x1200', $w11p, 155000, 'A'],

            // ---------- HP business (EliteBook / ProBook) ----------
            ['hp', 'business-laptops', 'HP EliteBook 820 G4', 'Intel Core i5-7200U', 8, 'SSD', 256, 'Intel HD 620', 12.5, '1920x1080', $w10p, 31000, 'B'],
            ['hp', 'business-laptops', 'HP EliteBook 840 G4', 'Intel Core i5-7300U', 8, 'SSD', 256, 'Intel HD 620', 14.0, '1920x1080', $w10p, 33000, 'B'],
            ['hp', 'business-laptops', 'HP EliteBook 840 G5', 'Intel Core i5-8250U', 8, 'SSD', 256, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 39500, 'B'],
            ['hp', 'business-laptops', 'HP EliteBook 840 G7', 'Intel Core i5-10310U', 16, 'SSD', 256, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 52000, 'A'],
            ['hp', 'business-laptops', 'HP EliteBook 840 G8', 'Intel Core i7-1165G7', 16, 'SSD', 512, 'Intel Iris Xe', 14.0, '1920x1080', $w11p, 71000, 'A'],
            ['hp', 'business-laptops', 'HP EliteBook 846 G9', 'Intel Core i5-1235U', 16, 'SSD', 512, 'Intel Iris Xe', 14.0, '1920x1080', $w11p, 79500, 'A'],
            ['hp', 'business-laptops', 'HP EliteBook 1040 G3', 'Intel Core i7-6600U', 8, 'SSD', 256, 'Intel HD 520', 14.0, '1920x1080', $w10p, 33500, 'C'],
            ['hp', 'business-laptops', 'HP ProBook 440 G5', 'Intel Core i5-8250U', 8, 'SSD', 256, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 34000, 'B'],
            ['hp', 'business-laptops', 'HP ProBook 450 G7', 'Intel Core i5-10210U', 8, 'SSD', 512, 'Intel UHD 620', 15.6, '1920x1080', $w11p, 39500, 'B'],
            ['hp', 'business-laptops', 'HP ProBook 450 G8', 'Intel Core i5-1135G7', 16, 'SSD', 512, 'Intel Iris Xe', 15.6, '1920x1080', $w11p, 50500, 'A'],

            // ---------- HP consumer / premium ----------
            ['hp', 'ultrabooks', 'HP Envy x360 13', 'AMD Ryzen 5 4500U', 8, 'SSD', 256, 'AMD Radeon Vega 6', 13.3, '1920x1080', $w11h, 46500, 'B'],
            ['hp', 'ultrabooks', 'HP Envy 13 ba0xxx', 'Intel Core i7-10510U', 8, 'SSD', 512, 'Intel UHD 620', 13.3, '1920x1080', $w11h, 52000, 'A'],
            ['hp', 'premium-laptops', 'HP Spectre x360 13-aw0xxx', 'Intel Core i7-1065G7', 16, 'SSD', 512, 'Intel Iris Plus', 13.3, '1920x1080', $w11p, 96000, 'A'],
            ['hp', 'premium-laptops', 'HP Spectre x360 14-ea0xxx', 'Intel Core i7-1165G7', 16, 'SSD', 1024, 'Intel Iris Xe', 13.5, '3000x2000', $w11p, 128000, 'A'],
            ['hp', 'performance-laptops', 'HP Pavilion 14 ce3xxx', 'Intel Core i7-1065G7', 16, 'SSD', 512, 'NVIDIA MX250', 14.0, '1920x1080', $w11h, 54500, 'B'],
            ['hp', 'performance-laptops', 'HP Envy 15 ep0xxx', 'Intel Core i7-10750H', 16, 'SSD', 512, 'NVIDIA GTX 1660 Ti', 15.6, '3840x2160', $w11h, 118000, 'A'],

            // ---------- HP gaming ----------
            ['hp', 'gaming-laptops', 'HP Pavilion Gaming 15-ec0xxx', 'AMD Ryzen 5 3550H', 8, 'SSD', 256, 'NVIDIA GTX 1650', 15.6, '1920x1080', $w11h, 52000, 'B'],
            ['hp', 'gaming-laptops', 'HP Pavilion Gaming 16-a0xxx', 'Intel Core i5-10300H', 16, 'SSD', 512, 'NVIDIA GTX 1650', 16.1, '1920x1080', $w11h, 66000, 'B'],
            ['hp', 'gaming-laptops', 'HP Omen 15-ek0xxx', 'Intel Core i7-10750H', 16, 'SSD', 512, 'NVIDIA RTX 2060', 15.6, '1920x1080', $w11h, 115000, 'A'],
            ['hp', 'gaming-laptops', 'HP Omen 17-cb0xxx', 'Intel Core i7-10750H', 32, 'SSD', 1024, 'NVIDIA RTX 2070 Super', 17.3, '1920x1080', $w11h, 168000, 'A'],

            // ---------- HP workstations ----------
            ['hp', 'workstations', 'HP ZBook 15 G3', 'Intel Core i7-6820HQ', 16, 'SSD', 512, 'NVIDIA Quadro M1000M', 15.6, '1920x1080', $w10p, 72000, 'C'],
            ['hp', 'workstations', 'HP ZBook 15 G5', 'Intel Core i7-8850H', 16, 'SSD', 512, 'NVIDIA Quadro P1000', 15.6, '1920x1080', $w11p, 112000, 'B'],
            ['hp', 'workstations', 'HP ZBook Studio G5', 'Intel Core i7-8750H', 32, 'SSD', 1024, 'NVIDIA Quadro P1000', 15.6, '3840x2160', $w11p, 145000, 'A'],

            // ---------- Lenovo ThinkPad ----------
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad T470', 'Intel Core i5-6300U', 8, 'SSD', 256, 'Intel HD 520', 14.0, '1920x1080', $w10p, 26500, 'B'],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad T480', 'Intel Core i5-8250U', 8, 'SSD', 256, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 33500, 'B'],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad T490', 'Intel Core i5-8265U', 16, 'SSD', 512, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 47500, 'B'],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad T14 Gen 1', 'Intel Core i5-10210U', 16, 'SSD', 512, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 58500, 'A'],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad T14 Gen 2', 'Intel Core i5-1145G7', 16, 'SSD', 512, 'Intel Iris Xe', 14.0, '1920x1080', $w11p, 69000, 'A'],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad T480s', 'Intel Core i7-8550U', 16, 'SSD', 512, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 46500, 'B'],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad L480', 'Intel Core i5-8250U', 8, 'SSD', 256, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 29500, 'C'],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad L490', 'Intel Core i5-8265U', 8, 'SSD', 256, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 32500, 'B'],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad E480', 'Intel Core i5-8250U', 8, 'SSD', 256, 'AMD Radeon 550', 14.0, '1920x1080', $w11p, 30500, 'B'],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad E490', 'Intel Core i5-8265U', 8, 'SSD', 512, 'AMD Radeon RX 550X', 14.0, '1920x1080', $w11p, 34500, 'B'],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad E14 Gen 2', 'Intel Core i5-1135G7', 8, 'SSD', 512, 'Intel Iris Xe', 14.0, '1920x1080', $w11p, 45500, 'A'],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad X270', 'Intel Core i5-6200U', 8, 'SSD', 256, 'Intel HD 520', 12.5, '1366x768', $w10p, 23500, 'C'],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad X280', 'Intel Core i5-7200U', 8, 'SSD', 256, 'Intel HD 620', 12.5, '1920x1080', $w10p, 28500, 'B'],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad X390', 'Intel Core i5-8265U', 8, 'SSD', 256, 'Intel UHD 620', 13.3, '1920x1080', $w11p, 37500, 'B'],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad X13 Gen 1', 'Intel Core i5-10210U', 16, 'SSD', 512, 'Intel UHD 620', 13.3, '1920x1080', $w11p, 55500, 'A'],
            ['lenovo', 'ultrabooks', 'Lenovo ThinkPad X1 Carbon G5', 'Intel Core i7-7600U', 16, 'SSD', 512, 'Intel HD 620', 14.0, '1920x1080', $w11p, 61500, 'B'],
            ['lenovo', 'ultrabooks', 'Lenovo ThinkPad X1 Carbon G6', 'Intel Core i5-8365U', 16, 'SSD', 512, 'Intel UHD 620', 14.0, '2560x1440', $w11p, 68500, 'A'],
            ['lenovo', 'ultrabooks', 'Lenovo ThinkPad X1 Carbon G8', 'Intel Core i7-1165G7', 16, 'SSD', 512, 'Intel Iris Xe', 14.0, '1920x1200', $w11p, 104500, 'A'],
            ['lenovo', 'ultrabooks', 'Lenovo ThinkPad X1 Yoga G4', 'Intel Core i7-8565U', 16, 'SSD', 512, 'Intel UHD 620', 14.0, '2560x1440', $w11p, 78500, 'A'],

            // ---------- Lenovo consumer / gaming ----------
            ['lenovo', 'student-laptops', 'Lenovo IdeaPad 3 15ITL6', 'Intel Core i3-1115G4', 8, 'SSD', 256, 'Intel UHD 620', 15.6, '1920x1080', $w11h, 30500, 'B'],
            ['lenovo', 'student-laptops', 'Lenovo IdeaPad Slim 3 15ABR05', 'AMD Ryzen 3 3250U', 8, 'SSD', 256, 'AMD Radeon Vega 3', 15.6, '1920x1080', $w11h, 26500, 'B'],
            ['lenovo', 'student-laptops', 'Lenovo IdeaPad Slim 5 14ITL05', 'Intel Core i5-1135G7', 8, 'SSD', 512, 'Intel Iris Xe', 14.0, '1920x1080', $w11h, 43500, 'A'],
            ['lenovo', 'ultrabooks', 'Lenovo Yoga C740-14', 'Intel Core i5-10210U', 8, 'SSD', 256, 'Intel UHD 620', 14.0, '1920x1080', $w11h, 41500, 'B'],
            ['lenovo', 'ultrabooks', 'Lenovo Yoga 9i 14ITL5', 'Intel Core i7-1185G7', 16, 'SSD', 512, 'Intel Iris Xe', 14.0, '3840x2160', $w11h, 102000, 'A'],
            ['lenovo', 'gaming-laptops', 'Lenovo IdeaPad L340-15IRH', 'Intel Core i5-9300H', 8, 'SSD', 512, 'NVIDIA GTX 1650', 15.6, '1920x1080', $w11h, 49500, 'B'],
            ['lenovo', 'gaming-laptops', 'Lenovo Legion 5 15IMH05', 'Intel Core i5-10300H', 16, 'SSD', 512, 'NVIDIA GTX 1650 Ti', 15.6, '1920x1080', $w11h, 68500, 'B'],
            ['lenovo', 'gaming-laptops', 'Lenovo Legion 5 15ACH6', 'AMD Ryzen 7 5800H', 16, 'SSD', 1024, 'NVIDIA RTX 3060', 15.6, '1920x1080', $w11h, 138000, 'A'],
            ['lenovo', 'gaming-laptops', 'Lenovo Legion Y7000P', 'Intel Core i7-9750H', 16, 'SSD', 1024, 'NVIDIA GTX 1660 Ti', 15.6, '1920x1080', $w11h, 98000, 'B'],
            ['lenovo', 'workstations', 'Lenovo ThinkPad P52', 'Intel Core i7-8850H', 16, 'SSD', 512, 'NVIDIA Quadro P1000', 15.6, '1920x1080', $w11p, 108000, 'B'],
            ['lenovo', 'workstations', 'Lenovo ThinkPad P53', 'Intel Core i7-9850H', 32, 'SSD', 1024, 'NVIDIA Quadro T1000', 15.6, '1920x1080', $w11p, 158000, 'A'],

            // ---------- Apple MacBooks ----------
            ['apple', 'macbooks', 'Apple MacBook Air 2017 A1466', 'Intel Core i5-5250U', 8, 'SSD', 128, 'Intel HD 6000', 13.3, '1440x900', $macos, 34500, 'B'],
            ['apple', 'macbooks', 'Apple MacBook Air 2019 A1932', 'Intel Core i5-8210Y', 8, 'SSD', 128, 'Intel UHD 617', 13.3, '2560x1600', $macos, 49500, 'B'],
            ['apple', 'macbooks', 'Apple MacBook Air Retina 2020 A2179', 'Intel Core i5-1030NG7', 8, 'SSD', 256, 'Intel Iris Plus', 13.3, '2560x1600', $macos, 63500, 'A'],
            ['apple', 'macbooks', 'Apple MacBook Air M1 2020 A2337', 'Apple M1 (8-core)', 8, 'SSD', 256, 'Apple 7-core GPU', 13.3, '2560x1600', $macos, 86500, 'A'],
            ['apple', 'macbooks', 'Apple MacBook Air M1 2020 A2337 16GB', 'Apple M1 (8-core)', 16, 'SSD', 512, 'Apple 8-core GPU', 13.3, '2560x1600', $macos, 112000, 'A'],
            ['apple', 'macbooks', 'Apple MacBook Air M2 2022 A2681', 'Apple M2 (8-core)', 8, 'SSD', 256, 'Apple 10-core GPU', 13.6, '2560x1664', $macos, 125000, 'A'],
            ['apple', 'macbooks', 'Apple MacBook Pro 13 2017 A1708', 'Intel Core i5-7360U', 8, 'SSD', 256, 'Intel Iris Plus 640', 13.3, '2560x1600', $macos, 52500, 'B'],
            ['apple', 'macbooks', 'Apple MacBook Pro 13 2019 A2159', 'Intel Core i5-8257U', 8, 'SSD', 256, 'Intel Iris Plus 645', 13.3, '2560x1600', $macos, 64500, 'B'],
            ['apple', 'macbooks', 'Apple MacBook Pro 13 M1 2020 A2338', 'Apple M1 (8-core)', 8, 'SSD', 256, 'Apple 8-core GPU', 13.3, '2560x1600', $macos, 97500, 'A'],
            ['apple', 'macbooks', 'Apple MacBook Pro 13 M2 2022 A2338', 'Apple M2 (8-core)', 8, 'SSD', 512, 'Apple 10-core GPU', 13.3, '2560x1600', $macos, 135000, 'A'],
            ['apple', 'macbooks', 'Apple MacBook Pro 15 2017 A1706', 'Intel Core i7-7660U', 16, 'SSD', 512, 'Intel Iris Plus 650', 15.4, '2880x1800', $macos, 84500, 'B'],
            ['apple', 'macbooks', 'Apple MacBook Pro 15 2019 A1990', 'Intel Core i7-9750H', 16, 'SSD', 512, 'AMD Radeon Pro 555X', 15.4, '2880x1800', $macos, 118000, 'B'],
            ['apple', 'macbooks', 'Apple MacBook Pro 16 2019 A2141', 'Intel Core i7-9750H', 16, 'SSD', 1024, 'AMD Radeon Pro 5300M', 16.0, '3072x1920', $macos, 165000, 'A'],
            ['apple', 'premium-laptops', 'Apple MacBook Pro 14 M1 Pro 2021 A2442', 'Apple M1 Pro (10-core)', 16, 'SSD', 1024, 'Apple 16-core GPU', 14.2, '3024x1964', $macos, 205000, 'A'],

            // ---------- ASUS ----------
            ['asus', 'student-laptops', 'Asus VivoBook 14 X412DA', 'AMD Ryzen 3 3200U', 4, 'SSD', 256, 'AMD Radeon Vega 3', 14.0, '1920x1080', $w11h, 25500, 'C'],
            ['asus', 'student-laptops', 'Asus VivoBook 15 X512JA', 'Intel Core i3-1005G1', 8, 'SSD', 256, 'Intel UHD 620', 15.6, '1920x1080', $w11h, 29500, 'B'],
            ['asus', 'student-laptops', 'Asus VivoBook 15 X1502ZA', 'Intel Core i3-1215U', 8, 'SSD', 512, 'Intel UHD 620', 15.6, '1920x1080', $w11h, 38500, 'A'],
            ['asus', 'business-laptops', 'Asus ExpertBook B9450FA', 'Intel Core i5-1035G7', 16, 'SSD', 512, 'Intel Iris Plus', 14.0, '1920x1080', $w11p, 79500, 'A'],
            ['asus', 'business-laptops', 'Asus ExpertBook P5440FA', 'Intel Core i5-8265U', 8, 'SSD', 256, 'Intel UHD 620', 14.0, '1920x1080', $w11p, 42500, 'B'],
            ['asus', 'ultrabooks', 'Asus ZenBook 14 UX433FA', 'Intel Core i5-8265U', 8, 'SSD', 512, 'Intel UHD 620', 14.0, '1920x1080', $w11h, 49500, 'B'],
            ['asus', 'ultrabooks', 'Asus ZenBook 14 UX425EA', 'Intel Core i5-1135G7', 8, 'SSD', 512, 'Intel Iris Xe', 14.0, '1920x1080', $w11h, 62500, 'A'],
            ['asus', 'ultrabooks', 'Asus ZenBook 13 UX325EA', 'Intel Core i7-1165G7', 16, 'SSD', 512, 'Intel Iris Xe', 13.3, '1920x1080', $w11h, 74500, 'A'],
            ['asus', 'premium-laptops', 'Asus ZenBook Duo 14 UX482', 'Intel Core i7-1165G7', 16, 'SSD', 1024, 'NVIDIA MX450', 14.0, '1920x1080', $w11h, 132000, 'A'],
            ['asus', 'gaming-laptops', 'Asus TUF Gaming F15 FX506LH', 'Intel Core i5-10300H', 8, 'SSD', 512, 'NVIDIA GTX 1650', 15.6, '1920x1080', $w11h, 63500, 'B'],
            ['asus', 'gaming-laptops', 'Asus TUF Gaming A15 FA506IV', 'AMD Ryzen 7 4800H', 16, 'SSD', 512, 'NVIDIA RTX 2060', 15.6, '1920x1080', $w11h, 98000, 'B'],
            ['asus', 'gaming-laptops', 'Asus TUF Gaming F17 FX706LH', 'Intel Core i5-11400H', 16, 'SSD', 512, 'NVIDIA RTX 3050', 17.3, '1920x1080', $w11h, 109000, 'A'],
            ['asus', 'gaming-laptops', 'Asus ROG Strix G15 G512', 'AMD Ryzen 7 4800H', 16, 'SSD', 1024, 'NVIDIA RTX 3060', 15.6, '1920x1080', $w11h, 142000, 'A'],
            ['asus', 'gaming-laptops', 'Asus ROG Zephyrus G14 GA401', 'AMD Ryzen 9 4900HS', 16, 'SSD', 1024, 'NVIDIA RTX 2060', 14.0, '1920x1080', $w11h, 158000, 'A'],
            ['asus', 'performance-laptops', 'Asus VivoBook Pro 15 N6506', 'Intel Core i9-11900H', 16, 'SSD', 1024, 'NVIDIA RTX 3050 Ti', 15.6, '1920x1080', $w11h, 165000, 'A'],

            // ---------- Acer ----------
            ['acer', 'student-laptops', 'Acer Aspire 3 A315-23', 'AMD Ryzen 3 3250U', 4, 'SSD', 256, 'AMD Radeon Vega 3', 15.6, '1920x1080', $w11h, 22500, 'C'],
            ['acer', 'student-laptops', 'Acer Aspire 5 A514-54', 'Intel Core i3-1005G1', 8, 'SSD', 256, 'Intel UHD 620', 14.0, '1920x1080', $w11h, 28500, 'B'],
            ['acer', 'student-laptops', 'Acer Aspire 5 A515-56', 'Intel Core i5-1135G7', 8, 'SSD', 512, 'Intel Iris Xe', 15.6, '1920x1080', $w11h, 41500, 'B'],
            ['acer', 'student-laptops', 'Acer Aspire 7 A715-75G', 'Intel Core i5-10300H', 8, 'SSD', 512, 'NVIDIA GTX 1650', 15.6, '1920x1080', $w11h, 54500, 'B'],
            ['acer', 'ultrabooks', 'Acer Swift 3 SF314-42', 'AMD Ryzen 5 4500U', 8, 'SSD', 512, 'AMD Radeon Vega 6', 14.0, '1920x1080', $w11h, 45500, 'B'],
            ['acer', 'ultrabooks', 'Acer Swift 5 SF514-55T', 'Intel Core i7-1165G7', 16, 'SSD', 512, 'Intel Iris Xe', 14.0, '1920x1080', $w11h, 84500, 'A'],
            ['acer', 'gaming-laptops', 'Acer Nitro 5 AN515-55', 'Intel Core i5-10300H', 8, 'SSD', 512, 'NVIDIA GTX 1650', 15.6, '1920x1080', $w11h, 58500, 'B'],
            ['acer', 'gaming-laptops', 'Acer Nitro 5 AN515-58', 'Intel Core i5-12450H', 16, 'SSD', 1024, 'NVIDIA RTX 3050', 15.6, '1920x1080', $w11h, 118000, 'A'],
            ['acer', 'gaming-laptops', 'Acer Predator Helios 300 PH315-53', 'Intel Core i7-10750H', 16, 'SSD', 1024, 'NVIDIA RTX 2060', 15.6, '1920x1080', $w11h, 135000, 'A'],
            ['acer', 'gaming-laptops', 'Acer Predator Triton 300 PT315-52', 'Intel Core i7-10750H', 16, 'SSD', 1024, 'NVIDIA RTX 3060', 15.6, '1920x1080', $w11h, 172000, 'A'],

            // ---------- Microsoft Surface ----------
            ['microsoft', 'premium-laptops', 'Microsoft Surface Laptop 3 13.5', 'Intel Core i5-1035G7', 8, 'SSD', 256, 'Intel Iris Plus', 13.5, '2256x1504', $w11h, 69500, 'B'],
            ['microsoft', 'premium-laptops', 'Microsoft Surface Laptop 4 13.5', 'Intel Core i5-1145G7', 16, 'SSD', 512, 'Intel Iris Xe', 13.5, '2256x1504', $w11h, 96500, 'A'],
            ['microsoft', 'premium-laptops', 'Microsoft Surface Laptop 4 15 AMD', 'AMD Ryzen 7 4980U', 16, 'SSD', 512, 'AMD Radeon RX Vega 8', 15.0, '2496x1664', $w11h, 108500, 'A'],
            ['microsoft', 'premium-laptops', 'Microsoft Surface Book 2 13.5', 'Intel Core i5-8350U', 8, 'SSD', 256, 'NVIDIA GTX 1050', 13.5, '3000x2000', $w11p, 74500, 'B'],
            ['microsoft', 'ultrabooks', 'Microsoft Surface Pro 6', 'Intel Core i5-8250U', 8, 'SSD', 256, 'Intel UHD 620', 12.3, '2736x1824', $w11p, 46500, 'B'],
            ['microsoft', 'ultrabooks', 'Microsoft Surface Pro 7', 'Intel Core i5-1035G4', 8, 'SSD', 256, 'Intel Iris Plus', 12.3, '2736x1824', $w11p, 54500, 'B'],

            // ---------- MSI ----------
            ['msi', 'gaming-laptops', 'MSI Katana GF66 11UC', 'Intel Core i5-11400H', 16, 'SSD', 512, 'NVIDIA RTX 3050', 15.6, '1920x1080', $w11h, 108000, 'A'],
            ['msi', 'gaming-laptops', 'MSI Katana GF63 9SC', 'Intel Core i5-9300H', 8, 'SSD', 512, 'NVIDIA GTX 1650', 15.6, '1920x1080', $w11h, 59500, 'B'],
            ['msi', 'gaming-laptops', 'MSI Modern 14 B10MW', 'Intel Core i5-10210U', 8, 'SSD', 512, 'Intel UHD 620', 14.0, '1920x1080', $w11h, 42500, 'B'],
            ['msi', 'performance-laptops', 'MSI Prestige 14 A10SC', 'Intel Core i7-10510U', 16, 'SSD', 512, 'NVIDIA GTX 1650 Max-Q', 14.0, '3840x2160', $w11h, 99500, 'A'],
            ['msi', 'gaming-laptops', 'MSI GL65 Leopard 9SD', 'Intel Core i7-9750H', 16, 'SSD', 512, 'NVIDIA GTX 1660 Ti', 15.6, '1920x1080', $w11h, 96000, 'B'],

            // ---------- Toshiba / Samsung ----------
            ['toshiba', 'budget-laptops', 'Toshiba Tecra A50-C', 'Intel Core i5-5200U', 4, 'SSD', 256, 'Intel HD 5500', 15.6, '1920x1080', $w10p, 19500, 'C'],
            ['toshiba', 'budget-laptops', 'Toshiba Satellite C55-C', 'Intel Core i3-5005U', 4, 'HDD', 500, 'Intel HD 5500', 15.6, '1366x768', $w10p, 16500, 'C'],
            ['samsung', 'ultrabooks', 'Samsung Notebook 9 NP900X5T', 'Intel Core i7-8550U', 16, 'SSD', 512, 'Intel UHD 620', 15.0, '1920x1080', $w11h, 67500, 'B'],
            ['samsung', 'budget-laptops', 'Samsung Notebook 3 NP350XAA', 'Intel Core i3-7100U', 4, 'HDD+SSD', 1024, 'Intel HD 620', 15.6, '1920x1080', $w10p, 21500, 'C'],

            // ---------- Budget / student classics ----------
            ['dell', 'budget-laptops', 'Dell Inspiron 15 3567', 'Intel Core i3-6006U', 4, 'HDD', 1000, 'Intel HD 520', 15.6, '1920x1080', $w10p, 20500, 'C'],
            ['dell', 'budget-laptops', 'Dell Inspiron 15 3579', 'Intel Core i5-8250U', 8, 'HDD+SSD', 1256, 'NVIDIA MX130', 15.6, '1920x1080', $w11h, 32500, 'B'],
            ['dell', 'budget-laptops', 'Dell Inspiron 14 3467', 'Intel Core i3-7100U', 4, 'HDD', 1000, 'Intel HD 620', 14.0, '1366x768', $w10p, 18500, 'C'],
            ['hp', 'budget-laptops', 'HP Notebook 15-bs5xx', 'Intel Core i3-6006U', 4, 'HDD', 1000, 'Intel HD 520', 15.6, '1920x1080', $w10p, 19500, 'C'],
            ['hp', 'budget-laptops', 'HP 15-da0xxx', 'Intel Core i3-7020U', 8, 'HDD+SSD', 1256, 'Intel HD 620', 15.6, '1920x1080', $w10p, 24500, 'B'],
            ['hp', 'budget-laptops', 'HP 15s-du1xxx', 'Intel Core i3-1005G1', 8, 'SSD', 256, 'Intel UHD 620', 15.6, '1920x1080', $w11h, 30500, 'B'],
            ['lenovo', 'budget-laptops', 'Lenovo IdeaPad 110-15ISK', 'Intel Core i3-6100U', 4, 'HDD', 1000, 'Intel HD 520', 15.6, '1366x768', $w10p, 16500, 'C'],
            ['lenovo', 'budget-laptops', 'Lenovo IdeaPad 320-15IKB', 'Intel Core i5-7200U', 8, 'HDD+SSD', 1256, 'NVIDIA 920MX', 15.6, '1920x1080', $w10p, 27500, 'B'],
            ['lenovo', 'budget-laptops', 'Lenovo V110-15ISK', 'Intel Core i3-6006U', 4, 'HDD', 500, 'Intel HD 520', 15.6, '1366x768', $w10p, 15500, 'C'],
            ['acer', 'budget-laptops', 'Acer Extensa 15 EX215-55', 'Intel Core i5-1235U', 8, 'SSD', 512, 'Intel Iris Xe', 15.6, '1920x1080', $w11h, 39500, 'A'],
            ['asus', 'budget-laptops', 'Asus X515JA-BR664T', 'Intel Core i3-1005G1', 8, 'SSD', 256, 'Intel UHD 620', 15.6, '1920x1080', $w11h, 28500, 'B'],
        ];

        $out = [];
        foreach ($rows as $r) {
            [$brand, $cat, $name, $cpu, $ram, $st, $cap, $gpu, $disp, $res, $os, $price, $grade] = $r;
            $out[] = [
                'brand' => $brand, 'cat' => $cat, 'name' => $name, 'cpu' => $cpu,
                'ram' => (int) $ram, 'st' => $st, 'cap' => (int) $cap, 'gpu' => $gpu,
                'disp' => (float) $disp, 'res' => $res, 'os' => $os,
                'price' => (int) $price, 'grade' => $grade,
            ];
        }
        return $out;
    }
}
