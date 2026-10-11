<div class="flex flex-col gap-6 lg:flex-row">
    <aside class="lg:w-64 lg:shrink-0">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <form method="get" action="<?= e(url('/shop')) ?>" class="space-y-4" role="search">
                <div>
                    <label for="shop-q" class="block text-sm font-medium text-slate-700">Search</label>
                    <input id="shop-q" name="q" value="<?= e($q ?? '') ?>" class="input mt-1.5" placeholder="Model or processor…">
                </div>
                <?php
                $brandOptions = ['' => 'All brands'];
                foreach (($brands ?? []) as $b) {
                    $brandOptions[$b['id']] = $b['name'];
                }
                $categoryOptions = ['' => 'All categories'];
                foreach (($categories ?? []) as $c) {
                    $categoryOptions[$c['id']] = $c['name'];
                }
                ?>
                <?= \App\Support\View::component('select', ['name' => 'brand', 'label' => 'Brand', 'options' => $brandOptions, 'value' => (string) ($brandId ?? 0)]) ?>
                <?= \App\Support\View::component('select', ['name' => 'category', 'label' => 'Category', 'options' => $categoryOptions, 'value' => (string) ($categoryId ?? 0)]) ?>
                <button type="submit" class="btn-primary w-full">Apply filters</button>
                <a href="<?= e(url('/shop')) ?>" class="block text-center text-sm text-slate-500 hover:text-slate-700">Reset</a>
            </form>
        </div>
    </aside>

    <section class="min-w-0 flex-1">
        <div class="flex items-center justify-between">
            <h1 class="text-lg font-semibold text-slate-900"><?= e($pageTitle ?? 'Shop') ?></h1>
            <p class="text-sm text-slate-500"><?= (int) ($total ?? 0) ?> model(s) in stock</p>
        </div>

        <?php if (($products ?? []) === []): ?>
            <div class="mt-4"><?= \App\Support\View::component('empty-state', [
                'title' => 'No laptops match your filters',
                'description' => 'Try a different search or clear the filters to see all available stock.',
                'icon' => 'search',
            ]) ?></div>
        <?php else: ?>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($products as $product): ?>
                    <a href="<?= e(url('/shop/' . $product['slug'])) ?>" class="group rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-blue-300 hover:shadow-md">
                        <p class="text-xs font-medium text-blue-700"><?= e($product['brand_name']) ?></p>
                        <p class="mt-1 font-semibold text-slate-900 group-hover:text-blue-700"><?= e($product['name']) ?></p>
                        <p class="mt-1 text-xs text-slate-500"><?= e($product['processor'] ?? '') ?></p>
                        <dl class="mt-3 space-y-1 text-xs text-slate-500">
                            <div class="flex justify-between"><dt>Memory</dt><dd class="font-medium text-slate-700"><?= e($product['ram_gb'] ? $product['ram_gb'] . ' GB' : '—') ?></dd></div>
                            <div class="flex justify-between"><dt>Storage</dt><dd class="font-medium text-slate-700"><?= e($product['storage_capacity_gb'] ? $product['storage_capacity_gb'] . ' GB ' . $product['storage_type'] : '—') ?></dd></div>
                            <div class="flex justify-between"><dt>Display</dt><dd class="font-medium text-slate-700"><?= e($product['display_size_inches'] ? $product['display_size_inches'] . '"' : '—') ?></dd></div>
                        </dl>
                        <div class="mt-4 flex items-end justify-between">
                            <p class="text-lg font-bold text-slate-900"><?= $product['from_price'] !== null ? money($product['from_price']) : '—' ?></p>
                            <p class="text-xs text-slate-400"><?= (int) $product['available_units'] ?> available</p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (($pages ?? 1) > 1): ?>
                <div class="mt-6 rounded-xl border border-slate-200 bg-white">
                    <?= \App\Support\View::component('pagination', [
                        'page' => $page, 'pages' => $pages, 'baseUrl' => '/shop',
                        'queryParams' => array_filter(['q' => $q ?? '', 'brand' => $brandId ?? 0, 'category' => $categoryId ?? 0]),
                    ]) ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
