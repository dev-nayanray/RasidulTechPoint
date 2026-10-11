<?php
/** @var list<array> $brands
 *  @var int $total
 *  @var int $page
 *  @var int $pages
 *  @var string $q
 */
?>
<form method="get" action="<?= e(url('/admin/brands')) ?>" class="flex max-w-md gap-2" role="search">
    <label class="sr-only" for="brands-q">Search brands</label>
    <input id="brands-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Search name or slug…">
    <button type="submit" class="btn-secondary shrink-0">Search</button>
</form>

<?php if (\App\Services\AuthService::can('products.create')): ?>
<details class="mt-4 rounded-xl border border-slate-200 bg-white p-5" open>
    <summary class="cursor-pointer text-sm font-semibold text-slate-900">Create brand</summary>
    <form method="post" action="<?= e(url('/admin/brands')) ?>" enctype="multipart/form-data" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-loading-form>
        <?= csrf_field() ?>
        <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Brand name', 'required' => true, 'placeholder' => 'e.g. Dell', 'errors' => []]) ?>
        <?= \App\Support\View::component('field', ['name' => 'slug', 'label' => 'Slug', 'required' => true, 'placeholder' => 'dell', 'help' => 'Lowercase, hyphens', 'errors' => []]) ?>
        <?= \App\Support\View::component('field', ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'value' => '0', 'help' => '0 = default, lower sorts first']) ?>
        <?= \App\Support\View::component('field', ['name' => 'description', 'label' => 'Description']) ?>
        <div class="space-y-1.5">
            <label for="field-logo" class="block text-sm font-medium text-slate-700">Logo</label>
            <input id="field-logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700">
        </div>
        <div class="flex items-end sm:col-span-2 lg:col-span-1"><button type="submit" class="btn-primary">Add brand</button></div>
    </form>
</details>
<?php endif; ?>

<div class="mt-6 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <div class="border-b border-slate-200 px-4 py-2 text-xs text-slate-400"><?= (int) $total ?> brand(s)</div>
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Name</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Slug</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Sort</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Models</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($brands as $brand): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium text-slate-900">
                        <?php if (!empty($brand['logo_path']) && file_exists(BASE_PATH . '/public/' . $brand['logo_path'])): ?>
                            <img src="<?= e(url($brand['logo_path'])) ?>" alt="" class="inline h-5 w-5 rounded object-contain mr-1.5 align-text-bottom">
                        <?php endif; ?>
                        <?= e($brand['name']) ?>
                    </td>
                    <td class="px-4 py-3"><code class="text-xs text-slate-500"><?= e($brand['slug']) ?></code></td>
                    <td class="px-4 py-3 text-right text-slate-500"><?= (int) $brand['sort_order'] ?></td>
                    <td class="px-4 py-3 text-right <?= (int) $brand['model_count'] > 0 ? 'text-slate-700' : 'text-slate-400' ?>"><?= (int) $brand['model_count'] ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => (int) $brand['is_active'] === 1 ? 'active' : 'inactive']) ?></td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <?php if (\App\Services\AuthService::can('products.update')): ?>
                            <button type="button" class="font-medium text-blue-700 hover:text-blue-800" data-modal-open="edit-brand-<?= (int) $brand['id'] ?>">Edit</button>
                        <?php endif; ?>
                        <?php if (\App\Services\AuthService::can('products.delete')): ?>
                            · <button type="button" class="font-medium text-red-600 hover:text-red-800" data-modal-open="delete-brand-<?= (int) $brand['id'] ?>">Delete</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($brands === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No brands match your search.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/brands', 'queryParams' => ['q' => $q]]) ?>
</div>

<!-- Edit modals -->
<?php foreach ($brands as $brand): ?>
    <?php if (\App\Services\AuthService::can('products.update')): ?>
        <div class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/50 p-4" data-modal="edit-brand-<?= (int) $brand['id'] ?>" role="dialog" aria-modal="true" aria-labelledby="edit-brand-<?= (int) $brand['id'] ?>-title">
            <form method="post" action="<?= e(url('/admin/brands/' . $brand['id'] . '/update')) ?>" enctype="multipart/form-data" class="w-full max-w-lg max-h-[90vh] overflow-y-auto space-y-4 rounded-xl bg-white p-6 shadow-xl" data-loading-form>
                <?= csrf_field() ?>
                <h2 id="edit-brand-<?= (int) $brand['id'] ?>-title" class="text-lg font-semibold text-slate-900">Edit brand</h2>
                <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Brand name', 'required' => true, 'value' => $brand['name']]) ?>
                <?= \App\Support\View::component('field', ['name' => 'slug', 'label' => 'Slug', 'required' => true, 'value' => $brand['slug']]) ?>
                <?= \App\Support\View::component('field', ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'value' => (string) $brand['sort_order']]) ?>
                <?= \App\Support\View::component('field', ['name' => 'description', 'label' => 'Description', 'value' => $brand['description'] ?? '']) ?>
                <div>
                    <label for="edit-logo-<?= (int) $brand['id'] ?>" class="block text-sm font-medium text-slate-700">Logo</label>
                    <?php if (!empty($brand['logo_path']) && file_exists(BASE_PATH . '/public/' . $brand['logo_path'])): ?>
                        <img src="<?= e(url($brand['logo_path'])) ?>" alt="" class="mt-1 h-10 w-10 rounded border border-slate-200 object-contain">
                    <?php endif; ?>
                    <input id="edit-logo-<?= (int) $brand['id'] ?>" name="logo" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-white">
                </div>
                <label class="flex items-center gap-2.5 text-sm">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" <?= (int) $brand['is_active'] === 1 ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
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
<?php foreach ($brands as $brand): ?>
    <?php if (\App\Services\AuthService::can('products.delete')): ?>
        <div class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/50 p-4" data-modal="delete-brand-<?= (int) $brand['id'] ?>" role="dialog" aria-modal="true">
            <form method="post" action="<?= e(url('/admin/brands/' . $brand['id'] . '/delete')) ?>" class="w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl" data-loading-form data-confirm="Delete brand <?= e($brand['name']) ?>?">
                <?= csrf_field() ?>
                <h2 class="text-lg font-semibold text-slate-900">Delete brand</h2>
                <p class="text-sm text-slate-600">
                    You are about to delete <strong><?= e($brand['name']) ?></strong>
                    (<?= (int) $brand['model_count'] ?> model(s)).
                </p>
                <?php if ((int) $brand['model_count'] > 0): ?>
                    <div class="space-y-1.5">
                        <label for="reassign-brand-<?= (int) $brand['id'] ?>" class="block text-sm font-medium text-slate-700">Reassign models to</label>
                        <select id="reassign-brand-<?= (int) $brand['id'] ?>" name="reassign_to" class="input" required>
                            <option value="">— select a target —</option>
                            <?php foreach ($brands as $target): ?>
                                <?php if ((int) $target['id'] !== (int) $brand['id']): ?>
                                    <option value="<?= (int) $target['id'] ?>"><?= e($target['name']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-slate-500">Required because this brand has product models. They will be moved to the target brand.</p>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-slate-500">This brand has no product models.</p>
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
