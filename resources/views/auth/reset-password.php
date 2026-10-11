<h1 class="text-lg font-semibold text-slate-900">Choose a new password</h1>
<p class="mt-1 text-sm text-slate-500">Reset links expire after 60 minutes and work only once.</p>

<form method="post" action="<?= e(url('/reset-password')) ?>" class="mt-6 space-y-4" novalidate data-loading-form>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token ?? '') ?>">
    <?= \App\Support\View::component('field', ['name' => 'password', 'label' => 'New password', 'type' => 'password', 'required' => true, 'help' => 'At least 10 characters.', 'autocomplete' => 'new-password']) ?>
    <?= \App\Support\View::component('field', ['name' => 'password_confirmation', 'label' => 'Confirm new password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
    <button type="submit" class="btn-primary w-full">Reset password</button>
</form>
