<?php /** @var array $page — a published content_pages row */ ?>

<?php /* ====== Hero ====== */ ?>
<section class="relative bg-navy-700 text-white overflow-hidden">
    <div class="absolute inset-0 opacity-30" aria-hidden="true" style="background: radial-gradient(60% 70% at 80% 20%, rgba(37,99,235,0.4) 0%, transparent 70%);"></div>
    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16 text-center">
        <nav class="text-xs text-slate-400" aria-label="Breadcrumb">
            <a href="<?= e(url('/')) ?>" class="hover:text-white">Home</a> <span aria-hidden="true">/</span> <span class="text-slate-300"><?= e($page['title']) ?></span>
        </nav>
        <h1 class="mt-3 text-3xl sm:text-4xl font-bold tracking-tight"><?= e($page['title']) ?></h1>
        <?php if (!empty($page['meta_description'])): ?>
            <p class="mt-3 max-w-2xl mx-auto text-base text-slate-300"><?= e($page['meta_description']) ?></p>
        <?php endif; ?>
    </div>
</section>

<?php /* ====== Body — trusted HTML authored in the admin CMS ====== */ ?>
<section class="mx-auto max-w-3xl px-4 sm:px-6 py-12 sm:py-16">
    <article class="prose prose-slate max-w-none prose-headings:text-navy-700 prose-a:text-brand-blue">
        <?= $page['body'] /* trusted CMS HTML, authored by content managers */ ?>
    </article>

    <div class="mt-10 rounded-xl border border-slate-200 bg-slate-50 p-6 text-center">
        <p class="text-sm text-slate-600">Still have a question about this?</p>
        <a href="<?= e(url('/contact')) ?>" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-brand-blue px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
            <?= \App\Support\View::component('icon', ['name' => 'mail', 'class' => 'h-4 w-4']) ?>
            Contact Rasidul Tech Point
        </a>
    </div>
</section>
