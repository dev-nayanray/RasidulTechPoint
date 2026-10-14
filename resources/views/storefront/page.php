<?php /** @var array $page — a published content_pages row */ ?>

<?php /* ====== Hero — premium navy with gradient overlay ====== */ ?>
<section class="relative bg-navy-700 text-white overflow-hidden">
    <div class="absolute inset-0 opacity-30" aria-hidden="true" style="background: radial-gradient(60% 70% at 80% 20%, rgba(37,99,235,0.4) 0%, transparent 70%), radial-gradient(40% 50% at 20% 80%, rgba(22,163,74,0.15) 0%, transparent 70%);"></div>
    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16 lg:py-20 text-center">
        <nav class="text-xs text-slate-400" aria-label="Breadcrumb">
            <a href="<?= e(url('/')) ?>" class="hover:text-white transition-colors">Home</a>
            <span aria-hidden="true" class="mx-2 text-slate-500">/</span>
            <span class="text-slate-300"><?= e($page['title']) ?></span>
        </nav>
        <h1 class="mt-3 text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-white"><?= e($page['title']) ?></h1>
        <?php if (!empty($page['meta_description'])): ?>
            <p class="mt-3 max-w-2xl mx-auto text-base text-slate-300 leading-relaxed"><?= e($page['meta_description']) ?></p>
        <?php endif; ?>
        <p class="mt-4 text-xs text-slate-400">Last updated: <?= e(date('F j, Y', strtotime($page['updated_at'] ?? $page['created_at'] ?? 'now'))) ?></p>
    </div>
</section>

<?php /* ====== Body — 2-col on desktop (sticky TOC sidebar + content) ====== */ ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 py-10 sm:py-14 lg:py-16">
    <div class="grid gap-8 lg:grid-cols-[240px_minmax(0,1fr)] xl:gap-12">

        <?php /* Sticky table-of-contents sidebar (desktop only) */ ?>
        <aside class="hidden lg:block">
            <div class="sticky top-24">
                <p class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-400">On this page</p>
                <nav class="policy-toc space-y-1 text-sm">
                    <a href="#contact-cta" class="block rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-100 hover:text-brand-blue transition-colors">Have a question?</a>
                </nav>
                <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs font-semibold text-slate-500">Need help?</p>
                    <a href="<?= e(url('/contact')) ?>" class="mt-1 block text-sm font-semibold text-brand-blue hover:underline">Contact us →</a>
                </div>
            </div>
        </aside>

        <?php /* Article body — premium prose styling */ ?>
        <article class="policy-body max-w-none prose prose-slate prose-headings:text-navy-700 prose-a:text-brand-blue prose-li:text-slate-600 prose-p:text-slate-600 prose-headings:font-bold prose-headings:tracking-tight prose-headings:scroll-mt-24 prose-strong:text-slate-900 prose-strong:font-semibold prose-h2:mt-10 prose-h2:mb-3 prose-h2:text-2xl prose-h3:mt-6 prose-h3:mb-2 prose-h3:text-lg prose-p:leading-relaxed prose-ul:my-4 prose-ul:list-disc prose-ul:pl-6 prose-li:my-1 prose-a:font-medium">
            <?= $page['body'] /* trusted CMS HTML, authored by content managers */ ?>
        </article>
    </div>

    <?php /* Contact CTA */ ?>
    <div id="contact-cta" class="mt-12 rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 to-blue-50/50 p-6 sm:p-8 text-center scroll-mt-24">
        <h2 class="text-xl font-bold tracking-tight text-navy-700">Still have a question?</h2>
        <p class="mt-2 text-sm text-slate-600 max-w-xl mx-auto">Our team is happy to help with anything related to this policy. Reach out and we'll respond as soon as we can.</p>
        <div class="mt-5 flex flex-wrap items-center justify-center gap-3">
            <a href="<?= e(url('/contact')) ?>" class="inline-flex items-center gap-2 rounded-lg bg-brand-blue px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
                <?= \App\Support\View::component('icon', ['name' => 'mail', 'class' => 'h-4 w-4']) ?>
                Contact Rasidul Tech Point
            </a>
            <a href="<?= e(url('/')) ?>" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 hover:border-slate-400 hover:bg-slate-50 transition">
                Back to home
            </a>
        </div>
    </div>
</section>
