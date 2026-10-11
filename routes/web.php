<?php

declare(strict_types=1);

/**
 * Web routes. All state-changing endpoints use POST (+ CSRF middleware) or
 * spoofed PUT/PATCH/DELETE through the _method field; never GET.
 *
 * Groups: public → auth → customer account → admin (permission gated).
 */

use App\Controllers\Account\AccountController;
use App\Controllers\Admin\AuditLogController;
use App\Controllers\Admin\BrandController;
use App\Controllers\Admin\CategoryController;
use App\Controllers\Admin\CustomerController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\DeliveryController;
use App\Controllers\Admin\InspectionController;
use App\Controllers\Admin\InventoryController;
use App\Controllers\Admin\InvoiceRefundController;
use App\Controllers\Admin\LocationController;
use App\Controllers\Admin\NotificationController;
use App\Controllers\Admin\OrderController;
use App\Controllers\Admin\PaymentController;
use App\Controllers\Admin\ProductImageController;
use App\Controllers\Admin\ProductModelController;
use App\Controllers\Admin\PurchaseOrderController;
use App\Controllers\Admin\PosController;
use App\Controllers\Admin\RoleController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\SupplierController;
use App\Controllers\Admin\UserController;
use App\Controllers\Auth\LoginController;
use App\Controllers\Auth\PasswordResetController;
use App\Controllers\Auth\RegisterController;
use App\Controllers\Storefront\HomeController;
use App\Controllers\Storefront\PageController;
use App\Controllers\Storefront\ShopController;
use App\Support\Router;

/** @var Router $router */

// ---------------------------------------------------------------- PUBLIC ----
$router->get('/', [HomeController::class, 'index'])->name('home');

$router->get('/shop', [ShopController::class, 'index'])->name('shop');
$router->get('/shop/{slug}', [ShopController::class, 'show'])->name('shop.product');
$router->get('/brands/{slug}', [ShopController::class, 'brand'])->name('shop.brand');
$router->get('/categories/{slug}', [ShopController::class, 'category'])->name('shop.category');

$router->get('/about', [PageController::class, 'about'])->name('about');
$router->get('/contact', [PageController::class, 'contact'])->name('contact');

// ---------------------------------------------------------------- AUTH ----
$router->group(['middleware' => ['guest', 'csrf']], function (Router $router): void {
    $router->get('/login', [LoginController::class, 'show'])->name('login');
    $router->post('/login', [LoginController::class, 'store'])->name('login.attempt');
    $router->get('/register', [RegisterController::class, 'show'])->name('register');
    $router->post('/register', [RegisterController::class, 'store'])->name('register.store');
    $router->get('/forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
    $router->post('/forgot-password', [PasswordResetController::class, 'sendResetEmail'])->name('password.email');
    $router->get('/reset-password', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    $router->post('/reset-password', [PasswordResetController::class, 'updatePassword'])->name('password.update');
});

$router->post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth', 'csrf')
    ->name('logout');

// ------------------------------------------------------------- CUSTOMER ----
$router->group(['prefix' => 'account', 'middleware' => ['auth', 'csrf']], function (Router $router): void {
    $router->get('', [AccountController::class, 'index'])->name('account');
    $router->get('/profile', [AccountController::class, 'profile'])->name('account.profile');
    $router->post('/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
    $router->get('/orders', [AccountController::class, 'orders'])->name('account.orders');
    $router->get('/orders/{reference}', [AccountController::class, 'order'])->name('account.orders.show');
});

// ---------------------------------------------------------------- ADMIN ----
$router->group(['prefix' => 'admin', 'middleware' => ['auth', 'csrf']], function (Router $router): void {
    // Overview
    $router->get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard.view')->name('admin.dashboard');

    // Staff users
    $router->get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
    $router->get('/users/create', [UserController::class, 'create'])
        ->middleware('permission:users.create|users.update');
    $router->post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
    $router->get('/users/{id}', [UserController::class, 'show'])->middleware('permission:users.view');
    $router->get('/users/{id}/edit', [UserController::class, 'edit'])->middleware('permission:users.update');
    $router->post('/users/{id}/update', [UserController::class, 'update'])->middleware('permission:users.update');
    $router->post('/users/{id}/status', [UserController::class, 'deactivate'])->middleware('permission:users.deactivate');
    $router->post('/users/{id}/reset-password', [UserController::class, 'initiatePasswordReset'])
        ->middleware('permission:users.update');
    $router->post('/users/{id}/delete', [UserController::class, 'destroy'])
        ->middleware('permission:users.deactivate');
    $router->post('/users/{id}/restore', [UserController::class, 'restore'])
        ->middleware('permission:users.deactivate');

    // Roles & permissions
    $router->get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.manage');
    $router->get('/roles/create', [RoleController::class, 'create'])->middleware('permission:roles.manage');
    $router->post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.manage');
    $router->get('/roles/{id}', [RoleController::class, 'show'])->middleware('permission:roles.manage');
    $router->get('/roles/{id}/edit', [RoleController::class, 'edit'])->middleware('permission:roles.manage');
    $router->post('/roles/{id}/permissions', [RoleController::class, 'updatePermissions'])
        ->middleware('permission:roles.manage');
    $router->post('/roles/{id}/delete', [RoleController::class, 'destroy'])
        ->middleware('permission:roles.manage');

    // Catalog: brands / categories / laptop models
    $router->get('/brands', [BrandController::class, 'index'])->middleware('permission:products.view');
    $router->post('/brands', [BrandController::class, 'store'])->middleware('permission:products.create');
    $router->post('/brands/{id}/update', [BrandController::class, 'update'])->middleware('permission:products.update');
    $router->post('/brands/{id}/delete', [BrandController::class, 'destroy'])->middleware('permission:products.delete');

    $router->get('/categories', [CategoryController::class, 'index'])->middleware('permission:products.view');
    $router->post('/categories', [CategoryController::class, 'store'])->middleware('permission:products.create');
    $router->post('/categories/{id}/update', [CategoryController::class, 'update'])->middleware('permission:products.update');
    $router->post('/categories/{id}/delete', [CategoryController::class, 'destroy'])->middleware('permission:products.delete');

    $router->get('/products', [ProductModelController::class, 'index'])->middleware('permission:products.view');
    $router->get('/products/create', [ProductModelController::class, 'create'])
        ->middleware('permission:products.create');
    $router->post('/products', [ProductModelController::class, 'store'])->middleware('permission:products.create');
    $router->get('/products/{id}', [ProductModelController::class, 'show'])->middleware('permission:products.view');
    $router->get('/products/{id}/edit', [ProductModelController::class, 'edit'])->middleware('permission:products.update');
    $router->post('/products/{id}/update', [ProductModelController::class, 'update'])->middleware('permission:products.update');
    $router->post('/products/{id}/delete', [ProductModelController::class, 'destroy'])->middleware('permission:products.delete');

    // Product images
    $router->post('/products/{id}/images', [ProductImageController::class, 'upload'])
        ->middleware('permission:products.update');
    $router->post('/products/images/{imageId}/primary', [ProductImageController::class, 'setPrimary'])
        ->middleware('permission:products.update');
    $router->post('/products/images/{imageId}/delete', [ProductImageController::class, 'destroy'])
        ->middleware('permission:products.delete');
    $router->post('/products/images/{imageId}/reorder', [ProductImageController::class, 'reorder'])
        ->middleware('permission:products.update');

    // Inventory
    $router->get('/inventory', [InventoryController::class, 'index'])->middleware('permission:inventory.view');
    $router->get('/inventory/movements', [InventoryController::class, 'movements'])->middleware('permission:inventory.view');
    $router->get('/inventory/create', [InventoryController::class, 'create'])
        ->middleware('permission:inventory.receive');
    $router->post('/inventory', [InventoryController::class, 'store'])->middleware('permission:inventory.receive');
    $router->get('/inventory/{id}', [InventoryController::class, 'show'])->middleware('permission:inventory.view');
    $router->post('/inventory/{id}/transition', [InventoryController::class, 'transition'])
        ->middleware('permission:inventory.view');
    $router->post('/inventory/{id}/price', [InventoryController::class, 'adjustPrice'])
        ->middleware('permission:inventory.adjust');
    $router->post('/inventory/{id}/transfer', [InventoryController::class, 'transfer'])
        ->middleware('permission:inventory.adjust');
    $router->post('/inventory/{id}/photos', [InventoryController::class, 'uploadPhoto'])
        ->middleware('permission:inventory.adjust');
    $router->post('/inventory/photos/{photoId}/delete', [InventoryController::class, 'deletePhoto'])
        ->middleware('permission:inventory.adjust');

    // Quality inspections
    $router->get('/inspections', [InspectionController::class, 'index'])
        ->middleware('permission:inspections.manage');
    $router->get('/inspections/create', [InspectionController::class, 'createForm'])
        ->middleware('permission:inspections.manage');
    $router->post('/inspections', [InspectionController::class, 'store'])
        ->middleware('permission:inspections.manage');
    $router->get('/inspections/{id}', [InspectionController::class, 'show'])
        ->middleware('permission:inspections.manage');
    $router->post('/inspections/{id}/approve', [InspectionController::class, 'approve'])
        ->middleware('permission:inventory.approve');
    $router->post('/inspections/checklist/add', [InspectionController::class, 'addChecklistItem'])
        ->middleware('permission:inspections.manage');
    $router->post('/inspections/checklist/{id}/toggle', [InspectionController::class, 'toggleChecklistItem'])
        ->middleware('permission:inspections.manage');
    $router->post('/inspections/checklist/{id}/delete', [InspectionController::class, 'deleteChecklistItem'])
        ->middleware('permission:inspections.manage');

    // Locations & branches
    $router->get('/locations', [LocationController::class, 'index'])->middleware('permission:inventory.view');
    $router->get('/locations/create', [LocationController::class, 'create'])
        ->middleware('permission:inventory.adjust');
    $router->post('/locations', [LocationController::class, 'store'])
        ->middleware('permission:inventory.adjust');
    $router->get('/locations/{id}/edit', [LocationController::class, 'edit'])
        ->middleware('permission:inventory.adjust');
    $router->post('/locations/{id}/update', [LocationController::class, 'update'])
        ->middleware('permission:inventory.adjust');
    $router->post('/locations/{id}/delete', [LocationController::class, 'destroy'])
        ->middleware('permission:inventory.adjust');

    // Suppliers
    $router->get('/suppliers', [SupplierController::class, 'index'])->middleware('permission:purchases.view');
    $router->get('/suppliers/create', [SupplierController::class, 'create'])
        ->middleware('permission:purchases.manage');
    $router->post('/suppliers', [SupplierController::class, 'store'])
        ->middleware('permission:purchases.manage');
    $router->get('/suppliers/{id}', [SupplierController::class, 'show'])
        ->middleware('permission:purchases.view');
    $router->get('/suppliers/{id}/edit', [SupplierController::class, 'edit'])
        ->middleware('permission:purchases.manage');
    $router->post('/suppliers/{id}/update', [SupplierController::class, 'update'])
        ->middleware('permission:purchases.manage');
    $router->post('/suppliers/{id}/delete', [SupplierController::class, 'destroy'])
        ->middleware('permission:purchases.manage');

    // Purchase orders & import batches
    $router->get('/purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchases.view');
    $router->get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])
        ->middleware('permission:purchases.manage');
    $router->post('/purchase-orders', [PurchaseOrderController::class, 'store'])
        ->middleware('permission:purchases.manage');
    $router->get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show'])
        ->middleware('permission:purchases.view');
    $router->get('/purchase-orders/{id}/edit', [PurchaseOrderController::class, 'edit'])
        ->middleware('permission:purchases.manage');
    $router->post('/purchase-orders/{id}/update', [PurchaseOrderController::class, 'update'])
        ->middleware('permission:purchases.manage');
    $router->post('/purchase-orders/{id}/status', [PurchaseOrderController::class, 'changeStatus'])
        ->middleware('permission:purchases.view');
    $router->post('/purchase-orders/{id}/allocate', [PurchaseOrderController::class, 'allocateLandedCost'])
        ->middleware('permission:inventory.adjust');

    // POS & cashier panel
    $router->get('/pos', [PosController::class, 'index'])->middleware('permission:pos.access');
    $router->get('/pos/shift', [PosController::class, 'shiftStatus'])->middleware('permission:pos.access');
    $router->post('/pos/shift/open', [PosController::class, 'openShift'])
        ->middleware('permission:pos.access');
    $router->post('/pos/shift/close', [PosController::class, 'closeShift'])
        ->middleware('permission:pos.access');
    $router->get('/pos/search', [PosController::class, 'search'])
        ->middleware('permission:pos.access');
    $router->get('/pos/customers', [PosController::class, 'searchCustomers'])
        ->middleware('permission:pos.access');
    $router->post('/pos/quick-customer', [PosController::class, 'quickCustomer'])
        ->middleware('permission:customers.create|customers.manage');
    $router->get('/pos/held', [PosController::class, 'heldList'])
        ->middleware('permission:pos.access');
    $router->post('/pos/hold', [PosController::class, 'hold'])
        ->middleware('permission:pos.access');
    $router->post('/pos/resume/{id}', [PosController::class, 'resume'])
        ->middleware('permission:pos.access');
    $router->post('/pos/cancel-held/{id}', [PosController::class, 'cancelHeld'])
        ->middleware('permission:pos.access');
    $router->post('/pos/checkout', [PosController::class, 'checkout'])
        ->middleware('permission:pos.access');
    $router->get('/pos/receipt/{orderId}', [PosController::class, 'receipt'])
        ->middleware('permission:pos.access');

    // Orders & payments
    $router->get('/orders', [OrderController::class, 'index'])->middleware('permission:orders.view');
    $router->get('/orders/create', [OrderController::class, 'create'])->middleware('permission:orders.manage');
    $router->post('/orders', [OrderController::class, 'store'])->middleware('permission:orders.manage');
    $router->get('/orders/{id}', [OrderController::class, 'show'])->middleware('permission:orders.view');
    $router->post('/orders/{id}/status', [OrderController::class, 'updateStatus'])->middleware('permission:orders.manage');
    $router->post('/orders/{id}/payments', [OrderController::class, 'recordPayment'])->middleware('permission:payments.manage');
    $router->post('/orders/{id}/payments/{paymentId}/confirm', [OrderController::class, 'confirmPayment'])
        ->middleware('permission:payments.manage');
    $router->post('/orders/{id}/fulfillment-notes', [OrderController::class, 'addFulfillmentNote'])
        ->middleware('permission:orders.manage');

    // Payment records
    $router->get('/payments', [PaymentController::class, 'index'])->middleware('permission:payments.view');
    $router->get('/payments/pending', [PaymentController::class, 'pendingQueue'])->middleware('permission:payments.view');
    $router->get('/payments/methods', [PaymentController::class, 'methodsIndex'])->middleware('permission:payments.view');
    $router->post('/payments/methods/{id}/update', [PaymentController::class, 'updateMethod'])
        ->middleware('permission:settings.manage');
    $router->get('/payments/reconciliation', [PaymentController::class, 'reconciliationIndex'])
        ->middleware('permission:payments.view');
    $router->post('/payments/reconciliation', [PaymentController::class, 'createReconciliationBatch'])
        ->middleware('permission:payments.manage');
    $router->post('/payments/reconciliation/{id}/confirm', [PaymentController::class, 'confirmReconciliationBatch'])
        ->middleware('permission:payments.manage');
    $router->get('/payments/{id}', [PaymentController::class, 'show'])->middleware('permission:payments.view');
    $router->post('/payments/{id}/verify', [PaymentController::class, 'verify'])
        ->middleware('permission:payments.manage');
    $router->post('/payments/{id}/refund', [PaymentController::class, 'refund'])
        ->middleware('permission:payments.refund');

    // Invoices + refunds + authorized corrections (Prompt 25)
    $router->get('/invoices', [InvoiceRefundController::class, 'invoiceIndex'])
        ->middleware('permission:orders.view');
    $router->get('/invoices/{id}', [InvoiceRefundController::class, 'invoiceShow'])
        ->middleware('permission:orders.view');
    $router->post('/orders/{id}/invoice', [InvoiceRefundController::class, 'invoiceGenerate'])
        ->middleware('permission:orders.manage');
    $router->post('/invoices/{id}/void', [InvoiceRefundController::class, 'invoiceVoid'])
        ->middleware('permission:settings.manage');
    $router->post('/invoices/{id}/mark-paid', [InvoiceRefundController::class, 'invoiceMarkPaid'])
        ->middleware('permission:payments.manage');

    $router->get('/refunds', [InvoiceRefundController::class, 'refundIndex'])
        ->middleware('permission:orders.view');
    $router->get('/refunds/{id}', [InvoiceRefundController::class, 'refundShow'])
        ->middleware('permission:orders.view');
    $router->post('/orders/{id}/refund', [InvoiceRefundController::class, 'refundCreate'])
        ->middleware('permission:payments.refund');
    $router->post('/refunds/{id}/process', [InvoiceRefundController::class, 'refundProcess'])
        ->middleware('permission:payments.refund');
    $router->post('/refunds/{id}/cancel', [InvoiceRefundController::class, 'refundCancel'])
        ->middleware('permission:payments.refund');

    $router->post('/orders/{id}/corrections', [InvoiceRefundController::class, 'correctionStore'])
        ->middleware('permission:pos.authorize_discount|payments.refund|settings.manage');

    // Delivery & courier management (Prompt 26)
    $router->get('/delivery', [DeliveryController::class, 'index'])->middleware('permission:delivery.manage');
    // Couriers
    $router->get('/delivery/couriers', [DeliveryController::class, 'courierIndex'])->middleware('permission:delivery.manage');
    $router->get('/delivery/couriers/create', [DeliveryController::class, 'courierCreate'])->middleware('permission:delivery.manage');
    $router->post('/delivery/couriers', [DeliveryController::class, 'courierStore'])->middleware('permission:delivery.manage');
    $router->get('/delivery/couriers/{id}', [DeliveryController::class, 'courierShow'])->middleware('permission:delivery.manage');
    $router->get('/delivery/couriers/{id}/edit', [DeliveryController::class, 'courierEdit'])->middleware('permission:delivery.manage');
    $router->post('/delivery/couriers/{id}/update', [DeliveryController::class, 'courierUpdate'])->middleware('permission:delivery.manage');
    $router->post('/delivery/couriers/{id}/delete', [DeliveryController::class, 'courierDelete'])->middleware('permission:delivery.manage');
    $router->post('/delivery/couriers/{id}/restore', [DeliveryController::class, 'courierRestore'])->middleware('permission:delivery.manage');
    // Zones
    $router->get('/delivery/zones', [DeliveryController::class, 'zoneIndex'])->middleware('permission:delivery.manage');
    $router->post('/delivery/zones', [DeliveryController::class, 'zoneStore'])->middleware('permission:delivery.manage');
    $router->post('/delivery/zones/{id}/update', [DeliveryController::class, 'zoneUpdate'])->middleware('permission:delivery.manage');
    $router->post('/delivery/zones/{id}/delete', [DeliveryController::class, 'zoneDelete'])->middleware('permission:delivery.manage');
    // Shipments
    $router->get('/delivery/shipments', [DeliveryController::class, 'shipmentIndex'])->middleware('permission:delivery.manage');
    $router->get('/delivery/shipments/{id}', [DeliveryController::class, 'shipmentShow'])->middleware('permission:delivery.manage');
    $router->get('/delivery/orders/{orderId}/shipments/create', [DeliveryController::class, 'shipmentCreateForm'])
        ->middleware('permission:delivery.manage');
    $router->post('/delivery/shipments', [DeliveryController::class, 'shipmentStore'])->middleware('permission:delivery.manage');
    $router->post('/delivery/shipments/{id}/dispatch', [DeliveryController::class, 'shipmentDispatch'])->middleware('permission:delivery.manage');
    $router->post('/delivery/shipments/{id}/transit', [DeliveryController::class, 'shipmentTransit'])->middleware('permission:delivery.manage');
    $router->post('/delivery/shipments/{id}/deliver', [DeliveryController::class, 'shipmentDeliver'])->middleware('permission:delivery.manage');
    $router->post('/delivery/shipments/{id}/fail', [DeliveryController::class, 'shipmentFail'])->middleware('permission:delivery.manage');
    $router->post('/delivery/shipments/{id}/return', [DeliveryController::class, 'shipmentReturn'])->middleware('permission:delivery.manage');
    $router->post('/delivery/shipments/{id}/cancel', [DeliveryController::class, 'shipmentCancel'])->middleware('permission:delivery.manage');

    // Customers
    $router->get('/customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
    $router->get('/customers/duplicates', [CustomerController::class, 'duplicates'])
        ->middleware('permission:customers.manage');
    $router->get('/customers/create', [CustomerController::class, 'create'])
        ->middleware('permission:customers.create|customers.manage');
    $router->post('/customers', [CustomerController::class, 'store'])
        ->middleware('permission:customers.create|customers.manage');
    $router->get('/customers/{id}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
    $router->get('/customers/{id}/edit', [CustomerController::class, 'edit'])->middleware('permission:customers.manage');
    $router->post('/customers/{id}/update', [CustomerController::class, 'update'])->middleware('permission:customers.manage');
    $router->post('/customers/{id}/status', [CustomerController::class, 'updateStatus'])->middleware('permission:customers.manage');
    $router->post('/customers/{id}/notes', [CustomerController::class, 'addNote'])->middleware('permission:customers.manage');
    $router->post('/customers/{id}/delete', [CustomerController::class, 'destroy'])->middleware('permission:customers.manage');
    $router->post('/customers/{id}/restore', [CustomerController::class, 'restore'])->middleware('permission:customers.manage');

    // Notifications
    $router->get('/notifications', [NotificationController::class, 'index'])
        ->middleware('permission:notifications.view');
    $router->post('/notifications/{id}/read', [NotificationController::class, 'markRead'])
        ->middleware('permission:notifications.view');
    $router->post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->middleware('permission:notifications.view');

    // Settings & audit
    $router->get('/settings', [SettingsController::class, 'edit'])->middleware('permission:settings.manage');
    $router->post('/settings', [SettingsController::class, 'update'])->middleware('permission:settings.manage');
    $router->get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit_logs.view');
    $router->get('/audit-logs/export', [AuditLogController::class, 'export'])
        ->middleware('permission:audit_logs.view');
});
