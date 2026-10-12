<?php /** @var list<array> $blocks $banners $pages $faqs */ ?>
<h1 class="text-2xl font-bold tracking-tight text-slate-900">Marketing &amp; Content</h1>
<div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <a href="<?= e(url('/content/blocks')) ?>" class="rounded-xl border border-slate-200 bg-white p-5 hover:bg-slate-50">
        <p class="font-semibold text-slate-900">Content Blocks</p>
        <p class="text-xs text-slate-500"><?= count($blocks) ?> block(s)</p>
    </a>
    <a href="<?= e(url('/content/banners')) ?>" class="rounded-xl border border-slate-200 bg-white p-5 hover:bg-slate-50">
        <p class="font-semibold text-slate-900">Banners</p>
        <p class="text-xs text-slate-500"><?= count($banners) ?> banner(s)</p>
    </a>
    <a href="<?= e(url('/content/pages')) ?>" class="rounded-xl border border-slate-200 bg-white p-5 hover:bg-slate-50">
        <p class="font-semibold text-slate-900">Info Pages</p>
        <p class="text-xs text-slate-500"><?= count($pages) ?> page(s)</p>
    </a>
    <a href="<?= e(url('/content/faqs')) ?>" class="rounded-xl border border-slate-200 bg-white p-5 hover:bg-slate-50">
        <p class="font-semibold text-slate-900">FAQs</p>
        <p class="text-xs text-slate-500"><?= count($faqs) ?> FAQ(s)</p>
    </a>
    <a href="<?= e(url('/content/seo')) ?>" class="rounded-xl border border-slate-200 bg-white p-5 hover:bg-slate-50">
        <p class="font-semibold text-slate-900">SEO Metadata</p>
        <p class="text-xs text-slate-500">Per-page SEO settings</p>
    </a>
    <a href="<?= e(url('/')) ?>" target="_blank" class="rounded-xl border border-blue-200 bg-blue-50 p-5 hover:bg-blue-100">
        <p class="font-semibold text-blue-900">View Homepage ↗</p>
        <p class="text-xs text-blue-700">Preview the live site</p>
    </a>
</div>
