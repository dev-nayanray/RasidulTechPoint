<?php

declare(strict_types=1);

/**
 * Demo catalog manager (LOCAL DEVELOPMENT ONLY).
 *
 *   php bin/demo-catalog.php            seed demo catalog (same as "seed")
 *   php bin/demo-catalog.php seed       insert 100+ demo laptop models with
 *                                       generated images + demo inventory
 *   php bin/demo-catalog.php media      download REAL brand logos + REAL
 *                                       laptop photos (Wikimedia Commons)
 *                                       and relink demo product images
 *   php bin/demo-catalog.php verify     audit DB rows, uniqueness, image files
 *   php bin/demo-catalog.php purge      remove ONLY is_demo=1 records
 *                                       (real products/units are untouched)
 *
 * The seeder is idempotent: re-running inserts nothing new.
 * Refuses to run outside APP_ENV=local so demo data can never reach
 * production. Also runs as part of `php bin/seed.php --demo`.
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Database\DemoMediaImporter;
use App\Database\Seeders\DemoCatalogSeeder;
use App\Database\Seeders\DemoContentSeeder;
use App\Support\Database\Connection;
use App\Support\Logger;

$action = $argv[1] ?? 'seed';

$fail = static function (string $m): void {
    fwrite(STDERR, $m . PHP_EOL);
    exit(1);
};

if (!in_array($action, ['seed', 'media', 'content', 'verify', 'purge'], true)) {
    $fail("Unknown action '{$action}'. Use: seed | media | content | verify | purge");
}
if ((string) \App\Support\Config::get('app.env') !== 'local') {
    $fail('Refusing to touch demo catalog outside APP_ENV=local (current: ' . (string) \App\Support\Config::get('app.env') . ').');
}

$pdo = Connection::pdo();

try {
    switch ($action) {
        case 'seed':
            $stats = (new DemoCatalogSeeder())->run($pdo, static function (string $line): void {
                print($line . PHP_EOL);
            });

            $total = $stats['models_inserted'] + $stats['models_skipped'];
            print(PHP_EOL . '---- Demo catalog seed summary ----' . PHP_EOL);
            print('Models inserted:        ' . $stats['models_inserted'] . PHP_EOL);
            print('Models skipped (dup):   ' . $stats['models_skipped'] . PHP_EOL);
            print('Slug conflicts (real):  ' . $stats['models_conflict'] . PHP_EOL);
            print('Model failures:         ' . $stats['models_failed'] . PHP_EOL);
            print('Demo units inserted:    ' . $stats['units_inserted'] . PHP_EOL);
            print('Demo units skipped:     ' . $stats['units_skipped'] . PHP_EOL);
            print('Images linked:          ' . $stats['images_linked'] . PHP_EOL);
            print('Images regenerated:     ' . $stats['images_regenerated'] . PHP_EOL);
            print('Image failures:         ' . $stats['images_failed'] . PHP_EOL);
            print("Demo models now in DB:  {$total}" . PHP_EOL);

            Logger::info('seed', 'demo-catalog seed', $stats);
            exit($stats['models_failed'] > 0 || $stats['images_failed'] > 0 ? 2 : 0);

        case 'media':
            print('Downloading real brand logos + laptop photos from Wikimedia Commons...' . PHP_EOL);
            $stats = (new DemoMediaImporter())->run($pdo, static function (string $line): void {
                print($line . PHP_EOL);
            });

            print(PHP_EOL . '---- Demo media import summary ----' . PHP_EOL);
            print('Brand logos downloaded:    ' . $stats['logos_ok'] . PHP_EOL);
            print('Logo failures:             ' . (count($stats['logos_failed']) ? implode(', ', $stats['logos_failed']) : 'none') . PHP_EOL);
            print('Laptop photos downloaded:  ' . $stats['photos_ok'] . PHP_EOL);
            print('Photo-less brands:         ' . (count($stats['photos_failed']) ? implode(', ', $stats['photos_failed']) : 'none') . PHP_EOL);
            print('Product images relinked:   ' . $stats['products_relinked'] . PHP_EOL);
            print('Old placeholders deleted:  ' . $stats['old_files_deleted'] . PHP_EOL);
            print('Attribution manifest:      storage/demo-media-manifest.json' . PHP_EOL);

            Logger::info('seed', 'demo-catalog media', $stats);
            exit($stats['logos_failed'] === [] && $stats['photos_failed'] === [] ? 0 : 2);

        case 'content':
            $stats = (new DemoContentSeeder())->run($pdo, static function (string $line): void {
                print($line . PHP_EOL);
            });
            print(PHP_EOL . '---- Demo content seed summary ----' . PHP_EOL);
            print('FAQs inserted:        ' . $stats['faqs_inserted'] . PHP_EOL);
            print('FAQs skipped (dup):   ' . $stats['faqs_skipped'] . PHP_EOL);
            print('Demo reviews inserted: ' . $stats['reviews_inserted'] . PHP_EOL);
            print('Demo reviews skipped:  ' . $stats['reviews_skipped'] . PHP_EOL);
            Logger::info('seed', 'demo-catalog content', $stats);
            exit(0);

        case 'verify':
            $counts = $pdo->query(
                "SELECT
                    (SELECT COUNT(*) FROM product_models WHERE is_demo = 1 AND deleted_at IS NULL) AS demo_models,
                    (SELECT COUNT(*) FROM product_models WHERE deleted_at IS NULL) AS all_models,
                    (SELECT COUNT(*) FROM inventory_units WHERE is_demo = 1) AS demo_units,
                    (SELECT COUNT(*) FROM inventory_units WHERE is_demo = 1 AND status = 'available') AS demo_units_available,
                    (SELECT COUNT(*) FROM product_models WHERE deleted_at IS NULL AND is_demo = 1
                        AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_model_id = product_models.id)) AS demo_models_without_image,
                    (SELECT COUNT(*) FROM product_models WHERE deleted_at IS NULL AND is_demo = 0) AS real_models,
                    (SELECT COUNT(*) FROM inventory_units WHERE is_demo = 0) AS real_units"
            )->fetch(PDO::FETCH_ASSOC);

            // Uniqueness
            $dupSlugs = (int) $pdo->query(
                'SELECT COUNT(*) FROM (SELECT slug FROM product_models GROUP BY slug HAVING COUNT(*) > 1) d'
            )->fetchColumn();
            $dupSkus = (int) $pdo->query(
                'SELECT COUNT(*) FROM (SELECT sku FROM inventory_units GROUP BY sku HAVING COUNT(*) > 1) d'
            )->fetchColumn();

            // Every demo image row resolves to a real PNG file of the right type
            $imgOk = 0;
            $imgBroken = [];
            $rows = $pdo->query(
                "SELECT pi.id, pi.file_path, pm.name
                 FROM product_images pi
                 JOIN product_models pm ON pm.id = pi.product_model_id
                 WHERE pm.is_demo = 1 AND pm.deleted_at IS NULL"
            )->fetchAll(PDO::FETCH_ASSOC);
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            foreach ($rows as $r) {
                $abs = BASE_PATH . '/public/' . $r['file_path'];
                if (is_file($abs) && str_starts_with((string) $finfo->file($abs), 'image/')) {
                    $imgOk++;
                } else {
                    $imgBroken[] = $r['name'] . ' → ' . $r['file_path'];
                }
            }

            print(PHP_EOL . '---- Demo catalog verification ----' . PHP_EOL);
            foreach ([
                'Demo product models' => $counts['demo_models'],
                'Demo models missing an image row' => $counts['demo_models_without_image'],
                'Demo inventory units' => $counts['demo_units'],
                'Demo units available' => $counts['demo_units_available'],
                'Demo image rows total' => count($rows),
                'Demo images resolving to valid files' => $imgOk,
                'Broken/missing demo images' => count($imgBroken),
                'Duplicate slugs (whole table)' => $dupSlugs,
                'Duplicate SKUs (whole table)' => $dupSkus,
                'Real models still present (is_demo=0)' => $counts['real_models'],
                'Real units still present (is_demo=0)' => $counts['real_units'],
            ] as $label => $value) {
                printf('%-40s %s%s', $label, $value, PHP_EOL);
            }
            foreach ($imgBroken as $b) {
                print '  BROKEN: ' . $b . PHP_EOL;
            }

            $healthy = $counts['demo_models'] >= 100
                && (int) $counts['demo_models_without_image'] === 0
                && $imgBroken === []
                && $dupSlugs === 0 && $dupSkus === 0;
            exit($healthy ? 0 : 2);

        case 'purge':
            // ONLY is_demo=1 rows are ever touched. Demo units that ended up
            // in orders/returns/service jobs are kept (and reported) so
            // business history stays consistent.
            $keptUnits = $pdo->query(
                "SELECT iu.sku FROM inventory_units iu
                 WHERE iu.is_demo = 1
                   AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.inventory_unit_id = iu.id)"
            )->fetchAll(PDO::FETCH_COLUMN);
            $keptModels = $pdo->query(
                "SELECT pm.name FROM product_models pm
                 WHERE pm.is_demo = 1
                   AND (EXISTS (SELECT 1 FROM order_items oi JOIN inventory_units iu ON iu.id = oi.inventory_unit_id WHERE iu.product_model_id = pm.id)
                     OR EXISTS (SELECT 1 FROM coupon_target_products c WHERE c.product_model_id = pm.id)
                     OR EXISTS (SELECT 1 FROM crm_inquiries c WHERE c.product_model_id = pm.id)
                     OR EXISTS (SELECT 1 FROM crm_quotations c WHERE c.product_model_id = pm.id)
                     OR EXISTS (SELECT 1 FROM purchase_order_items p WHERE p.product_model_id = pm.id))"
            )->fetchAll(PDO::FETCH_COLUMN);

            $files = $pdo->query(
                "SELECT pi.file_path FROM product_images pi
                 JOIN product_models pm ON pm.id = pi.product_model_id
                 WHERE pm.is_demo = 1"
            )->fetchAll(PDO::FETCH_COLUMN);

            $affected = Connection::transaction(function (PDO $pdo) use ($keptUnits): array {
                $del = static function (PDO $pdo, string $sql, array $params = []): int {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    return $stmt->rowCount();
                };
                $n = [];
                // Wishlist rows pointing at demo models.
                $n['wishlist_items'] = $del($pdo,
                    'DELETE wi FROM wishlist_items wi JOIN product_models pm ON pm.id = wi.product_model_id WHERE pm.is_demo = 1');
                // Demo unit photos / inspection records / movements.
                $n['unit_photos'] = $del($pdo,
                    'DELETE up FROM unit_photos up JOIN inventory_units iu ON iu.id = up.inventory_unit_id WHERE iu.is_demo = 1');
                $n['inspection_records'] = $del($pdo,
                    'DELETE ir FROM inspection_records ir JOIN inventory_units iu ON iu.id = ir.inventory_unit_id WHERE iu.is_demo = 1');
                $n['stock_movements'] = $del($pdo,
                    'DELETE sm FROM stock_movements sm JOIN inventory_units iu ON iu.id = sm.inventory_unit_id WHERE iu.is_demo = 1');
                // Demo units (skip any referenced by orders — kept above).
                if ($keptUnits === []) {
                    $n['inventory_units'] = $del($pdo, 'DELETE FROM inventory_units WHERE is_demo = 1');
                } else {
                    $ph = implode(',', array_fill(0, count($keptUnits), '?'));
                    $n['inventory_units'] = $del($pdo,
                        "DELETE FROM inventory_units WHERE is_demo = 1 AND sku NOT IN ({$ph})", $keptUnits);
                }
                // Images rows cascade on model delete, but delete explicitly for clarity.
                $n['product_images'] = $del($pdo,
                    'DELETE pi FROM product_images pi JOIN product_models pm ON pm.id = pi.product_model_id WHERE pm.is_demo = 1');
                // Demo models with zero remaining references of any kind.
                $n['product_models'] = $del($pdo,
                    'DELETE FROM product_models WHERE is_demo = 1
                     AND NOT EXISTS (SELECT 1 FROM inventory_units iu WHERE iu.product_model_id = product_models.id)
                     AND NOT EXISTS (SELECT 1 FROM coupon_target_products c WHERE c.product_model_id = product_models.id)
                     AND NOT EXISTS (SELECT 1 FROM crm_inquiries c WHERE c.product_model_id = product_models.id)
                     AND NOT EXISTS (SELECT 1 FROM crm_quotations c WHERE c.product_model_id = product_models.id)
                     AND NOT EXISTS (SELECT 1 FROM purchase_order_items p WHERE p.product_model_id = product_models.id)');
                return $n;
            });

            $removedFiles = 0;
            foreach ($files as $rel) {
                $abs = BASE_PATH . '/public/' . $rel;
                if (is_file($abs) && str_starts_with((string) basename((string) $rel), 'demo-')) {
                    @unlink($abs);
                    $removedFiles++;
                }
            }

            print(PHP_EOL . '---- Demo catalog purge ----' . PHP_EOL);
            foreach ($affected as $table => $n) {
                printf('%-22s %d deleted%s', $table, $n, PHP_EOL);
            }
            print('Image files removed:   ' . $removedFiles . PHP_EOL);
            if ($keptUnits !== []) {
                print 'KEPT (referenced by orders): units ' . implode(', ', $keptUnits) . PHP_EOL;
            }
            if ($keptModels !== []) {
                print 'KEPT (business references): models ' . implode(', ', $keptModels) . PHP_EOL;
            }
            Logger::info('seed', 'demo-catalog purge', ['tables' => $affected, 'files' => $removedFiles]);
            exit(0);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'DEMO CATALOG ' . strtoupper($action) . ' FAILED: ' . $e->getMessage() . PHP_EOL);
    Logger::error('seed', 'demo-catalog ' . $action . ' failed: ' . $e->getMessage());
    exit(1);
}
