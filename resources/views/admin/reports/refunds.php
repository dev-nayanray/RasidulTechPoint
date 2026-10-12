<?php
/** @var array $report @var string $from @var string $to */
$exportUrl = url('/admin/reports/refunds?from=' . $from . '&to=' . $to . '&export=csv');
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/reports')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Reports</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Refund Report</h1>
<form method="get" action="<?= e(url('/admin/reports/refunds')) ?>" class="mt-4 flex flex-wrap gap-2">
    <input type="date" name="from" value="<?= e($from) ?>" class="input">
    <input type="date" name="to" value="<?= e($to) ?>" class="input">
    <button type="submit" class="btn-primary">Generate</button>
    <a href="<?= e($exportUrl) ?>" class="btn-secondary">Export CSV</a>
</form>
<div class="mt-4 grid gap-4 sm:grid-cols-2">
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Total refunded</p><p class="mt-1 text-xl font-bold text-red-700"><?= money((string) ($report['total_refunded'] ?? 0)) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Refund count</p><p class="mt-1 text-xl font-bold text-slate-900"><?= (int) ($report['count'] ?? 0) ?></p></div>
</div>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Refund #</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Order</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Customer</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Amount</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Reason</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Inventory</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Processed</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach (($report['rows'] ?? []) as $r): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-mono text-blue-700 text-xs"><?= e($r['refund_number']) ?></td>
                <td class="px-4 py-3 text-slate-500 text-xs"><?= e($r['order_reference']) ?></td>
                <td class="px-4 py-3 text-slate-700"><?= e($r['customer_name']) ?></td>
                <td class="px-4 py-3 text-right font-semibold text-red-700"><?= money((string) $r['amount']) ?></td>
                <td class="px-4 py-3 text-slate-500 text-xs"><?= e($r['reason']) ?></td>
                <td class="px-4 py-3 capitalize text-xs"><?= e(str_replace('_', ' ', $r['inventory_action'] ?? '')) ?></td>
                <td class="px-4 py-3 text-xs text-slate-400"><?= e(local_datetime($r['processed_at'] ?? null)) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (($report['rows'] ?? []) === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No refunds in this period.</p><?php endif; ?>
</div>
