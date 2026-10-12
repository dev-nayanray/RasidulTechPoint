<?php
/** @var array $report
 *  @var string $from
 *  @var string $to
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/expenses')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Expenses</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Expense Report</h1>
<form method="get" action="<?= e(url('/admin/expenses/report')) ?>" class="mt-4 flex flex-wrap gap-2" role="search">
    <input type="date" name="from" value="<?= e($from) ?>" class="input">
    <input type="date" name="to" value="<?= e($to) ?>" class="input">
    <button type="submit" class="btn-primary">Generate</button>
</form>

<div class="mt-4 grid gap-4 sm:grid-cols-4">
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <p class="text-slate-500 text-xs">Total (all statuses)</p>
        <p class="mt-1 text-2xl font-bold text-slate-900"><?= money((string) ($report['total'] ?? 0)) ?></p>
    </div>
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm">
        <p class="text-amber-800 text-xs">Pending</p>
        <p class="mt-1 text-2xl font-bold text-amber-700"><?= money((string) ($report['pending'] ?? 0)) ?></p>
    </div>
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm">
        <p class="text-blue-800 text-xs">Approved (incl. paid)</p>
        <p class="mt-1 text-2xl font-bold text-blue-700"><?= money((string) ($report['approved'] ?? 0)) ?></p>
    </div>
    <div class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm">
        <p class="text-green-800 text-xs">Paid</p>
        <p class="mt-1 text-2xl font-bold text-green-700"><?= money((string) ($report['paid'] ?? 0)) ?></p>
    </div>
</div>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Category</th>
            <th class="px-4 py-3 text-center font-medium text-slate-500">Entries</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Pending</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Approved</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Paid</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Total</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach (($report['categories'] ?? []) as $cat): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-medium text-slate-900"><?= e($cat['name']) ?></td>
                <td class="px-4 py-3 text-center text-slate-500"><?= (int) $cat['entry_count'] ?></td>
                <td class="px-4 py-3 text-right text-amber-700"><?= money((string) $cat['pending_total']) ?></td>
                <td class="px-4 py-3 text-right text-blue-700"><?= money((string) $cat['approved_total']) ?></td>
                <td class="px-4 py-3 text-right text-green-700"><?= money((string) $cat['paid_total']) ?></td>
                <td class="px-4 py-3 text-right font-bold text-slate-900"><?= money((string) $cat['all_total']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="bg-slate-50">
            <tr>
                <td class="px-4 py-3 font-bold text-slate-900">TOTAL</td>
                <td></td>
                <td class="px-4 py-3 text-right font-bold text-amber-700"><?= money((string) ($report['pending'] ?? 0)) ?></td>
                <td class="px-4 py-3 text-right font-bold text-blue-700"><?= money((string) ($report['approved'] ?? 0)) ?></td>
                <td class="px-4 py-3 text-right font-bold text-green-700"><?= money((string) ($report['paid'] ?? 0)) ?></td>
                <td class="px-4 py-3 text-right font-bold text-slate-900"><?= money((string) ($report['total'] ?? 0)) ?></td>
            </tr>
        </tfoot>
    </table>
</div>
