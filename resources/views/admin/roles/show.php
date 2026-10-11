<?php
/** @var array $role
 *  @var list<array> $members
 *  @var int $memberCount
 */
$isSystem = (int) $role['is_system'] === 1;
$isOwner = $role['slug'] === 'owner';
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/roles')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Roles &amp; permissions</a></p>

<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900"><?= e($role['name']) ?></h1>
        <p class="mt-1 text-sm text-slate-500">
            <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs"><?= e($role['slug']) ?></code>
            <?php if ($isSystem): ?>
                <span class="ml-2 rounded-full border border-blue-200 bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700">System role</span>
            <?php else: ?>
                <span class="ml-2 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs font-medium text-slate-600">Custom role</span>
            <?php endif; ?>
        </p>
        <?php if (!empty($role['description'])): ?>
            <p class="mt-1 text-sm text-slate-600"><?= e($role['description']) ?></p>
        <?php endif; ?>
    </div>
    <div class="flex gap-2">
        <?php if (!$isOwner): ?>
            <a href="<?= e(url('/admin/roles/' . $role['id'] . '/edit')) ?>" class="btn-secondary">Edit permissions</a>
        <?php else: ?>
            <span class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-500">Implicitly holds every permission</span>
        <?php endif; ?>
        <?php if (!$isSystem): ?>
            <form method="post" action="<?= e(url('/admin/roles/' . $role['id'] . '/delete')) ?>" data-confirm="Delete role <?= e($role['name']) ?>? This cannot be undone." data-loading-form>
                <?= csrf_field() ?>
                <button type="submit" class="btn-danger">Delete role</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <!-- Summary card -->
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Summary</h2>
        <dl class="mt-3 space-y-2">
            <div><dt class="text-slate-500">Members</dt><dd class="font-medium text-slate-900"><?= $memberCount ?></dd></div>
            <div><dt class="text-slate-500">Permissions</dt><dd class="font-medium text-slate-900"><?= count($role['permission_names']) ?></dd></div>
            <div><dt class="text-slate-500">Type</dt><dd class="font-medium text-slate-900"><?= $isSystem ? 'System (cannot delete)' : 'Custom' ?></dd></div>
        </dl>
    </div>

    <!-- Permission list -->
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm lg:col-span-2">
        <h2 class="font-semibold text-slate-900">Permissions granted</h2>
        <?php if ($isOwner): ?>
            <p class="mt-2 text-sm text-slate-500">The owner role implicitly holds every permission. It cannot be restricted.</p>
        <?php elseif (empty($role['permission_names'])): ?>
            <p class="mt-2 text-sm text-slate-400">No permissions assigned. <a href="<?= e(url('/admin/roles/' . $role['id'] . '/edit')) ?>" class="font-medium text-blue-700 hover:text-blue-800">Edit permissions →</a></p>
        <?php else: ?>
            <div class="mt-3 flex flex-wrap gap-1.5">
                <?php foreach ($role['permission_names'] as $perm): ?>
                    <code class="rounded border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs text-slate-700"><?= e($perm) ?></code>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Members table -->
<section class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
        <h2 class="text-sm font-semibold text-slate-900">Members (<?= $memberCount ?>)</h2>
        <?php if (\App\Services\AuthService::can('users.view')): ?>
            <a href="<?= e(url('/admin/users')) ?>" class="text-xs font-medium text-blue-700 hover:text-blue-800">Manage users →</a>
        <?php endif; ?>
    </header>
    <?php if ($members === []): ?>
        <p class="px-4 py-8 text-center text-sm text-slate-400">No users currently hold this role.</p>
    <?php else: ?>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Name</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Email</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Last login</th>
                    <?php if (\App\Services\AuthService::can('users.view')): ?>
                        <th class="px-4 py-3"></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($members as $member): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium text-slate-900"><?= e($member['name']) ?></td>
                        <td class="px-4 py-3 text-slate-500"><?= e($member['email']) ?></td>
                        <td class="px-4 py-3">
                            <?php if ($member['deleted_at'] !== null): ?>
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">Deleted</span>
                            <?php elseif ((int) $member['is_active'] === 1): ?>
                                <span class="inline-flex items-center rounded-full border border-green-200 bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-700">Active</span>
                            <?php else: ?>
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-slate-500"><?= e(local_datetime($member['last_login_at'])) ?></td>
                        <?php if (\App\Services\AuthService::can('users.view')): ?>
                            <td class="px-4 py-3 text-right">
                                <a href="<?= e(url('/admin/users/' . $member['id'])) ?>" class="font-medium text-blue-700 hover:text-blue-800">View</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
