<?php /** @var array $order
 *  @var ?array $paymentMethod
 *  @var string $whatsapp
 */
$delivery = $order['delivery'] ?? null;
?>
<div class="mx-auto max-w-3xl px-4 sm:px-6 py-10">

    <?php /* Success banner */ ?>
    <div class="rounded-2xl bg-brand-green/10 ring-1 ring-brand-green/20 p-6 sm:p-8 text-center">
        <span class="inline-flex h-14 w-14 items-center justify-center rounded-full bg-brand-green text-white">
            <?= \App\Support\View::component('icon', ['name' => 'check-circle', 'class' => 'h-7 w-7']) ?>
        </span>
        <h1 class="mt-4 text-2xl sm:text-3xl font-bold tracking-tight text-navy-700">Order confirmed</h1>
        <p class="mt-2 text-sm text-slate-600 max-w-md mx-auto">Thank you. Your order has been received. We've reserved the laptops in your name — payment follows the method you selected.</p>
    </div>

    <?php /* Order card */ ?>
    <div class="mt-6 rounded-2xl bg-white ring-1 ring-slate-200 overflow-hidden">
        <div class="px-5 sm:px-6 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-wider text-slate-400">Order reference</p>
                <p class="font-mono text-lg font-bold text-navy-700"><?= e($order['reference']) ?></p>
            </div>
            <div class="text-right">
                <p class="text-xs uppercase tracking-wider text-slate-400">Placed</p>
                <p class="text-sm text-slate-600"><?= e(local_datetime($order['created_at'])) ?></p>
            </div>
        </div>

        <?php /* Items */ ?>
        <div class="px-5 sm:px-6 py-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Items</p>
            <ul class="mt-3 divide-y divide-slate-100">
                <?php foreach (($order['items'] ?? []) as $item): ?>
                    <li class="py-3 flex items-start gap-4">
                        <div class="w-12 h-12 shrink-0 rounded bg-slate-100 flex items-center justify-center text-slate-400">
                            <?= \App\Support\View::component('icon', ['name' => 'box', 'class' => 'h-6 w-6']) ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-navy-700 text-sm"><?= e($item['product_name']) ?></p>
                            <p class="text-xs text-slate-500 font-mono">SKU <?= e($item['sku']) ?> · <?= e($item['condition_grade'] ?? '') ?><?= !empty($item['serial_number']) ? ' · SN ' . e($item['serial_number']) : '' ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-semibold text-navy-700"><?= money($item['line_total']) ?></p>
                            <p class="text-[10px] text-slate-400">Unit <?= money($item['unit_price']) ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php /* Totals */ ?>
        <div class="px-5 sm:px-6 py-4 border-t border-slate-100 bg-slate-50">
            <dl class="space-y-1.5 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">Subtotal</dt><dd class="font-medium text-navy-700"><?= money($order['subtotal']) ?></dd></div>
                <?php if ((float) $order['discount'] > 0): ?>
                    <div class="flex justify-between"><dt class="text-slate-500">Discount</dt><dd class="font-medium text-brand-green">-<?= money($order['discount']) ?></dd></div>
                <?php endif; ?>
                <?php if ($delivery !== null && isset($delivery['fee'])): ?>
                    <div class="flex justify-between"><dt class="text-slate-500">Delivery</dt><dd class="font-medium text-navy-700"><?= (float) $delivery['fee'] === 0.0 ? 'Free' : money($delivery['fee']) ?></dd></div>
                <?php endif; ?>
            </dl>
            <div class="mt-3 pt-3 border-t border-slate-200 flex items-baseline justify-between">
                <dt class="text-base font-semibold text-navy-700">Total</dt>
                <dd class="text-2xl font-bold text-navy-700"><?= money($order['total']) ?></dd>
            </div>
        </div>

        <?php /* Status badges */ ?>
        <div class="px-5 sm:px-6 py-4 border-t border-slate-100 flex flex-wrap gap-2 text-xs">
            <span class="inline-flex items-center rounded-full bg-amber-50 text-amber-700 px-2.5 py-1 font-semibold ring-1 ring-amber-200">Order: <?= e(ucfirst($order['status'])) ?></span>
            <span class="inline-flex items-center rounded-full bg-red-50 text-red-700 px-2.5 py-1 font-semibold ring-1 ring-red-200">Payment: <?= e(ucfirst($order['payment_status'])) ?></span>
            <?php if ($delivery !== null): ?>
                <span class="inline-flex items-center rounded-full bg-blue-50 text-brand-blue px-2.5 py-1 font-semibold ring-1 ring-blue-200">Delivery: <?= e(ucfirst(str_replace('_', ' ', $delivery['method']))) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <?php /* Payment instructions */ ?>
    <?php if ($paymentMethod !== null && !empty($paymentMethod['instructions'])): ?>
        <div class="mt-4 rounded-xl bg-white ring-1 ring-slate-200 p-5">
            <h2 class="text-sm font-semibold text-navy-700">How to pay with <?= e($paymentMethod['label']) ?></h2>
            <p class="mt-2 text-sm text-slate-600 whitespace-pre-wrap"><?= e($paymentMethod['instructions']) ?></p>
            <p class="mt-3 text-xs text-slate-400">Payment is verified by the business per the existing payment state machine. Order placement does not mark the order as paid.</p>
        </div>
    <?php endif; ?>

    <?php /* Delivery details */ ?>
    <?php if ($delivery !== null): ?>
        <div class="mt-4 rounded-xl bg-white ring-1 ring-slate-200 p-5">
            <h2 class="text-sm font-semibold text-navy-700"><?= $delivery['method'] === 'store_pickup' ? 'Store pickup' : 'Delivery address' ?></h2>
            <?php if ($delivery['method'] === 'store_pickup'): ?>
                <p class="mt-2 text-sm text-slate-600">Pick up from our store. Our team will contact you at <?= e($delivery['phone']) ?> when your laptop is ready for collection.</p>
            <?php else: ?>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                    <div><dt class="text-xs text-slate-400">Name</dt><dd class="text-navy-700"><?= e($delivery['name']) ?></dd></div>
                    <div><dt class="text-xs text-slate-400">Phone</dt><dd class="text-navy-700"><?= e($delivery['phone']) ?></dd></div>
                    <?php if (!empty($delivery['email'])): ?><div><dt class="text-xs text-slate-400">Email</dt><dd class="text-navy-700"><?= e($delivery['email']) ?></dd></div><?php endif; ?>
                    <div class="col-span-2"><dt class="text-xs text-slate-400">Address</dt><dd class="text-navy-700"><?= e(trim(($delivery['address'] ?? '') . ', ' . ($delivery['area'] ?? '') . ', ' . ($delivery['city'] ?? '') . ' ' . ($delivery['postal_code'] ?? ''), ', ')) ?></dd></div>
                    <?php if (!empty($delivery['instructions'])): ?><div class="col-span-2"><dt class="text-xs text-slate-400">Instructions</dt><dd class="text-navy-700"><?= e($delivery['instructions']) ?></dd></div><?php endif; ?>
                </dl>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php /* Next steps + actions */ ?>
    <div class="mt-6 rounded-xl bg-slate-50 ring-1 ring-slate-200 p-5 text-center">
        <h2 class="text-sm font-semibold text-navy-700">Next steps</h2>
        <ul class="mt-2 text-sm text-slate-600 space-y-1.5 list-disc list-inside max-w-md mx-auto text-left">
            <li>Our team will contact you within one business day to confirm delivery / pickup.</li>
            <li>You'll receive updates as your order moves through fulfilment.</li>
            <?php if (current_user()): ?>
                <li>Track this order anytime from your <a href="<?= e(url('/account')) ?>" class="text-brand-blue hover:underline">account</a>.</li>
            <?php endif; ?>
        </ul>
        <div class="mt-4 flex flex-wrap items-center justify-center gap-3">
            <a href="<?= e(url('/shop')) ?>" class="inline-flex items-center rounded-lg bg-brand-blue px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">Continue shopping</a>
            <?php if (current_user()): ?>
                <a href="<?= e(url('/account/orders/' . $order['reference'])) ?>" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-navy-700 hover:bg-slate-50">View order details</a>
            <?php endif; ?>
            <?php if ($whatsapp !== ''): ?>
                <a href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', $whatsapp)) ?>?text=<?= urlencode('Hi, I just placed order ' . $order['reference']) ?>" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg border border-brand-green text-brand-green px-5 py-2.5 text-sm font-semibold hover:bg-brand-green/5">WhatsApp us</a>
            <?php endif; ?>
        </div>
    </div>
</div>
