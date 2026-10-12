<?php /** Reusable product card for storefront grids.
 *  @var array $product  row from CatalogRepository::publishedForStore
 *      keys: id, name, slug, processor, ram_gb, storage_type,
 *            storage_capacity_gb, display_size_inches, resolution, graphics,
 *            brand_name, brand_slug, available_units, from_price
 */
$brand = e($product['brand_name'] ?? '');
$name = e($product['name'] ?? '');
$processor = $product['processor'] ?? '—';
$ram = !empty($product['ram_gb']) ? $product['ram_gb'] . ' GB' : '—';
$storage = !empty($product['storage_capacity_gb'])
    ? $product['storage_capacity_gb'] . ' GB ' . ($product['storage_type'] ?? '')
    : '—';
$display = !empty($product['display_size_inches'])
    ? number_format((float) $product['display_size_inches'], 1) . '"'
    : '';
$price = $product['from_price'] !== null ? money($product['from_price']) : '—';
$available = (int) ($product['available_units'] ?? 0);
$url = url('/shop/' . $product['slug']);
?>
<article class="group flex flex-col rounded-xl bg-white ring-1 ring-slate-200 overflow-hidden transition hover:ring-brand-blue hover:shadow-md">
    <?php /* Image placeholder — clean SVG pattern until real product
            photos are uploaded via the admin. */ ?>
    <a href="<?= e($url) ?>" class="relative aspect-[4/3] bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center overflow-hidden" aria-label="<?= e($brand . ' ' . $name) ?>">
        <span class="text-slate-400 group-hover:text-brand-blue transition">
            <?= \App\Support\View::component('icon', ['name' => 'box', 'class' => 'h-12 w-12']) ?>
        </span>
        <?php /* Quality-checked badge — only shown when units are actually
                  available (i.e. inspected + listed). */ ?>
        <?php if ($available > 0): ?>
        <span class="absolute top-2 left-2 inline-flex items-center gap-1 rounded-full bg-brand-green/10 text-brand-green text-[10px] font-semibold px-2 py-0.5 ring-1 ring-brand-green/20">
            <span class="h-1.5 w-1.5 rounded-full bg-brand-green"></span> Quality Checked
        </span>
        <?php endif; ?>
        <?php if ($available === 0): ?>
        <span class="absolute top-2 left-2 inline-flex items-center rounded-full bg-slate-200 text-slate-600 text-[10px] font-semibold px-2 py-0.5">Out of stock</span>
        <?php elseif ($available <= 2): ?>
        <span class="absolute top-2 right-2 inline-flex items-center rounded-full bg-amber-50 text-amber-700 text-[10px] font-semibold px-2 py-0.5 ring-1 ring-amber-200">Only <?= $available ?> left</span>
        <?php endif; ?>
    </a>

    <div class="flex flex-col flex-1 p-4">
        <p class="text-xs font-semibold uppercase tracking-wider text-brand-blue"><?= $brand ?></p>
        <a href="<?= e($url) ?>" class="mt-0.5 font-semibold text-navy-700 hover:text-brand-blue leading-snug line-clamp-2"><?= $name ?></a>

        <dl class="mt-3 space-y-1 text-xs text-slate-500">
            <div class="flex items-center gap-2"><dt class="text-slate-400 w-16 shrink-0">Processor</dt><dd class="font-medium text-slate-700 truncate"><?= e($processor) ?></dd></div>
            <div class="flex items-center gap-2"><dt class="text-slate-400 w-16 shrink-0">Memory</dt><dd class="font-medium text-slate-700"><?= e($ram) ?></dd></div>
            <div class="flex items-center gap-2"><dt class="text-slate-400 w-16 shrink-0">Storage</dt><dd class="font-medium text-slate-700"><?= e($storage) ?></dd></div>
            <?php if ($display !== ''): ?><div class="flex items-center gap-2"><dt class="text-slate-400 w-16 shrink-0">Display</dt><dd class="font-medium text-slate-700"><?= e($display) ?></dd></div><?php endif; ?>
        </dl>

        <div class="mt-4 pt-3 border-t border-slate-100 flex items-end justify-between">
            <div>
                <p class="text-[10px] uppercase text-slate-400">From</p>
                <p class="text-lg font-bold text-navy-700"><?= e($price) ?></p>
            </div>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-2">
            <a href="<?= e($url) ?>" class="inline-flex items-center justify-center rounded-lg bg-navy-700 px-3 py-2 text-xs font-semibold text-white hover:bg-navy-800 transition">View details</a>
            <a href="<?= e($url) ?>#add-to-cart" class="inline-flex items-center justify-center rounded-lg bg-brand-blue px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 transition">Buy now</a>
        </div>
    </div>
</article>
