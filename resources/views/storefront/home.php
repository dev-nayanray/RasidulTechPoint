<?php /**
 * Homepage — Rasidul Tech Point reference design clone, fully dynamic.
 * Every product/price/count below is real database state; sample-looking
 * copy only appears where the reference itself uses honest placeholders.
 *
 * @var list<array> $featured   one exact available unit per model
 * @var list<array> $categories categories with available stock
 * @var list<array> $brands     brands with available stock
 * @var list<array{label:string,min:int,max:?int}> $budgetRanges
 * @var array{min:int,max:int} $priceBounds
 * @var list<array> $faqs
 * @var string $heroBlock $whyChooseBlock $howWeCheckBlock $finalCtaBlock
 */

$laptopSvg = static fn (string $label = 'Laptop illustration', bool $ok = true): string =>
    '<svg class="laptop" viewBox="0 0 320 200" role="img" aria-label="' . e($label) . '">'
    . '<ellipse class="lp-shadow" cx="160" cy="186" rx="124" ry="8"></ellipse>'
    . '<rect class="lp-lid" x="56" y="8" width="208" height="134" rx="10"></rect>'
    . '<rect class="lp-rim" x="56.5" y="8.5" width="207" height="133" rx="10"></rect>'
    . '<rect class="lp-screen" x="65" y="17" width="190" height="116" rx="4"></rect>'
    . '<rect class="lp-glow" x="75" y="27" width="46" height="7" rx="3.5"></rect>'
    . '<rect class="lp-card" x="75" y="42" width="52" height="34" rx="4"></rect>'
    . '<rect class="lp-card" x="133" y="42" width="52" height="34" rx="4"></rect>'
    . '<rect class="lp-card" x="191" y="42" width="54" height="34" rx="4"></rect>'
    . '<rect class="lp-line" x="75" y="86" width="170" height="5" rx="2.5"></rect>'
    . '<rect class="lp-line" x="75" y="97" width="132" height="5" rx="2.5"></rect>'
    . ($ok ? '<circle class="lp-ok" cx="236" cy="30" r="4"></circle>' : '')
    . '<path class="lp-base" d="M22 146h276l-12 24a10 10 0 0 1-9 5H43a10 10 0 0 1-9-5z"></path>'
    . '<rect class="lp-notch" x="124" y="146" width="72" height="6" rx="3"></rect>'
    . '<path class="lp-keys" d="M52 158h216M48 165h224M45 172h230"></path>'
    . '</svg>';

$arrowSvg = '<svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"></path></svg>';
$checkSvg = '<svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>';

// Tile icon set (reference icons).
$tileIcons = [
    '<svg class="ic" viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="10" rx="1.5"></rect><path d="M3 19h18"></path></svg>',
    '<svg class="ic" viewBox="0 0 24 24"><path d="M2 9l10-5 10 5-10 5z"></path><path d="M6 11.5V16c3 2.5 9 2.5 12 0v-4.5"></path></svg>',
    '<svg class="ic" viewBox="0 0 24 24"><path d="M3 16h18M5 16V8.5A1.5 1.5 0 0 1 6.5 7h11A1.5 1.5 0 0 1 19 8.5V16"></path><path d="M9 20h6"></path></svg>',
    '<svg class="ic" viewBox="0 0 24 24"><path d="M20 12l-8 8-9-9V3h8z"></path><path d="M7.5 7.5h.01"></path></svg>',
    '<svg class="ic" viewBox="0 0 24 24"><path d="M6 4h12l3 5-9 11L3 9z"></path><path d="M3 9h18M9 4l3 5 3-5"></path></svg>',
    '<svg class="ic" viewBox="0 0 24 24"><path d="M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6z"></path></svg>',
    '<svg class="ic" viewBox="0 0 24 24"><path d="M4 9l1.5-5h13L20 9M4 9v11h16V9M4 9h16M10 20v-6h4v6"></path></svg>',
];
$tileStyles = ['t-navy big', 't-tint', 't-white', 't-blue', 't-mint', 't-white wide', 't-ink wide'];

$specText = static fn (array $u): string =>
    trim(($u['processor'] ?? '') . ' | ' . (int) $u['ram_gb'] . ' GB | ' . (int) $u['storage_capacity_gb'] . ' GB ' . strtoupper((string) $u['storage_type']));

$heroUnit = $featured[0] ?? null;
?>

<?php /* ====== Hero ====== */ ?>
<section class="hero">
    <div class="wrap">
        <div class="hero-in">
            <div style="display: flex; flex-direction: column; gap: 24px; align-items: flex-start;">
                <span class="stamp mono">
                    <svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"></path></svg>
                    Dubai Imported | Authentic Used
                </span>
                <h1>Premium Laptops. <span class="hl">Smarter Prices.</span></h1>
                <p class="lead">Discover quality-checked Dubai-imported laptops for study, work, business, and everyday use.</p>
                <div class="hero-ctas">
                    <a class="btn btn-primary" href="<?= e(url('/shop')) ?>">Shop Laptops</a>
                    <a class="btn btn-onnavy" href="<?= e(url('/shop?budget=1')) ?>">Explore Budget Deals</a>
                </div>
                <ul class="trust">
                    <li><?= $checkSvg ?>Quality Checked</li>
                    <li><?= $checkSvg ?>Transparent Specifications</li>
                    <li><?= $checkSvg ?>Warranty Options</li>
                </ul>
            </div>
            <div class="art">
                <span class="qc"><?= $checkSvg ?>Quality Checked</span>
                <div class="art-lap">
                    <?php if ($heroUnit !== null): ?>
                        <span class="chipf cf1"><i>CPU</i><?= e(trim(explode('-', (string) $heroUnit['processor'])[0] ?: (string) $heroUnit['processor'])) ?></span>
                        <span class="chipf cf2"><i>RAM</i><?= (int) $heroUnit['ram_gb'] ?> GB</span>
                        <span class="chipf cf3"><i>SSD</i><?= (int) $heroUnit['storage_capacity_gb'] ?> GB</span>
                    <?php else: ?>
                        <span class="chipf cf1"><i>CPU</i>Core i5</span>
                        <span class="chipf cf2"><i>RAM</i>8 GB</span>
                        <span class="chipf cf3"><i>SSD</i>256 GB</span>
                    <?php endif; ?>
                    <?= $laptopSvg() ?>
                </div>
                <div class="tag">
                    <span class="mono">Spec tag | <?= $heroUnit !== null ? 'unit ' . e($heroUnit['sku']) : 'sample unit' ?></span>
                    <b><?= $heroUnit !== null ? e($heroUnit['brand_name'] . ' ' . $heroUnit['model_name']) : 'Rasidul Tech Point laptop' ?></b>
                    <span class="sp"><?= $heroUnit !== null ? e($specText($heroUnit)) : 'Real specs load here from live inventory' ?></span>
                    <span class="price"><?= $heroUnit !== null ? money($heroUnit['selling_price']) : '৳—' ?></span>
                </div>
                <p class="art-note"><?= $heroUnit !== null ? 'Exact unit ' . e($heroUnit['sku']) . ' — inspect its full condition on the product page.' : 'Illustration. Photos of each exact unit replace this on the live store.' ?></p>
            </div>
        </div>
    </div>
</section>

<?php /* ====== Quick finder ====== */ ?>
<div class="wrap">
    <div class="finder">
        <div>
            <span class="flabel mono">I need a laptop for</span>
            <div class="fchips">
                <?php foreach (['Business' => 'business', 'Student' => 'student', 'Core i5' => 'i5', 'Core i7' => 'i7', 'Portable' => 'portable', 'Premium' => 'premium'] as $label => $term): ?>
                    <a class="fchip" href="<?= e(url('/shop?q=' . urlencode($term))) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div>
            <span class="flabel mono">My budget</span>
            <div class="fchips">
                <?php foreach ($budgetRanges as $i => $range): ?>
                    <a class="fchip" href="<?= e(url('/shop?budget=' . ($i + 1))) ?>"><?= e($range['label']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <a class="btn btn-primary" href="<?= e(url('/shop')) ?>">Show all laptops</a>
    </div>
</div>

<?php /* ====== Shop by Category ====== */ ?>
<?php if ($categories !== []): ?>
<section class="sec" id="categories">
    <div class="wrap">
        <div class="sec-head">
            <div>
                <span class="eyebrow mono">Browse</span>
                <h2>Shop by Category</h2>
                <p class="sub">Start with how you plan to use the laptop.</p>
            </div>
        </div>
        <div class="bento">
            <?php foreach ($categories as $i => $cat): ?>
                <a class="tile <?= e($tileStyles[$i % count($tileStyles)]) ?>" href="<?= e(url('/shop?category=' . (int) $cat['id'])) ?>">
                    <span class="tile-mark" aria-hidden="true"><?= $tileIcons[$i % count($tileIcons)] ?></span>
                    <span class="tile-name"><?= e($cat['name']) ?></span>
                    <span class="tile-go mono"><?= (int) $cat['available_units'] ?> laptops <?= $arrowSvg ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php /* ====== Shop by Budget ====== */ ?>
<section class="sec sec-white" id="budget">
    <div class="wrap">
        <div class="sec-head">
            <div>
                <span class="eyebrow mono">Price</span>
                <h2>Shop by Budget</h2>
                <p class="sub">Pick a price range and see laptops in that range.</p>
            </div>
        </div>
        <div class="budget-grid">
            <?php
            $scale = max(1, $priceBounds['max']);
            foreach ($budgetRanges as $i => $range):
                $leftPct = min(100, (int) round(($range['min'] / $scale) * 100));
                $endPct = min(100, (int) round(($range['max'] ?? $scale) / $scale * 100));
                $widthPct = max(8, $endPct - $leftPct);
            ?>
                <a class="bcard" href="<?= e(url('/shop?budget=' . ($i + 1))) ?>">
                    <span class="bl"><?= e($range['label']) ?></span>
                    <span>
                        <span class="rtrack"><span class="rseg" style="left: <?= $leftPct ?>%; width: <?= $widthPct ?>%;"></span></span>
                        <span class="rscale mono"><span><?= e(money($priceBounds['min'])) ?></span><span><?= e(money($priceBounds['max'])) ?></span></span>
                    </span>
                    <span class="bgo">View laptops <?= $arrowSvg ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php /* ====== Featured Laptops ====== */ ?>
<section class="sec" id="featured">
    <div class="wrap">
        <div class="sec-head">
            <div>
                <span class="eyebrow mono">Ready to buy</span>
                <h2>Featured Laptops</h2>
                <p class="sub">Every card shows the specs and condition of one exact unit.</p>
            </div>
            <div class="seg" role="group" aria-label="Sort laptops">
                <a href="<?= e(url('/shop')) ?>">Featured</a>
                <a href="<?= e(url('/shop?sort=price_asc')) ?>">Price: Low to High</a>
                <a href="<?= e(url('/shop?sort=price_desc')) ?>">Price: High to Low</a>
            </div>
        </div>
        <?php if ($featured === []): ?>
            <div class="chips">
                <span class="pill pill-note">No laptops are in stock right now. New Dubai-imported stock appears here as soon as units are received and inspected.</span>
            </div>
        <?php else: ?>
            <div class="pgrid">
                <?php foreach ($featured as $u): ?>
                    <article class="pcard">
                        <div class="pimg">
                            <span class="st">
                                <span class="st-ok"><?= $checkSvg ?>Quality Checked</span>
                            </span>
                            <form method="post" action="<?= e(url('/account/wishlist/add')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="product_model_id" value="<?= (int) $u['model_id'] ?>">
                                <button class="wish" aria-label="Save to wishlist" title="Save to wishlist">
                                    <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-9-9.2C1.8 7.6 3.7 4.5 7 4.5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.3 0 5.2 3.1 4 6.3C19 15.6 12 20 12 20z"></path></svg>
                                </button>
                            </form>
                            <?php if (!empty($u['image_path'])): ?>
                                <img src="<?= e(url(preg_replace('#^public/#', '', (string) $u['image_path']))) ?>" alt="<?= e($u['brand_name'] . ' ' . $u['model_name']) ?>" loading="lazy" style="width: 100%; height: auto; max-height: 300px; object-fit: contain; border-radius: 12px;">
                            <?php else: ?>
                                <?= $laptopSvg($u['brand_name'] . ' ' . $u['model_name']) ?>
                                <span class="uid mono">Unit <?= e($u['sku']) ?> | photo goes here</span>
                            <?php endif; ?>
                        </div>
                        <div class="pbody">
                            <div class="ptop"><span class="pbrand mono"><?= e($u['brand_name']) ?></span><span class="grade">Grade <?= e($u['condition_grade']) ?></span></div>
                            <h3 class="pmodel"><?= e($u['model_name']) ?></h3>
                            <div class="specs">
                                <div class="spec"><span class="sl">CPU</span><span class="sv"><?= e($u['processor']) ?></span></div>
                                <div class="spec"><span class="sl">RAM</span><span class="sv"><?= (int) $u['ram_gb'] ?> GB</span></div>
                                <div class="spec"><span class="sl">STORAGE</span><span class="sv"><?= (int) $u['storage_capacity_gb'] ?> GB <?= e(strtoupper((string) $u['storage_type'])) ?></span></div>
                                <div class="spec"><span class="sl">SCREEN</span><span class="sv"><?= e(rtrim(rtrim(number_format((float) $u['display_size_inches'], 1, '.', ''), '0'), '.')) ?>"</span></div>
                            </div>
                        </div>
                        <div class="pfoot">
                            <div><span class="plabel mono">Price</span><span class="pprice"><?= e(money($u['selling_price'])) ?></span></div>
                            <?php if ((int) $u['available_units'] > 1): ?>
                                <span class="av-ok avail"><i></i>Available</span>
                            <?php else: ?>
                                <span class="av-low avail"><i></i>Only 1 left</span>
                            <?php endif; ?>
                        </div>
                        <div class="pact">
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('/shop/' . $u['model_slug'])) ?>">View Details</a>
                            <form method="post" action="<?= e(url('/cart/add')) ?>" style="display: contents;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="unit_id" value="<?= (int) $u['unit_id'] ?>">
                                <button class="btn btn-primary btn-sm" type="submit">Add to Cart</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="chips" style="margin-top: 18px;">
                <a class="btn btn-ghost" href="<?= e(url('/shop')) ?>">View all laptops <?= $arrowSvg ?></a>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php /* ====== Why choose us ====== */ ?>
<section class="sec sec-white" id="why">
    <div class="wrap">
        <div class="why-in">
            <div>
                <span class="eyebrow mono">About Us</span>
                <h2 class="h2">Why Choose Rasidul Tech Point?</h2>
                <p class="sub">What you can expect when you buy a used laptop from us.</p>
                <div style="margin-top: 24px;"><a class="btn btn-ghost" href="<?= e(url('/page/inspection-process')) ?>">See how we check laptops</a></div>
            </div>
            <div class="why-grid">
                <?php
                $whys = [
                    ['<circle cx="12" cy="12" r="9"></circle><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"></path>', 'Dubai-imported selection', 'Used laptops imported from Dubai, listed with their real condition.'],
                    ['<circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4M8 11l2 2 3.5-4"></path>', 'Individual unit inspection', 'Each unit has its own inspection record before it goes on sale.'],
                    ['<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"></path>', 'Clear condition and specifications', 'Grade, specs and known marks are written out for every unit.'],
                    ['<path d="M20 12l-8 8-9-9V3h8z"></path><path d="M7.5 7.5h.01"></path>', 'Transparent pricing', 'The price you see is the price of that exact unit, in BDT.'],
                    ['<path d="M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6z"></path>', 'Warranty information', 'When a warranty applies, its terms appear on that laptop\'s page.'],
                    ['<path d="M4 9l1.5-5h13L20 9M4 9v11h16V9M4 9h16M10 20v-6h4v6"></path>', 'Online and in-store buying', 'Order online or visit the store. Store details are in the footer.'],
                    ['<path d="M4 5h16v11H9l-5 4z"></path>', 'Customer support', 'Ask about any laptop before you buy. Contact options are in the footer.'],
                ];
                foreach ($whys as [$icon, $title, $text]): ?>
                    <div class="why">
                        <span class="why-ico"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg></span>
                        <div>
                            <h3><?= e($title) ?></h3>
                            <p><?= e($text) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<?php /* ====== How we check (inspection) ====== */ ?>
<section class="sec dark" id="inspection">
    <div class="wrap">
        <span class="eyebrow mono" style="color: rgb(127, 164, 245);">Inspection</span>
        <h2 class="h2">How We Check Our Laptops</h2>
        <p class="sub">Every laptop gets its own inspection record. You see the result on the product page, including any known marks or defects.</p>
        <div class="steps">
            <?php
            $steps = [
                ['Verify the laptop', 'Confirm the model and its identifying details match the unit.'],
                ['Test key hardware', 'Check the main components work as they should.'],
                ['Record condition and defects', 'Write down the condition and any known defects or marks.'],
                ['Approve for sale', 'Approve the unit for sale only after inspection is complete.'],
            ];
            foreach ($steps as $i => [$title, $text]): ?>
                <div class="stepc">
                    <span class="step-n"><?= $i + 1 ?></span>
                    <h3><?= e($title) ?></h3>
                    <p><?= e($text) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="record">
            <span class="mono" style="color: rgb(127, 164, 245);">Inspection record</span>
            <span>Result: <b>recorded per unit</b></span>
            <span>Known defects: <b>listed on the product page</b></span>
            <span>Not every laptop passes. Only approved units are marked Quality Checked.</span>
        </div>
    </div>
</section>

<?php /* ====== Popular Brands ====== */ ?>
<?php if ($brands !== []): ?>
<section class="sec" id="brands">
    <div class="wrap">
        <div class="brand-row">
            <div>
                <span class="eyebrow mono">Brands</span>
                <h2 class="h2" style="font-size: clamp(26px, 3vw, 34px);">Popular Brands</h2>
            </div>
            <div class="brand-list">
                <?php foreach ($brands as $b): ?>
                    <a class="brand" href="<?= e(url('/shop?brand=' . (int) $b['id'])) ?>"><?= e($b['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php /* ====== Special Deals ====== */ ?>
<section class="sec sec-white" id="deals">
    <div class="wrap">
        <div class="sec-head">
            <div>
                <span class="eyebrow mono">Offers</span>
                <h2>Special Deals</h2>
                <p class="sub">Promotions use live prices only.</p>
            </div>
        </div>
        <div class="deals">
            <div class="deal-main">
                <span class="mono" style="color: rgb(220, 231, 255);">Budget Deals</span>
                <h3>Find a laptop that fits your price.</h3>
                <p>Browse by budget and compare specs and condition side by side.</p>
                <a class="btn btn-light" href="<?= e(url('/shop?budget=1')) ?>">Explore Budget Deals</a>
                <?= $laptopSvg('', false) /* decorative */ ?>
            </div>
            <div class="deal-side">
                <div class="deal-ghost">
                    <span class="mono">Active promotions</span>
                    <p>When the business publishes a promotion, its live terms appear here. Prices on this site are always the real unit prices — we never show invented discounts.</p>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/shop')) ?>">Browse all laptops</a>
                </div>
                <div class="deal-ghost">
                    <span class="mono">Recently received</span>
                    <p>Freshly inspected Dubai stock appears in the shop as soon as it is approved for sale.</p>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/shop')) ?>">See newest arrivals</a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php /* ====== Customer Reviews (honest empty state — no fabricated reviews) ====== */ ?>
<section class="sec" id="reviews">
    <div class="wrap">
        <div class="rv-in">
            <div>
                <span class="eyebrow mono">Reviews</span>
                <h2 class="h2">Customer Reviews</h2>
                <p class="sub">Only reviews from real, confirmed orders are published here. No reviews have been added yet.</p>
            </div>
            <div class="rv-ghosts" aria-hidden="true">
                <div class="ghost"><span class="gdot"></span><span class="gbar" style="width: 90%;"></span><span class="gbar" style="width: 70%;"></span><span class="gbar" style="width: 40%;"></span></div>
                <div class="ghost"><span class="gdot"></span><span class="gbar" style="width: 80%;"></span><span class="gbar" style="width: 90%;"></span><span class="gbar" style="width: 50%;"></span></div>
                <div class="ghost"><span class="gdot"></span><span class="gbar" style="width: 70%;"></span><span class="gbar" style="width: 85%;"></span><span class="gbar" style="width: 35%;"></span></div>
            </div>
        </div>
    </div>
</section>

<?php /* ====== FAQ ====== */ ?>
<section class="sec sec-white" id="faq">
    <div class="wrap">
        <div class="faq-in">
            <div>
                <span class="eyebrow mono">Support</span>
                <h2 class="h2">Frequently Asked Questions</h2>
                <p class="sub">Warranty, delivery and return terms are set by the business.</p>
                <div class="help">
                    <h3 style="font-size: 17px; font-weight: 800;">Still have a question?</h3>
                    <p style="font-size: 14.5px; color: rgb(71, 85, 105);">Ask about any laptop before you buy.</p>
                    <a class="btn btn-primary btn-sm" href="<?= e(url('/contact')) ?>">Contact Us</a>
                </div>
            </div>
            <div class="faq">
                <?php if ($faqs === []): ?>
                    <div class="faq-item">
                        <p class="faq-a" style="padding: 8px 0;">FAQ entries appear here once the content manager publishes them (Admin → Marketing &amp; Content → FAQs).</p>
                    </div>
                <?php else: foreach ($faqs as $i => $faq): ?>
                    <details class="faq-item" <?= $i === 0 ? 'open' : '' ?>>
                        <summary class="faq-q">
                            <span><?= e($faq['question']) ?></span>
                            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>
                        </summary>
                        <p class="faq-a"><?= e($faq['answer']) ?></p>
                    </details>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</section>

<?php /* ====== Final CTA ====== */ ?>
<section class="sec">
    <div class="wrap">
        <div class="cta-panel dark">
            <h2>Find the Right Laptop for Your Budget.</h2>
            <div class="hero-ctas">
                <a class="btn btn-primary" href="<?= e(url('/shop')) ?>">Explore All Laptops</a>
                <a class="btn btn-onnavy" href="<?= e(url('/contact')) ?>">Contact Us</a>
            </div>
            <?= $laptopSvg('', false) ?>
        </div>
    </div>
</section>
