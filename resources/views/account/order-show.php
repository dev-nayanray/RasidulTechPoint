<?php /** @var array $order
 *  @var ?array $shipment
 *  @var ?array $activeInvoice
 *  @var list<array> $returns
 */
$stages = ['pending', 'confirmed', 'processing', 'completed'];
$currentIdx = array_search($order['status'], $stages, true);
$isCancelled = $order['status'] === 'cancelled';
$isRefunded = $order['status'] === 'refunded';
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/account/orders')) ?>" class="font-medium text-brand-blue hover:underline">← My Orders</a></p>
<h1 class="mt-2 text-xl font-bold tracking-tight text-navy-700">Order <?= e($order['reference']) ?></h1>
<p class="mt-1 text-xs text-slate-500">Placed <?= e(local_datetime($order['created_at'])) ?></p>

<?php /* Order progress tracker */ ?>
<?php if (!$isCancelled && !$isRefunded && $currentIdx !== false): ?>
<div class="mt-6 rounded-xl bg-white ring-1 ring-slate-200 p-4 sm:p-6">
    <div class="flex items-center justify-between gap-1">
        <?php foreach ($stages as $i => $stage): ?>
            <?php $done = $i <= $currentIdx; ?>
            <div class="flex-1 flex flex-col items-center text-center">
                <span class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold <?= $done ? 'bg-brand-blue text-white' : 'bg-slate-100 text-slate-400' ?>">
                    <?= $done ? '✓' : ($i + 1) ?>
                </span>
                <span class="mt-1.5 text-[10px] font-medium <?= $done ? 'text-navy-700' : 'text-slate-400' ?>"><?= e(ucfirst($stage)) ?></span>
            </div>
            <?php if ($i < count($stages) - 1): ?>
                <div class="flex-1 h-0.5 <?= $i < $currentIdx ? 'bg-brand-blue' : 'bg-slate-200' ?> mt-[-12px]"></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php elseif ($isCancelled): ?>
<div class="mt-6 rounded-lg bg-red-50 ring-1 ring-red-200 px-4 py-3 text-sm text-red-700">This order was cancelled.</div>
<?php elseif ($isRefunded): ?>
<div class="mt-6 rounded-lg bg-orange-50 ring-1 ring-orange-200 px-4 py-3 text-sm text-orange-700">This order was refunded.</div>
<?php endif; ?>

<?php /* Status badges */ ?>
<div class="mt-4 flex flex-wrap gap-2">
    <?= \App\Support\View::component('badge', ['status' => $order['status']]) ?>
    <?= \App\Support\View::component('badge', ['status' => $order['payment_status']]) ?>
    <?php if ($order['delivery'] ?? null): ?>
        <?= \App\Support\View::component('badge', ['status' => $order['delivery']['method'] ?? '']) ?>
    <?php endif; ?>
</div>

<div class="mt-4 grid gap-6 lg:grid-cols-3">
    <?php /* Items */ ?>
    <div class="lg:col-span-2 space-y-4">
        <div class="rounded-xl bg-white ring-1 ring-slate-200 p-4 sm:p-6">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Items</h2>
            <ul class="mt-3 divide-y divide-slate-100">
                <?php foreach (($order['items'] ?? []) as $item): ?>
                    <li class="py-3 flex items-start gap-4">
                        <div class="w-12 h-12 shrink-0 rounded bg-slate-100 flex items-center justify-center text-slate-400">
                            <?= \App\Support\View::component('icon', ['name' => 'box', 'class' => 'h-6 w-6']) ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-navy-700 text-sm"><?= e($item['product_name']) ?></p>
                            <p class="text-xs text-slate-500 font-mono">SKU <?= e($item['sku']) ?><?php if (!empty($item['serial_number'])): ?> · SN <?= e($item['serial_number']) ?><?php endif; ?><?php if (!empty($item['condition_grade'])): ?> · G<?= e($item['condition_grade']) ?><?php endif; ?></p>
                        </div>
                        <p class="text-sm font-semibold text-navy-700 whitespace-nowrap"><?= money($item['line_total']) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php /* Shipment tracking */ ?>
        <?php if ($shipment !== null): ?>
        <div class="rounded-xl bg-white ring-1 ring-slate-200 p-4 sm:p-6">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Shipment</h2>
            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <div><dt class="text-xs text-slate-400">Courier</dt><dd class="text-navy-700"><?= e($shipment['tracking_reference'] ?? '—') ?></dd></div>
                <div><dt class="text-xs text-slate-400">Tracking</dt><dd class="text-navy-700"><?= e($shipment['tracking_url'] ?? '—') ?></dd></div>
                <div><dt class="text-xs text-slate-400">Status</dt><dd><?= \App\Support\View::component('badge', ['status' => $shipment['status']]) ?></dd></div>
                <div><dt class="text-xs text-slate-400">Fee</dt><dd class="text-navy-700"><?= money($shipment['fee']) ?></dd></div>
            </dl>
        </div>
        <?php endif; ?>
    </div>

    <?php /* Summary + delivery + invoice */ ?>
    <aside class="space-y-4">
        <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Summary</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">Subtotal</dt><dd class="font-medium text-navy-700"><?= money($order['subtotal']) ?></dd></div>
                <?php if ((float) $order['discount'] > 0): ?>
                    <div class="flex justify-between"><dt class="text-slate-500">Discount</dt><dd class="font-medium text-brand-green">-<?= money($order['discount']) ?></dd></div>
                <?php endif; ?>
                <?php if ($order['delivery'] ?? null): ?>
                    <div class="flex justify-between"><dt class="text-slate-500">Delivery</dt><dd class="font-medium text-navy-700"><?= (float) ($order['delivery']['fee'] ?? 0) === 0.0 ? 'Free' : money($order['delivery']['fee']) ?></dd></div>
                <?php endif; ?>
            </dl>
            <div class="mt-3 pt-3 border-t border-slate-200 flex justify-between items-baseline">
                <dt class="text-sm font-semibold text-navy-700">Total</dt>
                <dd class="text-xl font-bold text-navy-700"><?= money($order['total']) ?></dd>
            </div>
        </div>

        <?php /* Delivery address */ ?>
        <?php if ($order['delivery'] ?? null): ?>
        <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400"><?= ($order['delivery']['method'] ?? '') === 'store_pickup' ? 'Pickup' : 'Delivery address' ?></h2>
            <dl class="mt-3 space-y-1 text-xs text-slate-600">
                <div><dt class="inline text-slate-400">Name:</dt> <dd class="inline text-navy-700"><?= e($order['delivery']['name'] ?? '') ?></dd></div>
                <div><dt class="inline text-slate-400">Phone:</dt> <dd class="inline text-navy-700"><?= e($order['delivery']['phone'] ?? '') ?></dd></div>
                <?php if (!empty($order['delivery']['address'])): ?><div><dt class="inline text-slate-400">Address:</dt> <dd class="inline text-navy-700"><?= e($order['delivery']['address']) ?></dd></div><?php endif; ?>
                <?php if (!empty($order['delivery']['city'])): ?><div><dt class="inline text-slate-400">City:</dt> <dd class="inline text-navy-700"><?= e($order['delivery']['city']) ?></dd></div><?php endif; ?>
            </dl>
        </div>
        <?php endif; ?>

        <?php /* Invoice */ ?>
        <?php if ($activeInvoice !== null): ?>
        <a href="<?= e(url('/admin/invoices/' . $activeInvoice['id'])) ?>" class="block rounded-xl bg-navy-700 ring-1 ring-navy-800 p-4 hover:bg-navy-800 transition">
            <div class="flex items-center gap-3 text-white">
                <?= \App\Support\View::component('icon', ['name' => 'file-text', 'class' => 'h-5 w-5']) ?>
                <div>
                    <p class="text-sm font-semibold">Invoice <?= e($activeInvoice['invoice_number']) ?></p>
                    <p class="text-xs text-slate-300"><?= e(ucfirst($activeInvoice['status'] ?? 'issued')) ?></p>
                </div>
            </div>
        </a>
        <?php endif; ?>
    </aside>
</div>
