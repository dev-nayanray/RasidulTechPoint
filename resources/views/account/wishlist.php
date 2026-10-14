<?php /** @var list<array> $items */
?>
<h1 class="text-xl font-bold tracking-tight text-navy-700">Wishlist</h1>
<p class="mt-1 text-sm text-slate-500">Saved laptops. Adding to wishlist does not reserve inventory — availability is checked live.</p>

<?php if ($items === []): ?>
    <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
        <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
            <?= \App\Support\View::component('icon', ['name' => 'heart', 'class' => 'h-6 w-6']) ?>
        </span>
        <p class="mt-4 font-semibold text-navy-700">Your wishlist is empty</p>
        <p class="mt-1 text-sm text-slate-500">Save laptops you're interested in and check back later.</p>
        <a href="<?= e(url('/shop')) ?>" class="mt-4 inline-flex items-center gap-2 rounded-lg bg-brand-blue px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">Browse Laptops</a>
    </div>
<?php else: ?>
    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($items as $item): ?>
            <?php
            $img = trim((string) ($item['image_path'] ?? ''));
            $imgOk = $img !== '' && is_file(BASE_PATH . '/public/' . $img);
            ?>
            <div class="rounded-xl bg-white ring-1 ring-slate-200 overflow-hidden">
                <a href="<?= e(url('/shop/' . $item['slug'])) ?>" class="block aspect-[4/3] bg-gradient-to-br from-slate-100 to-slate-200 overflow-hidden">
                    <?php if ($imgOk): ?>
                        <img src="<?= e(url($img)) ?>" alt="Illustrative photo of <?= e($item['brand_name'] . ' ' . $item['name']) ?> — not the exact unit" loading="lazy" class="h-full w-full object-cover hover:scale-[1.03] transition duration-300">
                    <?php else: ?>
                        <span class="flex h-full w-full items-center justify-center">
                            <?= \App\Support\View::component('icon', ['name' => 'box', 'class' => 'h-12 w-12 text-slate-400']) ?>
                        </span>
                    <?php endif; ?>
                </a>
                <div class="p-4">
                    <p class="text-xs font-semibold uppercase text-brand-blue"><?= e($item['brand_name']) ?></p>
                    <a href="<?= e(url('/shop/' . $item['slug'])) ?>" class="mt-0.5 block font-semibold text-navy-700 hover:text-brand-blue text-sm line-clamp-2"><?= e($item['name']) ?></a>
                    <p class="mt-1 text-xs text-slate-500"><?= e($item['processor'] ?? '—') ?> · <?= $item['ram_gb'] ? $item['ram_gb'] . 'GB' : '—' ?></p>
                    <div class="mt-2 flex items-center justify-between">
                        <div>
                            <?php if ((int) $item['available_units'] > 0): ?>
                                <p class="font-bold text-navy-700 text-sm"><?= $item['from_price'] !== null ? money($item['from_price']) : '—' ?></p>
                                <p class="text-[10px] text-brand-green"><?= (int) $item['available_units'] ?> in stock</p>
                            <?php else: ?>
                                <p class="text-xs text-slate-400">Out of stock</p>
                            <?php endif; ?>
                        </div>
                        <form method="post" action="<?= e(url('/account/wishlist/' . $item['id'] . '/remove')) ?>" class="inline">
                            <?= csrf_field() ?><button type="submit" class="text-xs text-red-600 hover:underline">Remove</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
