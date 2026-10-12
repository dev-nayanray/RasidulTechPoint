<?php
/** @var array $report */
$exportUrl = url('/admin/reports/payables?export=csv');
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/reports')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Reports</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Payables Report</h1>
<div class="mt-4 flex flex-wrap gap-2">
    <a href="<?= e($exportUrl) ?>" class="btn-secondary">Export CSV</a>
</div>
<div class="mt-4 grid gap-4 sm:grid-cols-2">
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Total outstanding</p><p class="mt-1 text-xl font-bold text-amber-700"><?= money((string) ($report['total_outstanding'] ?? 0)) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm"><p class="text-slate-500 text-xs">Suppliers</p><p class="mt-1 text-xl font-bold text-slate-900"><?= (int) ($report['count'] ?? 0) ?></p></div>
</div>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Supplier</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Contact</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Phone</th>
            <th class="px-4 py-3 text-center font-medium text-slate-500">Pending POs</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Outstanding balance</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach (($report['rows'] ?? []) as $r): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-medium text-blue-700"><a href="<?= e(url('/admin/suppliers/' . $r['id'])) ?>"><?= e($r['name']) ?></a></td>
                <td class="px-4 py-3 text-slate-500 text-xs"><?= e($r['contact_name'] ?? '—') ?></td>
                <td class="px-4 py-3 text-slate-500 text-xs"><?= e($r['phone'] ?? '—') ?></td>
                <td class="px-4 py-3 text-center text-slate-500"><?= (int) $r['pending_po_count'] ?></td>
                <td class="px-4 py-3 text-right font-bold text-amber-700"><?= money((string) $r['outstanding_balance']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (($report['rows'] ?? []) === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No outstanding payables.</p><?php endif; ?>
</div>
<p class="mt-2 text-xs text-slate-400">Based on supplier.outstanding_balance (manual field). Pending POs are approved/submitted but not yet received.</p>
