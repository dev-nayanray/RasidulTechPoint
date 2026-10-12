<?php
/** @var list<array> $entries
 *  @var list<string> $statuses
 *  @var list<string> $paymentAccounts
 *  @var list<array> $categories
 */
?>
<form method="get" action="<?= e(url('/admin/expenses/entries')) ?>" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-6" role="search">
    <div class="sm:col-span-2 space-y-1.5">
        <label for="ee-q" class="block text-sm font-medium text-slate-700">Search</label>
        <input id="ee-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Expense #, description, reference…">
    </div>
    <?= \App\Support\View::component('select', ['name' => 'status', 'label' => 'Status', 'value' => $status, 'options' => array_merge(['' => 'All'], array_combine($statuses, array_map(fn ($s) => ucfirst($s), $statuses)))]) ?>
    <?= \App\Support\View::component('select', ['name' => 'category', 'label' => 'Category', 'value' => (string) $categoryId, 'options' => array_merge(['' => 'All'], array_column($categories, 'name', 'id'))]) ?>
    <div class="space-y-1.5"><label for="ee-from" class="block text-sm font-medium text-slate-700">From</label><input id="ee-from" type="date" name="from" value="<?= e($from) ?>" class="input"></div>
    <div class="space-y-1.5"><label for="ee-to" class="block text-sm font-medium text-slate-700">To</label><input id="ee-to" type="date" name="to" value="<?= e($to) ?>" class="input"></div>
    <div class="flex items-end"><button type="submit" class="btn-primary">Apply</button></div>
</form>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Expense #</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Description</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Category</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Date</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Account</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Amount</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($entries as $e): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-mono text-blue-700"><a href="<?= e(url('/admin/expenses/entries/' . $e['id'])) ?>"><?= e($e['expense_number']) ?></a></td>
                <td class="px-4 py-3 text-slate-700"><?= e($e['description']) ?></td>
                <td class="px-4 py-3 text-slate-500 text-xs"><?= e($e['category_name']) ?></td>
                <td class="px-4 py-3 text-slate-500 text-xs"><?= e($e['expense_date']) ?></td>
                <td class="px-4 py-3 capitalize text-xs"><?= e($e['payment_account']) ?></td>
                <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $e['status']]) ?></td>
                <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money($e['amount']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($entries === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No expenses found.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/expenses/entries', 'queryParams' => array_filter(['q' => $q, 'status' => $status, 'category' => $categoryId ? (string) $categoryId : '', 'from' => $from, 'to' => $to])]) ?>
</div>
