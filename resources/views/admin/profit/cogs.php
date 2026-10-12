<?php
/** @var array $report
 *  @var string $from
 *  @var string $to
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/profit')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Profit</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">COGS Report</h1>
<form method="get" action="<?= e(url('/profit/cogs')) ?>" class="mt-4 flex flex-wrap gap-2">
    <input type="date" name="from" value="<?= e($from) ?>" class="input">
    <input type="date" name="to" value="<?= e($to) ?>" class="input">
    <button type="submit" class="btn-primary">Generate</button>
</form>

<div class="mt-4 grid gap-4 sm:grid-cols-4">
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Net revenue</p><p class="mt-1 text-xl font-bold text-slate-900"><?= money((string) ($report['net_revenue'] ?? 0)) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">COGS</p><p class="mt-1 text-xl font-bold text-red-700"><?= money((string) ($report['total_cogs'] ?? 0)) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Gross profit</p><p class="mt-1 text-xl font-bold <?= ($report['gross_profit'] ?? 0) >= 0 ? 'text-green-700' : 'text-red-700' ?>"><?= money((string) ($report['gross_profit'] ?? 0)) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Gross margin</p><p class="mt-1 text-xl font-bold text-slate-900"><?= number_format($report['gross_margin'] ?? 0, 2) ?>%</p></div>
</div>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Order #</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Customer</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Subtotal</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Discount</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Net revenue</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">COGS</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Gross profit</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Margin %</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach (($report['orders'] ?? []) as $o): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3"><a class="font-mono text-blue-700 hover:underline" href="<?= e(url('/admin/orders/' . $o['id'])) ?>"><?= e($o['reference']) ?></a></td>
                <td class="px-4 py-3 text-slate-700"><?= e($o['customer_name']) ?></td>
                <td class="px-4 py-3 text-right text-slate-500"><?= money($o['subtotal']) ?></td>
                <td class="px-4 py-3 text-right text-red-700"><?= (float) $o['discount'] > 0 ? '-' . money($o['discount']) : '—' ?></td>
                <td class="px-4 py-3 text-right font-medium text-slate-900"><?= money((string) $o['net_revenue']) ?></td>
                <td class="px-4 py-3 text-right text-red-700"><?= money((string) $o['cogs']) ?></td>
                <td class="px-4 py-3 text-right font-semibold <?= (float) $o['gross_profit'] >= 0 ? 'text-green-700' : 'text-red-700' ?>"><?= money((string) $o['gross_profit']) ?></td>
                <td class="px-4 py-3 text-right text-slate-500"><?= number_format((float) $o['gross_margin'], 2) ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="bg-slate-50 font-bold">
            <tr>
                <td colspan="2" class="px-4 py-3 text-slate-900">TOTAL (<?= (int) ($report['order_count'] ?? 0) ?> orders, <?= (int) ($report['unit_count'] ?? 0) ?> units)</td>
                <td class="px-4 py-3 text-right text-slate-700"><?= money((string) ($report['total_revenue'] ?? 0)) ?></td>
                <td class="px-4 py-3 text-right text-red-700"><?= money((string) ($report['total_discount'] ?? 0)) ?></td>
                <td class="px-4 py-3 text-right text-slate-900"><?= money((string) ($report['net_revenue'] ?? 0)) ?></td>
                <td class="px-4 py-3 text-right text-red-700"><?= money((string) ($report['total_cogs'] ?? 0)) ?></td>
                <td class="px-4 py-3 text-right <?= ($report['gross_profit'] ?? 0) >= 0 ? 'text-green-700' : 'text-red-700' ?>"><?= money((string) ($report['gross_profit'] ?? 0)) ?></td>
                <td class="px-4 py-3 text-right text-slate-900"><?= number_format($report['gross_margin'] ?? 0, 2) ?>%</td>
            </tr>
        </tfoot>
    </table>
    <?php if (($report['orders'] ?? []) === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No completed orders in this date range.</p><?php endif; ?>
</div>
