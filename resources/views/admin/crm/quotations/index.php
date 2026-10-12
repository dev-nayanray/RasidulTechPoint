<?php /** @var list<array> $quotations @var list<string> $statuses */ ?>
<form method="get" action="<?= e(url('/admin/crm/quotations')) ?>" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-3" role="search">
    <div class="sm:col-span-2 space-y-1.5">
        <label for="cq-q" class="block text-sm font-medium text-slate-700">Search</label>
        <input id="cq-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Quote #, customer name or phone…">
    </div>
    <?= \App\Support\View::component('select', ['name' => 'status', 'label' => 'Status', 'value' => $status, 'options' => array_merge(['' => 'All'], array_combine($statuses, array_map(fn ($s) => ucfirst($s), $statuses)))]) ?>
    <div class="flex items-end"><button type="submit" class="btn-primary">Apply</button></div>
</form>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Quote #</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Customer</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Valid until</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Total</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($quotations as $q): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-mono text-blue-700"><a href="<?= e(url('/admin/crm/quotations/' . $q['id'])) ?>"><?= e($q['quote_number']) ?></a></td>
                <td class="px-4 py-3 text-slate-700"><?= e($q['customer_name']) ?></td>
                <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $q['status']]) ?></td>
                <td class="px-4 py-3 text-xs text-slate-500"><?= e($q['valid_until'] ?? '—') ?></td>
                <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money($q['total']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($quotations === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No quotations found.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/crm/quotations', 'queryParams' => array_filter(['q' => $q, 'status' => $status])]) ?>
</div>
