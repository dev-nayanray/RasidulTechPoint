<?php /** @var array $errors */
$errors = $errors ?? [];
?>
<h1 class="text-lg font-semibold text-slate-900">Create your account</h1>
<p class="mt-1 text-sm text-slate-500">Track orders and check out faster.</p>

<?php if (!empty($errors)): ?>
    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <p class="font-semibold">Please fix the following:</p>
        <ul class="mt-1 list-disc list-inside text-xs">
            <?php foreach ($errors as $field => $msg): ?>
                <li><?= e($msg) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/register')) ?>" class="mt-6 space-y-4" novalidate data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Full name', 'required' => true, 'autocomplete' => 'name', 'error' => $errors['name'] ?? null]) ?>
    <?= \App\Support\View::component('field', ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'error' => $errors['email'] ?? null]) ?>
    <?= \App\Support\View::component('field', ['name' => 'phone', 'label' => 'Phone number', 'required' => true, 'autocomplete' => 'tel', 'error' => $errors['phone'] ?? null]) ?>
    <?= \App\Support\View::component('field', ['name' => 'address', 'label' => 'Address', 'autocomplete' => 'street-address', 'error' => $errors['address'] ?? null]) ?>
    <?= \App\Support\View::component('field', ['name' => 'city', 'label' => 'City', 'autocomplete' => 'address-level2', 'error' => $errors['city'] ?? null]) ?>
    <?= \App\Support\View::component('field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'help' => 'At least 8 characters.', 'autocomplete' => 'new-password', 'error' => $errors['password'] ?? null]) ?>
    <?= \App\Support\View::component('field', ['name' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'error' => $errors['password_confirmation'] ?? null]) ?>
    <button type="submit" class="btn-primary w-full">Create account</button>
</form>

<p class="mt-4 text-sm text-slate-500">Already registered? <a class="font-medium text-brand-blue hover:text-blue-700" href="<?= e(url('/login')) ?>">Sign in</a></p>
