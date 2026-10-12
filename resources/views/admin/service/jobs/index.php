<?php /** @var list<array> $jobs @var list<string> $statuses @var list<string> $serviceTypes */ ?>
<form method="get" action="<?= e(url('/admin/service/jobs')) ?>" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4" role="search">
    <div class="sm:col-span-2 space-y-1.5">
        <label for="sj-q" class="block text-sm font-medium text-slate-700">Search</label>
        <input id="sj-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Job #, customer, SKU, serial…">
    </div>
    <?= \App\Support\View::component('select', ['name' => 'status', 'label' => 'Status', 'value' => $status, 'options' => array_merge(['' => 'All'], array_combine($statuses, array_map(fn ($s) => ucfirst(str_replace('_', ' ', $s)), $statuses)))]) ?>
    <?= \App\Support\View::component('select', ['name' => 'type', 'label' => 'Type', 'value' => $serviceType, 'options' => array_merge(['' => 'All types'], array_combine($serviceTypes, array_map(fn ($t) => ucfirst($t), $serviceTypes)))]) ?>
    <div class="flex items-end"><button type="submit" class="btn-primary">Apply</button></div>
</form>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Job #</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Customer</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Product</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Type</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Technician</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Received</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Charge</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($jobs as $j): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-mono text-blue-700"><a href="<?= e(url('/admin/service/jobs/' . $j['id'])) ?>"><?= e($j['job_number']) ?></a></td>
                <td class="px-4 py-3 text-slate-700"><?= e($j['customer_name'] ?? 'Walk-in') ?></td>
                <td class="px-4 py-3 text-slate-500 text-xs"><?= e($j['product_name'] ?? '—') ?><span class="block font-mono"><?= e($j['unit_sku'] ?? '') ?></span></td>
                <td class="px-4 py-3 capitalize"><?= e($j['service_type']) ?></td>
                <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $j['status']]) ?></td>
                <td class="px-4 py-3 text-xs text-slate-500"><?= e($j['assigned_to_name'] ?? '—') ?></td>
                <td class="px-4 py-3 text-xs text-slate-400"><?= e(local_datetime($j['received_at'], 'd M Y')) ?></td>
                <td class="px-4 py-3 text-right text-slate-700"><?= money($j['total_charge']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($jobs === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No service jobs found.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/service/jobs', 'queryParams' => array_filter(['q' => $q, 'status' => $status, 'type' => $serviceType])]) ?>
</div>
