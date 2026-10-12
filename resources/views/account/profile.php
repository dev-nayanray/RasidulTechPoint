<?php /** @var array $customer @var array $errors */
?>
<h1 class="text-xl font-bold tracking-tight text-navy-700">My Profile</h1>

<div class="mt-4 grid gap-6 lg:grid-cols-2">
    <section class="rounded-xl bg-white ring-1 ring-slate-200 p-5 sm:p-6">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Account details</h2>
        <form method="post" action="<?= e(url('/account/profile')) ?>" class="mt-4 space-y-4" data-loading-form>
            <?= csrf_field() ?>
            <div class="space-y-1.5">
                <label for="pf-name" class="block text-sm font-medium text-navy-700">Full name</label>
                <input id="pf-name" name="name" type="text" required value="<?= e($customer['name'] ?? '') ?>" class="input text-sm" maxlength="120">
            </div>
            <div class="space-y-1.5">
                <label for="pf-phone" class="block text-sm font-medium text-navy-700">Phone</label>
                <input id="pf-phone" name="phone" type="tel" required value="<?= e($customer['phone'] ?? '') ?>" class="input text-sm" maxlength="30">
            </div>
            <div class="space-y-1.5">
                <label for="pf-email" class="block text-sm font-medium text-navy-700">Email</label>
                <input id="pf-email" name="email" type="email" value="<?= e($customer['email'] ?? current_user()['email'] ?? '') ?>" class="input text-sm" maxlength="190">
            </div>
            <div class="space-y-1.5">
                <label for="pf-address" class="block text-sm font-medium text-navy-700">Address</label>
                <input id="pf-address" name="address" type="text" value="<?= e($customer['address'] ?? '') ?>" class="input text-sm" maxlength="255">
            </div>
            <div class="space-y-1.5">
                <label for="pf-city" class="block text-sm font-medium text-navy-700">City</label>
                <input id="pf-city" name="city" type="text" value="<?= e($customer['city'] ?? '') ?>" class="input text-sm" maxlength="100">
            </div>
            <button type="submit" class="btn-primary text-sm">Save changes</button>
        </form>
        <p class="mt-3 text-xs text-slate-400">Account created: <?= e(local_datetime($customer['created_at'] ?? 'now')) ?></p>
    </section>

    <section class="rounded-xl bg-white ring-1 ring-slate-200 p-5 sm:p-6" id="password">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Change password</h2>
        <form method="post" action="<?= e(url('/account/profile/password')) ?>" class="mt-4 space-y-4" data-loading-form>
            <?= csrf_field() ?>
            <div class="space-y-1.5">
                <label for="pf-current" class="block text-sm font-medium text-navy-700">Current password</label>
                <input id="pf-current" name="current_password" type="password" required class="input text-sm" autocomplete="current-password">
            </div>
            <div class="space-y-1.5">
                <label for="pf-new" class="block text-sm font-medium text-navy-700">New password <span class="text-xs text-slate-400">(min 10 chars)</span></label>
                <input id="pf-new" name="new_password" type="password" required class="input text-sm" autocomplete="new-password" minlength="10">
            </div>
            <div class="space-y-1.5">
                <label for="pf-confirm" class="block text-sm font-medium text-navy-700">Confirm new password</label>
                <input id="pf-confirm" name="new_password_confirmation" type="password" required class="input text-sm" autocomplete="new-password" minlength="10">
            </div>
            <button type="submit" class="btn-primary text-sm">Change password</button>
        </form>
    </section>
</div>
