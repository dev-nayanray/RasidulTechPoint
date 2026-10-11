<?php
/** @var list<array> $invoices
 *  @var list<string> $statuses
 */
?>
<form method="get" action="<?= e(url('/admin/invoices')) ?>" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4" role="search">
    <div class="sm:col-span-2 space-y-1.5">
        <label for="inv-q" class="block text-sm font-medium text-slate-700">Search</label>
        <input id="inv-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Invoice #, order reference, billing name…">
    </div>
    <?= \App\Support\View::component('select', [
        'name' => 'status', 'label' => 'Status', 'value' => $status,
        'options' => array_merge(['' => 'All statuses'], array_combine($statuses, array_map(fn ($s) => ucfirst($s), $statuses))),
    ]) ?>
    <div class="flex items-end">
        <button type="submit" class="btn-primary">Apply filters</button>
    </div>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Invoice #</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Order</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Bill to</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Issued</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Total</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($invoices as $inv): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-mono font-medium text-blue-700"><a href="<?= e(url('/admin/invoices/' . $inv['id'])) ?>"><?= e($inv['invoice_number']) ?></a></td>
                    <td class="px-4 py-3 text-slate-500"><a class="hover:text-blue-700" href="<?= e(url('/admin/orders/' . $inv['order_id'])) ?>"><?= e($inv['order_reference']) ?></a></td>
                    <td class="px-4 py-3 text-slate-700"><?= e($inv['billing_name']) ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= e(local_datetime($inv['issue_date'], 'd M Y')) ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $inv['status']]) ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money($inv['total']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($invoices === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No invoices match your filters.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/invoices', 'queryParams' => array_filter(['q' => $q, 'status' => $status])]) ?>
</div>
