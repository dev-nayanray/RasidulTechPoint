<?php
/** @var array|null $user @var list<array> $roles */
$isEdit = !empty($user['id']);
$postPath = $isEdit ? '/admin/users/' . $user['id'] . '/update' : '/admin/users';
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/users')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← All users</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= $isEdit ? 'Edit ' . e($user['name']) : 'Create user' ?></h1>

<form method="post" action="<?= e(url($postPath)) ?>" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Full name', 'required' => true, 'value' => $user['name'] ?? '', 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true, 'value' => $user['email'] ?? '', 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'phone', 'label' => 'Phone', 'value' => $user['phone'] ?? '', 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', [
        'name' => 'password', 'label' => $isEdit ? 'New password (leave blank to keep)' : 'Password',
        'type' => 'password', 'required' => !$isEdit, 'help' => 'At least 10 characters.', 'errors' => $errors ?? [],
    ]) ?>

    <fieldset class="space-y-2">
        <legend class="text-sm font-medium text-slate-700">Roles <?= \App\Services\AuthService::can('roles.manage') ? '' : '(only roles.manage holders can change these)' ?></legend>
        <?php $currentRoles = $user['role_ids'] ?? []; ?>
        <?php foreach ($roles as $role): ?>
            <label class="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50 has-[:checked]:border-blue-300 has-[:checked]:bg-blue-50/50">
                <input type="checkbox" name="roles[]" value="<?= (int) $role['id'] ?>"
                    <?= in_array((int) $role['id'], $currentRoles, true) ? 'checked' : '' ?>
                    <?= \App\Services\AuthService::can('roles.manage') ? '' : 'disabled' ?>
                    class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                <span>
                    <span class="font-medium text-slate-900"><?= e($role['name']) ?></span>
                    <span class="block text-xs text-slate-500"><?= e($role['description']) ?></span>
                </span>
            </label>
        <?php endforeach; ?>
        <?php if (!empty($errors['roles'])): ?><p class="text-xs text-red-600"><?= e($errors['roles']) ?></p><?php endif; ?>
    </fieldset>

    <div class="flex gap-3">
        <button type="submit" class="btn-primary"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
        <a href="<?= e(url('/admin/users')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
