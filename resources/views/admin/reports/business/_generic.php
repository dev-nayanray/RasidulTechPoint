<?php
/**
 * Generic business report template with optional date filter + CSV export.
 * @var array $report
 * @var string $pageTitle
 * @var string $reportType (for export URL)
 * @var list<string> $columns
 * @var array $columnLabels (column => label)
 * @var string $totalKey (key in report for total, or null)
 * @var string $from (optional, defaults to month start)
 * @var string $to (optional, defaults to today)
 */
$from = $from ?? gmdate('Y-m-01');
$to = $to ?? gmdate('Y-m-d');
$totalKey = $totalKey ?? null;
$exportUrl = url('/admin/reports/' . ($reportType ?? '') . '?from=' . $from . '&to=' . $to . '&export=csv');
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/reports')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Reports</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= e($pageTitle) ?></h1>
<form method="get" action="<?= e(url('/admin/reports/' . ($reportType ?? ''))) ?>" class="mt-4 flex flex-wrap gap-2">
    <input type="date" name="from" value="<?= e($from) ?>" class="input">
    <input type="date" name="to" value="<?= e($to) ?>" class="input">
    <button type="submit" class="btn-primary">Generate</button>
    <a href="<?= e($exportUrl) ?>" class="btn-secondary">Export CSV</a>
</form>
<?php if ($totalKey && isset($report[$totalKey])): ?>
<div class="mt-4 grid gap-4 sm:grid-cols-2">
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Total</p><p class="mt-1 text-xl font-bold text-slate-900"><?= money((string) ($report[$totalKey] ?? 0)) ?></p></div>
    <?php if (isset($report['count'])): ?><div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Count</p><p class="mt-1 text-xl font-bold text-slate-900"><?= (int) $report['count'] ?></p></div><?php endif; ?>
</div>
<?php endif; ?>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <?php foreach ($columns as $col): ?>
            <th class="px-4 py-3 text-left font-medium text-slate-500"><?= e($columnLabels[$col] ?? ucfirst(str_replace('_', ' ', $col))) ?></th>
            <?php endforeach; ?>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach (($report['rows'] ?? []) as $row): ?>
            <tr class="hover:bg-slate-50">
                <?php foreach ($columns as $col): ?>
                <td class="px-4 py-3 text-slate-700">
                    <?php
                    $val = $row[$col] ?? '';
                    if (is_numeric($val) && in_array($col, ['total_purchase', 'total_landed', 'total_cost', 'total_retail', 'potential_profit', 'total_value', 'outstanding_balance', 'lifetime_value', 'total_charge', 'total_ltv', 'total_outstanding'], true)) {
                        echo money((string) $val);
                    } elseif (is_numeric($val) && in_array($col, ['unit_count', 'po_count', 'count', 'orders_count', 'orders_placed', 'payments_recorded', 'service_jobs'], true)) {
                        echo (int) $val;
                    } else {
                        echo e((string) $val);
                    }
                    ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (($report['rows'] ?? []) === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No data in this period.</p><?php endif; ?>
</div>
