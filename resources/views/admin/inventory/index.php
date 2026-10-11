<?php /** @var list<array> $units @var array $statusCounts */ ?>
<div class="flex flex-wrap gap-2">
    <a href="<?= e(url('/admin/inventory')) ?>" class="rounded-full border px-3 py-1 text-xs font-medium <?= $status === '' ? 'border-navy-700 bg-navy-700 text-white' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50' ?>">All (<?= array_sum($statusCounts) ?>)</a>
    <?php foreach ($statuses as $s): ?>
        <a href="<?= e(url('/admin/inventory?status=' . $s)) ?>" class="rounded-full border px-3 py-1 text-xs font-medium capitalize <?= $status === $s ? 'border-navy-700 bg-navy-700 text-white' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50' ?>">
            <?= e(str_replace('_', ' ', $s)) ?> (<?= (int) ($statusCounts[$s] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
</div>

<div class="mt-4 flex flex-wrap items-center justify-between gap-3">
    <form method="get" action="<?= e(url('/admin/inventory')) ?>" class="flex max-w-md gap-2" role="search">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <label class="sr-only" for="inventory-q">Search inventory</label>
        <input id="inventory-q" name="q" value="<?= e($q) ?>" class="input" placeholder="SKU, serial or model…">
        <button type="submit" class="btn-secondary shrink-0">Search</button>
    </form>
    <?php if (\App\Services\AuthService::can('inventory.receive')): ?>
        <a href="<?= e(url('/admin/inventory/create')) ?>" class="btn-primary">Receive stock</a>
    <?php endif; ?>
</div>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">SKU</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Model</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Grade</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Location</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Cost</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Price</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($units as $unit): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3"><a class="font-medium text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/inventory/' . $unit['id'])) ?>"><?= e($unit['sku']) ?></a></td>
                    <td class="px-4 py-3 text-slate-700"><?= e($unit['product_name']) ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $unit['condition_grade']]) ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $unit['status']]) ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($unit['location_name'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-right text-slate-500"><?= money((float) $unit['purchase_cost'] + (float) $unit['landed_cost']) ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money($unit['selling_price']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($units === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No inventory units match.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/inventory', 'queryParams' => array_filter(['q' => $q, 'status' => $status])]) ?>
</div>
