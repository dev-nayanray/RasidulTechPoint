<?php /** @var array|null $customer */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/customers')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Customers</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Add customer</h1>

<form method="post" action="<?= e(url('/admin/customers')) ?>" class="mt-6 max-w-xl space-y-4 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Full name', 'required' => true, 'value' => old('name'), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'phone', 'label' => 'Phone number', 'required' => true, 'value' => old('phone'), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'email', 'label' => 'Email (optional)', 'type' => 'email', 'value' => old('email'), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'address', 'label' => 'Address', 'value' => old('address'), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'city', 'label' => 'City', 'value' => old('city'), 'errors' => $errors ?? []]) ?>
    <div class="space-y-1.5">
        <label for="field-notes" class="block text-sm font-medium text-slate-700">Notes</label>
        <textarea id="field-notes" name="notes" rows="2" class="input"><?= e(old('notes')) ?></textarea>
    </div>
    <div class="flex gap-3">
        <button type="submit" class="btn-primary">Create customer</button>
        <a href="<?= e(url('/admin/customers')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
