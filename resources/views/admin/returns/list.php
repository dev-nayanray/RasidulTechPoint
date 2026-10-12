<?php /** @var list<array> $returns @var list<string> $statuses */ ?>
<form method="get" action="<?= e(url('/admin/returns/list')) ?>" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-3" role="search">
    <div class="sm:col-span-2 space-y-1.5">
        <label for="rt-q" class="block text-sm font-medium text-slate-700">Search</label>
        <input id="rt-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Return #, order, customer…">
    </div>
    <?= \App\Support\View::component('select', ['name' => 'status', 'label' => 'Status', 'value' => $status, 'options' => array_merge(['' => 'All'], array_combine($statuses, array_map(fn ($s) => ucfirst($s), $statuses)))]) ?>
    <div class="flex items-end"><button type="submit" class="btn-primary">Apply</button></div>
</form>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Return #</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Order</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Customer</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Type</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Created</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($returns as $r): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-mono text-blue-700"><a href="<?= e(url('/admin/returns/' . $r['id'])) ?>"><?= e($r['return_number']) ?></a></td>
                <td class="px-4 py-3 text-slate-500"><a class="hover:underline" href="<?= e(url('/admin/orders/' . $r['order_id'] ?? '')) ?>"><?= e($r['order_reference']) ?></a></td>
                <td class="px-4 py-3 text-slate-700"><?= e($r['customer_name']) ?></td>
                <td class="px-4 py-3 capitalize"><?= e(str_replace('_', ' ', $r['request_type'])) ?></td>
                <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $r['status']]) ?></td>
                <td class="px-4 py-3 text-xs text-slate-400"><?= e(local_datetime($r['created_at'], 'd M Y')) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($returns === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No returns found.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/returns/list', 'queryParams' => array_filter(['q' => $q, 'status' => $status])]) ?>
</div>
