<?php /** @var array $report @var string $status */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/reports')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Reports</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Inventory Valuation</h1>
<form method="get" action="<?= e(url('/admin/reports/inventory-valuation')) ?>" class="mt-4 flex flex-wrap gap-2">
    <select name="status" class="input"><option value="">All statuses</option>
        <?php foreach (['available','sold','reserved','returned','damaged','awaiting_inspection','in_transit','written_off'] as $s): ?>
        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_',' ',$s))) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-primary">Filter</button>
    <a href="<?= e(url('/admin/reports/inventory-valuation?export=csv')) ?>" class="btn-secondary">Export CSV</a>
</form>
<div class="mt-4 grid gap-4 sm:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Total cost (purchase + landed)</p><p class="mt-1 text-xl font-bold text-slate-900"><?= money((string)($report['total_cost'] ?? 0)) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Total retail (selling price)</p><p class="mt-1 text-xl font-bold text-slate-900"><?= money((string)($report['total_retail'] ?? 0)) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Total units</p><p class="mt-1 text-xl font-bold text-slate-900"><?= (int)($report['count'] ?? 0) ?></p></div>
</div>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
            <th class="px-4 py-3 text-center font-medium text-slate-500">Units</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Purchase cost</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Landed cost</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Total cost</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Retail value</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Potential profit</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach (($report['rows'] ?? []) as $r): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 capitalize text-slate-700"><?= e(str_replace('_',' ',$r['status'])) ?></td>
                <td class="px-4 py-3 text-center text-slate-500"><?= (int)$r['unit_count'] ?></td>
                <td class="px-4 py-3 text-right text-slate-700"><?= money((string)$r['total_purchase']) ?></td>
                <td class="px-4 py-3 text-right text-slate-700"><?= money((string)$r['total_landed']) ?></td>
                <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money((string)$r['total_cost']) ?></td>
                <td class="px-4 py-3 text-right text-slate-700"><?= money((string)$r['total_retail']) ?></td>
                <td class="px-4 py-3 text-right font-semibold text-green-700"><?= money((string)$r['potential_profit']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (($report['rows'] ?? []) === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No inventory found.</p><?php endif; ?>
</div>
