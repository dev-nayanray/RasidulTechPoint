<?php
/** @var array|null $page
 *  @var array<string,string> $errors
 */
$isEdit = $page !== null;
$action = $isEdit ? url('/content/pages/' . $page['id'] . '/update') : url('/content/pages');
$title = $isEdit ? ('Edit: ' . $page['title']) : 'New Page';
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/content/pages')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Pages</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= e($title) ?></h1>
<form method="post" action="<?= e($action) ?>" class="mt-6 space-y-4" data-loading-form>
    <?= csrf_field() ?>
    <div class="grid gap-4 sm:grid-cols-2">
        <?= \App\Support\View::component('field', ['name' => 'slug', 'label' => 'Slug (URL)', 'required' => true, 'value' => $isEdit ? ($page['slug'] ?? '') : old('slug'), 'errors' => $errors ?? []]) ?>
        <?= \App\Support\View::component('field', ['name' => 'title', 'label' => 'Title', 'required' => true, 'value' => $isEdit ? ($page['title'] ?? '') : old('title'), 'errors' => $errors ?? []]) ?>
    </div>
    <div class="space-y-1.5">
        <label for="field-body" class="block text-sm font-medium text-slate-700">Page body (HTML)</label>
        <textarea id="field-body" name="body" rows="20" class="input font-mono text-xs" style="min-height:30rem"><?= e($isEdit ? ($page['body'] ?? '') : old('body')) ?></textarea>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <?= \App\Support\View::component('field', ['name' => 'meta_title', 'label' => 'Meta title (SEO)', 'value' => $isEdit ? ($page['meta_title'] ?? '') : old('meta_title')]) ?>
        <?= \App\Support\View::component('field', ['name' => 'meta_description', 'label' => 'Meta description (SEO)', 'value' => $isEdit ? ($page['meta_description'] ?? '') : old('meta_description')]) ?>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <?= \App\Support\View::component('field', ['name' => 'display_order', 'label' => 'Display order', 'type' => 'number', 'value' => $isEdit ? ($page['display_order'] ?? '0') : '0']) ?>
        <label class="flex items-center gap-2 text-sm text-slate-700 mt-6">
            <input type="checkbox" name="is_published" value="1" <?= (!$isEdit || (int)($page['is_published'] ?? 0) === 1) ? 'checked' : '' ?>> Published
        </label>
    </div>
    <div class="flex gap-3">
        <button type="submit" class="btn-primary"><?= $isEdit ? 'Save changes' : 'Create page' ?></button>
        <a href="<?= e(url('/content/pages')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
