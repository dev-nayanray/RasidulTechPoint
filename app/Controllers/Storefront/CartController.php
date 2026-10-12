<?php

declare(strict_types=1);

namespace App\Controllers\Storefront;

use App\Exceptions\ValidationException;
use App\Services\AuditService;
use App\Services\CartService;
use App\Services\SettingService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Storefront shopping cart.
 *
 * Routes (all behind the public layout, CSRF on state-changing ones):
 *   GET  /cart              — view cart + order summary + empty/unavailable/price-change states
 *   POST /cart/add          — add a unit (keyed by inventory_unit_id, NOT product_model_id)
 *   POST /cart/remove       — remove a single unit
 *   POST /cart/clear        — empty the cart
 *   POST /cart/sync-prices  — accept the new price after a price-change notice
 *
 * Cart state is session-based (CartService stores in $_SESSION['cart']).
 * No DB writes happen for cart state — only at checkout does the
 * order + items + payment rows get created via the existing
 * OrderService.
 */
final class CartController
{
    public function index(): Response
    {
        $svc = new CartService();
        $cart = $svc->revalidatedItems();

        // Estimated delivery — only honest when a flat-fee setting exists.
        $flatFee = (float) SettingService::get('delivery_default_shipping_fee', '0');
        $freeThreshold = (float) SettingService::get('delivery_free_shipping_threshold', '');
        $deliveryEstimate = null;
        $deliveryBasis = 'Delivery fee will be confirmed at checkout.';
        if ($flatFee > 0) {
            $deliveryEstimate = $flatFee;
            $deliveryBasis = 'Flat ৳' . number_format($flatFee, 2) . ' delivery fee (configurable).';
            if ($freeThreshold > 0 && $cart['total'] >= $freeThreshold) {
                $deliveryEstimate = 0.0;
                $deliveryBasis = "Free delivery (orders over ৳" . number_format($freeThreshold, 2) . ").";
            }
        }

        // Estimated grand total — only counts available items (unavailable
        // ones are not added to the subtotal).
        $estimatedGrandTotal = $cart['total'] + ($deliveryEstimate ?? 0);

        return Response::html(View::render('storefront/cart/index', [
            'pageTitle' => 'Shopping cart',
            'metaTitle' => 'Shopping cart — Rasidul Tech Point',
            'cart' => $cart,
            'deliveryEstimate' => $deliveryEstimate,
            'deliveryBasis' => $deliveryBasis,
            'estimatedGrandTotal' => $estimatedGrandTotal,
            'freeShippingThreshold' => $freeThreshold,
        ]));
    }

    public function add(Request $request): Response
    {
        $unitId = (int) $request->input('unit_id', 0);
        if ($unitId <= 0) {
            flash_error('Please choose a specific laptop unit to add to your cart.');
            return Response::redirect(url('/shop'));
        }
        try {
            $item = (new CartService())->add($unitId);
            flash_success("Added {$item['brand_name']} {$item['name']} (SKU {$item['sku']}) to your cart.");
            AuditService::record('cart.add', 'inventory_unit', $unitId, [
                'sku' => $item['sku'],
            ], $request);
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        // Send back to the previous page so the customer can keep browsing.
        $referer = $request->header('referer');
        if ($referer !== null && str_contains($referer, $_SERVER['HTTP_HOST'] ?? '')) {
            return Response::redirect($referer);
        }
        return Response::redirect(url('/cart'));
    }

    public function remove(Request $request): Response
    {
        $unitId = (int) $request->input('unit_id', 0);
        if ($unitId > 0) {
            (new CartService())->remove($unitId);
            flash_success('Item removed from cart.');
            AuditService::record('cart.remove', 'inventory_unit', $unitId, [], $request);
        }
        return Response::redirect(url('/cart'));
    }

    public function clear(Request $request): Response
    {
        (new CartService())->clear();
        flash_success('Cart cleared.');
        AuditService::record('cart.clear', null, null, [], $request);
        return Response::redirect(url('/cart'));
    }

    public function syncPrices(Request $request): Response
    {
        $updated = (new CartService())->syncPrices();
        if ($updated > 0) {
            flash_success("Updated {$updated} price(s) in your cart to the current live prices.");
        } else {
            flash_success('Cart prices are up to date.');
        }
        return Response::redirect(url('/cart'));
    }
}
