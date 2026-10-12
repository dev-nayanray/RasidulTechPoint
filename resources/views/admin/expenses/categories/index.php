<?php /** @var list<array> $categories */ ?>
<div class="flex items-center justify-between">
    <p class="text-sm text-slate-500"><?= count($categories) ?> categor(ies)</p>
</div>
<details class="mt-4 rounded-xl border border-slate-200 bg-white p-5 text-sm">
    <summary class="cursor-pointer font-semibold text-slate-900">Add category</summary>
    <form method="post" action="<?= e(url('/admin/expenses/categories')) ?>" class="mt-4 grid gap-3 sm:grid-cols-3" data-loading-form>
        <?= csrf_field() ?>
        <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Name', 'required' => true]) ?>
        <?= \App\Support\View::component('field', ['name' => 'description', 'label' => 'Description']) ?>
        <?= \App\Support\View::component('field', ['name' => 'display_order', 'label' => 'Display order', 'type' => 'number', 'min' => '0', 'value' => '0']) ?>
        <div class="sm:col-span-3"><button type="submit" class="btn-primary text-xs">Create</button></div>
    </form>
</details>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Name</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Description</th>
            <th class="px-4 py-3 text-center font-medium text-slate-500">Order</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Active</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($categories as $c): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-medium text-slate-900"><?= e($c['name']) ?></td>
                <td class="px-4 py-3 text-slate-500 text-xs"><?= e($c['description'] ?? '—') ?></td>
                <td class="px-4 py-3 text-center text-slate-500"><?= (int) $c['display_order'] ?></td>
                <td class="px-4 py-3"><?= (int) $c['is_active'] === 1 ? \App\Support\View::component('badge', ['status' => 'active']) : \App\Support\View::component('badge', ['status' => 'inactive']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
