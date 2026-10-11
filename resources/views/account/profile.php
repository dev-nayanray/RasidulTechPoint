<h1 class="text-2xl font-bold tracking-tight text-slate-900">Profile</h1>

<form method="post" action="<?= e(url('/account/profile')) ?>" class="mt-6 max-w-xl space-y-4 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Full name', 'required' => true, 'value' => $customer['name'] ?? (current_user()['name'] ?? '')]) ?>
    <?= \App\Support\View::component('field', ['name' => 'phone', 'label' => 'Phone number', 'required' => true, 'value' => $customer['phone'] ?? '']) ?>
    <?= \App\Support\View::component('field', ['name' => 'address', 'label' => 'Address', 'value' => $customer['address'] ?? '']) ?>
    <?= \App\Support\View::component('field', ['name' => 'city', 'label' => 'City', 'value' => $customer['city'] ?? '']) ?>
    <button type="submit" class="btn-primary">Save changes</button>
</form>
