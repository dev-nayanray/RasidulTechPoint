<?php
/** @var array $user
 *  @var list<array> $roles
 *  @var list<array> $audit
 *  @var list<array> $loginHistory
 */
$isDeleted = isset($user['deleted_at']) && $user['deleted_at'] !== null;
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/users')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← All users</a></p>

<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div class="flex items-center gap-3">
        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-navy-700 text-lg font-semibold text-white"><?= e(strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900"><?= e($user['name']) ?></h1>
            <p class="text-sm text-slate-500"><?= e($user['email']) ?></p>
        </div>
    </div>
    <div class="flex flex-wrap gap-2">
        <?php if (!$isDeleted): ?>
            <?php if (\App\Services\AuthService::can('users.update')): ?>
                <a href="<?= e(url('/admin/users/' . $user['id'] . '/edit')) ?>" class="btn-secondary">Edit</a>
            <?php endif; ?>
            <?php if (\App\Services\AuthService::can('users.update')): ?>
                <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/reset-password')) ?>" data-confirm="Send a password reset email to <?= e($user['email']) ?>?" data-loading-form>
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-secondary">Send reset email</button>
                </form>
            <?php endif; ?>
            <?php if (\App\Services\AuthService::can('users.deactivate') && (int) $user['id'] !== current_user_id()): ?>
                <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/status')) ?>" data-confirm="Change account status for <?= e($user['name']) ?>?" data-loading-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="activate" value="<?= (int) $user['is_active'] === 1 ? '0' : '1' ?>">
                    <button type="submit" class="<?= (int) $user['is_active'] === 1 ? 'btn-danger' : 'btn-primary' ?>">
                        <?= (int) $user['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                    </button>
                </form>
            <?php endif; ?>
            <?php if (\App\Services\AuthService::can('users.deactivate') && (int) $user['id'] !== current_user_id()): ?>
                <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/delete')) ?>" data-confirm="Permanently delete <?= e($user['name']) ?>? The account will be soft-deleted and retained for audit history." data-loading-form>
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-danger">Delete</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <?php if (\App\Services\AuthService::can('users.deactivate')): ?>
                <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/restore')) ?>" data-confirm="Restore <?= e($user['name']) ?>?" data-loading-form>
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-primary">Restore user</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($isDeleted): ?>
    <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">This user was soft-deleted on <?= e(local_datetime($user['deleted_at'])) ?>. Restore to reactivate.</p>
<?php endif; ?>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Account</h2>
        <dl class="mt-3 space-y-2">
            <div><dt class="text-slate-500">Status</dt><dd><?= \App\Support\View::component('badge', ['status' => $isDeleted ? 'inactive' : ((int) $user['is_active'] === 1 ? 'active' : 'inactive')]) ?></dd></div>
            <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($user['phone'] ?? '—') ?></dd></div>
            <div><dt class="text-slate-500">Created</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($user['created_at'])) ?></dd></div>
            <div><dt class="text-slate-500">Last login</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($user['last_login_at'])) ?></dd></div>
            <?php if ($isDeleted): ?>
                <div><dt class="text-slate-500">Deleted</dt><dd class="font-medium text-red-600"><?= e(local_datetime($user['deleted_at'])) ?></dd></div>
            <?php endif; ?>
        </dl>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Roles</h2>
        <ul class="mt-3 space-y-1.5">
            <?php if (!empty($roles)): ?>
                <?php foreach ($roles as $role): ?>
                    <?php if (in_array((int) $role['id'], array_map('intval', $user['role_ids'] ?? []), true)): ?>
                        <li class="rounded-lg bg-slate-50 px-3 py-1.5 font-medium text-slate-800"><?= e($role['name']) ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <li class="text-slate-400">No roles assigned.</li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Recent activity</h2>
        <?php if (($audit ?? []) === []): ?>
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

<!-- Login history -->
<section class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
        <h2 class="text-sm font-semibold text-slate-900">Login history</h2>
        <p class="text-xs text-slate-400">Last <?= count($loginHistory ?? []) ?> attempt(s) — successful and failed</p>
    </header>
    <?php if (($loginHistory ?? []) === []): ?>
        <p class="px-4 py-8 text-center text-sm text-slate-400">No login attempts recorded for this user.</p>
    <?php else: ?>
        <table class="min-w-full divide-y divide-slate-100 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">When</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">IP</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Result</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Email used</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($loginHistory as $attempt): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2.5 text-slate-500"><?= e(local_datetime($attempt['attempted_at'])) ?></td>
                        <td class="px-4 py-2.5 font-mono text-xs text-slate-600"><?= e($attempt['ip']) ?></td>
                        <td class="px-4 py-2.5">
                            <?php if ((int) $attempt['was_successful'] === 1): ?>
                                <span class="inline-flex items-center rounded-full border border-green-200 bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-700">Success</span>
                            <?php else: ?>
                                <span class="inline-flex items-center rounded-full border border-red-200 bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700">Failed</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2.5 text-slate-500"><?= e($attempt['email']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
