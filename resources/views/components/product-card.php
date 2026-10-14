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
$image = trim((string) ($product['image_path'] ?? ''));
$imageOk = $image !== '' && is_file(BASE_PATH . '/public/' . $image);
$alt = 'Illustrative image of ' . $brand . ' ' . $name . ' — not an actual unit photo';
?>
<article class="group flex flex-col rounded-xl bg-white ring-1 ring-slate-200 overflow-hidden transition hover:ring-brand-blue hover:shadow-md">
    <a href="<?= e($url) ?>" class="relative aspect-[4/3] bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center overflow-hidden" aria-label="<?= e($brand . ' ' . $name) ?>">
        <?php if ($imageOk): ?>
            <img src="<?= e(url($image)) ?>" alt="<?= e($alt) ?>" loading="lazy"
                 class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]">
        <?php else: ?>
            <span class="text-slate-400 group-hover:text-brand-blue transition">
                <?= \App\Support\View::component('icon', ['name' => 'box', 'class' => 'h-12 w-12']) ?>
            </span>
        <?php endif; ?>
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

        <?php /* Compare toggle + machine-readable spec payload for compare.js */ ?>
        <button type="button" class="compare-toggle mt-2 w-full" data-compare-toggle="<?= e($product['slug']) ?>" aria-pressed="false">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3L4 7l4 4"/><path d="M4 7h16"/><path d="M16 21l4-4-4-4"/><path d="M20 17H4"/></svg>
            <span data-compare-label>Compare</span>
        </button>
        <script type="application/json" data-compare-data="<?= e($product['slug']) ?>"><?= json_encode([
            'slug' => (string) $product['slug'],
            'name' => (string) ($product['name'] ?? ''),
            'brand' => (string) ($product['brand_name'] ?? ''),
            'price' => $product['from_price'] !== null ? money($product['from_price']) : '—',
            'processor' => (string) ($product['processor'] ?? '—'),
            'ram' => !empty($product['ram_gb']) ? $product['ram_gb'] . ' GB' : '—',
            'storage' => !empty($product['storage_capacity_gb']) ? $product['storage_capacity_gb'] . ' GB ' . ($product['storage_type'] ?? '') : '—',
            'display' => !empty($product['display_size_inches']) ? number_format((float) $product['display_size_inches'], 1) . '"' . (!empty($product['resolution']) ? ' ' . $product['resolution'] : '') : '—',
            'graphics' => (string) ($product['graphics'] ?? '—'),
            'units' => (int) ($product['available_units'] ?? 0),
            'image' => $imageOk ? url($image) : null,
            'url' => $url,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    </div>
</article>
