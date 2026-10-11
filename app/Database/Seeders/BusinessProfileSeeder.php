<?php

declare(strict_types=1);

namespace App\Database\Seeders;

use PDO;

/**
 * Seeds the initial business profile, locations and core settings.
 * No user accounts are seeded here — the first administrator is created
 * ONLY through `php bin/create-admin.php` (secure interactive setup).
 */
final class BusinessProfileSeeder
{
    public function run(PDO $pdo): void
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM business_profiles')->fetchColumn();
        if ($count === 0) {
            $pdo->prepare(
                'INSERT INTO business_profiles
                    (name, legal_name, tagline, email, phone, address, city, country,
                     currency, timezone, about_text, default_warranty_days)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                'Rasidul Tech Point',
                'Rasidul Tech Point',
                'Dubai Imported | Authentic Used Laptops | Tested & Quality Checked',
                'info@rasidultechpoint.example',
                '+8801700000000',
                'Example Road 1',
                'Dhaka',
                'Bangladesh',
                'BDT',
                'Asia/Dhaka',
                'We import authentic used laptops from Dubai, test every unit and back it with warranty.',
                30,
            ]);
        }

        $count = (int) $pdo->query('SELECT COUNT(*) FROM locations')->fetchColumn();
        if ($count === 0) {
            $shop = $pdo->prepare('INSERT INTO locations (name, type, address, phone) VALUES (?, ?, ?, ?)');
            $shop->execute(['Main Shop', 'shop', 'Example Road 1, Dhaka', '+8801700000000']);
            $shop->execute(['Central Warehouse', 'warehouse', 'Dubai sourcing warehouse', null]);
        }

        $defaults = [
            ['low_stock_alert_threshold', '3', 'int', 'inventory'],
            ['order_reference_prefix', 'RTP', 'string', 'orders'],
            ['storefront_show_unpublished', '0', 'bool', 'storefront'],
        ];
        $exists = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = ?');
        $insert = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, setting_type, setting_group) VALUES (?, ?, ?, ?)'
        );
        foreach ($defaults as [$key, $value, $type, $group]) {
            $exists->execute([$key]);
            if ((int) $exists->fetchColumn() === 0) {
                $insert->execute([$key, $value, $type, $group]);
            }
        }
    }
}
