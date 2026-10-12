<h1 class="text-lg font-semibold text-slate-900">Management console</h1>
<p class="mt-1 text-sm text-slate-500">Restricted access — authorised staff only.</p>

<form method="post" action="<?= e(url('/rtp-admin')) ?>" class="mt-6 space-y-4" novalidate data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true, 'autocomplete' => 'username']) ?>
    <?= \App\Support\View::component('field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
    <button type="submit" class="btn-primary w-full">Sign in to console</button>
</form>

<div class="mt-4 flex items-center justify-end text-sm">
    <a class="font-medium text-blue-700 hover:text-blue-800" href="<?= e(url('/forgot-password')) ?>">Forgot password?</a>
</div>
