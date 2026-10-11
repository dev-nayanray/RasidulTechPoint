<?php /** @var array $customer */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/customers')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Customers</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= e($customer['name']) ?></h1>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Contact</h2>
        <dl class="mt-3 space-y-2">
            <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($customer['phone']) ?></dd></div>
            <div><dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-900"><?= e($customer['email'] ?? $customer['account_email'] ?? '—') ?></dd></div>
            <div><dt class="text-slate-500">Address</dt><dd class="font-medium text-slate-900"><?= e($customer['address'] ?? '—') ?><?= !empty($customer['city']) ? ', ' . e($customer['city']) : '' ?></dd></div>
            <div><dt class="text-slate-500">Customer since</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($customer['created_at'])) ?></dd></div>
            <?php if (!empty($customer['notes'])): ?><div><dt class="text-slate-500">Notes</dt><dd class="text-slate-700"><?= e($customer['notes']) ?></dd></div><?php endif; ?>
        </dl>
        <?php if (\App\Services\AuthService::can('orders.manage')): ?>
            <a href="<?= e(url('/admin/orders/create')) ?>" class="btn-primary mt-4 w-full">New order for this customer</a>
        <?php endif; ?>
    </div>

    <div class="lg:col-span-2 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <h2 class="border-b border-slate-200 px-5 py-4 text-sm font-semibold text-slate-900">Recent orders</h2>
        <?php if ($customer['orders'] === []): ?>
            <p class="px-5 py-10 text-center text-sm text-slate-400">No orders for this customer yet.</p>
        <?php else: ?>
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <tbody>
                    <?php foreach ($customer['orders'] as $order): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5"><a class="font-medium text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/orders/' . $order['id'])) ?>"><?= e($order['reference']) ?></a></td>
                            <td class="px-4 py-2.5"><?= \App\Support\View::component('badge', ['status' => $order['status']]) ?></td>
                            <td class="px-4 py-2.5"><?= \App\Support\View::component('badge', ['status' => $order['payment_status']]) ?></td>
                            <td class="px-4 py-2.5 text-slate-500"><?= e(local_datetime($order['created_at'])) ?></td>
                            <td class="px-4 py-2.5 text-right font-semibold text-slate-900"><?= money($order['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
