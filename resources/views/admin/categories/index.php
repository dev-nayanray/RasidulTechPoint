<?php /** @var list<array> $categories */ ?>
<?php if (\App\Services\AuthService::can('products.create')): ?>
    <?php
    $parentOptions = ['' => '— none —'];
    foreach ($categories as $c) {
        $parentOptions[$c['id']] = $c['name'];
    }
    ?>
    <form method="post" action="<?= e(url('/admin/categories')) ?>" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-[1fr_1fr_1fr_2fr_auto]" data-loading-form>
        <?= csrf_field() ?>
        <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Category name', 'required' => true]) ?>
        <?= \App\Support\View::component('field', ['name' => 'slug', 'label' => 'Slug', 'required' => true]) ?>
        <?= \App\Support\View::component('select', ['name' => 'parent_id', 'label' => 'Parent', 'options' => $parentOptions, 'placeholder' => '— none —']) ?>
        <?= \App\Support\View::component('field', ['name' => 'description', 'label' => 'Description']) ?>
        <div class="flex items-end"><button type="submit" class="btn-primary">Add</button></div>
    </form>
<?php endif; ?>

<div class="mt-6 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Name</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Slug</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Parent</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Models</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($categories as $category): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium text-slate-900"><?= e($category['name']) ?></td>
                    <td class="px-4 py-3"><code class="text-xs text-slate-500"><?= e($category['slug']) ?></code></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($category['parent_name'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= (int) $category['model_count'] ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => (int) $category['is_active'] === 1 ? 'active' : 'inactive']) ?></td>
                    <td class="px-4 py-3 text-right">
                        <?php if (\App\Services\AuthService::can('products.update')): ?>
                            <button type="button" class="font-medium text-blue-700 hover:text-blue-800" data-modal-open="edit-category-<?= (int) $category['id'] ?>">Edit</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($categories === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No categories yet.</p><?php endif; ?>
</div>

<?php foreach ($categories as $category): ?>
    <?php if (\App\Services\AuthService::can('products.update')): ?>
        <div class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/50 p-4" data-modal="edit-category-<?= (int) $category['id'] ?>" role="dialog" aria-modal="true" aria-labelledby="edit-category-<?= (int) $category['id'] ?>-title">
            <form method="post" action="<?= e(url('/admin/categories/' . $category['id'] . '/update')) ?>" class="w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl" data-loading-form>
                <?= csrf_field() ?>
                <h2 id="edit-category-<?= (int) $category['id'] ?>-title" class="text-lg font-semibold text-slate-900">Edit category</h2>
                <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Category name', 'required' => true, 'value' => $category['name']]) ?>
                <?= \App\Support\View::component('field', ['name' => 'slug', 'label' => 'Slug', 'required' => true, 'value' => $category['slug']]) ?>
                <?= \App\Support\View::component('select', [
                    'name' => 'parent_id', 'label' => 'Parent', 'value' => (string) ($category['parent_id'] ?? ''),
                    'options' => array_merge(['' => '— none —'], array_column($categories, 'name', 'id')),
                ]) ?>
                <?= \App\Support\View::component('field', ['name' => 'description', 'label' => 'Description', 'value' => $category['description']]) ?>
                <label class="flex items-center gap-2.5 text-sm">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" <?= (int) $category['is_active'] === 1 ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    Active (visible in storefront)
                </label>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="btn-secondary" data-modal-close>Cancel</button>
                    <button type="submit" class="btn-primary">Save</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
