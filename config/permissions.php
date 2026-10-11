<?php

declare(strict_types=1);

/**
 * Central permission catalog and default role → permission matrix.
 *
 * Permissions are module.name. The seeder inserts every permission listed
 * here and attaches the role defaults below. Runtime authorization always
 * checks PERMISSIONS (never role names) via AuthService::can().
 */

const RTP_PERMISSIONS = [
    // Overview
    'dashboard.view',
    // Users, roles, permissions
    'users.view',
    'users.create',
    'users.update',
    'users.deactivate',
    'roles.manage',
    // Catalog
    'products.view',
    'products.create',
    'products.update',
    'products.delete',
    // Inventory
    'inventory.view',
    'inventory.receive',
    'inventory.adjust',
    'inventory.approve',
    // Purchasing / suppliers (foundation: view only)
    'purchases.view',
    'purchases.manage',
    // Inspections
    'inspections.manage',
    // POS
    'pos.access',
    // Orders
    'orders.view',
    'orders.manage',
    // Customers
    'customers.view',
    'customers.create',
    'customers.manage',
    // Payments & finance
    'payments.view',
    'payments.manage',
    'finance.view',
    'expenses.manage',
    // Warranty & service
    'warranty.manage',
    'service.manage',
    // Delivery
    'delivery.manage',
    // CRM
    'crm.view',
    // Reports
    'reports.view',
    'reports.export',
    // Marketing & content
    'marketing.manage',
    'content.manage',
    // Notifications
    'notifications.view',
    // System
    'settings.manage',
    'audit_logs.view',
];

/**
 * role_slug => list of permissions (or ['*'] for everything).
 * 'customer' gets no back-office permissions at all.
 */
const RTP_ROLE_PERMISSIONS = [
    'owner' => ['*'],
    'manager' => [
        'dashboard.view', 'users.view',
        'products.view', 'products.create', 'products.update',
        'inventory.view', 'inventory.receive', 'inventory.adjust', 'inventory.approve',
        'purchases.view', 'purchases.manage', 'inspections.manage', 'pos.access',
        'orders.view', 'orders.manage', 'customers.view', 'customers.manage',
        'payments.view', 'payments.manage', 'finance.view', 'expenses.manage',
        'warranty.manage', 'service.manage', 'delivery.manage', 'crm.view',
        'reports.view', 'reports.export', 'notifications.view',
    ],
    'sales_cashier' => [
        'dashboard.view', 'pos.access',
        'products.view', 'inventory.view',
        'orders.view', 'orders.manage',
        'customers.view', 'customers.create',
        'payments.view', 'payments.manage',
        'notifications.view',
    ],
    'inventory_officer' => [
        'dashboard.view',
        'products.view', 'products.create', 'products.update',
        'inventory.view', 'inventory.receive', 'inventory.adjust', 'inventory.approve',
        'purchases.view', 'inspections.manage',
        'notifications.view',
    ],
    'technician' => [
        'dashboard.view',
        'products.view', 'inventory.view',
        'inspections.manage', 'service.manage',
        'notifications.view',
    ],
    'accountant' => [
        'dashboard.view',
        'orders.view', 'payments.view', 'payments.manage',
        'finance.view', 'expenses.manage',
        'customers.view',
        'reports.view', 'reports.export',
        'notifications.view',
    ],
    'content_manager' => [
        'dashboard.view',
        'products.view', 'products.create', 'products.update',
        'marketing.manage', 'content.manage',
        'notifications.view',
    ],
    'customer' => [],
];

/** Sidebar definition: section label => items (permission, label, route, icon, implemented). */
const RTP_ADMIN_NAV = [
    'Overview' => [
        ['dashboard.view', 'Dashboard', '/admin/dashboard', 'chart', true],
    ],
    'Products' => [
        ['products.view', 'Brands', '/admin/brands', 'tag', true],
        ['products.view', 'Categories', '/admin/categories', 'folder', true],
        ['products.view', 'Laptop Models', '/admin/products', 'box', true],
    ],
    'Inventory' => [
        ['inventory.view', 'Inventory Units', '/admin/inventory', 'layers', true],
    ],
    'Purchasing' => [
        ['purchases.view', 'Suppliers & Purchases', '/admin/purchasing', 'truck', false],
    ],
    'Inspections' => [
        ['inspections.manage', 'QA Inspections', '/admin/inspections', 'check-circle', false],
    ],
    'POS' => [
        ['pos.access', 'Point of Sale', '/admin/pos', 'shopping-cart', false],
    ],
    'Orders' => [
        ['orders.view', 'Orders', '/admin/orders', 'clipboard', true],
    ],
    'Customers' => [
        ['customers.view', 'Customers', '/admin/customers', 'users', true],
    ],
    'Finance' => [
        ['payments.view', 'Payments', '/admin/payments', 'credit-card', false],
        ['finance.view', 'Expenses', '/admin/expenses', 'wallet', false],
    ],
    'Warranty & Service' => [
        ['warranty.manage', 'Warranty & Repairs', '/admin/warranty', 'shield', false],
    ],
    'Delivery' => [
        ['delivery.manage', 'Courier & Delivery', '/admin/delivery', 'map-pin', false],
    ],
    'CRM' => [
        ['crm.view', 'Customer CRM', '/admin/crm', 'heart', false],
    ],
    'Reports' => [
        ['reports.view', 'Reports & Analytics', '/admin/reports', 'bar-chart', false],
    ],
    'Staff & Roles' => [
        ['users.view', 'Staff Users', '/admin/users', 'user', true],
        ['roles.manage', 'Roles & Permissions', '/admin/roles', 'key', true],
    ],
    'Notifications' => [
        ['notifications.view', 'Notifications', '/admin/notifications', 'bell', true],
    ],
    'Settings' => [
        ['settings.manage', 'Business Settings', '/admin/settings', 'settings', true],
        ['audit_logs.view', 'Audit Logs', '/admin/audit-logs', 'file-text', true],
    ],
];
