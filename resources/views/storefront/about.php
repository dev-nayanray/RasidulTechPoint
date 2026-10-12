<?php /** @var string $heroBlock
 *  @var string $businessBlock
 *  @var string $whyChooseBlock
 *  @var string $processBlock
 *  @var string $trustBlock
 *  @var string $finalCtaBlock
 */
?>

<?php /* ====== A. Hero ====== */ ?>
<section class="relative bg-navy-700 text-white overflow-hidden">
    <div class="absolute inset-0 opacity-30" aria-hidden="true" style="background: radial-gradient(60% 70% at 80% 20%, rgba(37,99,235,0.4) 0%, transparent 70%), radial-gradient(50% 60% at 10% 90%, rgba(22,163,74,0.25) 0%, transparent 70%);"></div>
    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 py-16 sm:py-20 lg:py-24 grid lg:grid-cols-2 gap-10 items-center">
        <div>
            <?php if (!empty($heroBlock)): ?>
                <div class="prose prose-invert max-w-none text-slate-200"><?= $heroBlock /* trusted HTML from CMS */ ?></div>
            <?php else: ?>
                <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold tracking-wide uppercase text-blue-200 ring-1 ring-white/10">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand-green"></span> About Rasidul Tech Point
                </p>
                <h1 class="mt-4 text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight">A Smarter Way to Buy<br><span class="text-blue-300">Your Next Laptop.</span></h1>
                <p class="mt-4 max-w-lg text-base sm:text-lg text-slate-300">Rasidul Tech Point helps customers explore Dubai-imported used laptops with clear specifications, transparent condition information, and prices suited to different budgets.</p>
            <?php endif; ?>

            <div class="mt-8 flex flex-wrap gap-3">
                <a href="<?= e(url('/shop')) ?>" class="inline-flex items-center gap-2 rounded-lg bg-brand-blue px-6 py-3 text-sm font-semibold hover:bg-blue-700 transition">Shop Laptops</a>
                <a href="<?= e(url('/contact')) ?>" class="inline-flex items-center gap-2 rounded-lg border border-white/20 px-6 py-3 text-sm font-semibold hover:bg-white/10 transition">Contact Our Team</a>
            </div>
        </div>

        <?php /* Visual placeholder — clean laptop-card mock. */ ?>
        <div class="relative">
            <div class="aspect-[4/3] rounded-2xl bg-gradient-to-br from-slate-800/60 to-navy-800 ring-1 ring-white/10 overflow-hidden shadow-2xl flex items-center justify-center p-8 text-center">
                <div>
                    <span class="inline-flex h-14 w-14 items-center justify-center rounded-xl bg-white/5 text-blue-300 ring-1 ring-white/10">
                        <?= \App\Support\View::component('icon', ['name' => 'box', 'class' => 'h-7 w-7']) ?>
                    </span>
                    <p class="mt-4 text-sm text-slate-400">Dubai-imported used laptops</p>
                    <p class="mt-1 text-xs text-slate-500">Individually inspected · condition-graded</p>
                </div>
            </div>
        </div>
    </div>
</section>

<?php /* ====== B. Our business ====== */ ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16">
    <div class="grid gap-8 lg:grid-cols-3 items-start">
        <div class="lg:col-span-2">
            <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Our business</h2>
            <?php if (!empty($businessBlock)): ?>
                <div class="mt-3 prose max-w-none text-slate-600"><?= $businessBlock ?></div>
            <?php else: ?>
                <p class="mt-3 text-slate-600 leading-relaxed">Rasidul Tech Point focuses on Dubai-imported used laptops. We don't claim to be the biggest or the cheapest — we focus on giving customers honest information about each unit: real specifications, real condition, real price.</p>
                <ul class="mt-4 space-y-2 text-slate-600 text-sm">
                    <li class="flex gap-2"><span class="text-brand-blue">▸</span> Dubai-imported used laptops with options for different budgets and needs.</li>
                    <li class="flex gap-2"><span class="text-brand-blue">▸</span> Product specifications and condition transparency on every listing.</li>
                    <li class="flex gap-2"><span class="text-brand-blue">▸</span> Quality inspection information where supported by actual records.</li>
                    <li class="flex gap-2"><span class="text-brand-blue">▸</span> Online shopping and physical-store service where configured.</li>
                </ul>
            <?php endif; ?>
        </div>
        <aside class="rounded-xl bg-white ring-1 ring-slate-200 p-5">
            <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-400">What we don't claim</h3>
            <ul class="mt-3 space-y-2 text-xs text-slate-500">
                <li class="flex gap-2"><span class="text-slate-300">×</span> A specific founding year.</li>
                <li class="flex gap-2"><span class="text-slate-300">×</span> A specific import volume.</li>
                <li class="flex gap-2"><span class="text-slate-300">×</span> A specific customer count.</li>
                <li class="flex gap-2"><span class="text-slate-300">×</span> A specific staff size.</li>
                <li class="flex gap-2"><span class="text-slate-300">×</span> A specific company history.</li>
            </ul>
            <p class="mt-3 text-xs text-slate-400">We don't fabricate. When the business grows enough that these numbers are real and verifiable, they will be added here.</p>
        </aside>
    </div>
</section>

<?php /* ====== C. Why customers should choose us ====== */ ?>
<section class="bg-slate-50 border-y border-slate-200">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16">
        <div class="max-w-2xl">
            <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Why customers should choose us</h2>
            <p class="mt-2 text-sm text-slate-500">Six concrete reasons — not marketing fluff. Each one is a feature that the actual application or workflow supports.</p>
        </div>
        <?php if (!empty($whyChooseBlock)): ?>
            <div class="mt-8 prose max-w-none"><?= $whyChooseBlock ?></div>
        <?php else: ?>
            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <?php
                $benefits = [
                    ['wallet', 'Budget-conscious options', 'Laptops across multiple price ranges — pick a budget filter and see only units actually in stock at that price.'],
                    ['cpu', 'Clear technical specifications', 'Processor, RAM, storage, display size, and resolution shown per model. No "specs may vary" disclaimers on the listing.'],
                    ['file-text', 'Transparent device condition', 'Per-unit condition grade (A/B/C/D) recorded at inspection. Cosmetic marks documented on the product page.'],
                    ['check-circle', 'Inspection information where available', 'A documented four-step inspection process. Not every unit passes — the ones that don\'t, we don\'t sell.'],
                    ['shield', 'Warranty information per policy', 'When warranty applies to a unit, it is shown on the product page. When it does not, that is shown too.'],
                    ['users', 'Customer support & assistance', 'A contact form, optional WhatsApp channel, and an admin team that reviews incoming inquiries.'],
                ];
                foreach ($benefits as [$icon, $title, $text]):
                ?>
                    <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5">
                        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
                            <?= \App\Support\View::component('icon', ['name' => $icon, 'class' => 'h-5 w-5']) ?>
                        </span>
                        <p class="mt-3 font-semibold text-navy-700"><?= e($title) ?></p>
                        <p class="mt-1 text-sm text-slate-500 leading-relaxed"><?= e($text) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php /* ====== D. Our quality-checking process ====== */ ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16">
    <div class="max-w-2xl">
        <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Our quality-checking process</h2>
        <p class="mt-2 text-sm text-slate-500">A four-step inspection process. Actual inspection results and device condition may vary by individual laptop — we record what we find.</p>
    </div>

    <?php if (!empty($processBlock)): ?>
        <div class="mt-8 prose max-w-none"><?= $processBlock ?></div>
    <?php else: ?>
        <ol class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <?php
            $steps = [
                ['1', 'Device identification', 'Verify make, model, serial, and identifying details against the import manifest.'],
                ['2', 'Hardware & functional checks', 'Boot, keyboard, trackpad, screen, ports, battery, Wi-Fi, and storage health.'],
                ['3', 'Condition & defect documentation', 'Photograph any cosmetic marks and document known issues on the product page.'],
                ['4', 'Approval for sale', 'Only units that pass all four steps are listed. The rest are set aside — not sold.'],
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

    <div class="mt-8 flex flex-wrap gap-3">
        <a href="<?= e(url('/shop')) ?>" class="inline-flex items-center gap-2 rounded-lg bg-brand-blue px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">Browse available laptops</a>
        <a href="<?= e(url('/contact')) ?>" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-navy-700 hover:bg-slate-50 transition">Ask about a specific unit</a>
    </div>
</section>

<?php /* ====== E. Shop by customer need ====== */ ?>
<section class="bg-slate-50 border-y border-slate-200">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16">
        <div class="text-center max-w-2xl mx-auto">
            <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Shop by customer need</h2>
            <p class="mt-2 text-sm text-slate-500">Pick the use case that matches yours — you'll see only laptops currently in stock. We don't claim every laptop suits every workload.</p>
        </div>
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <?php
            $needs = [
                ['book-open', 'Student & everyday use', 'Affordable, portable, capable for browsing + documents.', 'shop?q=student', 'wallet'],
                ['briefcase', 'Office & business use', 'Reliable performance for office apps and multitasking.', 'shop?q=business', 'briefcase'],
                ['cpu', 'Development & professional work', 'Higher-tier processors and more memory for IDEs and tooling.', 'shop?q=core+i7', 'cpu'],
                ['wallet', 'Budget-friendly laptops', 'Laptops under ৳15,000 in stock right now.', 'shop?budget=1', 'wallet'],
            ];
            foreach ($needs as [$icon, $title, $desc, $link, $badgeIcon]):
            ?>
                <a href="<?= e(url('/' . ltrim($link, '/'))) ?>" class="group rounded-xl bg-white p-5 ring-1 ring-slate-200 transition hover:ring-brand-blue hover:shadow-md flex flex-col">
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
    </div>
</section>

<?php /* ====== F. Trust & transparency ====== */ ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16">
    <div class="grid gap-8 lg:grid-cols-2 items-center">
        <div>
            <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Trust &amp; transparency</h2>
            <p class="mt-2 text-slate-600 leading-relaxed">We help customers make informed buying decisions by showing what we actually know about each unit — and being honest about what we don't know.</p>
            <?php if (!empty($trustBlock)): ?>
                <div class="mt-4 prose max-w-none text-slate-600"><?= $trustBlock ?></div>
            <?php else: ?>
                <ul class="mt-4 space-y-3 text-sm text-slate-600">
                    <li class="flex gap-2"><span class="text-brand-green">✓</span> Product-specific specifications — processor, RAM, storage, display, graphics.</li>
                    <li class="flex gap-2"><span class="text-brand-green">✓</span> Available condition information — per-unit grade and known defects.</li>
                    <li class="flex gap-2"><span class="text-brand-green">✓</span> Actual selling price — no inflated "original price" + fake discount.</li>
                    <li class="flex gap-2"><span class="text-brand-green">✓</span> Warranty terms where configured — and explicit absence where not.</li>
                    <li class="flex gap-2"><span class="text-brand-green">✓</span> Contact options for questions — form, WhatsApp, or in-store visit.</li>
                </ul>
                <p class="mt-4 text-xs text-slate-400">We don't say "100% risk-free", "best in Bangladesh", or "guaranteed perfect condition". A used laptop is a used laptop — what we do is inspect honestly and document what we find.</p>
            <?php endif; ?>
        </div>
        <div class="rounded-2xl bg-navy-700 text-white p-6 sm:p-8 ring-1 ring-navy-800">
            <p class="text-xs font-semibold uppercase tracking-wider text-blue-300">What this means for you</p>
            <p class="mt-3 text-lg font-semibold leading-snug">You see the same information our technicians recorded at inspection — not a marketing rewrite.</p>
            <p class="mt-3 text-sm text-slate-300">If a unit has a scratch on the lid, that's on the product page. If a unit's battery health is below threshold, that's on the product page. If we don't know something, we say so.</p>
            <a href="<?= e(url('/shop')) ?>" class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-blue-300 hover:text-blue-200">See live inventory →</a>
        </div>
    </div>
</section>

<?php /* ====== G. Final CTA ====== */ ?>
<section class="bg-navy-700 text-white relative overflow-hidden">
    <div class="absolute inset-0 opacity-25" aria-hidden="true" style="background: radial-gradient(50% 80% at 80% 50%, rgba(37,99,235,0.5) 0%, transparent 70%);"></div>
    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 py-16 sm:py-20 text-center">
        <?php if (!empty($finalCtaBlock)): ?>
            <div class="prose prose-invert max-w-none mx-auto"><?= $finalCtaBlock ?></div>
        <?php else: ?>
            <h2 class="text-2xl sm:text-3xl font-bold tracking-tight">Find a laptop that fits your needs and budget.</h2>
            <p class="mt-3 text-sm sm:text-base text-slate-300 max-w-lg mx-auto">Browse our full inventory or contact us with your requirements — we'll point you to the right unit.</p>
        <?php endif; ?>
        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            <a href="<?= e(url('/shop')) ?>" class="inline-flex items-center gap-2 rounded-lg bg-brand-blue px-6 py-3 text-sm font-semibold hover:bg-blue-700 transition">Explore laptops</a>
            <a href="<?= e(url('/contact')) ?>" class="inline-flex items-center gap-2 rounded-lg border border-white/20 px-6 py-3 text-sm font-semibold hover:bg-white/10 transition">Contact us</a>
        </div>
    </div>
</section>
