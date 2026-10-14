<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\CatalogRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\OrderRepository;
use App\Services\AuditService;
use App\Services\ContentService;
use App\Services\NotificationService;
use App\Services\OrderService;
use App\Services\SettingService;
use App\Support\Database\Connection;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;
use App\Support\View;
use PDO;

/**
 * Customer self-service area.
 *
 * Every method scopes by the authenticated user's customer record.
 * No customer_id is ever accepted from the client — it always comes
 * from current_user_id() → CustomerRepository::findByUserId().
 * This is the IDOR guard: another customer's data is unreachable.
 */
final class AccountController
{
    // ====== Dashboard ======

    public function index(): Response
    {
        $customer = $this->requireCustomer();
        $pdo = Connection::pdo();
        $customerId = (int) $customer['id'];
        $userId = (int) current_user_id();

        // Real stats from the customer's own records.
        $orderRepo = new OrderRepository();
        $recentOrders = $orderRepo->forCustomer($customerId, 5);

        // Every status must be pre-seeded: the dashboard view sums keys like
        // $orderStats['confirmed'] directly, and a missing key is a 500 for
        // customers who simply have no order in that status yet.
        $orderStats = array_fill_keys(array_merge(['total'], OrderService::ORDER_STATUSES), 0);
        $stmt = $pdo->prepare('SELECT status, COUNT(*) as c FROM orders WHERE customer_id = ? GROUP BY status');
        $stmt->execute([$customerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orderStats[$row['status']] = (int) $row['c'];
            $orderStats['total'] += (int) $row['c'];
        }

        $countStmt = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM warranty_registrations WHERE customer_id = ?),
                    (SELECT COUNT(*) FROM service_jobs WHERE customer_id = ?),
                    (SELECT COUNT(*) FROM wishlist_items WHERE customer_id = ?)'
        );
        $countStmt->execute([$customerId, $customerId, $customerId]);
        [$warrantyCount, $serviceCount, $wishlistCount] = array_map('intval', $countStmt->fetch(PDO::FETCH_NUM));
        $unreadNotifications = NotificationService::unreadCount($userId);

        return Response::html(View::render('account/dashboard', [
            'pageTitle' => 'Dashboard',
            'metaTitle' => 'My Account — Rasidul Tech Point',
            'customer' => $customer,
            'recentOrders' => $recentOrders,
            'orderStats' => $orderStats,
            'warrantyCount' => $warrantyCount,
            'serviceCount' => $serviceCount,
            'unreadNotifications' => $unreadNotifications,
            'wishlistCount' => $wishlistCount,
        ], layout: 'layouts/account'));
    }

    // ====== Profile ======

    public function profile(): Response
    {
        $customer = $this->requireCustomer();
        return Response::html(View::render('account/profile', [
            'pageTitle' => 'Profile',
            'metaTitle' => 'My Profile — Rasidul Tech Point',
            'customer' => $customer,
            'errors' => [],
        ], layout: 'layouts/account'));
    }

    public function updateProfile(Request $request): Response
    {
        $customer = $this->requireCustomer();
        $data = Validator::make($request->post(), [
            'name' => 'required|string|min:2|max:120',
            'phone' => 'required|string|min:6|max:30',
            'email' => 'nullable|email|max:190',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
        ], ['phone' => 'Phone number'])->validate();

        $stmt = Connection::pdo()->prepare(
            'UPDATE customers SET name = ?, phone = ?, email = ?, address = ?, city = ? WHERE id = ?'
        );
        $stmt->execute([
            $data['name'], $data['phone'],
            ($data['email'] ?? '') !== '' ? $data['email'] : null,
            $data['address'] ?? null, $data['city'] ?? null,
            (int) $customer['id'],
        ]);
        // Also update the user's name + email for login.
        $stmt = Connection::pdo()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
        $stmt->execute([$data['name'], strtolower(trim($data['email'] ?? '')), (int) current_user_id()]);

        flash_success('Profile updated.');
        AuditService::record('customer.profile_updated', 'customer', (int) $customer['id'], [], $request);
        return Response::redirect(url('/account/profile'));
    }

    public function changePassword(Request $request): Response
    {
        $customer = $this->requireCustomer();
        $userId = (int) current_user_id();

        $data = Validator::make($request->post(), [
            'current_password' => 'required|string|max:500',
            'new_password' => 'required|string|min:10|max:500|confirmed',
        ], [
            'current_password' => 'Current password',
            'new_password' => 'New password',
        ])->validate();

        $stmt = Connection::pdo()->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $hash = (string) $stmt->fetchColumn();
        if (!password_verify($data['current_password'], $hash)) {
            flash_error('Your current password is incorrect.');
            return Response::redirect(url('/account/profile#password'));
        }
        $stmt = Connection::pdo()->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([password_hash($data['new_password'], PASSWORD_DEFAULT), $userId]);

        flash_success('Password changed successfully.');
        AuditService::record('customer.password_changed', 'user', $userId, [], $request);
        return Response::redirect(url('/account/profile#password'));
    }

    // ====== Orders ======

    public function orders(Request $request): Response
    {
        $customer = $this->requireCustomer();
        $repo = new OrderRepository();
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 20));
        $orders = $repo->forCustomer((int) $customer['id'], $perPage);
        return Response::html(View::render('account/orders', [
            'pageTitle' => 'My Orders',
            'metaTitle' => 'My Orders — Rasidul Tech Point',
            'orders' => $orders,
            'orderStatuses' => \App\Services\OrderService::ORDER_STATUSES,
            'paymentStatuses' => \App\Services\OrderService::PAYMENT_STATUSES,
        ], layout: 'layouts/account'));
    }

    public function order(string $reference): Response
    {
        $customer = $this->requireCustomer();
        $repo = new OrderRepository();
        $order = $repo->findByReferenceForCustomer($reference, (int) $customer['id']);
        if ($order === null) {
            throw new NotFoundException('Order not found.');
        }

        // Decode delivery snapshot.
        $delivery = $order['delivery_snapshot'] ?? null;
        if (is_string($delivery) && $delivery !== '') {
            $delivery = json_decode($delivery, true);
        }
        $order['delivery'] = is_array($delivery) ? $delivery : null;

        // Shipment tracking info.
        $stmt = Connection::pdo()->prepare(
            'SELECT * FROM shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([(int) $order['id']]);
        $shipment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Active invoice.
        $invoiceSvc = new \App\Services\InvoiceService();
        $activeInvoice = $invoiceSvc->findByOrder((int) $order['id']);

        // Return requests.
        $stmt = Connection::pdo()->prepare(
            'SELECT * FROM return_requests WHERE order_id = ? ORDER BY id DESC'
        );
        $stmt->execute([(int) $order['id']]);
        $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return Response::html(View::render('account/order-show', [
            'pageTitle' => 'Order ' . $order['reference'],
            'metaTitle' => 'Order ' . $order['reference'] . ' — Rasidul Tech Point',
            'order' => $order,
            'shipment' => $shipment,
            'activeInvoice' => $activeInvoice,
            'returns' => $returns,
        ], layout: 'layouts/account'));
    }

    // ====== Addresses ======

    public function addresses(): Response
    {
        $customer = $this->requireCustomer();
        $stmt = Connection::pdo()->prepare(
            'SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id ASC'
        );
        $stmt->execute([(int) $customer['id']]);
        $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return Response::html(View::render('account/addresses', [
            'pageTitle' => 'Address Book',
            'metaTitle' => 'Address Book — Rasidul Tech Point',
            'addresses' => $addresses,
            'errors' => [],
        ], layout: 'layouts/account'));
    }

    public function addressStore(Request $request): Response
    {
        $customer = $this->requireCustomer();
        $data = Validator::make($request->post(), [
            'label' => 'nullable|string|max:60',
            'recipient_name' => 'required|string|min:2|max:120',
            'recipient_phone' => 'required|string|min:6|max:30',
            'address_line' => 'required|string|max:255',
            'area' => 'nullable|string|max:120',
            'city' => 'required|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'delivery_instructions' => 'nullable|string|max:500',
            'is_default' => 'nullable',
        ], [
            'recipient_name' => 'Recipient name',
            'recipient_phone' => 'Recipient phone',
            'address_line' => 'Address',
            'city' => 'City',
        ])->validate();

        $pdo = Connection::pdo();
        $customerId = (int) $customer['id'];
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        if ($isDefault) {
            $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?')->execute([$customerId]);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO customer_addresses
                (customer_id, label, recipient_name, recipient_phone, address_line, area, city, postal_code, delivery_instructions, is_default)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $customerId,
            $data['label'] ?? 'Home',
            $data['recipient_name'],
            $data['recipient_phone'],
            $data['address_line'],
            $data['area'] ?? null,
            $data['city'],
            $data['postal_code'] ?? null,
            $data['delivery_instructions'] ?? null,
            $isDefault,
        ]);
        flash_success('Address added.');
        return Response::redirect(url('/account/addresses'));
    }

    public function addressDelete(string $id, Request $request): Response
    {
        $customer = $this->requireCustomer();
        $stmt = Connection::pdo()->prepare('DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?');
        $stmt->execute([(int) $id, (int) $customer['id']]);
        flash_success('Address deleted.');
        return Response::redirect(url('/account/addresses'));
    }

    public function addressSetDefault(string $id, Request $request): Response
    {
        $customer = $this->requireCustomer();
        $pdo = Connection::pdo();
        $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?')->execute([(int) $customer['id']]);
        $pdo->prepare('UPDATE customer_addresses SET is_default = 1 WHERE id = ? AND customer_id = ?')->execute([(int) $id, (int) $customer['id']]);
        flash_success('Default address updated.');
        return Response::redirect(url('/account/addresses'));
    }

    // ====== Warranty ======

    public function warranty(): Response
    {
        $customer = $this->requireCustomer();
        $stmt = Connection::pdo()->prepare(
            'SELECT wr.*, pm.name AS product_name, b.name AS brand_name, iu.sku, iu.serial_number,
                    iu.condition_grade
             FROM warranty_registrations wr
             LEFT JOIN inventory_units iu ON iu.id = wr.inventory_unit_id
             LEFT JOIN product_models pm ON pm.id = iu.product_model_id
             LEFT JOIN brands b ON b.id = pm.brand_id
             WHERE wr.customer_id = ?
             ORDER BY wr.id DESC'
        );
        $stmt->execute([(int) $customer['id']]);
        $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return Response::html(View::render('account/warranty', [
            'pageTitle' => 'Warranty',
            'metaTitle' => 'My Warranty — Rasidul Tech Point',
            'registrations' => $registrations,
        ], layout: 'layouts/account'));
    }

    // ====== Service Jobs ======

    public function serviceJobs(): Response
    {
        $customer = $this->requireCustomer();
        $stmt = Connection::pdo()->prepare(
            'SELECT sj.*, pm.name AS product_name, b.name AS brand_name, iu.sku, iu.serial_number
             FROM service_jobs sj
             LEFT JOIN inventory_units iu ON iu.id = sj.inventory_unit_id
             LEFT JOIN product_models pm ON pm.id = iu.product_model_id
             LEFT JOIN brands b ON b.id = pm.brand_id
             WHERE sj.customer_id = ?
             ORDER BY sj.id DESC'
        );
        $stmt->execute([(int) $customer['id']]);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return Response::html(View::render('account/service-jobs', [
            'pageTitle' => 'Repairs & Service',
            'metaTitle' => 'My Service Jobs — Rasidul Tech Point',
            'jobs' => $jobs,
        ], layout: 'layouts/account'));
    }

    // ====== Notifications ======

    public function notifications(): Response
    {
        $userId = (int) current_user_id();
        $notifications = NotificationService::forUser($userId, 50);
        return Response::html(View::render('account/notifications', [
            'pageTitle' => 'Notifications',
            'metaTitle' => 'Notifications — Rasidul Tech Point',
            'notifications' => $notifications,
        ], layout: 'layouts/account'));
    }

    public function markNotificationRead(string $id): Response
    {
        $userId = (int) current_user_id();
        NotificationService::markRead($userId, (int) $id);
        return Response::redirect(url('/account/notifications'));
    }

    public function markAllNotificationsRead(): Response
    {
        $userId = (int) current_user_id();
        NotificationService::markAllRead($userId);
        return Response::redirect(url('/account/notifications'));
    }

    // ====== Wishlist ======

    public function wishlist(): Response
    {
        $customer = $this->requireCustomer();
        $stmt = Connection::pdo()->prepare(
            'SELECT wi.*, pm.name, pm.slug, pm.processor, pm.ram_gb, pm.storage_type, pm.storage_capacity_gb,
                    pm.display_size_inches, b.name AS brand_name,
                    (SELECT pi.file_path FROM product_images pi
                      WHERE pi.product_model_id = wi.product_model_id
                      ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) AS image_path,
                    (SELECT COUNT(*) FROM inventory_units iu WHERE iu.product_model_id = wi.product_model_id AND iu.status = "available") AS available_units,
                    (SELECT MIN(iu.selling_price) FROM inventory_units iu WHERE iu.product_model_id = wi.product_model_id AND iu.status = "available") AS from_price
             FROM wishlist_items wi
             JOIN product_models pm ON pm.id = wi.product_model_id
             JOIN brands b ON b.id = pm.brand_id
             WHERE wi.customer_id = ?
             ORDER BY wi.id DESC'
        );
        $stmt->execute([(int) $customer['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return Response::html(View::render('account/wishlist', [
            'pageTitle' => 'Wishlist',
            'metaTitle' => 'My Wishlist — Rasidul Tech Point',
            'items' => $items,
        ], layout: 'layouts/account'));
    }

    public function wishlistAdd(Request $request): Response
    {
        $customer = $this->requireCustomer();
        $modelId = (int) $request->input('product_model_id', 0);
        if ($modelId > 0) {
            $stmt = Connection::pdo()->prepare(
                'INSERT IGNORE INTO wishlist_items (customer_id, product_model_id) VALUES (?, ?)'
            );
            $stmt->execute([(int) $customer['id'], $modelId]);
            flash_success('Added to wishlist.');
        }
        $referer = $request->header('referer');
        if ($referer !== null && $this->isSameOriginRedirect($referer)) {
            return Response::redirect($referer);
        }
        return Response::redirect(url('/account/wishlist'));
    }

    public function wishlistRemove(string $id, Request $request): Response
    {
        $customer = $this->requireCustomer();
        $stmt = Connection::pdo()->prepare(
            'DELETE FROM wishlist_items WHERE id = ? AND customer_id = ?'
        );
        $stmt->execute([(int) $id, (int) $customer['id']]);
        flash_success('Removed from wishlist.');
        return Response::redirect(url('/account/wishlist'));
    }

    // ====== Support ======

    public function support(): Response
    {
        $customer = $this->requireCustomer();
        $pdo = Connection::pdo();
        $profile = $pdo->query('SELECT * FROM business_profiles ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        $hours = $pdo->query('SELECT * FROM business_hours ORDER BY day_of_week')->fetchAll(PDO::FETCH_ASSOC);
        $faqs = (new ContentService())->publishedFaqs();
        $whatsapp = (string) SettingService::get('contact_whatsapp', '');
        if ($whatsapp === '' && !empty($profile['phone'])) {
            $whatsapp = (string) $profile['phone'];
        }
        $whatsappDigits = $whatsapp !== '' ? preg_replace('/[^0-9]/', '', $whatsapp) : '';
        $whatsappUsable = strlen($whatsappDigits) >= 8 && strlen($whatsappDigits) <= 15;
        $addressParts = array_filter([$profile['address'] ?? null, $profile['city'] ?? null, $profile['country'] ?? null]);
        $address = implode(', ', $addressParts);

        return Response::html(View::render('account/support', [
            'pageTitle' => 'Support',
            'metaTitle' => 'Support — Rasidul Tech Point',
            'profile' => $profile,
            'hours' => $hours,
            'faqs' => $faqs,
            'whatsapp' => $whatsapp,
            'whatsappUsable' => $whatsappUsable,
            'address' => $address,
            'email' => (string) ($profile['email'] ?? ''),
            'emailValid' => !empty($profile['email']) && filter_var($profile['email'], FILTER_VALIDATE_EMAIL) !== false,
        ], layout: 'layouts/account'));
    }

    // ====== Helpers ======

    /**
     * Resolve the authenticated user's customer record. If the user has
     * no linked customer row (e.g. created via bin/create-admin.php or
     * by an admin via staff-user management), auto-create a minimal
     * customer profile so they can access the storefront account area
     * without a 403. The auto-creation uses the user's name + email
     * from the users table; the phone defaults to a placeholder that
     * the customer can update in their profile.
     */
    private function requireCustomer(): array
    {
        $userId = (int) current_user_id();
        if ($userId === 0) {
            throw new ForbiddenException('You must be signed in to access this area.');
        }
        $repo = new CustomerRepository();
        $customer = $repo->findByUserId($userId);
        if ($customer === null) {
            // Auto-create a customer profile linked to this user.
            // This handles admin-created users who also want to shop.
            $user = current_user();
            $name = $user['name'] ?? 'Customer';
            $email = $user['email'] ?? '';
            $phone = '+8801' . random_int(10000000, 99999999);
            try {
                $customerId = $repo->create([
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email,
                    'address' => null,
                    'city' => null,
                    'account_status' => 'active',
                ], $userId);
                $customer = $repo->find($customerId);
            } catch (\Throwable $e) {
                // If the phone collides (unlikely with random), try once more.
                $customerId = $repo->create([
                    'name' => $name,
                    'phone' => '+8801' . random_int(10000000, 99999999),
                    'email' => $email,
                    'address' => null,
                    'city' => null,
                    'account_status' => 'active',
                ], $userId);
                $customer = $repo->find($customerId);
            }
            if ($customer === null) {
                throw new ForbiddenException('Could not create a customer profile for your account. Please contact support.');
            }
        }
        return $customer;
    }

    /**
     * Strict same-origin check for redirect targets.
     * Prevents open-redirect via a crafted Referer header.
     */
    private function isSameOriginRedirect(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $expectedHost = $_SERVER['HTTP_HOST'] ?? '';
        $expectedHostParts = explode(':', $expectedHost);
        $expectedHostname = $expectedHostParts[0];
        $expectedPort = $expectedHostParts[1] ?? null;
        if ($host !== $expectedHostname) {
            return false;
        }
        if ($port !== null && $port !== ($expectedPort ?? '80')) {
            return false;
        }
        if ($scheme !== null && !in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        return true;
    }
}
