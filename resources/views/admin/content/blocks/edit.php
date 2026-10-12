<?php /** @var array $block */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/content/blocks')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Blocks</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= e($block['title'] ?? 'New Block') ?></h1>
<form method="post" action="<?= e(url('/content/blocks')) ?>" class="mt-6 space-y-4" data-loading-form>
    <?= csrf_field() ?>
    <input type="hidden" name="block_key" value="<?= e($block['block_key'] ?? '') ?>">
    <?= \App\Support\View::component('field', ['name' => 'title', 'label' => 'Title', 'required' => true, 'value' => $block['title'] ?? '']) ?>
    <div class="space-y-1.5">
        <label for="field-body" class="block text-sm font-medium text-slate-700">HTML body</label>
        <textarea id="field-body" name="body" rows="20" class="input font-mono text-xs" style="min-height:30rem"><?= e($block['body'] ?? '') ?></textarea>
        <p class="text-xs text-slate-500">Raw HTML. Only authorized staff (content.manage) can edit this. The storefront renders this HTML as trusted content.</p>
    </div>
    <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="checkbox" name="is_published" value="1" <?= (int)($block['is_published'] ?? 1) === 1 ? 'checked' : '' ?>> Published
    </label>
    <div class="flex gap-3">
        <button type="submit" class="btn-primary">Save block</button>
        <a href="<?= e(url('/content/blocks')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
