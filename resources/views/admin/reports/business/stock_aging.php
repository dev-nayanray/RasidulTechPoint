<?php /** @var array $report */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/reports')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Reports</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Stock Aging</h1>
<div class="mt-4"><a href="<?= e(url('/admin/reports/stock-aging?export=csv')) ?>" class="btn-secondary">Export CSV</a></div>
<div class="mt-4 grid gap-4 sm:grid-cols-2">
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Total cost (unsold)</p><p class="mt-1 text-xl font-bold text-slate-900"><?= money((string)($report['total_cost'] ?? 0)) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Total units (unsold)</p><p class="mt-1 text-xl font-bold text-slate-900"><?= (int)($report['count'] ?? 0) ?></p></div>
</div>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Age bucket</th>
            <th class="px-4 py-3 text-center font-medium text-slate-500">Units</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Total cost</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Total retail</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach (($report['rows'] ?? []) as $r): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-medium text-slate-900"><?= e($r['age_bucket']) ?></td>
                <td class="px-4 py-3 text-center text-slate-500"><?= (int)$r['unit_count'] ?></td>
                <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money((string)$r['total_cost']) ?></td>
                <td class="px-4 py-3 text-right text-slate-700"><?= money((string)$r['total_retail']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (($report['rows'] ?? []) === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No unsold stock found.</p><?php endif; ?>
</div>
<p class="mt-2 text-xs text-slate-400">Age calculated from received_at. Only unsold units (available, reserved, awaiting inspection) are included.</p>
