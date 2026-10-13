<?php /** @var list<array> $orders
 *  @var list<string> $orderStatuses
 *  @var list<string> $paymentStatuses
 */
?>
<div class="admin-page-header">
    <div class="admin-page-header-title">
        <h2>Orders</h2>
        <p class="admin-page-header-sub">Customer orders from the storefront and POS. Filter by status, payment, channel, or date range.</p>
    </div>
    <div class="admin-page-header-actions">
        <?php if (\App\Services\AuthService::can('orders.manage')): ?>
            <a href="<?= e(url('/admin/orders/create')) ?>" class="btn-primary">+ New order</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" action="<?= e(url('/admin/orders')) ?>" class="admin-filter-bar mt-4 sm:grid-cols-6" role="search">
    <div class="sm:col-span-3 space-y-1.5">
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
    <?= \App\Support\View::component('select', [
        'name' => 'channel', 'label' => 'Channel', 'value' => $channel,
        'options' => ['' => 'All channels', 'online' => 'Online', 'pos' => 'POS'],
    ]) ?>
    <div class="space-y-1.5">
        <label for="orders-from" class="block text-sm font-medium text-slate-700">From</label>
        <input id="orders-from" type="date" name="from" value="<?= e($from) ?>" class="input">
    </div>
    <div class="space-y-1.5">
        <label for="orders-to" class="block text-sm font-medium text-slate-700">To</label>
        <input id="orders-to" type="date" name="to" value="<?= e($to) ?>" class="input">
    </div>
    <div class="sm:col-span-6 flex flex-wrap gap-2">
        <button type="submit" class="btn-primary">Apply filters</button>
        <a href="<?= e(url('/admin/orders')) ?>" class="btn-secondary">Reset</a>
        <?php if (\App\Services\AuthService::can('orders.manage')): ?>
            <!-- New order button moved to page header -->
        <?php endif; ?>
    </div>
</form>

<div class="admin-card mt-4">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th>Reference</th>
                <th>Customer</th>
                <th>Channel</th>
                <th>Status</th>
                <th>Payment</th>
                <th>Placed</th>
                <th class="num">Total</th>
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
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/orders', 'queryParams' => array_filter(['q' => $q, 'status' => $status, 'payment' => $payment, 'channel' => $channel, 'from' => $from, 'to' => $to])]) ?>
    </div>
</div>
