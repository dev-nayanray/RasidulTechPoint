<?php
/** @var list<array> $movements
 *  @var list<string> $movementTypes
 *  @var list<string> $settlementStatuses
 *  @var list<array> $accounts
 */
?>
<form method="get" action="<?= e(url('/admin/cashbook/movements')) ?>" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-6" role="search">
    <div class="sm:col-span-2 space-y-1.5">
        <label for="cm-q" class="block text-sm font-medium text-slate-700">Search</label>
        <input id="cm-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Movement #, reference, description…">
    </div>
    <?= \App\Support\View::component('select', ['name' => 'account', 'label' => 'Account', 'value' => (string) $accountId, 'options' => array_merge(['' => 'All'], array_column($accounts, 'name', 'id'))]) ?>
    <?= \App\Support\View::component('select', ['name' => 'type', 'label' => 'Type', 'value' => $type, 'options' => array_merge(['' => 'All'], array_combine($movementTypes, array_map(fn ($t) => ucfirst(str_replace('_', ' ', $t)), $movementTypes)))]) ?>
    <?= \App\Support\View::component('select', ['name' => 'settlement', 'label' => 'Settlement', 'value' => $settlement, 'options' => array_merge(['' => 'All'], array_combine($settlementStatuses, array_map(fn ($s) => ucfirst($s), $settlementStatuses)))]) ?>
    <div class="flex items-end"><button type="submit" class="btn-primary">Apply</button></div>
</form>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Movement #</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Account</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Type</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Dir</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Amount</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Balance after</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Settlement</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Created</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($movements as $m): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-mono text-blue-700"><a href="<?= e(url('/admin/cashbook/movements/' . $m['id'])) ?>"><?= e($m['movement_number']) ?></a></td>
                <td class="px-4 py-3 text-slate-500"><?= e($m['account_name']) ?></td>
                <td class="px-4 py-3 capitalize text-xs"><?= e(str_replace('_', ' ', $m['movement_type'])) ?></td>
                <td class="px-4 py-3 text-xs <?= $m['direction'] === 'in' ? 'text-green-700' : 'text-red-700' ?>"><?= $m['direction'] === 'in' ? 'IN' : 'OUT' ?></td>
                <td class="px-4 py-3 text-right font-semibold <?= $m['direction'] === 'in' ? 'text-green-700' : 'text-red-700' ?>"><?= $m['direction'] === 'in' ? '+' : '-' ?><?= money($m['amount']) ?></td>
                <td class="px-4 py-3 text-right text-slate-700"><?= money($m['balance_after']) ?></td>
                <td class="px-4 py-3 text-xs">
                    <?php if ($m['settlement_status'] === 'verified'): ?><span class="text-green-700">✓ verified</span>
                    <?php elseif ($m['settlement_status'] === 'disputed'): ?><span class="text-red-700">! disputed</span>
                    <?php else: ?><span class="text-amber-700">⏳ unverified</span><?php endif; ?>
                </td>
                <td class="px-4 py-3 text-xs text-slate-400"><?= e(local_datetime($m['created_at'], 'd M Y')) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($movements === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No movements found.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/cashbook/movements', 'queryParams' => array_filter(['q' => $q, 'account' => $accountId ? (string) $accountId : '', 'type' => $type, 'settlement' => $settlement, 'from' => $from, 'to' => $to])]) ?>
</div>
