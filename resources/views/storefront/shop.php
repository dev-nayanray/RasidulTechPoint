<?php /** @var list<array> $products
 *  @var int $total
 *  @var int $page
 *  @var int $pages
 *  @var string $q
 *  @var int $brandId
 *  @var int $categoryId
 *  @var list<array> $brands
 *  @var list<array> $categories
 */
$activeFilters = [];
if ($q !== '') $activeFilters[] = "Search: \"{$q}\"";
if ($brandId > 0) $activeFilters[] = 'Brand filter';
if ($categoryId > 0) $activeFilters[] = 'Category filter';
?>
<div class="mx-auto max-w-7xl px-4 sm:px-6 py-6 sm:py-10">

    <?php /* Page header */ ?>
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
            <nav class="text-xs text-slate-400" aria-label="Breadcrumb">
                <a href="<?= e(url('/')) ?>" class="hover:text-slate-600">Home</a> <span class="mx-1">/</span> <span>Shop</span>
            </nav>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-navy-700">Shop Laptops</h1>
            <p class="mt-1 text-sm text-slate-500"><?= (int) $total ?> model(s) with available stock · Dubai-imported, quality-checked</p>
        </div>
        <div class="flex items-center gap-2">
            <?php /* Sort dropdown — client-side, submits the form */ ?>
            <label for="sort" class="text-xs text-slate-500">Sort</label>
            <select id="sort" name="sort" class="input text-sm py-1.5" form="shop-filters" onchange="document.getElementById('shop-filters').submit()">
                <option value="">Relevance</option>
                <option value="price_asc" <?= ($_GET['sort'] ?? '') === 'price_asc' ? 'selected' : '' ?>>Price: Low → High</option>
                <option value="price_desc" <?= ($_GET['sort'] ?? '') === 'price_desc' ? 'selected' : '' ?>>Price: High → Low</option>
                <option value="name_asc" <?= ($_GET['sort'] ?? '') === 'name_asc' ? 'selected' : '' ?>>Name: A → Z</option>
            </select>
            <?php /* Mobile filter toggle */ ?>
            <button type="button" class="lg:hidden inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium" data-filter-toggle aria-label="Toggle filters">
                <?= \App\Support\View::component('icon', ['name' => 'menu', 'class' => 'h-4 w-4']) ?>
                Filters
            </button>
        </div>
    </div>

    <div class="mt-6 flex flex-col lg:flex-row gap-6">

        <?php /* ====== Filters sidebar (desktop) / drawer (mobile) ====== */ ?>
        <aside class="lg:w-64 lg:shrink-0" data-filter-drawer>
            <div class="lg:sticky lg:top-24 rounded-xl border border-slate-200 bg-white p-5 lg:max-h-[calc(100vh-7rem)] lg:overflow-y-auto">
                <form method="get" action="<?= e(url('/shop')) ?>" id="shop-filters" class="space-y-5" role="search" data-auto-submit>
                    <div>
                        <label for="shop-q" class="block text-sm font-medium text-navy-700">Search</label>
                        <input id="shop-q" name="q" value="<?= e($q ?? '') ?>" class="input mt-1.5 text-sm" placeholder="Model or processor…" onchange="this.form.submit()">
                    </div>

                    <fieldset>
                        <legend class="text-sm font-medium text-navy-700">Brand</legend>
                        <div class="mt-2 space-y-1.5 text-sm">
                            <label class="flex items-center gap-2 text-slate-600"><input type="radio" name="brand" value="" <?= $brandId === 0 ? 'checked' : '' ?> class="text-brand-blue focus:ring-brand-blue" onchange="this.form.submit()"> All brands</label>
                            <?php foreach ($brands as $b): ?>
                                <label class="flex items-center gap-2 text-slate-600"><input type="radio" name="brand" value="<?= (int) $b['id'] ?>" <?= $brandId === (int) $b['id'] ? 'checked' : '' ?> class="text-brand-blue focus:ring-brand-blue" onchange="this.form.submit()"> <?= e($b['name']) ?> <span class="text-xs text-slate-400">(<?= (int) $b['model_count'] ?? 0 ?>)</span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-medium text-navy-700">Category</legend>
                        <div class="mt-2 space-y-1.5 text-sm">
                            <label class="flex items-center gap-2 text-slate-600"><input type="radio" name="category" value="" <?= $categoryId === 0 ? 'checked' : '' ?> class="text-brand-blue focus:ring-brand-blue" onchange="this.form.submit()"> All categories</label>
                            <?php foreach ($categories as $c): ?>
                                <label class="flex items-center gap-2 text-slate-600"><input type="radio" name="category" value="<?= (int) $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'checked' : '' ?> class="text-brand-blue focus:ring-brand-blue" onchange="this.form.submit()"> <?= e($c['name']) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-medium text-navy-700">Budget</legend>
                        <div class="mt-2 space-y-1.5 text-sm">
                            <label class="flex items-center gap-2 text-slate-600"><input type="radio" name="budget" value="" <?= (($_GET['budget'] ?? '') === '') ? 'checked' : '' ?> class="text-brand-blue focus:ring-brand-blue" onchange="this.form.submit()"> Any price</label>
                            <label class="flex items-center gap-2 text-slate-600"><input type="radio" name="budget" value="1" <?= (($_GET['budget'] ?? '') === '1') ? 'checked' : '' ?> class="text-brand-blue focus:ring-brand-blue" onchange="this.form.submit()"> Under ৳15,000</label>
                            <label class="flex items-center gap-2 text-slate-600"><input type="radio" name="budget" value="2" <?= (($_GET['budget'] ?? '') === '2') ? 'checked' : '' ?> class="text-brand-blue focus:ring-brand-blue" onchange="this.form.submit()"> ৳15k – ৳25k</label>
                            <label class="flex items-center gap-2 text-slate-600"><input type="radio" name="budget" value="3" <?= (($_GET['budget'] ?? '') === '3') ? 'checked' : '' ?> class="text-brand-blue focus:ring-brand-blue" onchange="this.form.submit()"> ৳25k – ৳40k</label>
                            <label class="flex items-center gap-2 text-slate-600"><input type="radio" name="budget" value="4" <?= (($_GET['budget'] ?? '') === '4') ? 'checked' : '' ?> class="text-brand-blue focus:ring-brand-blue" onchange="this.form.submit()"> ৳40,000+</label>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-medium text-navy-700">Condition</legend>
                        <div class="mt-2 space-y-1.5 text-sm">
                            <label class="flex items-center gap-2 text-slate-600"><input type="checkbox" name="grade[]" value="A" <?= in_array('A', $_GET['grade'] ?? [], true) ? 'checked' : '' ?> class="rounded text-brand-blue focus:ring-brand-blue" onchange="this.form.submit()"> Grade A (Like new)</label>
                            <label class="flex items-center gap-2 text-slate-600"><input type="checkbox" name="grade[]" value="B" <?= in_array('B', $_GET['grade'] ?? [], true) ? 'checked' : '' ?> class="rounded text-brand-blue focus:ring-brand-blue" onchange="this.form.submit()"> Grade B (Light wear)</label>
                            <label class="flex items-center gap-2 text-slate-600"><input type="checkbox" name="grade[]" value="C" <?= in_array('C', $_GET['grade'] ?? [], true) ? 'checked' : '' ?> class="rounded text-brand-blue focus:ring-brand-blue" onchange="this.form.submit()"> Grade C (Visible marks)</label>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-medium text-navy-700">Availability</legend>
                        <div class="mt-2 space-y-1.5 text-sm">
                            <label class="flex items-center gap-2 text-slate-600"><input type="checkbox" name="in_stock" value="1" <?= !empty($_GET['in_stock']) ? 'checked' : '' ?> class="rounded text-brand-blue focus:ring-brand-blue" onchange="this.form.submit()"> In stock only</label>
                        </div>
                    </fieldset>

                    <div class="pt-2 border-t border-slate-100">
                        <a href="<?= e(url('/shop')) ?>" class="block text-center text-xs text-slate-500 hover:text-slate-700">Reset filters</a>
                    </div>
                </form>
            </div>
        </aside>

        <?php /* Mobile drawer backdrop */ ?>
        <div class="lg:hidden fixed inset-0 z-40 bg-slate-900/50 hidden" data-filter-backdrop></div>

        <?php /* ====== Product grid ====== */ ?>
        <section class="min-w-0 flex-1">
            <?php /* Active filter chips */ ?>
            <?php if ($activeFilters !== []): ?>
                <div class="mb-4 flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-slate-500">Active:</span>
                    <?php foreach ($activeFilters as $filter): ?>
                        <span class="inline-flex items-center gap-1 rounded-full bg-blue-50 text-brand-blue px-2.5 py-1 font-medium ring-1 ring-blue-200"><?= e($filter) ?></span>
                    <?php endforeach; ?>
                    <a href="<?= e(url('/shop')) ?>" class="text-slate-500 hover:text-slate-700 underline">Clear all</a>
                </div>
            <?php endif; ?>

            <?php if ($products === []): ?>
                <div class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                        <?= \App\Support\View::component('icon', ['name' => 'search', 'class' => 'h-6 w-6']) ?>
                    </span>
                    <p class="mt-4 font-semibold text-navy-700">No laptops match your filters</p>
                    <p class="mt-1 text-sm text-slate-500 max-w-md mx-auto">Try removing a filter or two — or browse the full inventory. New shipments arrive weekly.</p>
                    <a href="<?= e(url('/shop')) ?>" class="mt-4 inline-flex items-center rounded-lg bg-brand-blue px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Reset filters</a>
                </div>
            <?php else: ?>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <?php foreach ($products as $product): ?>
                        <?= \App\Support\View::component('product-card', ['product' => $product]) ?>
                    <?php endforeach; ?>
                </div>

                <?php /* Pagination */ ?>
                <?php if ($pages > 1): ?>
                    <nav class="mt-8 flex items-center justify-center gap-1 text-sm" aria-label="Pagination">
                        <?php if ($page > 1): ?>
                            <a href="<?= e(url('/shop?' . http_build_query(array_merge($_GET, ['page' => $page - 1])))) ?>" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 hover:bg-slate-50">← Prev</a>
                        <?php endif; ?>
                        <span class="px-3 py-2 text-slate-500">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
                        <?php if ($page < $pages): ?>
                            <a href="<?= e(url('/shop?' . http_build_query(array_merge($_GET, ['page' => $page + 1])))) ?>" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 hover:bg-slate-50">Next →</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<script type="module">
    // Mobile filter drawer toggle.
    const toggle = document.querySelector('[data-filter-toggle]');
    const drawer = document.querySelector('[data-filter-drawer]');
    const backdrop = document.querySelector('[data-filter-backdrop]');
    const close = () => {
        drawer?.classList.remove('block');
        drawer?.classList.add('hidden');
        backdrop?.classList.add('hidden');
        document.body.style.overflow = '';
    };
    const open = () => {
        drawer?.classList.remove('hidden');
        drawer?.classList.add('block', 'fixed', 'inset-y-0', 'left-0', 'z-50', 'w-72', 'overflow-y-auto', 'bg-white', 'shadow-xl');
        backdrop?.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        // Add a close button to the top of the drawer on mobile
        const existing = drawer?.querySelector('[data-filter-close]');
        if (!existing && drawer) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.dataset.filterClose = '1';
            btn.className = 'lg:hidden mb-3 inline-flex items-center gap-1 text-sm text-slate-500';
            btn.innerHTML = '✕ Close';
            btn.addEventListener('click', close);
            drawer.querySelector('form')?.prepend(btn);
        }
    };
    toggle?.addEventListener('click', open);
    backdrop?.addEventListener('click', close);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') close();
    });
</script>
