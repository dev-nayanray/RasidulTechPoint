<h1 class="text-lg font-semibold text-slate-900">Forgot password</h1>
<p class="mt-1 text-sm text-slate-500">We will email you a secure, single-use reset link.</p>

<form method="post" action="<?= e(url('/forgot-password')) ?>" class="mt-6 space-y-4" novalidate data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true, 'autocomplete' => 'email']) ?>
    <button type="submit" class="btn-primary w-full">Send reset link</button>
</form>

<p class="mt-4 text-sm text-slate-500"><a class="font-medium text-blue-700 hover:text-blue-800" href="<?= e(url('/login')) ?>">Back to sign in</a></p>
