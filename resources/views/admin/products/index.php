<?php /** @var list<array> $models */ ?>
<div class="admin-page-header">
    <div class="admin-page-header-title">
        <h2>Laptop Models</h2>
        <p class="admin-page-header-sub"><?= (int) $total ?> model(s) in the catalog. Each model can have multiple physical inventory units.</p>
    </div>
    <div class="admin-page-header-actions">
        <?php if (\App\Services\AuthService::can('products.create')): ?>
            <a href="<?= e(url('/admin/products/create')) ?>" class="btn-primary">+ Add laptop model</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" action="<?= e(url('/admin/products')) ?>" class="admin-filter-bar mt-4 sm:grid-cols-4" role="search">
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
    <div class="sm:col-span-4 admin-filter-bar-actions">
        <button type="submit" class="btn-primary">
            <?= \App\Support\View::component('icon', ['name' => 'filter', 'class' => 'h-4 w-4']) ?>
            Apply filters
        </button>
        <a href="<?= e(url('/admin/products')) ?>" class="btn-secondary">Reset</a>
    </div>
</form>

<div class="admin-card mt-4">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th class="w-16">Image</th>
                <th>Model</th>
                <th>Brand</th>
                <th>Category</th>
                <th>Available</th>
                <th>Published</th>
                <th></th>
            </tr>
            </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($models as $model): ?>
                <?php
                $thumb = trim((string) ($model['image_path'] ?? ''));
                $thumbOk = $thumb !== '' && is_file(BASE_PATH . '/public/' . $thumb);
                ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3">
                        <a href="<?= e(url('/admin/products/' . $model['id'])) ?>" class="block h-10 w-14 rounded-md bg-slate-100 ring-1 ring-slate-200 overflow-hidden flex items-center justify-center">
                            <?php if ($thumbOk): ?>
                                <img src="<?= e(url($thumb)) ?>" alt="" class="h-full w-full object-cover">
                            <?php else: ?>
                                <span class="text-slate-300 text-[10px] font-semibold uppercase tracking-wide">No img</span>
                            <?php endif; ?>
                        </a>
                    </td>
                    <td class="px-4 py-3 font-medium text-slate-900">
                        <a href="<?= e(url('/admin/products/' . $model['id'])) ?>" class="hover:text-blue-700"><?= e($model['name']) ?></a>
                        <?php if ((int) ($model['is_demo'] ?? 0) === 1): ?>
                            <span class="ml-2 inline-flex items-center rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800 ring-1 ring-amber-300" title="Demo catalog record (local testing only) — removable via bin/demo-catalog.php purge">Demo</span>
                        <?php endif; ?>
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
</div>
