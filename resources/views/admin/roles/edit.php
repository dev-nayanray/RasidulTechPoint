<?php /** @var array $role @var array $permissionsGrouped */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/roles/' . $role['id'])) ?>" class="font-medium text-blue-700 hover:text-blue-800">← <?= e($role['name']) ?></a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Edit role: <?= e($role['name']) ?></h1>
<p class="mt-1 text-sm text-slate-500">Choose exactly what this role may do. Changes take effect immediately for every user holding this role and are recorded in the audit log.</p>

<form method="post" action="<?= e(url('/admin/roles/' . $role['id'] . '/permissions')) ?>" class="mt-6 space-y-4" data-loading-form>
    <?= csrf_field() ?>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($permissionsGrouped as $module => $permissions): ?>
            <fieldset class="rounded-lg border border-slate-200 p-3">
                <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-slate-400"><?= e($module) ?></legend>
                <?php foreach ($permissions as $permission): ?>
                    <label class="mt-2 flex items-center gap-2.5 text-sm hover:cursor-pointer">
                        <input type="checkbox" name="permissions[]" value="<?= e($permission['name']) ?>"
                            <?= in_array($permission['name'], $role['permission_names'], true) ? 'checked' : '' ?>
                            class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-slate-700"><code class="text-xs"><?= e($permission['name']) ?></code></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>
    </div>
    <div class="flex gap-3">
        <button type="submit" class="btn-primary">Save permissions</button>
        <a href="<?= e(url('/admin/roles/' . $role['id'])) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
