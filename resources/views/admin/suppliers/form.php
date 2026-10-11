<?php
/** @var array|null $supplier
 *  @var array $errors
 */
$isEdit = !empty($supplier['id']);
$postPath = $isEdit ? '/admin/suppliers/' . $supplier['id'] . '/update' : '/admin/suppliers';
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/suppliers')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Suppliers</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= $isEdit ? 'Edit: ' . e($supplier['name']) : 'Add supplier' ?></h1>

<form method="post" action="<?= e(url($postPath)) ?>" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Supplier name', 'required' => true, 'value' => old('name', $supplier['name'] ?? ''), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'contact_name', 'label' => 'Contact person', 'value' => old('contact_name', $supplier['contact_name'] ?? ''), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => old('email', $supplier['email'] ?? ''), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'phone', 'label' => 'Phone', 'value' => old('phone', $supplier['phone'] ?? ''), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'address', 'label' => 'Address', 'value' => old('address', $supplier['address'] ?? ''), 'errors' => $errors ?? []]) ?>
    <div class="grid gap-4 sm:grid-cols-2">
        <?= \App\Support\View::component('field', ['name' => 'city', 'label' => 'City', 'value' => old('city', $supplier['city'] ?? ''), 'errors' => $errors ?? []]) ?>
        <?= \App\Support\View::component('field', ['name' => 'country', 'label' => 'Country', 'value' => old('country', $supplier['country'] ?? ''), 'errors' => $errors ?? []]) ?>
    </div>
    <div>
        <label for="field-notes" class="block text-sm font-medium text-slate-700">Notes</label>
        <textarea id="field-notes" name="notes" rows="3" class="input mt-1.5" placeholder="Internal notes about this supplier…"><?= e(old('notes', $supplier['notes'] ?? '')) ?></textarea>
    </div>
    <?php if ($isEdit): ?>
    <label class="flex items-center gap-2.5 text-sm">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" <?= (!isset($supplier['is_active']) || (int) $supplier['is_active'] === 1) ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
        Active
    </label>
    <?php endif; ?>
    <div class="flex gap-3">
        <button type="submit" class="btn-primary"><?= $isEdit ? 'Save changes' : 'Create supplier' ?></button>
        <a href="<?= e(url('/admin/suppliers')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>

<?php if (!empty($errors)): ?>
<div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
    <?php foreach ($errors as $field => $msg): ?>
        <p><?= e($msg) ?></p>
    <?php endforeach; ?>
</div>
<?php endif; ?>
