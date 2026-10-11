<?php /** @var list<array> $models */ ?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-slate-500"><?= (int) $total ?> model(s)</p>
    <?php if (\App\Services\AuthService::can('products.create')): ?>
        <a href="<?= e(url('/admin/products/create')) ?>" class="btn-primary">Add laptop model</a>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(url('/admin/products')) ?>" class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4" role="search">
    <label class="space-y-1.5 sm:col-span-2">
        <span class="block text-sm font-medium text-slate-700">Search</span>
        <input name="q" value="<?= e($q) ?>" class="input" placeholder="Model name…">
    </label>
    <?= \App\Support\View::component('select', [
        'name' => 'brand', 'label' => 'Brand', 'value' => (string) $brandFilter,
        'options' => array_merge(['0' => 'All brands'], array_column($brands, 'name', 'id')),
    ]) ?>
    <?= \App\Support\View::component('select', [
        'name' => 'category', 'label' => 'Category', 'value' => (string) $categoryFilter,
        'options' => array_merge(['0' => 'All categories'], array_column($categories, 'name', 'id')),
    ]) ?>
    <div class="sm:col-span-4 flex gap-2">
        <button type="submit" class="btn-primary">Apply filters</button>
        <a href="<?= e(url('/admin/products')) ?>" class="btn-secondary">Reset</a>
    </div>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Model</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Brand</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Category</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Available</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Published</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($models as $model): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium text-slate-900">
                        <a href="<?= e(url('/admin/products/' . $model['id'])) ?>" class="hover:text-blue-700"><?= e($model['name']) ?></a>
                    </td>
                    <td class="px-4 py-3 text-slate-500"><?= e($model['brand_name']) ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($model['category_name']) ?></td>
                    <td class="px-4 py-3"><?= (int) $model['available_units'] === 0
                        ? '<span class="text-slate-400">0</span>'
                        : '<span class="font-semibold text-green-700">' . (int) $model['available_units'] . '</span>' ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => (int) $model['is_published'] === 1 ? 'active' : 'inactive']) ?></td>
                    <td class="px-4 py-3 text-right">
                        <?php if (\App\Services\AuthService::can('products.update')): ?>
                            <a href="<?= e(url('/admin/products/' . $model['id'] . '/edit')) ?>" class="font-medium text-blue-700 hover:text-blue-800">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($models === []): ?>
        <p class="px-4 py-10 text-center text-sm text-slate-400">No laptop models match your filters.</p>
    <?php endif; ?>
    <?= \App\Support\View::component('pagination', [
        'page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/products',
        'queryParams' => array_filter(['q' => $q, 'brand' => $brandFilter, 'category' => $categoryFilter]),
    ]) ?>
</div>
