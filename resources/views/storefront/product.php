<?php /** @var array $model
 *  @var list<array{id:int,sku:string,condition_grade:string,selling_price:string} $units
 *  @var list<array{id:int,file_path:string,alt:?string,is_primary:int}> $images
 */
$hasUnits = ($units ?? []) !== [];
$lowestPrice = $hasUnits ? min(array_map(fn ($u) => (float) $u['selling_price'], $units)) : null;
$highestPrice = $hasUnits ? max(array_map(fn ($u) => (float) $u['selling_price'], $units)) : null;
$isPriceRange = $hasUnits && $lowestPrice !== $highestPrice;
$fromPrice = $lowestPrice !== null ? money($lowestPrice) : '—';

// Gallery: only images whose file actually exists (missing files fall back to placeholder).
$gallery = array_values(array_filter($images ?? [], fn ($g) => is_file(BASE_PATH . '/public/' . $g['file_path'])));
$galleryAlt = fn ($g) => trim((string) ($g['alt'] ?? '')) !== ''
    ? (string) $g['alt']
    : 'Illustrative image of ' . $model['name'] . ' — not an actual unit photo';
?>
<nav class="text-xs text-slate-500 px-4 sm:px-6" aria-label="Breadcrumb">
    <ol class="mx-auto max-w-7xl flex items-center gap-1.5 py-4">
        <li><a href="<?= e(url('/')) ?>" class="hover:text-brand-blue">Home</a></li>
        <li aria-hidden="true">/</li>
        <li><a href="<?= e(url('/shop')) ?>" class="hover:text-brand-blue">Shop</a></li>
        <li aria-hidden="true">/</li>
        <li><a href="<?= e(url('/brands/' . $model['brand_slug'])) ?>" class="hover:text-brand-blue"><?= e($model['brand_name']) ?></a></li>
        <li aria-hidden="true">/</li>
        <li aria-current="page" class="font-medium text-navy-700 truncate"><?= e($model['name']) ?></li>
    </ol>
</nav>

<div class="mx-auto max-w-7xl px-4 sm:px-6 pb-10">
    <div class="grid gap-8 lg:grid-cols-2">

        <?php /* ====== Left: Image gallery ====== */ ?>
        <section>
            <?php if ($gallery !== []): ?>
            <div class="rounded-2xl bg-gradient-to-br from-slate-100 to-slate-200 aspect-[4/3] ring-1 ring-slate-200 overflow-hidden flex items-center justify-center">
                <img src="<?= e(url($gallery[0]['file_path'])) ?>" alt="<?= e($galleryAlt($gallery[0])) ?>"
                     class="h-full w-full object-cover" id="product-main-image">
            </div>
            <?php if (count($gallery) > 1): ?>
            <div class="mt-3 grid grid-cols-5 gap-2">
                <?php foreach ($gallery as $gi => $g): ?>
                    <button type="button"
                            class="product-gallery-thumb aspect-square rounded-lg ring-1 <?= $gi === 0 ? 'ring-brand-blue' : 'ring-slate-200' ?> overflow-hidden bg-slate-100"
                            data-full-src="<?= e(url($g['file_path'])) ?>" data-full-alt="<?= e($galleryAlt($g)) ?>"
                            aria-label="Show image <?= $gi + 1 ?> of <?= count($gallery) ?>">
                        <img src="<?= e(url($g['file_path'])) ?>" alt="<?= e($galleryAlt($g)) ?>" class="h-full w-full object-cover">
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <p class="mt-2 text-xs text-slate-400">Catalog images are illustrative. Photos of the exact unit you select are shown after you pick a unit below.</p>
            <?php else: ?>
            <div class="rounded-2xl bg-gradient-to-br from-slate-100 to-slate-200 aspect-[4/3] ring-1 ring-slate-200 overflow-hidden flex items-center justify-center">
                <span class="text-slate-400">
                    <?= \App\Support\View::component('icon', ['name' => 'box', 'class' => 'h-20 w-20']) ?>
                </span>
            </div>
            <p class="mt-2 text-xs text-slate-400">No catalog photos yet. Photos of the exact unit you select are shown after you pick a unit below.</p>
            <?php endif; ?>
        </section>

        <?php /* ====== Right: Buy box ====== */ ?>
        <section class="rounded-2xl bg-white ring-1 ring-slate-200 p-6 sm:p-8">
            <p class="text-xs font-semibold uppercase tracking-wider text-brand-blue"><?= e($model['brand_name']) ?> · <?= e($model['category_name']) ?></p>
            <h1 class="mt-1 text-2xl sm:text-3xl font-bold tracking-tight text-navy-700"><?= e($model['name']) ?></h1>

            <?php if ($hasUnits): ?>
                <div class="mt-4 flex items-baseline gap-3">
                    <span class="text-xs uppercase text-slate-400"><?= $isPriceRange ? 'From' : 'Price' ?></span>
                    <span class="text-3xl font-bold text-navy-700"><?= e($fromPrice) ?></span>
                    <?php if ($isPriceRange): ?>
                        <span class="text-sm text-slate-500">to <?= money($highestPrice) ?></span>
                    <?php endif; ?>
                </div>
                <p class="mt-1 text-xs text-slate-500">Price shown is for the lowest-condition unit. Higher grades cost more.</p>

                <div class="mt-4 flex flex-wrap gap-2 text-xs">
                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-green/10 text-brand-green px-2.5 py-1 font-semibold ring-1 ring-brand-green/20">
                        <span class="h-1.5 w-1.5 rounded-full bg-brand-green"></span> Quality Checked
                    </span>
                    <span class="inline-flex items-center rounded-full bg-blue-50 text-brand-blue px-2.5 py-1 font-semibold ring-1 ring-blue-200">
                        <?= count($units) ?> unit(s) available
                    </span>
                </div>
            <?php else: ?>
                <div class="mt-4">
                    <span class="text-3xl font-bold text-slate-400">Out of stock</span>
                    <p class="mt-1 text-xs text-slate-500">This model has no available units right now.</p>
                </div>
            <?php endif; ?>

            <?php /* Quick spec summary */ ?>
            <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <?php
                $specs = [
                    'Processor' => $model['processor'],
                    'Memory' => !empty($model['ram_gb']) ? $model['ram_gb'] . ' GB' : null,
                    'Storage' => !empty($model['storage_capacity_gb']) ? $model['storage_capacity_gb'] . ' GB ' . $model['storage_type'] : null,
                    'Graphics' => $model['graphics'],
                    'Display' => !empty($model['display_size_inches']) ? $model['display_size_inches'] . '"' . ($model['resolution'] ? ' ' . $model['resolution'] : '') : null,
                    'OS' => $model['operating_system'],
                ];
                foreach ($specs as $label => $value): ?>
                    <div>
                        <dt class="text-xs text-slate-400 uppercase tracking-wider"><?= e($label) ?></dt>
                        <dd class="mt-0.5 font-medium text-navy-700"><?= e($value ?? '—') ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>

            <?php /* Action buttons */ ?>
            <div class="mt-6 flex flex-col gap-2">
                <?php if ($hasUnits): ?>
                    <a href="#units" class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-blue px-6 py-3 text-sm font-semibold text-white hover:bg-blue-700 transition">
                        <?= \App\Support\View::component('icon', ['name' => 'shopping-cart', 'class' => 'h-5 w-5']) ?>
                        Choose a unit to buy
                    </a>
                    <a href="#units" id="add-to-cart" class="inline-flex items-center justify-center gap-2 rounded-lg bg-navy-700 px-6 py-3 text-sm font-semibold text-white hover:bg-navy-800 transition">
                        Buy now (select unit below)
                    </a>
                <?php else: ?>
                    <a href="<?= e(url('/contact')) ?>" class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-blue px-6 py-3 text-sm font-semibold text-white hover:bg-blue-700 transition">
                        Notify me when back in stock
                    </a>
                <?php endif; ?>
                <?php /* WhatsApp option — only when business has configured a number */ ?>
                <?php $whatsapp = \App\Services\SettingService::get('contact_whatsapp', ''); ?>
                <?php if ($whatsapp !== ''): ?>
                    <a href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', $whatsapp)) ?>?text=<?= urlencode('Hi, I am interested in the ' . $model['name'] . ' on your website') ?>" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 rounded-lg border border-brand-green text-brand-green px-6 py-3 text-sm font-semibold hover:bg-brand-green/5 transition">
                        <?= \App\Support\View::component('icon', ['name' => 'megaphone', 'class' => 'h-5 w-5']) ?>
                        WhatsApp us about this laptop
                    </a>
                <?php endif; ?>
            </div>

            <?php /* Trust badges */ ?>
            <dl class="mt-6 pt-6 border-t border-slate-100 grid grid-cols-3 gap-3 text-xs text-center">
                <div><dt class="text-slate-400">Inspection</dt><dd class="mt-1 font-semibold text-navy-700">Per-unit</dd></div>
                <div><dt class="text-slate-400">Warranty</dt><dd class="mt-1 font-semibold text-navy-700">Where set</dd></div>
                <div><dt class="text-slate-400">Delivery</dt><dd class="mt-1 font-semibold text-navy-700">Or pickup</dd></div>
            </dl>
        </section>
    </div>

    <?php /* ====== Description + specs table ====== */ ?>
    <section class="mt-12 grid gap-8 lg:grid-cols-3">
        <div class="lg:col-span-2 rounded-2xl bg-white ring-1 ring-slate-200 p-6 sm:p-8">
            <h2 class="text-lg font-semibold text-navy-700">About this laptop</h2>
            <p class="mt-3 text-sm leading-relaxed text-slate-600">
                <?= e($model['description'] ?? 'Dubai-imported authentic used laptop, individually inspected and condition-graded by our technicians. Real specifications are shown above. Select a specific unit below to see its exact condition grade, price, and any known cosmetic marks.') ?>
            </p>

            <h3 class="mt-8 text-base font-semibold text-navy-700">Technical specifications</h3>
            <table class="mt-3 w-full text-sm">
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($specs as $label => $value): ?>
                        <tr><th scope="row" class="py-2.5 text-left text-slate-500 font-normal w-1/3"><?= e($label) ?></th><td class="py-2.5 font-medium text-navy-700"><?= e($value ?? '—') ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <aside class="space-y-4">
            <div class="rounded-2xl bg-white ring-1 ring-slate-200 p-5">
                <h3 class="text-sm font-semibold text-navy-700">Inspection summary</h3>
                <p class="mt-2 text-xs text-slate-500 leading-relaxed">Every unit on this page passed our four-step inspection: identity verified, hardware tested, condition recorded, and approved for sale.</p>
                <a href="<?= e(url('/about')) ?>" class="mt-3 inline-flex text-xs font-semibold text-brand-blue hover:underline">See the inspection process →</a>
            </div>
            <div class="rounded-2xl bg-white ring-1 ring-slate-200 p-5">
                <h3 class="text-sm font-semibold text-navy-700">Warranty &amp; returns</h3>
                <ul class="mt-2 space-y-1.5 text-xs text-slate-500">
                    <li>Warranty terms shown per-unit when configured</li>
                    <li>Return policy set by the business</li>
                    <li><a href="<?= e(url('/page/returns')) ?>" class="text-brand-blue hover:underline">Read return policy →</a></li>
                </ul>
            </div>
            <div class="rounded-2xl bg-white ring-1 ring-slate-200 p-5">
                <h3 class="text-sm font-semibold text-navy-700">Delivery</h3>
                <p class="mt-2 text-xs text-slate-500">Delivery available nationwide or pick up from our physical store.</p>
                <a href="<?= e(url('/contact')) ?>" class="mt-2 inline-flex text-xs font-semibold text-brand-blue hover:underline">Visit us →</a>
            </div>
        </aside>
    </section>

    <?php /* ====== Units in stock — exact condition per unit ====== */ ?>
    <section id="units" class="mt-12">
        <h2 class="text-lg font-semibold text-navy-700">Available units — exact condition</h2>
        <p class="mt-1 text-sm text-slate-500">Used laptops are individually tracked. Each unit below is the actual laptop you would receive — its condition, price, and SKU are real.</p>

        <?php if (!$hasUnits): ?>
            <div class="mt-4"><?= \App\Support\View::component('empty-state', [
                'title' => 'No units currently available',
                'description' => 'This model is listed but every unit is sold or reserved. Contact us about the next shipment.',
                'icon' => 'package-check',
            ]) ?></div>
        <?php else: ?>
            <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                <?php foreach ($units as $unit): ?>
                    <li class="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4 hover:ring-brand-blue hover:ring-1 transition">
                        <div class="flex items-center gap-4">
                            <span class="flex h-12 w-12 items-center justify-center rounded-lg bg-blue-50 text-sm font-bold text-brand-blue">G<?= e($unit['condition_grade']) ?></span>
                            <div>
                                <p class="font-semibold text-navy-700">Grade <?= e($unit['condition_grade']) ?></p>
                                <p class="text-xs text-slate-500">SKU <?= e($unit['sku']) ?></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="font-bold text-navy-700"><?= money($unit['selling_price']) ?></p>
                            <form method="post" action="<?= e(url('/cart/add')) ?>" class="mt-1 inline" data-loading-form>
                                <?= csrf_field() ?>
                                <input type="hidden" name="unit_id" value="<?= (int) $unit['id'] ?>">
                                <button type="submit" class="inline-flex items-center gap-1 rounded-lg bg-brand-blue px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 transition">
                                    <?= \App\Support\View::component('icon', ['name' => 'shopping-cart', 'class' => 'h-3.5 w-3.5']) ?>
                                    Add to cart
                                </button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="mt-3 text-xs text-slate-400">Grade A = like new · Grade B = light wear · Grade C = visible marks (documented on inspection). The exact condition of each unit is recorded at inspection.</p>
        <?php endif; ?>
    </section>

    <?php /* ====== Related laptops ====== */ ?>
    <section class="mt-16">
        <h2 class="text-lg font-semibold text-navy-700">Related laptops</h2>
        <p class="mt-1 text-sm text-slate-500">Other Dubai-imported laptops in similar price range.</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <?php /* Placeholder — real related-products lookup ships with the catalog-related module */ ?>
            <div class="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-400">Related laptops appear here when other in-stock models are available.</div>
        </div>
    </section>
</div>
