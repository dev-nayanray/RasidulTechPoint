<h1 class="text-lg font-semibold text-slate-900">Create your account</h1>
<p class="mt-1 text-sm text-slate-500">Track orders and check out faster.</p>

<form method="post" action="<?= e(url('/register')) ?>" class="mt-6 space-y-4" novalidate data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Full name', 'required' => true, 'autocomplete' => 'name']) ?>
    <?= \App\Support\View::component('field', ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true, 'autocomplete' => 'email']) ?>
    <?= \App\Support\View::component('field', ['name' => 'phone', 'label' => 'Phone number', 'required' => true, 'autocomplete' => 'tel']) ?>
    <?= \App\Support\View::component('field', ['name' => 'address', 'label' => 'Address', 'autocomplete' => 'street-address']) ?>
    <?= \App\Support\View::component('field', ['name' => 'city', 'label' => 'City', 'autocomplete' => 'address-level2']) ?>
    <?= \App\Support\View::component('field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'help' => 'At least 10 characters.', 'autocomplete' => 'new-password']) ?>
    <?= \App\Support\View::component('field', ['name' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
    <button type="submit" class="btn-primary w-full">Create account</button>
</form>

<p class="mt-4 text-sm text-slate-500">Already registered? <a class="font-medium text-blue-700 hover:text-blue-800" href="<?= e(url('/login')) ?>">Sign in</a></p>
