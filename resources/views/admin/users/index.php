<?php /** @var list<array> $users */ ?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-slate-500"><?= (int) $total ?> user(s)</p>
    <?php if (\App\Services\AuthService::can('users.create')): ?>
        <a href="<?= e(url('/admin/users/create')) ?>" class="btn-primary">Add user</a>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(url('/admin/users')) ?>" class="mt-4 flex max-w-md gap-2" role="search">
    <label class="sr-only" for="users-q">Search users</label>
    <input id="users-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Search name or email…">
    <button type="submit" class="btn-secondary shrink-0">Search</button>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Name</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Email</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Roles</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Last login</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($users as $user): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium text-slate-900"><?= e($user['name']) ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($user['email']) ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($user['roles'] ?? '—') ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => (int) $user['is_active'] === 1 ? 'active' : 'inactive']) ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= e(local_datetime($user['last_login_at'])) ?></td>
                    <td class="px-4 py-3 text-right">
                        <a href="<?= e(url('/admin/users/' . $user['id'])) ?>" class="font-medium text-blue-700 hover:text-blue-800">View</a>
                        <?php if (\App\Services\AuthService::can('users.update')): ?>
                            · <a href="<?= e(url('/admin/users/' . $user['id'] . '/edit')) ?>" class="font-medium text-blue-700 hover:text-blue-800">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($users === []): ?>
        <p class="px-4 py-10 text-center text-sm text-slate-400">No users match your search.</p>
    <?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/users', 'queryParams' => ['q' => $q]]) ?>
</div>
