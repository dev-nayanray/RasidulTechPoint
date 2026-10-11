<?php /** @var list<array> $locations */ ?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-slate-500"><?= count($locations) ?> location(s)</p>
    <?php if (\App\Services\AuthService::can('inventory.adjust')): ?>
        <a href="<?= e(url('/admin/locations/create')) ?>" class="btn-primary">Add location</a>
    <?php endif; ?>
</div>

<div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Name</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Code</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Type</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Address</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Available</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Awaiting</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Total</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($locations as $loc): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium text-slate-900">
                        <?= e($loc['name']) ?>
                        <?php if ((int) $loc['is_primary'] === 1): ?>
                            <span class="ml-1 rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-[10px] font-medium text-blue-700">Primary</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3"><code class="text-xs text-slate-600"><?= e($loc['code'] ?? '—') ?></code></td>
                    <td class="px-4 py-3 text-slate-500 capitalize"><?= e(str_replace('_', ' ', $loc['type'])) ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($loc['address'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-right font-medium <?= (int) $loc['available_count'] > 0 ? 'text-green-700' : 'text-slate-400' ?>"><?= (int) $loc['available_count'] ?></td>
                    <td class="px-4 py-3 text-right <?= (int) $loc['awaiting_count'] > 0 ? 'text-amber-700' : 'text-slate-400' ?>"><?= (int) $loc['awaiting_count'] ?></td>
                    <td class="px-4 py-3 text-right font-medium text-slate-700"><?= (int) $loc['total_units'] ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => (int) $loc['is_active'] === 1 ? 'active' : 'inactive']) ?></td>
                    <td class="px-4 py-3 text-right">
                        <?php if (\App\Services\AuthService::can('inventory.adjust')): ?>
                            <a href="<?= e(url('/admin/locations/' . $loc['id'] . '/edit')) ?>" class="font-medium text-blue-700 hover:text-blue-800">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($locations === []): ?>
        <p class="px-4 py-10 text-center text-sm text-slate-400">No locations configured.</p>
    <?php endif; ?>
</div>

<p class="mt-4 text-xs text-slate-500">
    Per-user branch assignment (restricting staff to their branch only) is not yet implemented. The dashboard already supports location filtering for inventory metrics, and the inventory receive form lets you assign units to a location.
</p>
