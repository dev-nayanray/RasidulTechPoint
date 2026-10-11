<section class="rounded-2xl bg-navy-700 px-6 py-12 text-center text-white sm:py-16">
    <p class="text-sm font-medium tracking-wide text-blue-300">DUBAI IMPORTED | AUTHENTIC USED LAPTOPS</p>
    <h1 class="mx-auto mt-3 max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">Tested &amp; quality-checked laptops, ready to work.</h1>
    <p class="mx-auto mt-4 max-w-xl text-sm text-slate-300 sm:text-base">Every unit is individually inspected, graded and priced — with warranty included. Shop online or visit our store.</p>
    <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
        <a href="<?= e(url('/shop')) ?>" class="inline-flex items-center rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold hover:bg-blue-700">Browse laptops</a>
        <a href="<?= e(url('/about')) ?>" class="inline-flex items-center rounded-lg border border-white/20 px-5 py-2.5 text-sm font-semibold hover:bg-white/10">How we test</a>
    </div>
</section>

<section class="mt-10">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-slate-900">Available now</h2>
        <a href="<?= e(url('/shop')) ?>" class="text-sm font-medium text-blue-700 hover:text-blue-800">View all →</a>
    </div>

    <?php if (($featured ?? []) === []): ?>
        <div class="mt-4"><?= \App\Support\View::component('empty-state', [
            'title' => 'No laptops in stock right now',
            'description' => 'New shipments from Dubai arrive regularly. Check back soon or contact us.',
            'icon' => 'box',
        ]) ?></div>
    <?php else: ?>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <?php foreach ($featured as $product): ?>
                <a href="<?= e(url('/shop/' . $product['slug'])) ?>" class="group rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-blue-300 hover:shadow-md">
                    <p class="text-xs font-medium text-blue-700"><?= e($product['brand_name']) ?></p>
                    <p class="mt-1 font-semibold text-slate-900 group-hover:text-blue-700"><?= e($product['name']) ?></p>
                    <dl class="mt-3 space-y-1 text-xs text-slate-500">
                        <div class="flex justify-between"><dt>Processor</dt><dd class="font-medium text-slate-700"><?= e($product['processor'] ?? '—') ?></dd></div>
                        <div class="flex justify-between"><dt>Memory</dt><dd class="font-medium text-slate-700"><?= e($product['ram_gb'] ? $product['ram_gb'] . ' GB' : '—') ?></dd></div>
                        <div class="flex justify-between"><dt>Storage</dt><dd class="font-medium text-slate-700"><?= e($product['storage_capacity_gb'] ? $product['storage_capacity_gb'] . ' GB ' . $product['storage_type'] : '—') ?></dd></div>
                    </dl>
                    <p class="mt-4 text-lg font-bold text-slate-900"><?= $product['from_price'] !== null ? money($product['from_price']) : '—' ?></p>
                    <p class="text-xs text-slate-400"><?= (int) $product['available_units'] ?> unit(s) available</p>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="mt-12 grid gap-4 sm:grid-cols-3">
    <?php foreach ([
        ['package-check', 'Tested & Quality Checked', 'Every laptop passes a full hardware inspection before it is listed.'],
        ['shield', 'Warranty Included', 'Default warranty on all units, with service support from our technicians.'],
        ['truck', 'Direct from Dubai', 'We import authentic used stock directly and price it fairly.'],
    ] as [$icon, $title, $text]): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                <?= \App\Support\View::component('icon', ['name' => $icon, 'class' => 'h-5 w-5']) ?>
            </span>
            <p class="mt-3 font-semibold text-slate-900"><?= e($title) ?></p>
            <p class="mt-1 text-sm text-slate-500"><?= e($text) ?></p>
        </div>
    <?php endforeach; ?>
</section>
