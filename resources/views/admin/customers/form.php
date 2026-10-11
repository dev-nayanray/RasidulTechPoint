<?php
/** @var array|null $customer
 *  @var array<string,string> $errors
 */
$isEdit = $customer !== null;
$action = $isEdit ? url('/admin/customers/' . $customer['id'] . '/update') : url('/admin/customers');
$title = $isEdit ? ('Edit: ' . $customer['name']) : 'Add customer';
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/customers')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Customers</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= e($title) ?></h1>

<form method="post" action="<?= e($action) ?>" class="mt-6 max-w-xl space-y-4 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Full name', 'required' => true, 'value' => $isEdit ? ($customer['name'] ?? '') : old('name'), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'phone', 'label' => 'Phone number', 'required' => true, 'value' => $isEdit ? ($customer['phone'] ?? '') : old('phone'), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'alt_phone', 'label' => 'Alternate phone (optional)', 'value' => $isEdit ? ($customer['alt_phone'] ?? '') : old('alt_phone'), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'email', 'label' => 'Email (optional)', 'type' => 'email', 'value' => $isEdit ? ($customer['email'] ?? '') : old('email'), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'address', 'label' => 'Address', 'value' => $isEdit ? ($customer['address'] ?? '') : old('address'), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'city', 'label' => 'City', 'value' => $isEdit ? ($customer['city'] ?? '') : old('city'), 'errors' => $errors ?? []]) ?>
    <div class="space-y-1.5">
        <label for="field-notes" class="block text-sm font-medium text-slate-700">Notes (summary)</label>
        <textarea id="field-notes" name="notes" rows="2" class="input"><?= e($isEdit ? ($customer['notes'] ?? '') : old('notes')) ?></textarea>
        <p class="text-xs text-slate-500">Short summary shown on the customer card. Time-stamped staff notes live on the customer page.</p>
    </div>
    <div class="flex gap-3">
        <button type="submit" class="btn-primary"><?= $isEdit ? 'Save changes' : 'Create customer' ?></button>
        <a href="<?= e(url('/admin/customers')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
