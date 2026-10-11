<?php /** @var array|null $model */ ?>
<?php
$isEdit = !empty($model['id']);
$postPath = $isEdit ? '/admin/products/' . $model['id'] . '/update' : '/admin/products';
$brandOptions = array_column($brands, 'name', 'id');
$categoryOptions = array_column($categories, 'name', 'id');
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/products')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Laptop models</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= $isEdit ? 'Edit: ' . e($model['name']) : 'Add laptop model' ?></h1>
<p class="mt-1 text-sm text-slate-500">Catalog entries describe the model; individual physical units are managed separately under Inventory.</p>

<form method="post" action="<?= e(url($postPath)) ?>" class="mt-6 max-w-3xl space-y-6" novalidate data-loading-form>
    <?= csrf_field() ?>

    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Basics</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Model name', 'required' => true, 'value' => $model['name'] ?? '', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'slug', 'label' => 'Slug (blank = auto from name)', 'value' => $model['slug'] ?? '', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('select', ['name' => 'brand_id', 'label' => 'Brand', 'required' => true, 'options' => $brandOptions, 'value' => (string) ($model['brand_id'] ?? ''), 'placeholder' => 'Choose brand…', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('select', ['name' => 'category_id', 'label' => 'Category', 'required' => true, 'options' => $categoryOptions, 'value' => (string) ($model['category_id'] ?? ''), 'placeholder' => 'Choose category…', 'errors' => $errors ?? []]) ?>
        </div>
    </section>

    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Specifications</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <?= \App\Support\View::component('field', ['name' => 'processor', 'label' => 'Processor', 'value' => $model['processor'] ?? '', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'ram_gb', 'label' => 'RAM (GB)', 'type' => 'number', 'value' => $model['ram_gb'] ?? '', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('select', ['name' => 'storage_type', 'label' => 'Storage type', 'value' => (string) ($model['storage_type'] ?? ''), 'options' => ['' => '—', 'SSD' => 'SSD', 'HDD' => 'HDD', 'HDD+SSD' => 'HDD + SSD'], 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'storage_capacity_gb', 'label' => 'Storage capacity (GB)', 'type' => 'number', 'value' => $model['storage_capacity_gb'] ?? '', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'graphics', 'label' => 'Graphics', 'value' => $model['graphics'] ?? '', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'display_size_inches', 'label' => 'Display size (inches)', 'value' => $model['display_size_inches'] ?? '', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'resolution', 'label' => 'Resolution', 'value' => $model['resolution'] ?? '', 'placeholder' => '1920x1080', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'operating_system', 'label' => 'Operating system', 'value' => $model['operating_system'] ?? '', 'errors' => $errors ?? []]) ?>
        </div>
    </section>

    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Description &amp; SEO</h2>
        <div class="space-y-1.5">
            <label for="field-description" class="block text-sm font-medium text-slate-700">General description</label>
            <textarea id="field-description" name="description" rows="4" class="input"><?= e($model['description'] ?? '') ?></textarea>
        </div>
        <?= \App\Support\View::component('field', ['name' => 'seo_title', 'label' => 'SEO title', 'value' => $model['seo_title'] ?? '', 'errors' => $errors ?? []]) ?>
        <?= \App\Support\View::component('field', ['name' => 'seo_description', 'label' => 'SEO description', 'value' => $model['seo_description'] ?? '', 'errors' => $errors ?? []]) ?>
        <label class="flex items-center gap-2.5 text-sm">
            <input type="hidden" name="is_published" value="0">
            <input type="checkbox" name="is_published" value="1" <?= !empty($model['is_published']) ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
            Published (visible in storefront)
        </label>
    </section>

    <div class="flex gap-3">
        <button type="submit" class="btn-primary"><?= $isEdit ? 'Save changes' : 'Create model' ?></button>
        <a href="<?= e(url('/admin/products')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
