<?php /** @var array $cart
 *  @var array $old
 *  @var array $errors
 *  @var list<array> $paymentMethods
 *  @var ?array $customer
 *  @var float $deliveryFee
 *  @var bool $deliveryFeeConfirmed
 *  @var float $flatFee
 *  @var float $freeShippingThreshold
 *  @var ?array $couponPreview
 *  @var ?string $couponError
 *  @var float $grandTotal
 *  @var float $discount
 *  @var string $whatsapp
 */
?>
<nav class="text-xs text-slate-500 px-4 sm:px-6" aria-label="Breadcrumb">
    <ol class="mx-auto max-w-7xl flex items-center gap-1.5 py-4">
        <li><a href="<?= e(url('/')) ?>" class="hover:text-brand-blue">Home</a></li>
        <li aria-hidden="true">/</li>
        <li><a href="<?= e(url('/cart')) ?>" class="hover:text-brand-blue">Cart</a></li>
        <li aria-hidden="true">/</li>
        <li aria-current="page" class="font-medium text-navy-700">Checkout</li>
    </ol>
</nav>

<div class="mx-auto max-w-7xl px-4 sm:px-6 pb-12">
    <h1 class="text-2xl font-bold tracking-tight text-navy-700">Checkout</h1>
    <p class="mt-1 text-sm text-slate-500">Review your order, provide delivery details, and choose a payment method. All prices are in BDT and computed server-side from live inventory.</p>

    <form method="post" action="<?= e(url('/checkout')) ?>" class="mt-6 grid gap-8 lg:grid-cols-3" data-loading-form>
        <?= csrf_field() ?>

        <?php /* ====== LEFT: Form sections (A-D) ====== */ ?>
        <div class="lg:col-span-2 space-y-6">

            <?php /* A. Contact details */ ?>
            <section class="rounded-xl bg-white ring-1 ring-slate-200 p-5 sm:p-6">
                <h2 class="text-base font-semibold text-navy-700 flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-blue text-white text-xs font-bold">1</span>
                    Contact details
                </h2>
                <p class="mt-1 text-xs text-slate-500">We'll use these to confirm your order and arrange delivery.</p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <label for="ck-name" class="block text-sm font-medium text-navy-700">Full name <span class="text-red-600">*</span></label>
                        <input id="ck-name" name="name" type="text" required value="<?= e($old['name']) ?>" class="input text-sm" autocomplete="name" maxlength="120">
                    </div>
                    <div class="space-y-1.5">
                        <label for="ck-phone" class="block text-sm font-medium text-navy-700">Phone <span class="text-red-600">*</span></label>
                        <input id="ck-phone" name="phone" type="tel" required value="<?= e($old['phone']) ?>" class="input text-sm" autocomplete="tel" placeholder="+8801XXXXXXXXX">
                    </div>
                    <div class="space-y-1.5 sm:col-span-2">
                        <label for="ck-email" class="block text-sm font-medium text-navy-700">Email <span class="text-xs font-normal text-slate-400">(optional)</span></label>
                        <input id="ck-email" name="email" type="email" value="<?= e($old['email']) ?>" class="input text-sm" autocomplete="email" placeholder="you@example.com">
                    </div>
                </div>
                <?php if ($customer !== null): ?>
                    <p class="mt-3 text-xs text-slate-400">Signed in as <?= e($customer['name']) ?>. <a href="<?= e(url('/account')) ?>" class="text-brand-blue hover:underline">View account</a>.</p>
                <?php else: ?>
                    <p class="mt-3 text-xs text-slate-400">Checking out as a guest. <a href="<?= e(url('/login') . '?redirect=' . urlencode(url('/checkout'))) ?>" class="text-brand-blue hover:underline">Sign in</a> for faster checkout and order tracking.</p>
                <?php endif; ?>
            </section>

            <?php /* B. Delivery method */ ?>
            <section class="rounded-xl bg-white ring-1 ring-slate-200 p-5 sm:p-6">
                <h2 class="text-base font-semibold text-navy-700 flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-blue text-white text-xs font-bold">2</span>
                    Delivery method
                </h2>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label class="block cursor-pointer rounded-lg border border-slate-200 p-4 has-[:checked]:border-brand-blue has-[:checked]:bg-blue-50/50 transition">
                        <div class="flex items-start gap-3">
                            <input type="radio" name="delivery_method" value="home_delivery" <?= $old['delivery_method'] === 'home_delivery' ? 'checked' : '' ?> class="mt-0.5 text-brand-blue focus:ring-brand-blue" data-delivery-toggle>
                            <div>
                                <p class="font-semibold text-navy-700">Home delivery</p>
                                <p class="mt-0.5 text-xs text-slate-500">We'll deliver to your address. <?php if ($flatFee > 0): ?>Flat fee ৳<?= number_format($flatFee, 2) ?><?php if ($freeShippingThreshold > 0): ?> · Free over ৳<?= number_format($freeShippingThreshold, 2) ?><?php endif; ?><?php else: ?>Fee confirmed at checkout<?php endif; ?>.</p>
                            </div>
                        </div>
                    </label>
                    <label class="block cursor-pointer rounded-lg border border-slate-200 p-4 has-[:checked]:border-brand-blue has-[:checked]:bg-blue-50/50 transition">
                        <div class="flex items-start gap-3">
                            <input type="radio" name="delivery_method" value="store_pickup" <?= $old['delivery_method'] === 'store_pickup' ? 'checked' : '' ?> class="mt-0.5 text-brand-blue focus:ring-brand-blue" data-delivery-toggle>
                            <div>
                                <p class="font-semibold text-navy-700">Store pickup</p>
                                <p class="mt-0.5 text-xs text-slate-500">Pick up from our store. No delivery fee.</p>
                            </div>
                        </div>
                    </label>
                </div>
            </section>

            <?php /* C. Delivery address (shown only for home_delivery) */ ?>
            <section class="rounded-xl bg-white ring-1 ring-slate-200 p-5 sm:p-6" data-delivery-address>
                <h2 class="text-base font-semibold text-navy-700 flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-blue text-white text-xs font-bold">3</span>
                    Delivery address
                </h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5 sm:col-span-2">
                        <label for="ck-address" class="block text-sm font-medium text-navy-700">Street address <span class="text-red-600">*</span></label>
                        <input id="ck-address" name="address" type="text" value="<?= e($old['address']) ?>" class="input text-sm" autocomplete="street-address">
                    </div>
                    <div class="space-y-1.5">
                        <label for="ck-area" class="block text-sm font-medium text-navy-700">Area / Thana</label>
                        <input id="ck-area" name="area" type="text" value="<?= e($old['area']) ?>" class="input text-sm">
                    </div>
                    <div class="space-y-1.5">
                        <label for="ck-city" class="block text-sm font-medium text-navy-700">City / District <span class="text-red-600">*</span></label>
                        <input id="ck-city" name="city" type="text" value="<?= e($old['city']) ?>" class="input text-sm" autocomplete="address-level2">
                    </div>
                    <div class="space-y-1.5">
                        <label for="ck-postal" class="block text-sm font-medium text-navy-700">Postal code</label>
                        <input id="ck-postal" name="postal_code" type="text" value="<?= e($old['postal_code']) ?>" class="input text-sm" autocomplete="postal-code">
                    </div>
                    <div class="space-y-1.5 sm:col-span-2">
                        <label for="ck-instructions" class="block text-sm font-medium text-navy-700">Delivery instructions <span class="text-xs font-normal text-slate-400">(optional)</span></label>
                        <textarea id="ck-instructions" name="delivery_instructions" rows="2" class="input text-sm" placeholder="Landmark, gate code, preferred time…"><?= e($old['delivery_instructions']) ?></textarea>
                    </div>
                </div>
                <p class="mt-3 text-xs text-slate-400">A snapshot of this address is stored on your order at checkout — future changes to your profile do not alter the historical order.</p>
            </section>

            <?php /* D. Payment method */ ?>
            <section class="rounded-xl bg-white ring-1 ring-slate-200 p-5 sm:p-6">
                <h2 class="text-base font-semibold text-navy-700 flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-blue text-white text-xs font-bold"><?= $old['delivery_method'] === 'store_pickup' ? '3' : '4' ?></span>
                    Payment method
                </h2>
                <p class="mt-1 text-xs text-slate-500">Only payment methods enabled by the business are shown. Order placement does not mark the order as paid — payment status is set by the configured workflow.</p>

                <div class="mt-4 space-y-2">
                    <?php foreach ($paymentMethods as $method): ?>
                        <label class="block cursor-pointer rounded-lg border border-slate-200 p-4 has-[:checked]:border-brand-blue has-[:checked]:bg-blue-50/50 transition">
                            <div class="flex items-start gap-3">
                                <input type="radio" name="payment_method_code" value="<?= e($method['code']) ?>" required <?= $old['payment_method_code'] === $method['code'] ? 'checked' : '' ?> class="mt-0.5 text-brand-blue focus:ring-brand-blue">
                                <div class="flex-1">
                                    <p class="font-semibold text-navy-700"><?= e($method['label']) ?></p>
                                    <?php if (!empty($method['description'])): ?><p class="mt-0.5 text-xs text-slate-500"><?= e($method['description']) ?></p><?php endif; ?>
                                    <?php if (!empty($method['instructions'])): ?>
                                        <details class="mt-1.5">
                                            <summary class="text-xs font-semibold text-brand-blue cursor-pointer hover:underline">How to pay</summary>
                                            <p class="mt-1 text-xs text-slate-500 whitespace-pre-wrap"><?= e($method['instructions']) ?></p>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php /* Coupon (optional, server-validated via CouponService) */ ?>
            <section class="rounded-xl bg-white ring-1 ring-slate-200 p-5 sm:p-6">
                <h2 class="text-base font-semibold text-navy-700 flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-blue text-white text-xs font-bold"><?= $old['delivery_method'] === 'store_pickup' ? '4' : '5' ?></span>
                    Coupon code <span class="text-xs font-normal text-slate-400">(optional)</span>
                </h2>
                <div class="mt-3 flex gap-2">
                    <input type="text" name="coupon_code" value="<?= e($old['coupon_code']) ?>" class="input text-sm font-mono" placeholder="e.g. NEWYEAR50">
                    <button type="submit" name="apply_coupon" value="1" formnovalidate class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-slate-50">Apply</button>
                </div>
                <?php if ($couponPreview !== null): ?>
                    <p class="mt-2 text-xs text-brand-green">✓ Coupon <?= e($couponPreview['code']) ?> applied — discount <?= money($couponPreview['discount']) ?>.</p>
                <?php elseif ($couponError !== null): ?>
                    <p class="mt-2 text-xs text-red-700"><?= e($couponError) ?></p>
                <?php endif; ?>
                <p class="mt-2 text-xs text-slate-400">Coupon eligibility is re-checked server-side at order placement using the existing promotion service.</p>
            </section>
        </div>

        <?php /* ====== RIGHT: Order summary (E. Review) ====== */ ?>
        <aside class="lg:col-span-1">
            <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5 lg:sticky lg:top-24">
                <h2 class="text-base font-semibold text-navy-700">Order review</h2>

                <ul class="mt-4 space-y-3 text-sm max-h-72 overflow-y-auto">
                    <?php foreach ($cart['items'] as $item): ?>
                        <li class="flex items-start gap-3 pb-3 border-b border-slate-100 last:border-b-0">
                            <div class="w-10 h-10 shrink-0 rounded bg-slate-100 flex items-center justify-center text-slate-400">
                                <?= \App\Support\View::component('icon', ['name' => 'box', 'class' => 'h-5 w-5']) ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-navy-700 text-xs truncate"><?= e($item['brand_name'] . ' ' . $item['name']) ?></p>
                                <p class="text-[10px] text-slate-400 font-mono">SKU <?= e($item['sku']) ?> · G<?= e($item['condition_grade']) ?></p>
                            </div>
                            <p class="text-xs font-semibold text-navy-700 whitespace-nowrap"><?= money($item['unit_price']) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <dl class="mt-4 pt-4 border-t border-slate-200 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Subtotal (<?= $cart['count'] ?>)</dt><dd class="font-medium"><?= money($cart['total']) ?></dd></div>
                    <?php if ($discount > 0): ?>
                        <div class="flex justify-between"><dt class="text-slate-500">Discount</dt><dd class="font-medium text-brand-green">-<?= money($discount) ?></dd></div>
                    <?php endif; ?>
                    <div class="flex justify-between"><dt class="text-slate-500">Delivery</dt><dd class="font-medium"><?php if ($deliveryFee == 0): ?><span class="text-brand-green">Free</span><?php else: ?><?= money($deliveryFee) ?><?php endif; ?></dd></div>
                    <?php if (!$deliveryFeeConfirmed): ?>
                        <p class="text-xs text-slate-400">Delivery fee will be confirmed by the business.</p>
                    <?php endif; ?>
                </dl>

                <div class="mt-4 pt-4 border-t border-slate-200 flex items-baseline justify-between">
                    <dt class="text-sm font-semibold text-navy-700">Total payable</dt>
                    <dd class="text-2xl font-bold text-navy-700"><?= money($grandTotal) ?></dd>
                </div>
                <p class="mt-1 text-xs text-slate-400">Final total is recomputed server-side immediately before order creation. The form never trusts client-submitted totals.</p>

                <label class="mt-4 flex items-start gap-2 text-xs text-slate-600">
                    <input type="checkbox" name="confirm" value="1" required class="mt-0.5 text-brand-blue focus:ring-brand-blue">
                    <span>I confirm the order details above. I understand that order placement does not mark the order as paid — payment follows the selected method's workflow.</span>
                </label>

                <button type="submit" class="mt-4 w-full inline-flex items-center justify-center gap-2 rounded-lg bg-brand-blue px-6 py-3 text-sm font-semibold text-white hover:bg-blue-700 transition" data-submit-btn>
                    Place order
                    <?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 -rotate-90']) ?>
                </button>
                <p class="mt-2 text-center text-xs text-slate-400">By placing this order you agree to our <a href="<?= e(url('/page/terms')) ?>" class="hover:text-slate-600">terms</a> and <a href="<?= e(url('/page/privacy')) ?>" class="hover:text-slate-600">privacy policy</a>.</p>

                <?php if ($whatsapp !== ''): ?>
                    <p class="mt-3 pt-3 border-t border-slate-100 text-center text-xs text-slate-500">Need help? <a href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', $whatsapp)) ?>?text=<?= urlencode('Hi, I need help with checkout') ?>" target="_blank" rel="noopener" class="font-semibold text-brand-green hover:underline">Chat on WhatsApp</a></p>
                <?php endif; ?>
            </div>
        </aside>
    </form>
</div>

<script type="module">
    // Toggle delivery address section visibility based on selected method.
    const toggles = document.querySelectorAll('[data-delivery-toggle]');
    const addressBox = document.querySelector('[data-delivery-address]');
    const sync = () => {
        const selected = document.querySelector('[data-delivery-toggle]:checked');
        if (!addressBox || !selected) return;
        addressBox.style.display = selected.value === 'home_delivery' ? '' : 'none';
    };
    toggles.forEach((el) => el.addEventListener('change', sync));
    sync();

    // Disable submit button on click + change label to show loading.
    const form = document.querySelector('[data-loading-form]');
    const btn = document.querySelector('[data-submit-btn]');
    if (form && btn) {
        form.addEventListener('submit', () => {
            btn.disabled = true;
            btn.classList.add('opacity-60', 'cursor-not-allowed');
            btn.textContent = 'Placing order…';
        });
    }
</script>
