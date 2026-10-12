<?php /** @var array $seo */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/content')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Content</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">SEO Metadata</h1>
<form method="post" action="<?= e(url('/content/seo')) ?>" class="mt-6 max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-6" data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'page_key', 'label' => 'Page key (e.g. home, about, shop)', 'required' => true, 'value' => $seo['page_key'] ?? 'home']) ?>
    <?= \App\Support\View::component('field', ['name' => 'meta_title', 'label' => 'Meta title', 'value' => $seo['meta_title'] ?? '']) ?>
    <div class="space-y-1.5">
        <label for="field-meta_description" class="block text-sm font-medium text-slate-700">Meta description</label>
        <textarea id="field-meta_description" name="meta_description" rows="2" class="input"><?= e($seo['meta_description'] ?? '') ?></textarea>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <?= \App\Support\View::component('field', ['name' => 'og_title', 'label' => 'OpenGraph title', 'value' => $seo['og_title'] ?? '']) ?>
        <?= \App\Support\View::component('field', ['name' => 'og_description', 'label' => 'OpenGraph description', 'value' => $seo['og_description'] ?? '']) ?>
    </div>
    <?= \App\Support\View::component('field', ['name' => 'og_image_path', 'label' => 'OpenGraph image path (optional)', 'value' => $seo['og_image_path'] ?? '']) ?>
    <?= \App\Support\View::component('field', ['name' => 'canonical_url', 'label' => 'Canonical URL (optional)', 'value' => $seo['canonical_url'] ?? '']) ?>
    <div class="space-y-1.5">
        <label for="field-extra_head_tags" class="block text-sm font-medium text-slate-700">Extra head tags (optional, raw HTML)</label>
        <textarea id="field-extra_head_tags" name="extra_head_tags" rows="3" class="input font-mono text-xs"><?= e($seo['extra_head_tags'] ?? '') ?></textarea>
    </div>
    <button type="submit" class="btn-primary">Save SEO metadata</button>
</form>
