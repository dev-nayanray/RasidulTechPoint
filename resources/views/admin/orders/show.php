<?php /** @var array $order */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/orders')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Orders</a></p>

<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Order <?= e($order['reference']) ?></h1>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <?= \App\Support\View::component('badge', ['status' => $order['status']]) ?>
            <?= \App\Support\View::component('badge', ['status' => $order['payment_status']]) ?>
            <?= \App\Support\View::component('badge', ['status' => $order['channel']]) ?>
        </p>
    </div>
    <?php if (\App\Services\AuthService::can('orders.manage')): ?>
        <form method="post" action="<?= e(url('/admin/orders/' . $order['id'] . '/status')) ?>" class="flex items-end gap-2" data-loading-form data-confirm="Change order status?">
            <?= csrf_field() ?>
            <div class="space-y-1">
                <label for="order-status" class="block text-xs font-medium text-slate-500">Update status</label>
                <select id="order-status" name="status" class="input">
                    <?php foreach (\App\Services\OrderService::ORDER_STATUSES as $s): ?>
                        <option value="<?= e($s) ?>" <?= $s === $order['status'] ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-secondary">Apply</button>
        </form>
    <?php endif; ?>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-900">Items (sale-time snapshots)</h2>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-slate-500">Product</th>
                        <th class="px-4 py-3 text-left font-medium text-slate-500">SKU</th>
                        <th class="px-4 py-3 text-left font-medium text-slate-500">Serial</th>
                        <th class="px-4 py-3 text-left font-medium text-slate-500">Grade</th>
                        <th class="px-4 py-3 text-right font-medium text-slate-500">Unit price</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($order['items'] as $item): ?>
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-900"><?= e($item['product_name']) ?></td>
                            <td class="px-4 py-3 text-slate-500"><?= e($item['sku']) ?></td>
                            <td class="px-4 py-3 text-slate-500"><?= e($item['serial_number'] ?? '—') ?></td>
                            <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $item['condition_grade'] ?? '—']) ?></td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money($item['unit_price']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm">
                <p class="flex justify-between text-slate-500"><span>Subtotal</span><span><?= money($order['subtotal']) ?></span></p>
                <?php if ((float) $order['discount'] > 0): ?>
                    <p class="flex justify-between text-slate-500"><span>Discount</span><span class="text-green-700">-<?= money($order['discount']) ?></span></p>
                <?php endif; ?>
                <p class="flex justify-between text-base font-bold text-slate-900"><span>Total</span><span><?= money($order['total']) ?></span></p>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-900">Payments</h2>
            <?php if ($order['payments'] === []): ?>
                <p class="px-5 py-8 text-center text-sm text-slate-400">No payments recorded yet — payment state is tracked separately from order state.</p>
            <?php else: ?>
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <tbody>
                        <?php foreach ($order['payments'] as $payment): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2.5 capitalize"><?= e(str_replace('_', ' ', $payment['method'])) ?></td>
                                <td class="px-4 py-2.5"><?= \App\Support\View::component('badge', ['status' => $payment['status']]) ?></td>
                                <td class="px-4 py-2.5 text-slate-500"><?= e($payment['reference'] ?? '') ?></td>
                                <td class="px-4 py-2.5 text-xs text-slate-400"><?= e($payment['received_by_name'] ?? '') ?> · <?= e(local_datetime($payment['paid_at'])) ?></td>
                                <td class="px-4 py-2.5 text-right font-semibold text-slate-900"><?= money($payment['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (\App\Services\AuthService::can('payments.manage') && !in_array($order['status'], ['cancelled', 'refunded'], true)): ?>
                <form method="post" action="<?= e(url('/admin/orders/' . $order['id'] . '/payments')) ?>" class="grid gap-3 border-t border-slate-200 p-4 sm:grid-cols-[1fr_1fr_1fr_auto]" data-loading-form>
                    <?= csrf_field() ?>
                    <?= \App\Support\View::component('field', ['name' => 'amount', 'label' => 'Amount', 'required' => true, 'placeholder' => number_format((float) $order['total'], 2, '.', '')]) ?>
                    <?= \App\Support\View::component('select', ['name' => 'method', 'label' => 'Method', 'required' => true, 'options' => array_combine($methods, array_map(fn ($m) => ucfirst(str_replace('_', ' ', $m)), $methods)), 'value' => 'cash']) ?>
                    <?= \App\Support\View::component('field', ['name' => 'reference', 'label' => 'Reference (optional)']) ?>
                    <div class="flex items-end"><button type="submit" class="btn-primary">Record payment</button></div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Customer</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Name</dt><dd class="font-medium text-slate-900"><a class="hover:text-blue-700" href="<?= e(url('/admin/customers/' . $order['customer_id'])) ?>"><?= e($order['customer_name']) ?></a></dd></div>
                <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($order['customer_phone']) ?></dd></div>
                <div><dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-900"><?= e($order['customer_email'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Address</dt><dd class="font-medium text-slate-900"><?= e($order['customer_address'] ?? '—') ?></dd></div>
            </dl>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Timeline</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Placed</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($order['created_at'])) ?></dd></div>
                <div><dt class="text-slate-500">Placed by</dt><dd class="font-medium text-slate-900"><?= e($order['placed_by_name'] ?? 'online') ?></dd></div>
                <?php if ($order['completed_at']): ?><div><dt class="text-slate-500">Completed</dt><dd class="font-medium text-green-700"><?= e(local_datetime($order['completed_at'])) ?></dd></div><?php endif; ?>
                <?php if ($order['cancelled_at']): ?><div><dt class="text-slate-500">Cancelled</dt><dd class="font-medium text-red-700"><?= e(local_datetime($order['cancelled_at'])) ?></dd></div><?php endif; ?>
            </dl>
            <?php if (!empty($order['notes'])): ?>
                <p class="mt-3 border-t border-slate-100 pt-3 text-slate-600"><?= e($order['notes']) ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
