<?php
/** @var list<array> $customers
 *  @var array<string,string> $statuses
 *  @var bool $canSeeFinance
 */
?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-slate-500"><?= (int) $total ?> customer(s)</p>
    <div class="flex gap-2">
        <?php if (\App\Services\AuthService::can('customers.manage')): ?>
            <a href="<?= e(url('/admin/customers/duplicates')) ?>" class="btn-secondary">Duplicate check</a>
        <?php endif; ?>
        <?php if (\App\Services\AuthService::can('customers.create') || \App\Services\AuthService::can('customers.manage')): ?>
            <a href="<?= e(url('/admin/customers/create')) ?>" class="btn-primary">Add customer</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" action="<?= e(url('/admin/customers')) ?>" class="mt-4 flex flex-wrap max-w-2xl gap-2" role="search">
    <label class="sr-only" for="customers-q">Search customers</label>
    <input id="customers-q" name="q" value="<?= e($q) ?>" class="input flex-1 min-w-[14rem]" placeholder="Name, phone or email…">
    <label class="sr-only" for="customers-status">Status filter</label>
    <select id="customers-status" name="status" class="input w-40">
        <option value="">All statuses</option>
        <?php foreach ($statuses as $s): ?>
            <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-secondary shrink-0">Search</button>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Name</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Phone</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Email</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">City</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Orders</th>
                <?php if ($canSeeFinance): ?>
                    <th class="px-4 py-3 text-right font-medium text-slate-500">Lifetime value</th>
                <?php endif; ?>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Since</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($customers as $customer): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium text-slate-900"><a class="hover:text-blue-700" href="<?= e(url('/admin/customers/' . $customer['id'])) ?>"><?= e($customer['name']) ?></a></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($customer['phone']) ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($customer['email'] ?? $customer['account_email'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($customer['city'] ?? '—') ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $customer['account_status']]) ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= (int) $customer['orders_count'] ?></td>
                    <?php if ($canSeeFinance): ?>
                        <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= e(money($customer['lifetime_value'])) ?></td>
                    <?php endif; ?>
                    <td class="px-4 py-3 text-slate-500"><?= e(local_datetime($customer['created_at'], 'd M Y')) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($customers === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No customers match your search.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', [
        'page' => $page,
        'pages' => $pages,
        'baseUrl' => '/admin/customers',
        'queryParams' => ['q' => $q, 'status' => $status],
    ]) ?>
</div>
