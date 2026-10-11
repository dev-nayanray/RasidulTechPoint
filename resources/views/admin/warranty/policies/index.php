<?php /** @var list<array> $policies */ ?>
<div class="flex items-center justify-between">
    <p class="text-sm text-slate-500"><?= count($policies) ?> policy(s)</p>
    <a href="<?= e(url('/admin/warranty/policies/create')) ?>" class="btn-primary">Add policy</a>
</div>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Name</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Duration</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Coverage</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
            <th class="px-4 py-3"></th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($policies as $p): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-medium text-slate-900"><?= e($p['name']) ?></td>
                <td class="px-4 py-3 text-slate-700"><?= (int) $p['duration_days'] ?> days</td>
                <td class="px-4 py-3 capitalize"><?= e(str_replace('_', ' ', $p['coverage_type'])) ?></td>
                <td class="px-4 py-3"><?= (int) $p['is_active'] === 1 ? \App\Support\View::component('badge', ['status' => 'active']) : \App\Support\View::component('badge', ['status' => 'inactive']) ?></td>
                <td class="px-4 py-3 text-right"><a href="<?= e(url('/admin/warranty/policies/' . $p['id'] . '/edit')) ?>" class="btn-secondary text-xs">Edit</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($policies === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No warranty policies yet.</p><?php endif; ?>
</div>
