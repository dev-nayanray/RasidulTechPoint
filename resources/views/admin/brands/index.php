<?php /** @var list<array> $brands */ ?>
<?php if (\App\Services\AuthService::can('products.create')): ?>
<form method="post" action="<?= e(url('/admin/brands')) ?>" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-[1fr_1fr_2fr_auto]" data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Brand name', 'required' => true, 'placeholder' => 'e.g. Dell']) ?>
    <?= \App\Support\View::component('field', ['name' => 'slug', 'label' => 'Slug', 'required' => true, 'placeholder' => 'dell']) ?>
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
                <th class="px-4 py-3 text-left font-medium text-slate-500">Models</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($brands as $brand): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium text-slate-900"><?= e($brand['name']) ?></td>
                    <td class="px-4 py-3"><code class="text-xs text-slate-500"><?= e($brand['slug']) ?></code></td>
                    <td class="px-4 py-3 text-slate-500"><?= (int) $brand['model_count'] ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => (int) $brand['is_active'] === 1 ? 'active' : 'inactive']) ?></td>
                    <td class="px-4 py-3 text-right">
                        <?php if (\App\Services\AuthService::can('products.update')): ?>
                            <button type="button" class="font-medium text-blue-700 hover:text-blue-800" data-modal-open="edit-brand-<?= (int) $brand['id'] ?>">Edit</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($brands === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No brands yet.</p><?php endif; ?>
</div>

<?php foreach ($brands as $brand): ?>
    <?php if (\App\Services\AuthService::can('products.update')): ?>
        <div class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/50 p-4" data-modal="edit-brand-<?= (int) $brand['id'] ?>" role="dialog" aria-modal="true" aria-labelledby="edit-brand-<?= (int) $brand['id'] ?>-title">
            <form method="post" action="<?= e(url('/admin/brands/' . $brand['id'] . '/update')) ?>" class="w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl" data-loading-form>
                <?= csrf_field() ?>
                <h2 id="edit-brand-<?= (int) $brand['id'] ?>-title" class="text-lg font-semibold text-slate-900">Edit brand</h2>
                <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Brand name', 'required' => true, 'value' => $brand['name']]) ?>
                <?= \App\Support\View::component('field', ['name' => 'slug', 'label' => 'Slug', 'required' => true, 'value' => $brand['slug']]) ?>
                <?= \App\Support\View::component('field', ['name' => 'description', 'label' => 'Description', 'value' => $brand['description']]) ?>
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
