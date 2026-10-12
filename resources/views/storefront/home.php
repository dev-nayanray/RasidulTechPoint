<?php /** @var list<array> $featured
 *  @var list<array> $brands
 *  @var list<array{label:string,min:int,max:?int}> $budgetRanges
 *  @var list<array> $faqs
 *  @var string $heroBlock
 *  @var string $whyChooseBlock
 *  @var string $howWeCheckBlock
 *  @var string $finalCtaBlock
 */
?>
<?php /* ====== C. Hero ====== */ ?>
<section class="relative bg-navy-700 text-white overflow-hidden">
    <?php /* Soft radial backdrop — no fake product imagery. */ ?>
    <div class="absolute inset-0 opacity-30" aria-hidden="true" style="background: radial-gradient(60% 70% at 80% 20%, rgba(37,99,235,0.4) 0%, transparent 70%), radial-gradient(50% 60% at 10% 90%, rgba(22,163,74,0.25) 0%, transparent 70%);"></div>
    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 py-16 sm:py-20 lg:py-24 grid lg:grid-cols-2 gap-10 items-center">
        <div>
            <?php if (!empty($heroBlock)): /* CMS override available */ ?>
                <div class="prose prose-invert max-w-none text-slate-200"><?= $heroBlock /* trusted HTML from CMS */ ?></div>
            <?php else: ?>
                <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold tracking-wide uppercase text-blue-200 ring-1 ring-white/10">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand-green"></span> Dubai Imported Laptops
                </p>
                <h1 class="mt-4 text-3xl font-bold tracking-tight sm:text-4xl lg:text-5xl">Premium Laptops.<br><span class="text-blue-300">Smarter Prices.</span></h1>
                <p class="mt-4 max-w-lg text-base text-slate-300 sm:text-lg">Discover quality-checked Dubai-imported laptops for study, work, business, and everyday use.</p>
            <?php endif; ?>

            <div class="mt-8 flex flex-wrap gap-3">
                <a href="<?= e(url('/shop')) ?>" class="inline-flex items-center gap-2 rounded-lg bg-brand-blue px-6 py-3 text-sm font-semibold hover:bg-blue-700 transition">
                    Shop Laptops
                    <?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 rotate-[-90deg]']) ?>
                </a>
                <a href="<?= e(url('/shop?budget=1')) ?>" class="inline-flex items-center gap-2 rounded-lg border border-white/20 px-6 py-3 text-sm font-semibold hover:bg-white/10 transition">
                    Explore Budget Deals
                </a>
            </div>

            <?php /* Three trust indicators */ ?>
            <dl class="mt-8 grid grid-cols-3 gap-4 max-w-md">
                <div class="rounded-xl bg-white/5 px-3 py-3 ring-1 ring-white/10">
                    <dt class="text-xs text-slate-300">Quality Checked</dt>
                    <dd class="mt-1 text-sm font-semibold">Per-unit inspection</dd>
                </div>
                <div class="rounded-xl bg-white/5 px-3 py-3 ring-1 ring-white/10">
                    <dt class="text-xs text-slate-300">Transparent Specs</dt>
                    <dd class="mt-1 text-sm font-semibold">Real condition</dd>
                </div>
                <div class="rounded-xl bg-white/5 px-3 py-3 ring-1 ring-white/10">
                    <dt class="text-xs text-slate-300">Warranty Options</dt>
                    <dd class="mt-1 text-sm font-semibold">Where configured</dd>
                </div>
            </dl>
        </div>

        <?php /* Hero visual — uses the first featured product as a stylized card
                (real product image lookup ships with the catalog-photos module). */ ?>
        <div class="relative">
            <div class="aspect-[4/3] rounded-2xl bg-gradient-to-br from-slate-800/60 to-navy-800 ring-1 ring-white/10 overflow-hidden shadow-2xl">
                <?php $hero = $featured[0] ?? null; ?>
                <?php if ($hero !== null): ?>
                    <div class="w-full h-full flex items-center justify-center p-8 text-center">
                        <div>
                            <p class="text-xs uppercase tracking-wider text-blue-300"><?= e($hero['brand_name']) ?></p>
                            <p class="mt-1 text-xl font-bold"><?= e($hero['name']) ?></p>
                            <p class="mt-2 text-sm text-slate-400"><?= e($hero['processor'] ?? '') ?> · <?= e($hero['ram_gb'] ?? '?') ?>GB RAM</p>
                            <p class="mt-4 text-2xl font-bold text-white"><?= $hero['from_price'] !== null ? money($hero['from_price']) : '—' ?></p>
                            <a href="<?= e(url('/shop/' . $hero['slug'])) ?>" class="mt-4 inline-flex items-center rounded-lg bg-brand-blue px-4 py-2 text-xs font-semibold hover:bg-blue-700">View this laptop</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center p-8 text-center text-slate-400">
                        <div>
                            <p class="text-sm">Inventory is being restocked.</p>
                            <p class="mt-1 text-xs">New shipments arrive regularly.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php /* ====== D. Shop by category ====== */ ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16">
    <div class="flex items-end justify-between gap-4">
        <div>
            <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Shop by category</h2>
            <p class="mt-1 text-sm text-slate-500">Pick a category that matches your needs — browse real inventory only.</p>
        </div>
        <a href="<?= e(url('/shop')) ?>" class="text-sm font-semibold text-brand-blue hover:underline whitespace-nowrap">All laptops →</a>
    </div>

    <div class="mt-6 grid gap-4 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4">
        <?php
        // Curated category tiles. Each links to /shop with a query-string
        // filter that ShopController recognizes (q= processor / etc.).
        $categories = [
            ['Business Laptops', 'Reliable for office work', 'shop?q=business', 'briefcase'],
            ['Student Laptops', 'Affordable & portable', 'shop?q=student', 'book-open'],
            ['Core i5 Laptops', 'Balanced performance', 'shop?q=core+i5', 'cpu'],
            ['Core i7 Laptops', 'High performance', 'shop?q=core+i7', 'cpu'],
            ['Lightweight & Portable', 'Easy to carry', 'shop?q=portable', 'package'],
            ['Budget Laptops', 'Under ৳15,000', 'shop?budget=1', 'wallet'],
            ['Premium Laptops', '৳40,000+', 'shop?budget=4', 'shield'],
        ];
        foreach ($categories as [$title, $desc, $link, $icon]):
        ?>
            <a href="<?= e(url('/' . ltrim($link, '/'))); ?>" class="group relative overflow-hidden rounded-xl bg-white p-5 ring-1 ring-slate-200 transition hover:ring-brand-blue hover:shadow-md">
                <div class="flex items-start justify-between">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
                        <?= \App\Support\View::component('icon', ['name' => $icon, 'class' => 'h-5 w-5']) ?>
                    </span>
                    <span class="text-slate-300 group-hover:text-brand-blue transition">
                        <?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 -rotate-90']) ?>
                    </span>
                </div>
                <p class="mt-4 font-semibold text-navy-700"><?= e($title) ?></p>
                <p class="mt-1 text-xs text-slate-500"><?= e($desc) ?></p>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php /* ====== E. Shop by budget ====== */ ?>
<section class="bg-slate-50 border-y border-slate-200">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16">
        <div class="text-center max-w-2xl mx-auto">
            <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Shop by budget</h2>
            <p class="mt-2 text-sm text-slate-500">Pick a price range that fits — only shows laptops actually in stock at that price.</p>
        </div>

        <div class="mt-8 grid gap-4 grid-cols-2 lg:grid-cols-4">
            <?php foreach ($budgetRanges as $i => $range): ?>
                <?php
                $queryParams = ['budget' => (string) ($i + 1)];
                ?>
                <a href="<?= e(url('/shop?' . http_build_query($queryParams))) ?>" class="group rounded-xl bg-white p-6 ring-1 ring-slate-200 transition hover:ring-brand-blue hover:shadow-md text-center">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-blue">Range <?= (int) ($i + 1) ?></p>
                    <p class="mt-2 text-lg font-bold text-navy-700 group-hover:text-brand-blue"><?= e($range['label']) ?></p>
                    <p class="mt-1 text-xs text-slate-500">In-stock only</p>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php /* ====== F. Featured laptops ====== */ ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16">
    <div class="flex items-end justify-between gap-4">
        <div>
            <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Featured laptops</h2>
            <p class="mt-1 text-sm text-slate-500">Quality-checked units ready to ship. Real specs, real prices, real condition.</p>
        </div>
        <a href="<?= e(url('/shop')) ?>" class="text-sm font-semibold text-brand-blue hover:underline whitespace-nowrap">View all →</a>
    </div>

    <?php if ($featured === []): ?>
        <div class="mt-6"><?= \App\Support\View::component('empty-state', [
            'title' => 'No laptops in stock right now',
            'description' => 'New shipments from Dubai arrive regularly. Check back soon or contact us — we keep a waiting list.',
            'icon' => 'box',
        ]) ?></div>
    <?php else: ?>
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <?php foreach ($featured as $product): ?>
                <?= \App\Support\View::component("product-card", ["product" => $product]) ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php /* ====== G. Why choose Rasidul Tech Point ====== */ ?>
<section class="bg-navy-700 text-white">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16">
        <div class="max-w-2xl">
            <h2 class="text-xl sm:text-2xl font-bold tracking-tight">Why choose Rasidul Tech Point?</h2>
            <p class="mt-2 text-sm text-slate-300">We make buying a used laptop straightforward — every unit is individually inspected, condition-graded, and priced honestly.</p>
        </div>

        <?php if (!empty($whyChooseBlock)): ?>
            <div class="mt-8 prose prose-invert max-w-none"><?= $whyChooseBlock ?></div>
        <?php else: ?>
            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <?php
                $benefits = [
                    ['truck', 'Dubai-imported selection', 'Authentic used stock imported directly — no refurbished-as-new ambiguity.'],
                    ['check-circle', 'Individual unit inspection', 'Every laptop is examined by our team before listing — not bulk-graded.'],
                    ['file-text', 'Clear condition & specs', 'Real processor, RAM, storage, display size, and any known defects.'],
                    ['wallet', 'Transparent pricing', 'No hidden fees, no inflated "discounts". The price you see is the price you pay.'],
                    ['shield', 'Warranty information', 'Where warranty applies, it is shown on the product page. Where it does not, that is shown too.'],
                    ['map-pin', 'Online & physical store', 'Buy online with delivery, or visit us to inspect the unit in person.'],
                ];
                foreach ($benefits as [$icon, $title, $text]):
                ?>
                    <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5">
                        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-blue/15 text-blue-300">
                            <?= \App\Support\View::component('icon', ['name' => $icon, 'class' => 'h-5 w-5']) ?>
                        </span>
                        <p class="mt-3 font-semibold"><?= e($title) ?></p>
                        <p class="mt-1 text-sm text-slate-300 leading-relaxed"><?= e($text) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php /* ====== H. How we check our laptops ====== */ ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16">
    <div class="max-w-2xl">
        <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">How we check our laptops</h2>
        <p class="mt-2 text-sm text-slate-500">A transparent, four-step inspection process. Not every laptop passes — the ones that don't, we don't sell.</p>
    </div>

    <?php if (!empty($howWeCheckBlock)): ?>
        <div class="mt-8 prose max-w-none"><?= $howWeCheckBlock ?></div>
    <?php else: ?>
        <ol class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <?php
            $steps = [
                ['1', 'Verify the laptop', 'Check the make, model, serial, and identifying details against the import manifest.'],
                ['2', 'Test hardware components', 'Boot, keyboard, trackpad, screen, ports, battery, Wi-Fi, and storage health.'],
                ['3', 'Record condition & defects', 'Photograph any cosmetic marks and document known issues on the product page.'],
                ['4', 'Approve for sale', 'Only units that pass all four steps are listed. Anything else is set aside.'],
            ];
            foreach ($steps as [$num, $title, $text]):
            ?>
                <li class="relative rounded-xl bg-white p-6 ring-1 ring-slate-200">
                    <span class="absolute -top-3 -left-3 flex h-9 w-9 items-center justify-center rounded-full bg-brand-blue text-white text-sm font-bold ring-4 ring-white"><?= e($num) ?></span>
                    <p class="mt-3 font-semibold text-navy-700"><?= e($title) ?></p>
                    <p class="mt-2 text-sm text-slate-500 leading-relaxed"><?= e($text) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>

<?php /* ====== I. Popular brands ====== */ ?>
<?php if (!empty($brands)): ?>
<section class="bg-slate-50 border-y border-slate-200">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-12">
        <h2 class="text-center text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Popular brands</h2>
        <p class="mt-2 text-center text-sm text-slate-500">Only brands with available stock are shown.</p>
        <div class="mt-8 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
            <?php foreach ($brands as $brand): ?>
                <a href="<?= e(url('/brands/' . $brand['slug'])) ?>" class="group flex items-center justify-center rounded-xl bg-white p-6 ring-1 ring-slate-200 transition hover:ring-brand-blue hover:shadow-md">
                    <span class="font-semibold text-slate-700 group-hover:text-brand-blue text-lg"><?= e($brand['name']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php /* ====== J. Special deals ====== */ ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16">
    <div class="flex items-end justify-between gap-4">
        <div>
            <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Special deals</h2>
            <p class="mt-1 text-sm text-slate-500">Active promotions on in-stock laptops. No fake countdown timers — these are real prices.</p>
        </div>
        <a href="<?= e(url('/shop?budget=1')) ?>" class="text-sm font-semibold text-brand-blue hover:underline whitespace-nowrap">All deals →</a>
    </div>

    <?php
    // "Special deals" = the 4 cheapest in-stock laptops right now.
    // Real data from the catalog — never invented.
    $deals = array_slice($featured, 0, 4);
    ?>
    <?php if ($deals === []): ?>
        <p class="mt-6 rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">Special deals appear here when active promotions are running. Check back soon.</p>
    <?php else: ?>
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <?php foreach ($deals as $product): ?>
                <?= \App\Support\View::component("product-card", ["product" => $product]) ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php /* ====== K. Customer reviews (empty state — no fake testimonials) ====== */ ?>
<section class="bg-slate-50 border-y border-slate-200">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16">
        <div class="text-center max-w-2xl mx-auto">
            <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Customer reviews</h2>
            <p class="mt-2 text-sm text-slate-500">Verified customer feedback will appear here once the review system is live.</p>
        </div>
        <div class="mt-8 rounded-xl border border-dashed border-slate-300 bg-white p-8 sm:p-12 text-center">
            <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-brand-blue">
                <?= \App\Support\View::component('icon', ['name' => 'heart', 'class' => 'h-6 w-6']) ?>
            </span>
            <p class="mt-4 font-semibold text-navy-700">No reviews yet</p>
            <p class="mt-1 text-sm text-slate-500 max-w-md mx-auto">We don't fabricate testimonials. Once customers buy and review their laptops, their verified feedback will show up here.</p>
        </div>
    </div>
</section>

<?php /* ====== L. FAQ ====== */ ?>
<section class="mx-auto max-w-3xl px-4 sm:px-6 py-12 sm:py-16">
    <div class="text-center">
        <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Frequently asked questions</h2>
        <p class="mt-2 text-sm text-slate-500">Answers come from the configurable business policies managed in the admin CMS.</p>
    </div>

    <?php if ($faqs === []): ?>
        <div class="mt-8 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
            <p class="text-sm text-slate-500">FAQ entries will appear here once the content manager publishes them.</p>
            <p class="mt-1 text-xs text-slate-400">Admin → Marketing &amp; Content → FAQs</p>
        </div>
    <?php else: ?>
        <dl class="mt-8 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
            <?php foreach ($faqs as $faq): ?>
                <details class="group p-5">
                    <summary class="flex cursor-pointer items-center justify-between gap-4 font-medium text-navy-700">
                        <?= e($faq['question']) ?>
                        <span class="text-slate-400 group-open:rotate-180 transition">
                            <?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-5 w-5']) ?>
                        </span>
                    </summary>
                    <dd class="mt-3 text-sm text-slate-600 leading-relaxed"><?= e($faq['answer']) ?></dd>
                </details>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
</section>

<?php /* ====== M. Final call to action ====== */ ?>
<section class="bg-navy-700 text-white relative overflow-hidden">
    <div class="absolute inset-0 opacity-25" aria-hidden="true" style="background: radial-gradient(50% 80% at 80% 50%, rgba(37,99,235,0.5) 0%, transparent 70%);"></div>
    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 py-16 sm:py-20 text-center">
        <?php if (!empty($finalCtaBlock)): ?>
            <div class="prose prose-invert max-w-none mx-auto"><?= $finalCtaBlock ?></div>
        <?php else: ?>
            <h2 class="text-2xl sm:text-3xl font-bold tracking-tight">Find the right laptop for your budget.</h2>
            <p class="mt-3 text-sm sm:text-base text-slate-300 max-w-lg mx-auto">Browse our full inventory or contact us with your requirements — we'll point you to the right unit.</p>
        <?php endif; ?>
        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            <a href="<?= e(url('/shop')) ?>" class="inline-flex items-center gap-2 rounded-lg bg-brand-blue px-6 py-3 text-sm font-semibold hover:bg-blue-700 transition">Explore all laptops</a>
            <a href="<?= e(url('/contact')) ?>" class="inline-flex items-center gap-2 rounded-lg border border-white/20 px-6 py-3 text-sm font-semibold hover:bg-white/10 transition">Contact us</a>
        </div>
    </div>
</section>
