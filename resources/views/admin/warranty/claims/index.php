<?php /** @var list<array> $claims @var list<string> $statuses */ ?>
<form method="get" action="<?= e(url('/admin/warranty/claims')) ?>" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-3" role="search">
    <div class="sm:col-span-2 space-y-1.5">
        <label for="wc-q" class="block text-sm font-medium text-slate-700">Search</label>
        <input id="wc-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Claim #, registration #, SKU, serial, customer…">
    </div>
    <?= \App\Support\View::component('select', ['name' => 'status', 'label' => 'Status', 'value' => $status, 'options' => array_merge(['' => 'All'], array_combine($statuses, array_map(fn ($s) => ucfirst(str_replace('_', ' ', $s)), $statuses)))]) ?>
    <div class="flex items-end"><button type="submit" class="btn-primary">Apply</button></div>
</form>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Claim #</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Product</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Customer</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Type</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Filed</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Warranty ends</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($claims as $c): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-mono text-blue-700"><a href="<?= e(url('/admin/warranty/claims/' . $c['id'])) ?>"><?= e($c['claim_number']) ?></a></td>
                <td class="px-4 py-3 text-slate-700"><?= e($c['product_name']) ?></td>
                <td class="px-4 py-3 text-slate-700"><?= e($c['customer_name']) ?></td>
                <td class="px-4 py-3 capitalize"><?= e($c['claim_type']) ?></td>
                <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $c['status']]) ?></td>
                <td class="px-4 py-3 text-xs text-slate-400"><?= e(local_datetime($c['filed_at'], 'd M Y')) ?></td>
                <td class="px-4 py-3 text-xs text-slate-500"><?= e($c['warranty_end_date']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($claims === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No warranty claims found.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/warranty/claims', 'queryParams' => array_filter(['q' => $q, 'status' => $status])]) ?>
</div>
