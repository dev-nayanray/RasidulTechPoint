<?php /** @var list<array> $categories */ ?>
<?php if (\App\Services\AuthService::can('products.create')): ?>
    <?php
    $parentOptions = ['' => '— none —'];
    foreach ($categories as $c) {
        $parentOptions[$c['id']] = $c['name'];
    }
    ?>
    <details class="rounded-xl border border-slate-200 bg-white p-5" open>
        <summary class="cursor-pointer text-sm font-semibold text-slate-900">Create category</summary>
        <form method="post" action="<?= e(url('/admin/categories')) ?>" enctype="multipart/form-data" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-loading-form>
            <?= csrf_field() ?>
            <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Category name', 'required' => true, 'errors' => []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'slug', 'label' => 'Slug', 'required' => true, 'help' => 'Lowercase, hyphens', 'errors' => []]) ?>
            <?= \App\Support\View::component('select', ['name' => 'parent_id', 'label' => 'Parent', 'options' => $parentOptions, 'placeholder' => '— none —']) ?>
            <?= \App\Support\View::component('field', ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'value' => '0', 'help' => '0 = default, lower sorts first']) ?>
            <?= \App\Support\View::component('field', ['name' => 'description', 'label' => 'Description']) ?>
            <div class="space-y-1.5">
                <label for="field-image" class="block text-sm font-medium text-slate-700">Image</label>
                <input id="field-image" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700">
            </div>
            <?= \App\Support\View::component('field', ['name' => 'seo_title', 'label' => 'SEO title', 'help' => 'Overrides the category name in <title>']) ?>
            <?= \App\Support\View::component('field', ['name' => 'seo_description', 'label' => 'SEO description', 'help' => 'Meta description for search engines']) ?>
            <div class="flex items-end sm:col-span-2 lg:col-span-1"><button type="submit" class="btn-primary">Add category</button></div>
        </form>
    </details>
<?php endif; ?>

<div class="mt-6 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Name</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Slug</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Parent</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Sort</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Models</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Children</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($categories as $category): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium text-slate-900">
                        <?= e($category['name']) ?>
                        <?php if (!empty($category['image_path']) && file_exists(BASE_PATH . '/public/' . $category['image_path'])): ?>
                            <img src="<?= e(url($category['image_path'])) ?>" alt="" class="ml-1 inline h-6 w-6 rounded object-contain">
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3"><code class="text-xs text-slate-500"><?= e($category['slug']) ?></code></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($category['parent_name'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-right text-slate-500"><?= (int) $category['sort_order'] ?></td>
                    <td class="px-4 py-3 text-right <?= (int) $category['model_count'] > 0 ? 'text-slate-700' : 'text-slate-400' ?>"><?= (int) $category['model_count'] ?></td>
                    <td class="px-4 py-3 text-right <?= (int) $category['child_count'] > 0 ? 'text-slate-700' : 'text-slate-400' ?>"><?= (int) $category['child_count'] ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => (int) $category['is_active'] === 1 ? 'active' : 'inactive']) ?></td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <?php if (\App\Services\AuthService::can('products.update')): ?>
                            <button type="button" class="font-medium text-blue-700 hover:text-blue-800" data-modal-open="edit-category-<?= (int) $category['id'] ?>">Edit</button>
                        <?php endif; ?>
                        <?php if (\App\Services\AuthService::can('products.delete')): ?>
                            · <button type="button" class="font-medium text-red-600 hover:text-red-800" data-modal-open="delete-category-<?= (int) $category['id'] ?>">Delete</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($categories === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No categories yet.</p><?php endif; ?>
</div>

<!-- Edit modals -->
<?php foreach ($categories as $category): ?>
    <?php if (\App\Services\AuthService::can('products.update')): ?>
        <div class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/50 p-4" data-modal="edit-category-<?= (int) $category['id'] ?>" role="dialog" aria-modal="true">
            <form method="post" action="<?= e(url('/admin/categories/' . $category['id'] . '/update')) ?>" enctype="multipart/form-data" class="w-full max-w-lg max-h-[90vh] overflow-y-auto space-y-4 rounded-xl bg-white p-6 shadow-xl" data-loading-form>
                <?= csrf_field() ?>
                <h2 class="text-lg font-semibold text-slate-900">Edit category</h2>
                <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Category name', 'required' => true, 'value' => $category['name']]) ?>
                <?= \App\Support\View::component('field', ['name' => 'slug', 'label' => 'Slug', 'required' => true, 'value' => $category['slug']]) ?>
                <?= \App\Support\View::component('select', [
                    'name' => 'parent_id', 'label' => 'Parent', 'value' => (string) ($category['parent_id'] ?? ''),
                    'options' => array_merge(['' => '— none —'], array_column($categories, 'name', 'id')),
                ]) ?>
                <?= \App\Support\View::component('field', ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'value' => (string) $category['sort_order']]) ?>
                <?= \App\Support\View::component('field', ['name' => 'description', 'label' => 'Description', 'value' => $category['description'] ?? '']) ?>
                <?= \App\Support\View::component('field', ['name' => 'seo_title', 'label' => 'SEO title', 'value' => $category['seo_title'] ?? '']) ?>
                <?= \App\Support\View::component('field', ['name' => 'seo_description', 'label' => 'SEO description', 'value' => $category['seo_description'] ?? '']) ?>
                <div>
                    <label for="edit-image-<?= (int) $category['id'] ?>" class="block text-sm font-medium text-slate-700">Image</label>
                    <?php if (!empty($category['image_path']) && file_exists(BASE_PATH . '/public/' . $category['image_path'])): ?>
                        <img src="<?= e(url($category['image_path'])) ?>" alt="" class="mt-1 h-12 w-12 rounded border border-slate-200 object-contain">
                    <?php endif; ?>
                    <input id="edit-image-<?= (int) $category['id'] ?>" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-white">
                </div>
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

<!-- Delete modals -->
<?php foreach ($categories as $category): ?>
    <?php if (\App\Services\AuthService::can('products.delete')): ?>
        <div class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/50 p-4" data-modal="delete-category-<?= (int) $category['id'] ?>" role="dialog" aria-modal="true">
            <form method="post" action="<?= e(url('/admin/categories/' . $category['id'] . '/delete')) ?>" class="w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl" data-loading-form data-confirm="Delete category <?= e($category['name']) ?>?">
                <?= csrf_field() ?>
                <h2 class="text-lg font-semibold text-slate-900">Delete category</h2>
                <p class="text-sm text-slate-600">
                    You are about to delete <strong><?= e($category['name']) ?></strong>
                    (<?= (int) $category['model_count'] ?> model(s), <?= (int) $category['child_count'] ?> child category/categories).
                </p>
                <?php if ((int) $category['model_count'] > 0): ?>
                    <div class="space-y-1.5">
                        <label for="reassign-<?= (int) $category['id'] ?>" class="block text-sm font-medium text-slate-700">Reassign models to</label>
                        <select id="reassign-<?= (int) $category['id'] ?>" name="reassign_to" class="input" required>
                            <option value="">— select a target —</option>
                            <?php foreach ($categories as $target): ?>
                                <?php if ((int) $target['id'] !== (int) $category['id']): ?>
                                    <option value="<?= (int) $target['id'] ?>"><?= e($target['name']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-slate-500">Required because this category has product models. Children will be reparented to this category's parent.</p>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-slate-500">This category has no product models. Children will be reparented to this category's parent.</p>
                    <input type="hidden" name="reassign_to" value="0">
                <?php endif; ?>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="btn-secondary" data-modal-close>Cancel</button>
                    <button type="submit" class="btn-danger">Delete</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
