<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use App\Support\Session;
use PDO;

/**
 * Session-backed shopping cart for the storefront.
 *
 * Stores are keyed by `inventory_unit_id` (the actual physical laptop),
 * not by `product_model_id`. This matches the project's per-unit
 * inventory model: each laptop has its own SKU, serial, condition grade,
 * and price — it cannot be bought twice.
 *
 * Cart shape (serialized in $_SESSION['cart']):
 *   [
 *     'items' => [
 *       <unit_id_int> => [
 *         'unit_id'      => int,
 *         'product_model_id' => int,
 *         'sku'          => string,
 *         'name'         => string,
 *         'brand_name'   => string,
 *         'processor'    => ?string,
 *         'ram_gb'       => ?int,
 *         'storage_capacity_gb' => ?int,
 *         'storage_type' => ?string,
 *         'condition_grade' => string,
 *         'unit_price'   => float,        // server-trusted at add time
 *         'added_at'     => int (timestamp),
 *       ], ...
 *     ]
 *   ]
 *
 * Persistence model:
 *  - Session-based: cart survives normal navigation. No DB writes
 *    until checkout.
 *  - Cart does NOT reserve inventory. Adding a laptop to the cart
 *    does not move it from 'available' → 'reserved'. The unit is
 *    revalidated at checkout (status must still be 'available',
 *    price must still match). If it's been sold to someone else,
 *    the customer is told so and the item is removed from the cart.
 *
 * Per-unit semantics:
 *  - Quantity is ALWAYS 1. The cart enforces this — adding a unit
 *    that's already in the cart is a no-op (not a quantity bump).
 *  - The unique key is `inventory_unit_id`; one physical laptop
 *    can appear in a single cart at most once.
 *  - Adding the same unit from two concurrent sessions is allowed
 *    at the cart layer — the double-sell prevention happens at
 *    order placement via `OrderService::createOrder`'s
 *    `SELECT ... FOR UPDATE` on the inventory_units rows. The
 *    second checkout will see the unit as 'sold' and be rejected.
 *
 * Auth/login:
 *  - When a customer logs in mid-cart, the cart contents (which
 *    were keyed on the anonymous session) are migrated to the
 *    customer's session automatically — Session::regenerate is
 *    called on login but the cart key is preserved.
 */
final class CartService
{
    private const SESSION_KEY = 'cart';

    /** Add a unit to the cart. Idempotent — adding a unit already in the cart is a no-op. */
    public function add(int $unitId): array
    {
        $unit = $this->loadUnitForCart($unitId);
        if ($unit === null) {
            throw new ValidationException(['unit_id' => 'This laptop is no longer available.']);
        }
        if ($unit['status'] !== 'available') {
            throw new ValidationException([
                'unit_id' => "Unit {$unit['sku']} is not sellable (status: {$unit['status']}).",
            ]);
        }

        $cart = $this->load();
        if (isset($cart['items'][$unitId])) {
            // Per-unit rule: quantity is always 1. Re-adding is a no-op.
            return $cart['items'][$unitId];
        }

        $item = [
            'unit_id' => $unitId,
            'product_model_id' => (int) $unit['product_model_id'],
            'sku' => (string) $unit['sku'],
            'name' => (string) $unit['name'],
            'brand_name' => (string) $unit['brand_name'],
            'processor' => $unit['processor'] ?? null,
            'ram_gb' => isset($unit['ram_gb']) ? (int) $unit['ram_gb'] : null,
            'storage_capacity_gb' => isset($unit['storage_capacity_gb']) ? (int) $unit['storage_capacity_gb'] : null,
            'storage_type' => $unit['storage_type'] ?? null,
            'condition_grade' => (string) $unit['condition_grade'],
            'unit_price' => (float) $unit['selling_price'],
            'added_at' => time(),
        ];
        $cart['items'][$unitId] = $item;
        $this->save($cart);
        return $item;
    }

    /** Remove a single unit from the cart. */
    public function remove(int $unitId): void
    {
        $cart = $this->load();
        unset($cart['items'][$unitId]);
        $this->save($cart);
    }

    /** Empty the cart entirely. */
    public function clear(): void
    {
        $this->save(['items' => []]);
    }

    /**
     * Return all cart items with server-revalidated availability + price.
     * If a unit has been sold elsewhere, its row carries
     * `available: false` + `unavailable_reason` so the cart UI can
     * surface the change. If the price has changed, the row carries
     * `price_changed: true` + the new price.
     *
     * @return array{items:list<array>,total:float,count:int,has_unavailable:bool,has_price_change:bool}
     */
    public function revalidatedItems(): array
    {
        $cart = $this->load();
        $out = [];
        $total = 0.0;
        $hasUnavailable = false;
        $hasPriceChange = false;
        foreach ($cart['items'] as $unitId => $item) {
            $row = $item;
            $unit = $this->loadUnitForCart($unitId);
            if ($unit === null) {
                $row['available'] = false;
                $row['unavailable_reason'] = 'This laptop has been removed from inventory.';
                $row['current_price'] = null;
                $row['price_changed'] = false;
                $hasUnavailable = true;
            } elseif ($unit['status'] !== 'available') {
                $row['available'] = false;
                $row['unavailable_reason'] = "This laptop is no longer available (status: {$unit['status']}).";
                $row['current_price'] = (float) $unit['selling_price'];
                $row['price_changed'] = (float) $unit['selling_price'] !== $item['unit_price'];
                $hasUnavailable = true;
                if ($row['price_changed']) $hasPriceChange = true;
            } else {
                $row['available'] = true;
                $row['unavailable_reason'] = null;
                $row['current_price'] = (float) $unit['selling_price'];
                $row['price_changed'] = (float) $unit['selling_price'] !== $item['unit_price'];
                if ($row['price_changed']) {
                    $hasPriceChange = true;
                } else {
                    $total += $item['unit_price'];
                }
            }
            $out[] = $row;
        }
        return [
            'items' => $out,
            'total' => round($total, 2),
            'count' => count($out),
            'has_unavailable' => $hasUnavailable,
            'has_price_change' => $hasPriceChange,
        ];
    }

    /** Total number of items (always equals item count for unique-unit carts). */
    public function count(): int
    {
        return count($this->load()['items']);
    }

    /**
     * Prune unavailable items from the cart (called by the cart view's
     * "remove unavailable" button, and by the checkout flow before
     * order placement). Returns the list of removed unit_ids.
     *
     * @return list<int>
     */
    public function pruneUnavailable(): array
    {
        $cart = $this->load();
        $removed = [];
        foreach (array_keys($cart['items']) as $unitId) {
            $unit = $this->loadUnitForCart((int) $unitId);
            if ($unit === null || $unit['status'] !== 'available') {
                unset($cart['items'][$unitId]);
                $removed[] = (int) $unitId;
            }
        }
        $this->save($cart);
        return $removed;
    }

    /**
     * Sync the cached unit_price in the cart with the live DB price.
     * Called by the cart UI when the customer accepts the new price
     * after a price-change notice. Returns the count of items updated.
     */
    public function syncPrices(): int
    {
        $cart = $this->load();
        $updated = 0;
        foreach ($cart['items'] as $unitId => $item) {
            $unit = $this->loadUnitForCart((int) $unitId);
            if ($unit !== null && $unit['status'] === 'available') {
                $live = (float) $unit['selling_price'];
                if ($live !== $item['unit_price']) {
                    $cart['items'][$unitId]['unit_price'] = $live;
                    $updated++;
                }
            }
        }
        $this->save($cart);
        return $updated;
    }

    /** @return list<int> */
    public function unitIds(): array
    {
        $cart = $this->load();
        return array_map('intval', array_keys($cart['items']));
    }

    // ---------------------------------------------------- INTERNAL --

    /** @return array{items:array<int,mixed>} */
    private function load(): array
    {
        $cart = Session::get(self::SESSION_KEY);
        if (!is_array($cart) || !isset($cart['items']) || !is_array($cart['items'])) {
            return ['items' => []];
        }
        return $cart;
    }

    private function save(array $cart): void
    {
        Session::set(self::SESSION_KEY, $cart);
        // Surface the count to the layout for the header badge.
        Session::set('cart_count', count($cart['items']));
    }

    /**
     * Load the unit row + its product model metadata for the cart.
     * Returns null when the unit has been deleted.
     */
    private function loadUnitForCart(int $unitId): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT iu.id, iu.sku, iu.serial_number, iu.product_model_id, iu.condition_grade,
                    iu.selling_price, iu.status, pm.name, pm.processor, pm.ram_gb,
                    pm.storage_type, pm.storage_capacity_gb,
                    b.name AS brand_name
             FROM inventory_units iu
             JOIN product_models pm ON pm.id = iu.product_model_id
             JOIN brands b ON b.id = pm.brand_id
             WHERE iu.id = ?'
        );
        $stmt->execute([$unitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
