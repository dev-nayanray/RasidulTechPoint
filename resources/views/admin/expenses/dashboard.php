<?php /** @var int $pending $approved $paid @var float $pendingTotal $paidTotal @var list<array> $recent @var list<array> $categories */ ?>
<div class="grid gap-4 sm:grid-cols-3">
    <?= \App\Support\View::component('stat-card', ['label' => 'Pending', 'value' => $pending, 'icon' => 'clock']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Approved', 'value' => $approved, 'icon' => 'check-circle']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Paid', 'value' => $paid, 'icon' => 'dollar-sign']) ?>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <section class="lg:col-span-2 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Recent expenses (this month)</h2>
            <div class="flex gap-2">
                <a href="<?= e(url('/admin/expenses/entries/create')) ?>" class="btn-primary text-xs">New expense</a>
                <a href="<?= e(url('/admin/expenses/report')) ?>" class="btn-secondary text-xs">Report</a>
            </div>
        </header>
        <?php if ($recent === []): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-400">No expenses this month.</p>
        <?php else: ?>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50"><tr>
                <th class="px-4 py-2 text-left font-medium text-slate-500">Expense #</th>
                <th class="px-4 py-2 text-left font-medium text-slate-500">Description</th>
                <th class="px-4 py-2 text-left font-medium text-slate-500">Category</th>
                <th class="px-4 py-2 text-left font-medium text-slate-500">Date</th>
                <th class="px-4 py-2 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-2 text-right font-medium text-slate-500">Amount</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($recent as $e): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2 font-mono text-blue-700"><a href="<?= e(url('/admin/expenses/entries/' . $e['id'])) ?>"><?= e($e['expense_number']) ?></a></td>
                    <td class="px-4 py-2 text-slate-700"><?= e($e['description']) ?></td>
                    <td class="px-4 py-2 text-slate-500 text-xs"><?= e($e['category_name']) ?></td>
                    <td class="px-4 py-2 text-slate-500 text-xs"><?= e($e['expense_date']) ?></td>
                    <td class="px-4 py-2"><?= \App\Support\View::component('badge', ['status' => $e['status']]) ?></td>
                    <td class="px-4 py-2 text-right font-semibold text-slate-900"><?= money($e['amount']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <aside class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Summary</h2>
            <dl class="mt-3 space-y-2 text-xs">
                <div><dt class="text-slate-500">Pending total</dt><dd class="font-bold text-amber-700"><?= money((string) $pendingTotal) ?></dd></div>
                <div><dt class="text-slate-500">Paid total</dt><dd class="font-bold text-green-700"><?= money((string) $paidTotal) ?></dd></div>
            </dl>
            <div class="mt-3 flex flex-col gap-2">
                <a href="<?= e(url('/admin/expenses/entries')) ?>" class="btn-secondary text-xs">All entries</a>
                <a href="<?= e(url('/admin/expenses/categories')) ?>" class="btn-secondary text-xs">Categories</a>
            </div>
        </div>
    </aside>
</div>
