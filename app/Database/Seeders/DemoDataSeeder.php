<?php

declare(strict_types=1);

namespace App\Database\Seeders;

use App\Services\InventoryService;
use App\Services\OrderService;
use PDO;

/**
 * DEVELOPMENT-ONLY demo data: brands, categories, laptop models,
 * inventory units with movements, demo staff and a demo customer order.
 *
 * Guarded: refuses to run unless APP_ENV is local AND --demo is passed.
 * Never seeds administrator credentials.
 */
final class DemoDataSeeder
{
    public function run(PDO $pdo): void
    {
        if ((string) \App\Support\Config::get('app.env') !== 'local') {
            throw new \RuntimeException('DemoDataSeeder may only run in the local environment.');
        }

        // ---- Brands & categories -------------------------------------------------
        $brands = ['Dell' => 'dell', 'HP' => 'hp', 'Lenovo' => 'lenovo', 'Asus' => 'asus', 'Acer' => 'acer', 'Apple' => 'apple'];
        $brandIds = [];
        $findBrand = $pdo->prepare('SELECT id FROM brands WHERE slug = ?');
        $insBrand = $pdo->prepare('INSERT INTO brands (name, slug, description) VALUES (?, ?, ?)');
        foreach ($brands as $name => $slug) {
            $findBrand->execute([$slug]);
            $id = $findBrand->fetchColumn();
            if ($id === false) {
                $insBrand->execute([$name, $slug, "{$name} laptops"]);
                $id = $pdo->lastInsertId();
            }
            $brandIds[$slug] = (int) $id;
        }

        $cats = [
            'Business Laptops' => 'business-laptops',
            'Gaming Laptops' => 'gaming-laptops',
            'Ultrabooks' => 'ultrabooks',
            'Workstations' => 'workstations',
        ];
        $catIds = [];
        $findCat = $pdo->prepare('SELECT id FROM categories WHERE slug = ?');
        $insCat = $pdo->prepare('INSERT INTO categories (name, slug, description) VALUES (?, ?, ?)');
        foreach ($cats as $name => $slug) {
            $findCat->execute([$slug]);
            $id = $findCat->fetchColumn();
            if ($id === false) {
                $insCat->execute([$name, $slug, "{$name} category"]);
                $id = $pdo->lastInsertId();
            }
            $catIds[$slug] = (int) $id;
        }

        // ---- Laptop models -------------------------------------------------------
        $models = [
            ['dell', 'business-laptops', 'Dell Latitude 7490', 'Intel Core i7-8650U', 16, 'SSD', 512,
             'Intel UHD 620', 14.0, '1920x1080', 'Windows 11 Pro', 1],
            ['dell', 'business-laptops', 'Dell Latitude 5420', 'Intel Core i5-1145G7', 16, 'SSD', 256,
             'Intel Iris Xe', 14.0, '1920x1080', 'Windows 11 Pro', 1],
            ['hp', 'business-laptops', 'HP EliteBook 840 G6', 'Intel Core i7-8565U', 16, 'SSD', 512,
             'Intel UHD 620', 14.0, '1920x1080', 'Windows 11 Pro', 1],
            ['lenovo', 'business-laptops', 'Lenovo ThinkPad T480', 'Intel Core i5-8350U', 16, 'SSD', 256,
             'Intel UHD 620', 14.0, '1920x1080', 'Windows 11 Pro', 1],
            ['lenovo', 'ultrabooks', 'Lenovo ThinkPad X1 Carbon G7', 'Intel Core i7-8665U', 16, 'SSD', 512,
             'Intel UHD 620', 14.0, '1920x1080', 'Windows 11 Pro', 1],
            ['asus', 'gaming-laptops', 'Asus TUF Gaming F15', 'Intel Core i5-10300H', 16, 'SSD', 512,
             'NVIDIA GTX 1650', 15.6, '1920x1080', 'Windows 11 Home', 1],
            ['hp', 'gaming-laptops', 'HP Pavilion Gaming 15', 'AMD Ryzen 5 5600H', 16, 'SSD', 512,
             'NVIDIA GTX 1650', 15.6, '1920x1080', 'Windows 11 Home', 1],
            ['dell', 'workstations', 'Dell Precision 7530', 'Intel Xeon E-2176M', 32, 'SSD', 1024,
             'NVIDIA Quadro P2000', 15.6, '1920x1080', 'Windows 11 Pro', 0],
        ];
        $modelIds = [];
        $findModel = $pdo->prepare('SELECT id FROM product_models WHERE slug = ?');
        $insModel = $pdo->prepare(
            'INSERT INTO product_models
                (brand_id, category_id, name, slug, processor, ram_gb, storage_type, storage_capacity_gb,
                 graphics, display_size_inches, resolution, operating_system, description,
                 seo_title, seo_description, is_published)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($models as [$brandSlug, $catSlug, $name, $cpu, $ram, $st, $cap, $gpu, $disp, $res, $os, $published]) {
            $slug = \str_slug($name);
            $findModel->execute([$slug]);
            $id = $findModel->fetchColumn();
            if ($id === false) {
                $insModel->execute([
                    $brandIds[$brandSlug], $catIds[$catSlug], $name, $slug, $cpu, $ram, $st, $cap,
                    $gpu, $disp, $res, $os,
                    "Dubai imported {$name}. Tested, quality checked and ready with warranty.",
                    "{$name} in Bangladesh | Rasidul Tech Point",
                    "Buy authentic used {$name} imported from Dubai. Tested and quality checked.",
                    $published,
                ]);
                $id = $pdo->lastInsertId();
            }
            $modelIds[$slug] = (int) $id;
        }

        // ---- Demo staff (non-admin) ----------------------------------------------
        $demoUsers = [
            ['Karim Manager', 'manager@rtp.local', 'manager'],
            ['Rahim Cashier', 'cashier@rtp.local', 'sales_cashier'],
            ['Nayeem Inventory', 'inventory@rtp.local', 'inventory_officer'],
            ['Jahid Technician', 'technician@rtp.local', 'technician'],
        ];
        $roleIds = [];
        foreach ($pdo->query('SELECT id, slug FROM roles')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $roleIds[$row['slug']] = (int) $row['id'];
        }
        $findUser = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $insUser = $pdo->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)');
        $linkRole = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $staffIds = [];
        foreach ($demoUsers as [$name, $email, $roleSlug]) {
            $findUser->execute([$email]);
            $id = $findUser->fetchColumn();
            if ($id === false) {
                // Fixed demo password; DEV ONLY accounts, never admin.
                $insUser->execute([$name, $email, password_hash('demo-password-123', PASSWORD_DEFAULT)]);
                $id = $pdo->lastInsertId();
            }
            $linkRole->execute([(int) $id, $roleIds[$roleSlug]]);
            $staffIds[$roleSlug] = (int) $id;
        }

        // ---- Inventory units through the real service (movements included) -------
        $inventory = InventoryService::forSeeding();
        $unitSpecs = [
            ['dell-latitude-7490', 'A', 42000, 3500, 58500, 'DXB-LOT-2026-08', 'available'],
            ['dell-latitude-7490', 'B', 38500, 3500, 52500, 'DXB-LOT-2026-08', 'available'],
            ['dell-latitude-5420', 'A', 46000, 3500, 64000, 'DXB-LOT-2026-09', 'available'],
            ['hp-elitebook-840-g6', 'B', 36500, 3000, 49500, 'DXB-LOT-2026-08', 'available'],
            ['lenovo-thinkpad-t480', 'B', 33000, 3000, 45000, 'DXB-LOT-2026-07', 'available'],
            ['lenovo-thinkpad-x1-carbon-g7', 'A', 62000, 4000, 82000, 'DXB-LOT-2026-09', 'available'],
            ['asus-tuf-gaming-f15', 'B', 68000, 4500, 89000, 'DXB-LOT-2026-09', 'available'],
            ['hp-pavilion-gaming-15', 'C', 52000, 4500, 66000, 'DXB-LOT-2026-08', 'damaged'],
            ['dell-precision-7530', 'A', 95000, 6000, 125000, 'DXB-LOT-2026-09', 'awaiting_inspection'],
            ['dell-latitude-7490', 'C', 35000, 3500, 47000, 'DXB-LOT-2026-07', 'available'],
        ];
        $unitIds = [];
        $seq = 1;
        $unitExists = $pdo->prepare('SELECT id FROM inventory_units WHERE sku = ?');
        foreach ($unitSpecs as [$modelSlug, $grade, $cost, $landed, $price, $lot, $finalStatus]) {
            $sku = sprintf('RTP-%s-%04d', strtoupper(substr($modelSlug, 0, 3)), $seq);
            $unitExists->execute([$sku]);
            $existing = $unitExists->fetchColumn();
            if ($existing !== false) {
                $unitIds[] = (int) $existing;
                $seq++;
                continue;
            }
            $unitId = $inventory->receiveUnitForSeeding([
                'sku' => $sku,
                'serial_number' => sprintf('SN-DEMO-%06d', $seq + 1),
                'product_model_id' => $modelIds[$modelSlug],
                'condition_grade' => $grade,
                'purchase_cost' => $cost,
                'landed_cost' => $landed,
                'selling_price' => $price,
                'supplier_ref' => 'Dubai Supplier LLC',
                'purchase_ref' => $lot,
            ], $finalStatus, $staffIds['inventory_officer'] ?? null);
            $unitIds[] = $unitId;
            $seq++;
        }

        // ---- Demo customer + one completed order ---------------------------------
        $findCustomer = $pdo->prepare('SELECT id FROM customers WHERE phone = ?');
        $insCustomer = $pdo->prepare('INSERT INTO customers (name, phone, email, address, city) VALUES (?, ?, ?, ?, ?)');
        $findCustomer->execute(['+8801811111111']);
        $customerId = $findCustomer->fetchColumn();
        if ($customerId === false) {
            $insCustomer->execute(['Demo Customer', '+8801811111111', 'demo.customer@example.com', 'Dhanmondi 27', 'Dhaka']);
            $customerId = (int) $pdo->lastInsertId();
        }

        $soldUnit = end($unitIds);
        $orderExists = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE inventory_unit_id = ?');
        $orderExists->execute([$soldUnit]);
        if ((int) $orderExists->fetchColumn() === 0) {
            $order = OrderService::forSeeding()->createOrderWithStatus(
                (int) $customerId,
                [$soldUnit],
                'pos',
                'completed',
                $staffIds['sales_cashier'] ?? null
            );
            OrderService::forSeeding()->recordPaymentForSeededOrder($order['id'], (float) $order['total'], 'cash');
        }
    }
}
