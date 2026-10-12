<?php
/** @var array $report @var string $from @var string $to @var string $type */
$exportUrl = url('/admin/reports/' . $type . '?from=' . $from . '&to=' . $to . '&export=csv');
$title = ucfirst(str_replace('-', ' ', $type)) . ' Report';
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/reports')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Reports</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= e($title) ?></h1>
<form method="get" action="<?= e(url('/admin/reports/' . $type)) ?>" class="mt-4 flex flex-wrap gap-2">
    <input type="date" name="from" value="<?= e($from) ?>" class="input">
    <input type="date" name="to" value="<?= e($to) ?>" class="input">
    <button type="submit" class="btn-primary">Generate</button>
    <a href="<?= e($exportUrl) ?>" class="btn-secondary">Export CSV</a>
</form>
<div class="mt-4 grid gap-4 sm:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Total</p><p class="mt-1 text-xl font-bold text-slate-900"><?= money((string) ($report['total'] ?? $report['total_refunded'] ?? $report['total_outstanding'] ?? $report['total_expected'] ?? 0)) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Count</p><p class="mt-1 text-xl font-bold text-slate-900"><?= (int) ($report['count'] ?? 0) ?></p></div>
</div>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <tbody class="divide-y divide-slate-100">
            <?php foreach (($report['rows'] ?? []) as $r): ?>
            <tr class="hover:bg-slate-50">
                <?php foreach ($r as $col => $val): ?>
                <td class="px-4 py-3 text-slate-700"><?= e(is_string($val) ? $val : (is_numeric($val) ? number_format((float) $val, 2) : '')) ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (($report['rows'] ?? []) === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No data in this period.</p><?php endif; ?>
</div>
