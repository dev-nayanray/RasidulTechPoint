<?php

declare(strict_types=1);

namespace App\Database\Seeders;

use PDO;

/**
 * Seeds roles, permissions and the default role→permission matrix
 * from config/permissions.php.
 */
final class RolePermissionSeeder
{
    public function run(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO permissions (name, module, description) VALUES (?, ?, ?)'
        );
        $selectPerm = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $insertRole = $pdo->prepare(
            'INSERT INTO roles (name, slug, description, is_system) VALUES (?, ?, ?, 1)'
        );
        $selectRole = $pdo->prepare('SELECT id FROM roles WHERE slug = ?');
        $link = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)'
        );

        // Permissions (idempotent).
        $permIds = [];
        foreach (RTP_PERMISSIONS as $permission) {
            [$module] = explode('.', $permission, 2);
            $selectPerm->execute([$permission]);
            $id = $selectPerm->fetchColumn();
            if ($id === false) {
                $stmt->execute([$permission, $module, 'Permission: ' . $permission]);
                $id = $pdo->lastInsertId();
            }
            $permIds[$permission] = (int) $id;
        }

        // Roles.
        $roleNames = [
            'owner' => ['Owner / Super Admin', 'Full unrestricted access'],
            'manager' => ['Manager', 'Runs day-to-day operations'],
            'sales_cashier' => ['Sales / Cashier', 'POS and order entry'],
            'inventory_officer' => ['Inventory Officer', 'Stock receipt and catalog upkeep'],
            'technician' => ['Technician', 'Inspections and repairs'],
            'accountant' => ['Accountant', 'Payments, finance and reports'],
            'content_manager' => ['Content Manager', 'Catalog content and marketing'],
            'customer' => ['Customer', 'Storefront customer account'],
        ];

        foreach ($roleNames as $slug => [$name, $description]) {
            $selectRole->execute([$slug]);
            $roleId = $selectRole->fetchColumn();
            if ($roleId === false) {
                $insertRole->execute([$name, $slug, $description]);
                $roleId = $pdo->lastInsertId();
            }

            $permissions = RTP_ROLE_PERMISSIONS[$slug] ?? [];
            if (in_array('*', $permissions, true)) {
                $permissions = RTP_PERMISSIONS;
            }
            foreach ($permissions as $permission) {
                $link->execute([(int) $roleId, $permIds[$permission]]);
            }
        }
    }
}
