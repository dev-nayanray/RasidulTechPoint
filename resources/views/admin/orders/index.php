<?php /** @var list<array> $orders */ ?>
<form method="get" action="<?= e(url('/admin/orders')) ?>" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4" role="search">
    <div class="sm:col-span-2 space-y-1.5">
        <label for="orders-q" class="block text-sm font-medium text-slate-700">Search</label>
        <input id="orders-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Reference, customer or phone…">
    </div>
    <?= \App\Support\View::component('select', [
        'name' => 'status', 'label' => 'Status', 'value' => $status,
        'options' => array_merge(['' => 'All statuses'], array_combine($orderStatuses, array_map(
            fn ($s) => ucfirst(str_replace('_', ' ', $s)), $orderStatuses
        ))),
    ]) ?>
    <?= \App\Support\View::component('select', [
        'name' => 'payment', 'label' => 'Payment', 'value' => $payment,
        'options' => array_merge(['' => 'All'], array_combine($paymentStatuses, array_map(
            fn ($s) => ucfirst($s), $paymentStatuses
        ))),
    ]) ?>
    <div class="sm:col-span-4 flex gap-2">
        <button type="submit" class="btn-primary">Apply filters</button>
        <a href="<?= e(url('/admin/orders')) ?>" class="btn-secondary">Reset</a>
        <?php if (\App\Services\AuthService::can('orders.manage')): ?>
            <a href="<?= e(url('/admin/orders/create')) ?>" class="btn-primary ml-auto">New order</a>
        <?php endif; ?>
    </div>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Reference</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Customer</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Channel</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Payment</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Placed</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Total</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($orders as $order): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3"><a class="font-medium text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/orders/' . $order['id'])) ?>"><?= e($order['reference']) ?></a></td>
                    <td class="px-4 py-3 text-slate-700"><?= e($order['customer_name']) ?><span class="block text-xs text-slate-400"><?= e($order['customer_phone']) ?></span></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $order['channel']]) ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $order['status']]) ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $order['payment_status']]) ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= e(local_datetime($order['created_at'])) ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money($order['total']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($orders === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No orders match your filters.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/orders', 'queryParams' => array_filter(['q' => $q, 'status' => $status, 'payment' => $payment])]) ?>
</div>
