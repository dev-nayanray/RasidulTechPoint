<?php /** @var array $cart  // {items, total, count, has_unavailable, has_price_change}
 *  @var ?float $deliveryEstimate
 *  @var string $deliveryBasis
 *  @var float $estimatedGrandTotal
 *  @var float $freeShippingThreshold
 */
?>
<nav class="text-xs text-slate-500 px-4 sm:px-6" aria-label="Breadcrumb">
    <ol class="mx-auto max-w-7xl flex items-center gap-1.5 py-4">
        <li><a href="<?= e(url('/')) ?>" class="hover:text-brand-blue">Home</a></li>
        <li aria-hidden="true">/</li>
        <li><a href="<?= e(url('/shop')) ?>" class="hover:text-brand-blue">Shop</a></li>
        <li aria-hidden="true">/</li>
        <li aria-current="page" class="font-medium text-navy-700">Cart</li>
    </ol>
</nav>

<div class="mx-auto max-w-7xl px-4 sm:px-6 pb-12">
    <h1 class="text-2xl font-bold tracking-tight text-navy-700">Shopping cart</h1>

    <?php if ($cart['count'] === 0): ?>
        <?php /* ====== Empty cart state ====== */ ?>
        <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <span class="inline-flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                <?= \App\Support\View::component('icon', ['name' => 'shopping-cart', 'class' => 'h-7 w-7']) ?>
            </span>
            <p class="mt-4 text-lg font-semibold text-navy-700">Your cart is empty</p>
            <p class="mt-1 text-sm text-slate-500 max-w-md mx-auto">Browse our inventory of Dubai-imported, quality-checked laptops. Each unit is individually inspected — when you add one to your cart, you're choosing the actual laptop you'll receive.</p>
            <a href="<?= e(url('/shop')) ?>" class="mt-5 inline-flex items-center gap-2 rounded-lg bg-brand-blue px-6 py-3 text-sm font-semibold text-white hover:bg-blue-700 transition">
                Shop Laptops
                <?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 -rotate-90']) ?>
            </a>
        </div>
    <?php else: ?>

        <?php /* ====== Unavailable / price-change notices ====== */ ?>
        <?php if ($cart['has_unavailable']): ?>
            <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <strong>Some items are no longer available.</strong> They're marked below — please remove them before checking out. We don't reserve laptops just because they're in your cart; another customer may have purchased one in the meantime.
                <form method="post" action="<?= e(url('/cart/remove')) ?>" class="mt-2 inline">
                    <?= csrf_field() ?>
                    <?php foreach ($cart['items'] as $item): ?>
                        <?php if (empty($item['available'])): ?>
                            <input type="hidden" name="unit_id" value="<?= (int) $item['unit_id'] ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($cart['has_price_change']): ?>
            <div class="mt-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                <strong>Some prices have changed.</strong> The current live price is shown next to each item. Accept the new prices to continue:
                <form method="post" action="<?= e(url('/cart/sync-prices')) ?>" class="mt-2 inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="inline-flex items-center rounded-lg bg-brand-blue px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">Accept new prices</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="mt-6 grid gap-8 lg:grid-cols-3">

            <?php /* ====== Cart items ====== */ ?>
            <section class="lg:col-span-2 space-y-4">
                <?php foreach ($cart['items'] as $item): ?>
                    <article class="rounded-xl bg-white ring-1 ring-slate-200 p-4 sm:p-5 <?= empty($item['available']) ? 'opacity-75' : '' ?>">
                        <div class="flex gap-4">
                            <?php
                            $img = trim((string) ($item['image_path'] ?? ''));
                            $imgOk = $img !== '' && is_file(BASE_PATH . '/public/' . $img);
                            ?>
                            <?php if ($imgOk): ?>
                            <img src="<?= e(url($img)) ?>" alt="Illustrative photo of <?= e($item['brand_name'] . ' ' . $item['name']) ?> — not the exact unit"
                                 class="w-24 h-24 sm:w-32 sm:h-32 object-cover rounded-lg ring-1 ring-slate-200 shrink-0">
                            <?php else: ?>
                            <div class="w-24 h-24 sm:w-32 sm:h-32 shrink-0 rounded-lg bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center text-slate-400">
                                <?= \App\Support\View::component('icon', ['name' => 'box', 'class' => 'h-10 w-10']) ?>
                            </div>
                            <?php endif; ?>

                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wider text-brand-blue"><?= e($item['brand_name']) ?></p>
                                <p class="mt-0.5 font-semibold text-navy-700 leading-snug"><?= e($item['name']) ?></p>

                                <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-slate-500">
                                    <div><dt class="inline text-slate-400">SKU:</dt> <dd class="inline font-mono text-slate-700"><?= e($item['sku']) ?></dd></div>
                                    <div><dt class="inline text-slate-400">Grade:</dt> <dd class="inline font-medium text-navy-700">G<?= e($item['condition_grade']) ?></dd></div>
                                    <div><dt class="inline text-slate-400">Processor:</dt> <dd class="inline text-slate-700"><?= e($item['processor'] ?? '—') ?></dd></div>
                                    <div><dt class="inline text-slate-400">Memory:</dt> <dd class="inline text-slate-700"><?= $item['ram_gb'] ? e($item['ram_gb'] . ' GB') : '—' ?></dd></div>
                                    <div class="col-span-2"><dt class="inline text-slate-400">Storage:</dt> <dd class="inline text-slate-700"><?= $item['storage_capacity_gb'] ? e($item['storage_capacity_gb'] . ' GB ' . $item['storage_type']) : '—' ?></dd></div>
                                </dl>

                                <?php if (empty($item['available'])): ?>
                                    <p class="mt-2 text-xs font-semibold text-red-700">⚠ <?= e($item['unavailable_reason'] ?? 'Unavailable') ?></p>
                                <?php endif; ?>
                                <?php if (!empty($item['price_changed'])): ?>
                                    <p class="mt-2 text-xs font-semibold text-amber-700">Price changed: was <?= money($item['unit_price']) ?>, now <?= money($item['current_price']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-[10px] uppercase text-slate-400">Unit price</p>
                                <p class="text-lg font-bold text-navy-700">
                                    <?= money(!empty($item['available']) && !empty($item['price_changed']) ? $item['current_price'] : $item['unit_price']) ?>
                                </p>
                            </div>
                            <form method="post" action="<?= e(url('/cart/remove')) ?>" data-loading-form data-confirm="Remove this laptop from your cart?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="unit_id" value="<?= (int) $item['unit_id'] ?>">
                                <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:text-red-700 hover:border-red-200">
                                    <?= \App\Support\View::component('icon', ['name' => 'trash', 'class' => 'h-4 w-4']) ?>
                                    Remove
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>

                <div class="flex flex-wrap gap-2">
                    <a href="<?= e(url('/shop')) ?>" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-slate-50">
                        <?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 rotate-90']) ?>
                        Continue shopping
                    </a>
                    <form method="post" action="<?= e(url('/cart/clear')) ?>" class="inline" data-loading-form data-confirm="Clear all items from your cart?">
                        <?= csrf_field() ?>
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:text-red-700 hover:border-red-200">
                            <?= \App\Support\View::component('icon', ['name' => 'trash', 'class' => 'h-4 w-4']) ?>
                            Clear cart
                        </button>
                    </form>
                </div>
            </section>

            <?php /* ====== Order summary ====== */ ?>
            <aside class="lg:col-span-1">
                <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5 lg:sticky lg:top-24">
                    <h2 class="text-base font-semibold text-navy-700">Order summary</h2>

                    <dl class="mt-4 space-y-2.5 text-sm">
                        <div class="flex items-center justify-between">
                            <dt class="text-slate-500"><?= $cart['count'] ?> item(s) subtotal</dt>
                            <dd class="font-semibold text-navy-700"><?= money($cart['total']) ?></dd>
                        </div>

                        <div class="flex items-center justify-between">
                            <dt class="text-slate-500">Estimated delivery</dt>
                            <dd class="font-medium">
                                <?php if ($deliveryEstimate === null): ?>
                                    <span class="text-slate-500">Confirmed at checkout</span>
                                <?php elseif ($deliveryEstimate == 0): ?>
                                    <span class="text-brand-green">Free</span>
                                <?php else: ?>
                                    <?= money($deliveryEstimate) ?>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <p class="text-xs text-slate-400"><?= e($deliveryBasis) ?></p>
                    </dl>

                    <div class="mt-4 pt-4 border-t border-slate-200 flex items-baseline justify-between">
                        <dt class="text-sm font-semibold text-navy-700">Estimated total</dt>
                        <dd class="text-2xl font-bold text-navy-700"><?= money($estimatedGrandTotal) ?></dd>
                    </div>

                    <p class="mt-3 text-xs text-slate-400">All prices in BDT. Final delivery fee + any applicable discount are calculated at checkout based on live inventory prices.</p>

                    <?php if ($cart['has_unavailable']): ?>
                        <div class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            Please remove unavailable items before proceeding to checkout.
                        </div>
                        <button type="button" disabled class="mt-4 w-full inline-flex items-center justify-center gap-2 rounded-lg bg-slate-300 px-6 py-3 text-sm font-semibold text-slate-500 cursor-not-allowed">
                            Proceed to checkout
                        </button>
                    <?php else: ?>
                        <a href="<?= e(url('/checkout')) ?>" class="mt-4 w-full inline-flex items-center justify-center gap-2 rounded-lg bg-brand-blue px-6 py-3 text-sm font-semibold text-white hover:bg-blue-700 transition">
                            Proceed to checkout
                            <?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 -rotate-90']) ?>
                        </a>
                    <?php endif; ?>

                    <p class="mt-3 text-center text-xs text-slate-400">
                        <a href="<?= e(url('/page/returns')) ?>" class="hover:text-slate-600">Return policy</a> · <a href="<?= e(url('/page/warranty')) ?>" class="hover:text-slate-600">Warranty</a>
                    </p>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</div>
