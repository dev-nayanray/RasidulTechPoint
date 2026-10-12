<?php
/** @var array $report @var string $from @var string $to */
$exportUrl = url('/admin/reports/sales?from=' . $from . '&to=' . $to . '&export=csv');
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/reports')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Reports</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Sales Report</h1>
<form method="get" action="<?= e(url('/admin/reports/sales')) ?>" class="mt-4 flex flex-wrap gap-2">
    <input type="date" name="from" value="<?= e($from) ?>" class="input">
    <input type="date" name="to" value="<?= e($to) ?>" class="input">
    <button type="submit" class="btn-primary">Generate</button>
    <a href="<?= e($exportUrl) ?>" class="btn-secondary">Export CSV</a>
</form>
<div class="mt-4 grid gap-4 sm:grid-cols-4">
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Gross revenue</p><p class="mt-1 text-xl font-bold text-slate-900"><?= money((string) ($report['total_revenue'] ?? 0)) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Discounts</p><p class="mt-1 text-xl font-bold text-red-700"><?= money((string) ($report['total_discount'] ?? 0)) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Net revenue</p><p class="mt-1 text-xl font-bold text-green-700"><?= money((string) ($report['total_net'] ?? 0)) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Orders</p><p class="mt-1 text-xl font-bold text-slate-900"><?= (int) ($report['order_count'] ?? 0) ?></p></div>
</div>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Date</th>
            <th class="px-4 py-3 text-center font-medium text-slate-500">Orders</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Gross revenue</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Discounts</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Net revenue</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach (($report['rows'] ?? []) as $r): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 text-slate-700"><?= e($r['sale_date']) ?></td>
                <td class="px-4 py-3 text-center text-slate-500"><?= (int) $r['order_count'] ?></td>
                <td class="px-4 py-3 text-right text-slate-700"><?= money((string) $r['gross_revenue']) ?></td>
                <td class="px-4 py-3 text-right text-red-700"><?= money((string) $r['total_discount']) ?></td>
                <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money((string) $r['net_revenue']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (($report['rows'] ?? []) === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No sales in this period.</p><?php endif; ?>
</div>
