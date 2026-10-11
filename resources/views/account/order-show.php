<?php /** @var array $order */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/account/orders')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← All my orders</a></p>

<div class="mt-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Order <?= e($order['reference']) ?></h1>
    <div class="flex gap-2">
        <?= \App\Support\View::component('badge', ['status' => $order['status']]) ?>
        <?= \App\Support\View::component('badge', ['status' => $order['payment_status']]) ?>
    </div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Item</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">SKU</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Grade</th>
                    <th class="px-4 py-3 text-right font-medium text-slate-500">Price</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($order['items'] as $item): ?>
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900"><?= e($item['product_name']) ?></td>
                        <td class="px-4 py-3 text-slate-500"><?= e($item['sku']) ?></td>
                        <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $item['condition_grade'] ?? '—']) ?></td>
                        <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money($item['unit_price']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-slate-50">
                <tr><td colspan="3" class="px-4 py-2 text-right text-slate-500">Subtotal</td><td class="px-4 py-2 text-right font-medium"><?= money($order['subtotal']) ?></td></tr>
                <?php if ((float) $order['discount'] > 0): ?>
                    <tr><td colspan="3" class="px-4 py-2 text-right text-slate-500">Discount</td><td class="px-4 py-2 text-right font-medium text-green-700">-<?= money($order['discount']) ?></td></tr>
                <?php endif; ?>
                <tr><td colspan="3" class="px-4 py-2 text-right font-semibold text-slate-700">Total</td><td class="px-4 py-2 text-right text-base font-bold text-slate-900"><?= money($order['total']) ?></td></tr>
            </tfoot>
        </table>
    </div>

    <div class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Details</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Placed</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($order['created_at'])) ?></dd></div>
                <?php if ($order['completed_at']): ?>
                    <div><dt class="text-slate-500">Completed</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($order['completed_at'])) ?></dd></div>
                <?php endif; ?>
                <div><dt class="text-slate-500">Channel</dt><dd class="font-medium text-slate-900 capitalize"><?= e($order['channel']) ?></dd></div>
            </dl>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Payments</h2>
            <?php if ($order['payments'] === []): ?>
                <p class="mt-2 text-slate-500">No payments recorded yet.</p>
            <?php else: ?>
                <ul class="mt-3 space-y-2">
                    <?php foreach ($order['payments'] as $payment): ?>
                        <li class="flex items-center justify-between">
                            <span class="capitalize"><?= e(str_replace('_', ' ', $payment['method'])) ?></span>
                            <span class="font-semibold"><?= money($payment['amount']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
