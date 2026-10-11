<?php
/** @var list<array> $suppliers
 *  @var int $total
 *  @var int $page
 *  @var int $pages
 *  @var string $q
 *  @var float $totalOutstanding
 */
?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-slate-500">
        <?= (int) $total ?> supplier(s)
        <?php if ($totalOutstanding > 0): ?>
            · Outstanding: <span class="font-semibold text-amber-700"><?= e(money($totalOutstanding)) ?></span>
        <?php endif; ?>
    </p>
    <?php if (\App\Services\AuthService::can('purchases.manage')): ?>
        <a href="<?= e(url('/admin/suppliers/create')) ?>" class="btn-primary">Add supplier</a>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(url('/admin/suppliers')) ?>" class="mt-4 flex max-w-md gap-2" role="search">
    <label class="sr-only" for="suppliers-q">Search suppliers</label>
    <input id="suppliers-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Name, contact, email or phone…">
    <button type="submit" class="btn-secondary shrink-0">Search</button>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Name</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Contact</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Phone</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">City</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Outstanding</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Units</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($suppliers as $supplier): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium text-slate-900">
                        <a href="<?= e(url('/admin/suppliers/' . $supplier['id'])) ?>" class="hover:text-blue-700"><?= e($supplier['name']) ?></a>
                    </td>
                    <td class="px-4 py-3 text-slate-500"><?= e($supplier['contact_name'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($supplier['phone'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($supplier['city'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-right <?= (float) $supplier['outstanding_balance'] > 0 ? 'font-semibold text-amber-700' : 'text-slate-400' ?>"><?= e(money($supplier['outstanding_balance'])) ?></td>
                    <td class="px-4 py-3 text-right <?= (int) $supplier['unit_count'] > 0 ? 'text-slate-700' : 'text-slate-400' ?>"><?= (int) $supplier['unit_count'] ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => (int) $supplier['is_active'] === 1 ? 'active' : 'inactive']) ?></td>
                    <td class="px-4 py-3 text-right">
                        <?php if (\App\Services\AuthService::can('purchases.manage')): ?>
                            <a href="<?= e(url('/admin/suppliers/' . $supplier['id'] . '/edit')) ?>" class="font-medium text-blue-700 hover:text-blue-800">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($suppliers === []): ?>
        <p class="px-4 py-10 text-center text-sm text-slate-400">No suppliers match your search.</p>
    <?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/suppliers', 'queryParams' => ['q' => $q]]) ?>
</div>
