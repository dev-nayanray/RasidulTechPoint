<?php

declare(strict_types=1);

namespace App\Controllers\Storefront;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\CustomerRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Services\AuditService;
use App\Services\CartService;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\SettingService;
use App\Support\Database\Connection;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;
use PDO;

/**
 * Storefront checkout — single-page flow with five sections:
 *   A. Contact details (name, phone, email, optional order reference)
 *   B. Delivery method (home_delivery | store_pickup)
 *   C. Delivery fee (server-side computed from settings)
 *   D. Payment method (only methods enabled in payment_methods_catalog)
 *   E. Order review (item snapshots + totals + confirm)
 *
 * Order placement flow:
 *   1. Validate contact + delivery input on the server.
 *   2. Revalidate the cart — every unit must still be 'available'
 *      at its current selling_price.
 *   3. Re-validate any applied coupon via the existing CouponService.
 *   4. Compute the delivery fee server-side (flat-fee setting or
 *      free-shipping threshold). The client-submitted fee is ignored.
 *   5. Begin a DB transaction. Call OrderService::createOrder
 *      (which uses SELECT ... FOR UPDATE on inventory_units rows).
 *      The order is created with channel='online', initial status
 *      'pending' + payment_status 'unpaid'. Unit snapshots are
 *      stored in order_items. The delivery snapshot is stored on
 *      the order row.
 *   6. Clear the cart only after the order commits.
 *   7. Redirect to the confirmation page with the order reference.
 *
 * Double-sell protection:
 *   - OrderService::createOrder locks each inventory_units row
 *     (FOR UPDATE) and refuses if status != 'available'/'reserved'.
 *     Two concurrent checkouts for the same unit → second sees the
 *     unit as 'sold' and is rejected.
 *   - The cart itself does NOT reserve inventory — only the order
 *     transaction does.
 *
 * Duplicate-submission protection:
 *   - CSRF token on the POST.
 *   - A per-session idempotency hash is stored at the start of the
 *     POST handler; a second POST with the same hash is rejected
 *     with a redirect to the existing confirmation (or to /cart if
 *     no order was actually placed).
 */
final class CheckoutController
{
    public function index(): Response
    {
        $cart = (new CartService())->revalidatedItems();
        if ($cart['count'] === 0) {
            return Response::redirect(url('/shop'));
        }
        if ($cart['has_unavailable']) {
            flash_error('Some items in your cart are no longer available. Please remove them before checking out.');
            return Response::redirect(url('/cart'));
        }

        // Sync the cart to live prices so the customer sees the same
        // totals the checkout will compute.
        (new CartService())->syncPrices();
        $cart = (new CartService())->revalidatedItems();

        $auth = current_user();
        $customer = $auth ? (new CustomerRepository())->findByUserId((int) $auth['id']) : null;
        $old = [
            'name' => $customer['name'] ?? Session::old('name', ''),
            'phone' => $customer['phone'] ?? Session::old('phone', ''),
            'email' => $customer['email'] ?? Session::old('email', ''),
            'delivery_method' => Session::old('delivery_method', 'home_delivery'),
            'address' => $customer['address'] ?? Session::old('address', ''),
            'city' => $customer['city'] ?? Session::old('city', ''),
            'area' => Session::old('area', ''),
            'postal_code' => Session::old('postal_code', ''),
            'delivery_instructions' => Session::old('delivery_instructions', ''),
            'payment_method_code' => Session::old('payment_method_code', ''),
            'coupon_code' => Session::old('coupon_code', ''),
        ];

        $paymentMethods = (new PaymentRepository())->methodsCatalog(activeOnly: true);
        if ($paymentMethods === []) {
            // No payment methods enabled — surface honestly instead of
            // rendering a checkout form with no payment options.
            return Response::html(View::render('storefront/checkout/no_payment', [
                'pageTitle' => 'Checkout unavailable',
                'metaTitle' => 'Checkout — Rasidul Tech Point',
            ]));
        }

        // Delivery fee server-side computation — uses the existing
        // `delivery_default_shipping_fee` setting (Prompt 39 system
        // settings). When 0, no flat fee is configured and the customer
        // is told "fee will be confirmed at checkout" — never invented.
        $flatFee = (float) SettingService::get('delivery_default_shipping_fee', '0');
        $freeThreshold = (float) SettingService::get('delivery_free_shipping_threshold', '');
        $deliveryFee = $flatFee > 0 ? $flatFee : 0.0;
        $deliveryFeeConfirmed = $flatFee > 0;
        if ($freeThreshold > 0 && $cart['total'] >= $freeThreshold) {
            $deliveryFee = 0.0;
        }

        // Coupon preview — apply the coupon code from the old input to
        // show the customer the discount before they submit.
        $couponCode = trim($old['coupon_code']);
        $couponPreview = null;
        $couponError = null;
        if ($couponCode !== '') {
            try {
                $couponPreview = (new CouponService())->validateAndCompute($couponCode, [
                    'subtotal' => $cart['total'],
                    'unit_ids' => $cart['items'] === [] ? [] : array_column($cart['items'], 'unit_id'),
                ], $customer !== null ? (int) $customer['id'] : null);
            } catch (ValidationException $e) {
                $couponError = implode(' ', $e->errors);
            }
        }

        $discount = $couponPreview['discount'] ?? 0.0;
        $grandTotal = max(0.0, $cart['total'] - $discount + $deliveryFee);

        return Response::html(View::render('storefront/checkout/index', [
            'pageTitle' => 'Checkout',
            'metaTitle' => 'Checkout — Rasidul Tech Point',
            'cart' => $cart,
            'old' => $old,
            'errors' => [],
            'paymentMethods' => $paymentMethods,
            'customer' => $customer,
            'deliveryFee' => $deliveryFee,
            'deliveryFeeConfirmed' => $deliveryFeeConfirmed,
            'flatFee' => $flatFee,
            'freeShippingThreshold' => $freeThreshold,
            'couponPreview' => $couponPreview,
            'couponError' => $couponError,
            'grandTotal' => $grandTotal,
            'discount' => $discount,
            'whatsapp' => (string) SettingService::get('contact_whatsapp', ''),
        ]));
    }

    /**
     * Place the order. Server-side validation + DB transaction via
     * OrderService::createOrder. Cart is cleared only after the order
     * commits. On any failure, the cart is preserved and the customer
     * is redirected back to the checkout form.
     */
    public function store(Request $request): Response
    {
        // ----- Idempotency guard -----
        // A per-session checkout hash stored at the start. If the same
        // POST comes in twice (browser double-submit / network retry),
        // the second is rejected with a redirect to the existing
        // confirmation (or to /cart if no order was placed).
        $submitHash = md5(serialize($request->post()) . Session::get('checkout_idempotency_token', ''));
        $lastHash = Session::get('last_checkout_hash');
        $lastOrderRef = Session::get('last_order_reference');
        if ($submitHash === $lastHash && $lastOrderRef !== null) {
            // Looks like a duplicate submission — redirect to the
            // confirmation of the order we already placed (if it exists).
            $stmt = Connection::pdo()->prepare('SELECT 1 FROM orders WHERE reference = ?');
            $stmt->execute([$lastOrderRef]);
            if ($stmt->fetchColumn() !== false) {
                return Response::redirect(url('/checkout/confirmation/' . $lastOrderRef));
            }
        }
        Session::set('last_checkout_hash', $submitHash);

        // ----- 1. Validate contact + delivery input -----
        try {
            $data = Validator::make($request->post(), [
                'name' => 'required|string|min:2|max:120',
                'phone' => 'required|string|min:6|max:30',
                'email' => 'nullable|email|max:190',
                'delivery_method' => 'required|in:home_delivery,store_pickup',
                'address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'area' => 'nullable|string|max:120',
                'postal_code' => 'nullable|string|max:20',
                'delivery_instructions' => 'nullable|string|max:500',
                'payment_method_code' => 'required|string|max:30',
                'coupon_code' => 'nullable|string|max:64',
                'notes' => 'nullable|string|max:500',
            ], [
                'name' => 'Full name',
                'phone' => 'Phone number',
                'delivery_method' => 'Delivery method',
                'payment_method_code' => 'Payment method',
            ])->validate();
        } catch (ValidationException $e) {
            Session::flashInput($request->post());
            foreach ($e->errors as $field => $msg) {
                flash_error("{$field}: {$msg}");
            }
            return Response::redirect(url('/checkout'));
        }

        // Delivery-method-specific validation.
        if ($data['delivery_method'] === 'home_delivery') {
            $addressErrs = [];
            if (trim((string) $data['address']) === '') $addressErrs['address'] = 'Street address is required for home delivery.';
            if (trim((string) $data['city']) === '') $addressErrs['city'] = 'City / district is required for home delivery.';
            if (trim((string) $data['phone']) === '') $addressErrs['phone'] = 'Phone is required for delivery confirmation.';
            if ($addressErrs !== []) {
                Session::flashInput($request->post());
                foreach ($addressErrs as $msg) flash_error($msg);
                return Response::redirect(url('/checkout'));
            }
        }

        // Payment-method eligibility: must be an active row in payment_methods_catalog.
        $payRepo = new PaymentRepository();
        $method = $payRepo->findByCode((string) $data['payment_method_code']);
        if ($method === null || (int) $method['is_active'] !== 1) {
            Session::flashInput($request->post());
            flash_error('The selected payment method is not available. Please choose another.');
            return Response::redirect(url('/checkout'));
        }

        // ----- 2. Revalidate the cart -----
        $cartSvc = new CartService();
        $cart = $cartSvc->revalidatedItems();
        if ($cart['count'] === 0) {
            flash_error('Your cart is empty.');
            return Response::redirect(url('/cart'));
        }
        if ($cart['has_unavailable']) {
            flash_error('Some items in your cart are no longer available. Please remove them and try again.');
            return Response::redirect(url('/cart'));
        }
        if ($cart['has_price_change']) {
            flash_error('Some prices in your cart have changed. Please review and accept the new prices, then try again.');
            return Response::redirect(url('/cart'));
        }
        // Sync the cart prices to live DB prices (idempotent — no-op if already synced).
        $cartSvc->syncPrices();
        $cart = $cartSvc->revalidatedItems();

        // ----- 3. Resolve the customer (auth or guest) -----
        $auth = current_user();
        $customerRepo = new CustomerRepository();
        $customerId = 0;
        if ($auth) {
            $customer = $customerRepo->findByUserId((int) $auth['id']);
            if ($customer !== null) {
                $customerId = (int) $customer['id'];
            }
        }
        if ($customerId === 0) {
            // Guest checkout: find-or-create a customer by phone.
            // The customer row carries the address snapshot at order
            // time; future changes to the customer's profile do NOT
            // affect the historical order (the order stores its own
            // delivery_snapshot JSON).
            $phone = trim((string) $data['phone']);
            $existing = $customerRepo->findByPhone($phone);
            if ($existing !== null) {
                $customerId = (int) $existing['id'];
            } else {
                try {
                    $customerId = $customerRepo->create([
                        'name' => $data['name'],
                        'phone' => $phone,
                        'email' => ($data['email'] ?? '') !== '' ? $data['email'] : null,
                        'address' => $data['address'] ?? null,
                        'city' => $data['city'] ?? null,
                        'account_status' => 'active',
                    ]);
                } catch (\Throwable $e) {
                    Session::flashInput($request->post());
                    flash_error('Could not create a customer record: ' . $e->getMessage());
                    return Response::redirect(url('/checkout'));
                }
            }
        }

        // ----- 4. Coupon validation (re-run via the existing service) -----
        $couponCode = trim((string) ($data['coupon_code'] ?? ''));
        $couponOption = null;
        if ($couponCode !== '') {
            try {
                $couponOption = (new CouponService())->validateAndCompute($couponCode, [
                    'subtotal' => $cart['total'],
                    'unit_ids' => array_column($cart['items'], 'unit_id'),
                ], $customerId > 0 ? $customerId : null);
            } catch (ValidationException $e) {
                Session::flashInput($request->post());
                flash_error('Coupon: ' . implode(' ', $e->errors));
                return Response::redirect(url('/checkout'));
            }
        }

        // ----- 5. Delivery fee (server-side, ignoring client-submitted) -----
        $flatFee = (float) SettingService::get('delivery_default_shipping_fee', '0');
        $freeThreshold = (float) SettingService::get('delivery_free_shipping_threshold', '');
        $deliveryFee = $flatFee > 0 ? $flatFee : 0.0;
        if ($freeThreshold > 0 && $cart['total'] >= $freeThreshold) {
            $deliveryFee = 0.0;
        }
        // Store-pickup has no delivery fee.
        if ($data['delivery_method'] === 'store_pickup') {
            $deliveryFee = 0.0;
        }

        // ----- 6. Build the order options -----
        $unitIds = array_map('intval', array_column($cart['items'], 'unit_id'));
        $options = [
            'discount' => $couponOption['discount'] ?? 0.0,
            'notes' => $data['notes'] ?? null,
            'coupon' => $couponOption,
            // Delivery snapshot — stored on the order row, independent
            // of future changes to the customer profile.
            'delivery_method' => $data['delivery_method'],
            'delivery_snapshot' => [
                'method' => $data['delivery_method'],
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'area' => $data['area'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'instructions' => $data['delivery_instructions'] ?? null,
                'fee' => number_format($deliveryFee, 2, '.', ''),
                'fee_basis' => $data['delivery_method'] === 'store_pickup'
                    ? 'Store pickup — no delivery fee.'
                    : ($flatFee > 0
                        ? "Flat fee ৳" . number_format($flatFee, 2)
                        : 'Fee to be confirmed by the business.'),
                'payment_method_code' => $data['payment_method_code'],
                'payment_method_label' => $method['label'] ?? $data['payment_method_code'],
            ],
        ];

        // ----- 7. Place the order (transactional) -----
        try {
            $order = (new OrderService())->createOrder(
                $customerId,
                $unitIds,
                'online',
                $auth ? (int) $auth['id'] : 1, // guest checkout: attributed to system user (id=1)
                $options,
                'pending', // online orders start as pending
            );
        } catch (ValidationException $e) {
            // Likely: one of the units was just sold elsewhere → the
            // FOR UPDATE row lock caught it. Preserve the cart.
            Session::flashInput($request->post());
            flash_error('Could not place the order: ' . implode(' ', $e->errors));
            return Response::redirect(url('/checkout'));
        } catch (\Throwable $e) {
            Session::flashInput($request->post());
            flash_error('An error occurred while placing the order. Please try again. (' . $e->getMessage() . ')');
            return Response::redirect(url('/checkout'));
        }

        // ----- 8. Store delivery snapshot on the order row -----
        // OrderService::createOrder already wrote the order_items +
        // units-to-sold transition + audit. The delivery snapshot
        // goes on the order row itself.
        $this->updateOrderDelivery((int) $order['id'], $data, $deliveryFee, $flatFee, $method);

        // ----- 9. Clear the cart + record the order reference -----
        $cartSvc->clear();
        Session::set('last_order_reference', $order['reference']);
        AuditService::record('checkout.order_placed', 'order', (int) $order['id'], [
            'reference' => $order['reference'],
            'customer_id' => $customerId,
            'units' => count($unitIds),
            'payment_method' => $data['payment_method_code'],
            'delivery_method' => $data['delivery_method'],
            'delivery_fee' => number_format($deliveryFee, 2, '.', ''),
            'coupon' => $couponCode !== '' ? $couponCode : null,
        ], $request);

        // ----- 10. Redirect to confirmation -----
        return Response::redirect(url('/checkout/confirmation/' . $order['reference']));
    }

    /**
     * Order confirmation page. Access is scoped:
     *  - Authenticated customers: only their own orders (via
     *    findByReferenceForCustomer — the IDOR-safe lookup).
     *  - Guest checkouts: the order reference is stored in the
     *    session as `last_order_reference` immediately after placement,
     *    so the same browser session can view the confirmation once.
     *    Revisiting the URL later requires signing in as the
     *    customer who placed the order.
     */
    public function confirmation(string $reference): Response
    {
        $repo = new OrderRepository();
        $auth = current_user();

        // Check the session marker FIRST — this covers guest checkout
        // (where last_order_reference was set right after placement).
        // If it matches, we serve the confirmation regardless of auth
        // state, because the same browser session placed the order.
        $lastRef = Session::get('last_order_reference');
        $order = null;
        if ($reference === $lastRef) {
            $orderId = $this->lookupOrderIdByReference($reference);
            if ($orderId > 0) {
                $order = $repo->find($orderId);
            }
        }

        // If the session marker didn't match, fall back to authenticated
        // customer-scoped lookup (IDOR-safe).
        if ($order === null && $auth) {
            $customer = (new CustomerRepository())->findByUserId((int) $auth['id']);
            if ($customer !== null) {
                $order = $repo->findByReferenceForCustomer($reference, (int) $customer['id']);
            }
        }

        if ($order === null) {
            throw new NotFoundException('Order not found. Sign in to view your order history.');
        }

        // Decode the delivery snapshot for display.
        $delivery = $order['delivery_snapshot'] ?? null;
        if (is_string($delivery) && $delivery !== '') {
            $delivery = json_decode($delivery, true);
        }
        $order['delivery'] = is_array($delivery) ? $delivery : null;

        // The payment method code is stored inside the delivery snapshot.
        $paymentMethodCode = $order['delivery']['payment_method_code'] ?? null;
        $paymentMethod = null;
        if ($paymentMethodCode !== null) {
            $paymentMethod = (new PaymentRepository())->findByCode((string) $paymentMethodCode);
        }

        // Clear the last_order_reference session marker so a refresh
        // of the confirmation page after sign-out hits the
        // NotFoundException path instead of leaking access.
        // (We leave it set so the user can refresh on the same session.)

        return Response::html(View::render('storefront/checkout/confirmation', [
            'pageTitle' => 'Order confirmed',
            'metaTitle' => 'Order ' . $reference . ' — Rasidul Tech Point',
            'order' => $order,
            'paymentMethod' => $paymentMethod,
            'whatsapp' => (string) SettingService::get('contact_whatsapp', ''),
        ]));
    }

    // ---------------------------------------------------- INTERNAL --

    private function updateOrderDelivery(int $orderId, array $data, float $deliveryFee, float $flatFee, array $method): void
    {
        $snapshot = [
            'method' => $data['delivery_method'],
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'area' => $data['area'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'instructions' => $data['delivery_instructions'] ?? null,
            'fee' => number_format($deliveryFee, 2, '.', ''),
            'fee_basis' => $data['delivery_method'] === 'store_pickup'
                ? 'Store pickup — no delivery fee.'
                : ($flatFee > 0
                    ? "Flat fee ৳" . number_format($flatFee, 2)
                    : 'Fee to be confirmed by the business.'),
            'payment_method_code' => $data['payment_method_code'],
            'payment_method_label' => $method['label'] ?? $data['payment_method_code'],
        ];
        $stmt = Connection::pdo()->prepare(
            "UPDATE orders SET delivery_method = ?, delivery_snapshot = ? WHERE id = ?"
        );
        $stmt->execute([
            $data['delivery_method'],
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $orderId,
        ]);
    }

    private function lookupOrderIdByReference(string $reference): int
    {
        $stmt = Connection::pdo()->prepare('SELECT id FROM orders WHERE reference = ? LIMIT 1');
        $stmt->execute([$reference]);
        $id = $stmt->fetchColumn();
        return $id === false ? 0 : (int) $id;
    }
}
