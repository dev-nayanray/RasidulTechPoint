<?php /** @var list<array> $roles @var array $permissionsGrouped */ ?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-slate-500"><?= count($roles) ?> role(s) configured</p>
    <a href="<?= e(url('/admin/roles/create')) ?>" class="btn-primary">Create role</a>
</div>

<div class="mt-4 space-y-3">
    <?php foreach ($roles as $role): ?>
        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <a href="<?= e(url('/admin/roles/' . $role['id'])) ?>" class="font-semibold text-slate-900 hover:text-blue-700"><?= e($role['name']) ?></a>
                        <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500"><?= e($role['slug']) ?></code>
                        <?php if ((int) $role['is_system'] === 1): ?>
                            <span class="rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-[10px] font-medium text-blue-700">System</span>
                        <?php else: ?>
                            <span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-medium text-slate-600">Custom</span>
                        <?php endif; ?>
                    </div>
                    <p class="mt-0.5 text-sm text-slate-500"><?= e($role['description']) ?> — <?= (int) $role['user_count'] ?> member(s), <?= (int) $role['permission_count'] ?> permission(s)</p>
                </div>
                <div class="flex gap-2">
                    <a href="<?= e(url('/admin/roles/' . $role['id'])) ?>" class="btn-secondary text-xs">View members</a>
                    <?php if ($role['slug'] !== 'owner'): ?>
                        <a href="<?= e(url('/admin/roles/' . $role['id'] . '/edit')) ?>" class="btn-secondary text-xs">Edit permissions</a>
                    <?php else: ?>
                        <span class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-500">Full access</span>
                    <?php endif; ?>
                </div>
            </header>
        </section>
    <?php endforeach; ?>
</div>

<section class="mt-8 rounded-xl border border-slate-200 bg-white p-5">
    <h2 class="font-semibold text-slate-900">Permission catalog</h2>
    <p class="mt-1 text-sm text-slate-500">All permissions defined in <code>config/permissions.php</code> and stored in the database.</p>
    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($permissionsGrouped as $module => $permissions): ?>
            <div class="rounded-lg border border-slate-200 p-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400"><?= e($module) ?></p>
                <ul class="mt-2 space-y-1 text-sm text-slate-700">
                    <?php foreach ($permissions as $permission): ?>
                        <li><code class="text-xs"><?= e($permission['name']) ?></code></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>
</section>
