<?php /** @var array $model @var list<array> $units */ ?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <div>
        <p class="text-sm text-slate-500"><a href="<?= e(url('/admin/products')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Laptop models</a></p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900"><?= e($model['name']) ?></h1>
        <p class="text-sm text-slate-500"><?= e($model['brand_name']) ?> · <?= e($model['category_name']) ?> ·
            <?= \App\Support\View::component('badge', ['status' => (int) $model['is_published'] === 1 ? 'active' : 'inactive', 'label' => (int) $model['is_published'] === 1 ? 'published' : 'unpublished']) ?>
        </p>
    </div>
    <div class="flex gap-2">
        <?php if (\App\Services\AuthService::can('products.update')): ?>
            <a href="<?= e(url('/admin/products/' . $model['id'] . '/edit')) ?>" class="btn-secondary">Edit</a>
        <?php endif; ?>
        <a href="<?= e(url('/shop/' . $model['slug'])) ?>" class="btn-secondary">View in storefront</a>
        <?php if (\App\Services\AuthService::can('products.delete') && empty($units)): ?>
            <form method="post" action="<?= e(url('/admin/products/' . $model['id'] . '/delete')) ?>" data-confirm="Soft-delete this model? It will disappear from the storefront but history is preserved.">
                <?= csrf_field() ?>
                <button type="submit" class="btn-danger">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-slate-900">Specifications</h2>
        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <?php
            $specs = [
                'Processor' => $model['processor'],
                'RAM' => $model['ram_gb'] ? $model['ram_gb'] . ' GB' : null,
                'Storage' => $model['storage_capacity_gb'] ? $model['storage_capacity_gb'] . ' GB ' . $model['storage_type'] : null,
                'Graphics' => $model['graphics'],
                'Display' => $model['display_size_inches'] ? $model['display_size_inches'] . '" ' . ($model['resolution'] ?? '') : null,
                'OS' => $model['operating_system'],
            ];
            foreach ($specs as $label => $value): ?>
                <div><dt class="text-slate-500"><?= e($label) ?></dt><dd class="font-medium text-slate-900"><?= e($value ?? '—') ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <?php if (!empty($model['description'])): ?>
            <p class="mt-4 border-t border-slate-100 pt-4 text-sm text-slate-600"><?= e($model['description']) ?></p>
        <?php endif; ?>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="text-sm font-semibold text-slate-900">Record</h2>
        <dl class="mt-3 space-y-2">
            <div><dt class="text-slate-500">Slug</dt><dd class="font-mono text-xs text-slate-700"><?= e($model['slug']) ?></dd></div>
            <div><dt class="text-slate-500">Created</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($model['created_at'])) ?></dd></div>
            <div><dt class="text-slate-500">Updated</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($model['updated_at'])) ?></dd></div>
        </dl>
    </div>
</div>

<section class="mt-6">
    <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold text-slate-900">Units of this model</h2>
        <?php if (\App\Services\AuthService::can('inventory.receive')): ?>
            <a href="<?= e(url('/admin/inventory/create')) ?>" class="btn-secondary">Receive unit</a>
        <?php endif; ?>
    </div>
    <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">SKU</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Grade</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                    <th class="px-4 py-3 text-right font-medium text-slate-500">Selling price</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($units as $unit): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3"><a class="font-medium text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/inventory/' . $unit['id'])) ?>"><?= e($unit['sku']) ?></a></td>
                        <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $unit['condition_grade']]) ?></td>
                        <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $unit['status']]) ?></td>
                        <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money($unit['selling_price']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($units === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No units received for this model yet.</p><?php endif; ?>
    </div>
</section>
