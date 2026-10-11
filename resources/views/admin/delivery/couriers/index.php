<?php /** @var list<array> $couriers */ ?>
<div class="flex items-center justify-between">
    <p class="text-sm text-slate-500"><?= (int) $total ?> courier(s)</p>
    <a href="<?= e(url('/admin/delivery/couriers/create')) ?>" class="btn-primary">Add courier</a>
</div>

<form method="get" action="<?= e(url('/admin/delivery/couriers')) ?>" class="mt-4 flex max-w-md gap-2" role="search">
    <label class="sr-only" for="couriers-q">Search couriers</label>
    <input id="couriers-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Name, contact, email or phone…">
    <button type="submit" class="btn-secondary">Search</button>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Name</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Contact</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">API provider</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Base fee</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Shipments</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($couriers as $c): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium text-blue-700"><a href="<?= e(url('/admin/delivery/couriers/' . $c['id'])) ?>"><?= e($c['name']) ?></a></td>
                    <td class="px-4 py-3 text-slate-500 text-xs"><?= e($c['contact_name'] ?? '—') ?><span class="block"><?= e($c['phone'] ?? '') ?></span></td>
                    <td class="px-4 py-3 text-slate-700"><?= e(ucfirst($c['api_provider'])) ?></td>
                    <td class="px-4 py-3 text-slate-700"><?= money($c['base_fee']) ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= (int) ($c['shipment_count'] ?? 0) ?></td>
                    <td class="px-4 py-3"><?= (int) $c['is_active'] === 1 ? \App\Support\View::component('badge', ['status' => 'active']) : \App\Support\View::component('badge', ['status' => 'inactive']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($couriers === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No couriers match your search.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/delivery/couriers', 'queryParams' => ['q' => $q]]) ?>
</div>
