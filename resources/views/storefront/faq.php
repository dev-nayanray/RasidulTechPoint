<?php /** @var list<array> $faqs */ ?>

<?php /* ====== Hero ====== */ ?>
<section class="relative bg-navy-700 text-white overflow-hidden">
    <div class="absolute inset-0 opacity-30" aria-hidden="true" style="background: radial-gradient(60% 70% at 80% 20%, rgba(37,99,235,0.4) 0%, transparent 70%);"></div>
    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16 text-center">
        <nav class="text-xs text-slate-400" aria-label="Breadcrumb">
            <a href="<?= e(url('/')) ?>" class="hover:text-white">Home</a> <span aria-hidden="true">/</span> <span class="text-slate-300">FAQ</span>
        </nav>
        <h1 class="mt-3 text-3xl sm:text-4xl font-bold tracking-tight">Frequently asked questions</h1>
        <p class="mt-3 max-w-2xl mx-auto text-base sm:text-lg text-slate-300">Straight answers about buying Dubai-imported used laptops from Rasidul Tech Point.</p>
        <div class="mt-6 flex flex-wrap items-center justify-center gap-3 text-sm">
            <a href="<?= e(url('/shop')) ?>" class="inline-flex items-center gap-2 rounded-lg bg-brand-blue px-4 py-2 font-semibold hover:bg-blue-700 transition">
                <?= \App\Support\View::component('icon', ['name' => 'shopping-cart', 'class' => 'h-4 w-4']) ?>
                Browse laptops
            </a>
            <a href="<?= e(url('/contact')) ?>" class="inline-flex items-center gap-2 rounded-lg bg-white/10 px-4 py-2 font-semibold ring-1 ring-white/10 hover:bg-white/15 transition">
                <?= \App\Support\View::component('icon', ['name' => 'mail', 'class' => 'h-4 w-4']) ?>
                Ask us directly
            </a>
        </div>
    </div>
</section>

<?php /* ====== FAQ list ====== */ ?>
<?php if (!empty($faqs)): ?>
<section class="mx-auto max-w-3xl px-4 sm:px-6 py-12 sm:py-16">
    <dl class="divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
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
    <p class="mt-6 text-center text-sm text-slate-500">
        Didn't find your answer?
        <a href="<?= e(url('/contact')) ?>" class="font-semibold text-brand-blue hover:underline">Send us a message</a> — we reply to every inquiry.
    </p>
</section>
<?php else: ?>
<section class="mx-auto max-w-3xl px-4 sm:px-6 py-12 sm:py-16">
    <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
        <?= \App\Support\View::component('icon', ['name' => 'book-open', 'class' => 'mx-auto h-8 w-8 text-slate-300']) ?>
        <p class="mt-3 text-sm text-slate-500">FAQ entries will appear here once the content manager publishes them.</p>
        <p class="mt-1 text-xs text-slate-400">Admin → Marketing &amp; Content → FAQs</p>
        <a href="<?= e(url('/contact')) ?>" class="mt-4 inline-flex items-center gap-2 rounded-lg bg-brand-blue px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
            <?= \App\Support\View::component('icon', ['name' => 'mail', 'class' => 'h-4 w-4']) ?>
            Contact us instead
        </a>
    </div>
</section>
<?php endif; ?>
