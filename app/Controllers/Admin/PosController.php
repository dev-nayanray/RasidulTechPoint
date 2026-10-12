<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\AuditService;
use App\Services\PosService;
use App\Services\SettingService;
use App\Support\Database\Connection;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * POS and cashier panel.
 *
 * Routes (all require pos.access via group middleware):
 *   GET  /admin/pos                              terminal UI
 *   GET  /admin/pos/shift                        current shift (HTML partial)
 *   POST /admin/pos/shift/open                   start shift
 *   POST /admin/pos/shift/close                  close + reconcile
 *   GET  /admin/pos/search                       JSON unit search
 *   GET  /admin/pos/customers                    JSON customer search
 *   POST /admin/pos/quick-customer               JSON walk-in customer create
 *   GET  /admin/pos/held                         held sales list (HTML)
 *   POST /admin/pos/hold                         save cart as held sale
 *   POST /admin/pos/resume/{id}                  resume held sale (JSON cart payload)
 *   POST /admin/pos/cancel-held/{id}             cancel a held sale
 *   POST /admin/pos/checkout                     finalise the sale
 *   GET  /admin/pos/receipt/{orderId}            print-friendly receipt
 */
final class PosController
{
    public function index(Request $request): Response
    {
        $svc = new PosService();
        $shift = $svc->getCurrentShift(current_user_id());
        $held = $svc->heldSales(current_user_id(), AuthService::can('pos.authorize_discount'));
        $canAuthorizeDiscount = AuthService::can('pos.authorize_discount');
        $discountThreshold = (float) SettingService::get('pos_discount_authorization_threshold', PosService::DISCOUNT_AUTHORIZATION_THRESHOLD);

        // Business profile for the receipt header.
        $biz = $this->businessProfile();

        return Response::html(View::render('admin/pos/terminal', [
            'pageTitle' => 'Point of Sale',
            'shift' => $shift,
            'heldSales' => $held,
            'canAuthorizeDiscount' => $canAuthorizeDiscount,
            'discountThreshold' => $discountThreshold,
            'paymentMethods' => $svc->paymentMethodLabels(),
            'business' => $biz,
        ]));
    }

    public function shiftStatus(): Response
    {
        $svc = new PosService();
        $shift = $svc->getCurrentShift(current_user_id());

        return Response::html(View::partial('admin/pos/_shift', [
            'shift' => $shift,
        ]));
    }

    public function openShift(Request $request): Response
    {
        $openingFloat = (float) $request->input('opening_float', 0);
        $notes = trim((string) $request->input('notes', ''));
        try {
            $shift = (new PosService())->startShift(current_user_id(), $openingFloat, $notes !== '' ? $notes : null);
            flash_success('Shift opened with float ' . money((string) $shift['opening_float']) . '.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/pos'));
    }

    public function closeShift(Request $request): Response
    {
        $svc = new PosService();
        $shift = $svc->getCurrentShift(current_user_id());
        if ($shift === null) {
            flash_error('You have no open shift to close.');
            return Response::redirect(url('/admin/pos'));
        }

        $actual = (float) $request->input('actual_cash', 0);
        $notes = trim((string) $request->input('notes', ''));
        try {
            $closed = $svc->closeShift((int) $shift['id'], current_user_id(), $actual, $notes !== '' ? $notes : null);
            $disc = (float) $closed['discrepancy'];
            if (abs($disc) < 0.005) {
                flash_success('Shift closed. Cash reconciled perfectly (' . money((string) $closed['actual_cash']) . ').');
            } else {
                $dir = $disc > 0 ? 'over' : 'short';
                flash_error('Shift closed with a ' . $dir . ' of ' . money(number_format(abs($disc), 2, '.', '')) . '. Please review the discrepancy report.');
            }
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/pos'));
    }

    public function search(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $units = (new PosService())->searchUnits($q, 25);
        return Response::json(['units' => $units]);
    }

    public function searchCustomers(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $customers = (new PosService())->searchCustomers($q, 25);
        return Response::json(['customers' => $customers]);
    }

    public function quickCustomer(Request $request): Response
    {
        try {
            $name = trim((string) $request->input('name', ''));
            $phone = trim((string) $request->input('phone', ''));
            $email = trim((string) $request->input('email', ''));
            $id = (new PosService())->quickCreateCustomer(
                $name,
                $phone,
                $email !== '' ? $email : null,
            );
            AuditService::record('pos.quick_customer', 'customer', $id, ['name' => $name, 'phone' => $phone], $request);
            return Response::json(['id' => $id, 'name' => $name, 'phone' => $phone]);
        } catch (\App\Exceptions\ValidationException $e) {
            return Response::json(['errors' => $e->errors], 422);
        } catch (\Throwable $e) {
            return Response::json(['errors' => ['general' => $e->getMessage()]], 500);
        }
    }

    public function heldList(): Response
    {
        $svc = new PosService();
        $held = $svc->heldSales(current_user_id(), AuthService::can('pos.authorize_discount'));
        return Response::html(View::render('admin/pos/held', [
            'pageTitle' => 'Held sales',
            'heldSales' => $held,
        ]));
    }

    public function hold(Request $request): Response
    {
        $svc = new PosService();
        $shift = $svc->getCurrentShift(current_user_id());

        $payload = $request->post();
        $cart = $this->extractCart($payload);
        $customerId = (int) ($payload['customer_id'] ?? 0);
        $customerName = trim((string) ($payload['customer_name'] ?? 'Walk-in customer'));
        $customerPhone = trim((string) ($payload['customer_phone'] ?? '')) ?: null;
        $notes = trim((string) ($payload['notes'] ?? '')) ?: null;

        try {
            $result = $svc->holdSale(
                $cart,
                current_user_id(),
                $shift !== null ? (int) $shift['id'] : null,
                $customerId > 0 ? $customerId : null,
                $customerName !== '' ? $customerName : 'Walk-in customer',
                $customerPhone,
                $notes,
            );
            return Response::json(['held_sale_id' => $result['id'], 'reference' => $result['reference']]);
        } catch (\App\Exceptions\ValidationException $e) {
            return Response::json(['errors' => $e->errors], 422);
        } catch (\Throwable $e) {
            return Response::json(['errors' => ['general' => $e->getMessage()]], 500);
        }
    }

    public function resume(string $id): Response
    {
        $svc = new PosService();
        try {
            $result = $svc->resumeHeldSale((int) $id, current_user_id());
            return Response::json($result);
        } catch (\App\Exceptions\ValidationException $e) {
            return Response::json(['errors' => $e->errors], 422);
        } catch (\Throwable $e) {
            return Response::json(['errors' => ['general' => $e->getMessage()]], 500);
        }
    }

    public function cancelHeld(string $id, Request $request): Response
    {
        $svc = new PosService();
        $reason = trim((string) $request->input('reason', ''));
        try {
            $svc->cancelHeldSale((int) $id, current_user_id(), $reason !== '' ? $reason : null);
            return Response::json(['ok' => true]);
        } catch (\App\Exceptions\ValidationException $e) {
            return Response::json(['errors' => $e->errors], 422);
        } catch (\Throwable $e) {
            return Response::json(['errors' => ['general' => $e->getMessage()]], 500);
        }
    }

    public function checkout(Request $request): Response
    {
        $svc = new PosService();
        $shift = $svc->getCurrentShift(current_user_id());

        $payload = $request->post();
        $cart = $this->extractCart($payload);
        $cart['customer_id'] = (int) ($payload['customer_id'] ?? 0);
        $cart['payment_method'] = (string) ($payload['payment_method'] ?? 'cash');
        $cart['paid_amount'] = (float) ($payload['paid_amount'] ?? 0);
        $cart['payment_reference'] = $payload['payment_reference'] ?? null;
        $cart['notes'] = $payload['notes'] ?? null;
        $cart['held_sale_id'] = isset($payload['held_sale_id']) ? (int) $payload['held_sale_id'] : null;
        $cart['coupon_code'] = isset($payload['coupon_code']) ? trim((string) $payload['coupon_code']) : '';

        // If the cashier tried to apply a discount above the threshold,
        // they had to enter the authorizer confirmation. The server
        // re-validates: the current user must hold pos.authorize_discount
        // for the discount to be approved at this checkout (single-user
        // authorisation flow — the manager is at their own terminal).
        $discountAuthorizedBy = null;
        $discount = (float) ($cart['discount'] ?? 0);
        $subtotal = $this->computeSubtotalFromPayload($payload);
        if ($discount > 0) {
            try {
                $discountAuthorizedBy = $svc->assertDiscountAuthorized(
                    $discount,
                    $subtotal,
                    current_user_id(),
                    AuthService::can('pos.authorize_discount') ? current_user_id() : null,
                );
            } catch (\App\Exceptions\ValidationException $e) {
                return Response::json(['errors' => $e->errors], 422);
            }
        }

        try {
            $result = $svc->checkout(
                $cart,
                current_user_id(),
                $shift !== null ? (int) $shift['id'] : null,
                $discountAuthorizedBy,
            );
            return Response::json($result);
        } catch (\App\Exceptions\ValidationException $e) {
            return Response::json(['errors' => $e->errors], 422);
        } catch (\Throwable $e) {
            return Response::json(['errors' => ['general' => $e->getMessage()]], 500);
        }
    }

    public function receipt(string $orderId): Response
    {
        $order = (new OrderRepository())->find((int) $orderId);
        if ($order === null) {
            throw new NotFoundException('Order not found.');
        }
        $biz = $this->businessProfile();
        return Response::html(View::render('admin/pos/receipt', [
            'pageTitle' => 'Receipt ' . $order['reference'],
            'order' => $order,
            'business' => $biz,
        ]), 200)->header('X-Print-Layout', 'receipt');
    }

    /**
     * @return array{items:list<array{unit_id:int}>, subtotal:float, discount:float, total:float}
     */
    private function extractCart(array $payload): array
    {
        $items = [];
        foreach (($payload['items'] ?? []) as $row) {
            // Accept either {"unit_id": 12} or {"id": 12} shapes.
            $uid = (int) ($row['unit_id'] ?? $row['id'] ?? 0);
            if ($uid > 0) {
                $items[] = ['unit_id' => $uid] + $row;
            }
        }
        $subtotal = (float) ($payload['subtotal'] ?? 0);
        $discount = (float) ($payload['discount'] ?? 0);
        $total = (float) ($payload['total'] ?? max(0.0, $subtotal - $discount));
        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
        ];
    }

    private function computeSubtotalFromPayload(array $payload): float
    {
        // Reuse the unit ids to compute the actual subtotal server-side.
        $cart = $this->extractCart($payload);
        $ids = array_map(fn ($i) => (int) $i['unit_id'], $cart['items']);
        if ($ids === []) {
            return 0.0;
        }
        sort($ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Connection::pdo()->prepare(
            "SELECT COALESCE(SUM(selling_price), 0) FROM inventory_units
             WHERE id IN ({$placeholders}) AND status IN ('available','reserved')"
        );
        $stmt->execute($ids);
        return (float) $stmt->fetchColumn();
    }

    /** @return array{name:string,address:string,phone:string,email:string} */
    private function businessProfile(): array
    {
        $row = Connection::pdo()->query(
            'SELECT name, address, phone, email FROM business_profiles ORDER BY id LIMIT 1'
        )->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['name' => 'Rasidul Tech Point', 'address' => '', 'phone' => '', 'email' => ''];
        }
        return [
            'name' => (string) ($row['name'] ?? 'Rasidul Tech Point'),
            'address' => (string) ($row['address'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
        ];
    }
}
