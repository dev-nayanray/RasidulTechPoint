<h1 class="text-2xl font-bold tracking-tight text-slate-900">My orders</h1>

<?php if (($orders ?? []) === []): ?>
    <div class="mt-6"><?= \App\Support\View::component('empty-state', [
        'title' => 'No orders yet',
        'description' => 'When you buy a laptop, your orders and their status will appear here.',
        'icon' => 'clipboard',
    ]) ?></div>
<?php else: ?>
    <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Reference</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Date</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Payment</th>
                    <th class="px-4 py-3 text-right font-medium text-slate-500">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($orders as $order): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="<?= e(url('/account/orders/' . $order['reference'])) ?>" class="font-medium text-blue-700 hover:text-blue-800"><?= e($order['reference']) ?></a>
                        </td>
                        <td class="px-4 py-3 text-slate-500"><?= e(local_datetime($order['created_at'])) ?></td>
                        <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $order['status']]) ?></td>
                        <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $order['payment_status']]) ?></td>
                        <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money($order['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
