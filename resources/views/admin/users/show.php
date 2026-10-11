<?php /** @var array $user */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/users')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← All users</a></p>

<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div class="flex items-center gap-3">
        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-navy-700 text-lg font-semibold text-white"><?= e(strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900"><?= e($user['name']) ?></h1>
            <p class="text-sm text-slate-500"><?= e($user['email']) ?></p>
        </div>
    </div>
    <div class="flex gap-2">
        <?php if (\App\Services\AuthService::can('users.update')): ?>
            <a href="<?= e(url('/admin/users/' . $user['id'] . '/edit')) ?>" class="btn-secondary">Edit</a>
        <?php endif; ?>
        <?php if (\App\Services\AuthService::can('users.deactivate') && (int) $user['id'] !== current_user_id()): ?>
            <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/status')) ?>" data-confirm="Change account status for <?= e($user['name']) ?>?">
                <?= csrf_field() ?>
                <input type="hidden" name="activate" value="<?= (int) $user['is_active'] === 1 ? '0' : '1' ?>">
                <button type="submit" class="<?= (int) $user['is_active'] === 1 ? 'btn-danger' : 'btn-primary' ?>">
                    <?= (int) $user['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Account</h2>
        <dl class="mt-3 space-y-2">
            <div><dt class="text-slate-500">Status</dt><dd><?= \App\Support\View::component('badge', ['status' => (int) $user['is_active'] === 1 ? 'active' : 'inactive']) ?></dd></div>
            <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($user['phone'] ?? '—') ?></dd></div>
            <div><dt class="text-slate-500">Created</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($user['created_at'])) ?></dd></div>
            <div><dt class="text-slate-500">Last login</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($user['last_login_at'])) ?></dd></div>
        </dl>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Roles</h2>
        <ul class="mt-3 space-y-1.5">
            <?php foreach ($roles ?? [] as $role): ?>
                <?php if (in_array((int) $role['id'], array_map('intval', $user['role_ids']), true)): ?>
                    <li class="rounded-lg bg-slate-50 px-3 py-1.5 font-medium text-slate-800"><?= e($role['name']) ?></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Recent activity</h2>
        <?php if (($audit = $audit ?? []) === []): ?>
            <p class="mt-2 text-slate-400">No recorded activity.</p>
        <?php else: ?>
            <ul class="mt-3 space-y-1.5">
                <?php foreach (array_slice($audit, 0, 8) as $event): ?>
                    <li class="flex justify-between gap-2">
                        <span class="font-medium text-slate-700"><?= e($event['action']) ?></span>
                        <span class="text-xs text-slate-400"><?= e(local_datetime($event['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
