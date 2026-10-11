<?php
/** @var list<array> $refunds
 *  @var list<string> $statuses
 */
?>
<form method="get" action="<?= e(url('/admin/refunds')) ?>" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4" role="search">
    <div class="sm:col-span-2 space-y-1.5">
        <label for="rf-q" class="block text-sm font-medium text-slate-700">Search</label>
        <input id="rf-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Refund # or order reference…">
    </div>
    <?= \App\Support\View::component('select', [
        'name' => 'status', 'label' => 'Status', 'value' => $status,
        'options' => array_merge(['' => 'All'], array_combine($statuses, array_map(fn ($s) => ucfirst($s), $statuses))),
    ]) ?>
    <div class="flex items-end">
        <button type="submit" class="btn-primary">Apply filters</button>
    </div>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Refund #</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Order</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Reason</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Inventory</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Authorized by</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Amount</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($refunds as $r): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-mono font-medium text-blue-700"><a href="<?= e(url('/admin/refunds/' . $r['id'])) ?>"><?= e($r['refund_number']) ?></a></td>
                    <td class="px-4 py-3 text-slate-500"><a class="hover:text-blue-700" href="<?= e(url('/admin/orders/' . $r['order_id'])) ?>"><?= e($r['order_reference']) ?></a></td>
                    <td class="px-4 py-3 text-slate-700 max-w-xs truncate"><?= e($r['reason']) ?></td>
                    <td class="px-4 py-3 capitalize text-xs"><?= e(str_replace('_', ' ', $r['inventory_action'])) ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $r['status']]) ?></td>
                    <td class="px-4 py-3 text-xs text-slate-500"><?= e($r['authorized_by_name'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-red-700"><?= money($r['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($refunds === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No refunds match your filters.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/refunds', 'queryParams' => array_filter(['q' => $q, 'status' => $status])]) ?>
</div>
