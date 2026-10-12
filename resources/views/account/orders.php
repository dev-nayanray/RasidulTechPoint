<?php /** @var list<array> $orders */
?>
<div class="flex items-center justify-between">
    <h1 class="text-xl font-bold tracking-tight text-navy-700">My Orders</h1>
</div>

<?php if ($orders === []): ?>
    <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
        <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
            <?= \App\Support\View::component('icon', ['name' => 'clipboard', 'class' => 'h-6 w-6']) ?>
        </span>
        <p class="mt-4 font-semibold text-navy-700">No orders yet</p>
        <p class="mt-1 text-sm text-slate-500">When you place your first order, it will appear here.</p>
        <a href="<?= e(url('/shop')) ?>" class="mt-4 inline-flex items-center gap-2 rounded-lg bg-brand-blue px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">Browse Laptops</a>
    </div>
<?php else: ?>
    <div class="mt-4 space-y-3">
        <?php foreach ($orders as $order): ?>
            <a href="<?= e(url('/account/orders/' . $order['reference'])) ?>" class="block rounded-xl bg-white ring-1 ring-slate-200 p-4 hover:ring-brand-blue transition">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="font-mono text-sm font-semibold text-navy-700"><?= e($order['reference']) ?></p>
                        <p class="text-xs text-slate-500"><?= e(local_datetime($order['created_at'])) ?></p>
                    </div>
                    <div class="text-right">
                        <p class="font-bold text-navy-700"><?= money($order['total']) ?></p>
                        <div class="mt-1 flex gap-1.5 justify-end">
                            <?= \App\Support\View::component('badge', ['status' => $order['status']]) ?>
                            <?= \App\Support\View::component('badge', ['status' => $order['payment_status']]) ?>
                        </div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
