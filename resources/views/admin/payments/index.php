<?php
/** @var list<array> $payments
 *  @var list<array> $methods
 */
?>
<form method="get" action="<?= e(url('/admin/payments')) ?>" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-6" role="search">
    <div class="sm:col-span-2 space-y-1.5">
        <label for="pay-q" class="block text-sm font-medium text-slate-700">Search</label>
        <input id="pay-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Reference, evidence, order #, customer…">
    </div>
    <?= \App\Support\View::component('select', [
        'name' => 'status', 'label' => 'Status', 'value' => $status,
        'options' => ['' => 'All', 'pending' => 'Pending', 'completed' => 'Completed', 'refunded' => 'Refunded', 'failed' => 'Failed'],
    ]) ?>
    <?= \App\Support\View::component('select', [
        'name' => 'method', 'label' => 'Method', 'value' => $method,
        'options' => array_merge(['' => 'All methods'], array_column($methods, 'label', 'code')),
    ]) ?>
    <?= \App\Support\View::component('select', [
        'name' => 'verified', 'label' => 'Verified', 'value' => $verified,
        'options' => ['' => 'All', 'yes' => 'Verified', 'no' => 'Awaiting verification'],
    ]) ?>
    <div class="space-y-1.5">
        <label for="pay-from" class="block text-sm font-medium text-slate-700">From</label>
        <input id="pay-from" type="date" name="from" value="<?= e($from) ?>" class="input">
    </div>
    <div class="space-y-1.5">
        <label for="pay-to" class="block text-sm font-medium text-slate-700">To</label>
        <input id="pay-to" type="date" name="to" value="<?= e($to) ?>" class="input">
    </div>
    <div class="sm:col-span-6 flex flex-wrap gap-2">
        <button type="submit" class="btn-primary">Apply filters</button>
        <a href="<?= e(url('/admin/payments')) ?>" class="btn-secondary">Reset</a>
        <?php if (\App\Services\AuthService::can('payments.manage')): ?>
            <a href="<?= e(url('/admin/payments/pending')) ?>" class="btn-secondary ml-auto">Verification queue</a>
            <a href="<?= e(url('/admin/payments/reconciliation')) ?>" class="btn-secondary">Reconciliation</a>
        <?php endif; ?>
    </div>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">ID</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Order</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Customer</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Method</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Reference / evidence</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Received</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Amount</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($payments as $p): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3"><a class="font-medium text-blue-700" href="<?= e(url('/admin/payments/' . $p['id'])) ?>">#<?= (int) $p['id'] ?></a></td>
                    <td class="px-4 py-3"><a class="text-blue-700 hover:underline" href="<?= e(url('/admin/orders/' . $p['order_id'])) ?>"><?= e($p['order_reference']) ?></a></td>
                    <td class="px-4 py-3 text-slate-700"><?= e($p['customer_name']) ?><span class="block text-xs text-slate-400"><?= e($p['customer_phone']) ?></span></td>
                    <td class="px-4 py-3 capitalize"><?= e(str_replace('_', ' ', $p['method'])) ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $p['status']]) ?></td>
                    <td class="px-4 py-3 text-xs text-slate-500">
                        <?php if (!empty($p['reference'])): ?><span class="block">ref: <?= e($p['reference']) ?></span><?php endif; ?>
                        <?php if (!empty($p['evidence_ref'])): ?><span class="block text-green-700">evidence: <?= e($p['evidence_ref']) ?></span><?php endif; ?>
                        <?php if (empty($p['reference']) && empty($p['evidence_ref'])): ?>—<?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-400">
                        <?= e($p['received_by_name'] ?? '') ?>
                        <?php if (!empty($p['paid_at'])): ?><span class="block"><?= e(local_datetime($p['paid_at'])) ?></span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money($p['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($payments === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No payments match your filters.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/payments', 'queryParams' => array_filter(['q' => $q, 'status' => $status, 'method' => $method, 'verified' => $verified, 'from' => $from, 'to' => $to])]) ?>
</div>
