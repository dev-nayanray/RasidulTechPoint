<?php
/** @var list<array> $batches
 *  @var int $total
 *  @var int $page
 *  @var int $pages
 */
$canManage = \App\Services\AuthService::can('payments.manage');
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/payments')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Payments</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Reconciliation batches</h1>
<p class="mt-1 text-sm text-slate-500">Daily summaries of completed payments. Expected total is computed server-side from the payments table; actual total is counted at the till at end-of-day; discrepancy is the difference.</p>

<?php if ($canManage): ?>
<form method="post" action="<?= e(url('/admin/payments/reconciliation')) ?>" class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-3" data-loading-form>
    <?= csrf_field() ?>
    <div class="space-y-1.5">
        <label for="rb-date" class="block text-xs font-medium text-slate-500">Batch date</label>
        <input id="rb-date" type="date" name="batch_date" required class="input" value="<?= e(gmdate('Y-m-d')) ?>">
    </div>
    <div class="space-y-1.5">
        <label for="rb-notes" class="block text-xs font-medium text-slate-500">Notes</label>
        <input id="rb-notes" type="text" name="notes" class="input" placeholder="Drop safe # / daily close">
    </div>
    <div class="flex items-end">
        <button type="submit" class="btn-primary">Create batch</button>
    </div>
</form>
<?php endif; ?>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Date</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Payments</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Expected</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Actual</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Discrepancy</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Prepared by</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Confirmed</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($batches as $b): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium text-slate-900"><?= e($b['batch_date']) ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $b['status']]) ?></td>
                    <td class="px-4 py-3 text-slate-700"><?= (int) $b['payment_count'] ?></td>
                    <td class="px-4 py-3 text-right text-slate-700"><?= money($b['expected_total']) ?></td>
                    <td class="px-4 py-3 text-right text-slate-700"><?= $b['actual_total'] !== null ? money($b['actual_total']) : '—' ?></td>
                    <td class="px-4 py-3 text-right font-semibold <?= (float) ($b['discrepancy'] ?? 0) === 0.0 ? 'text-slate-900' : 'text-amber-700' ?>"><?= $b['discrepancy'] !== null ? money($b['discrepancy']) : '—' ?></td>
                    <td class="px-4 py-3 text-slate-500 text-xs"><?= e($b['prepared_by_name'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-slate-500 text-xs">
                        <?php if (!empty($b['confirmed_by_name'])): ?>
                            <?= e($b['confirmed_by_name']) ?>
                            <span class="block text-slate-400"><?= e(local_datetime($b['confirmed_at'])) ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($batches === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No reconciliation batches yet. Create one above to start a daily summary.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/payments/reconciliation']) ?>
</div>

<!-- Confirm form for the most recent draft batch -->
<?php foreach ($batches as $b): if ($b['status'] === 'draft' && $canManage): ?>
<form method="post" action="<?= e(url('/admin/payments/reconciliation/' . $b['id'] . '/confirm')) ?>" class="mt-4 grid gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:grid-cols-4" data-loading-form data-confirm="Confirm this reconciliation batch?">
    <?= csrf_field() ?>
    <p class="sm:col-span-4 text-xs font-medium text-amber-900">Confirm batch for <?= e($b['batch_date']) ?> (expected <?= money($b['expected_total']) ?>):</p>
    <div class="space-y-1.5">
        <label for="rb-actual" class="block text-xs font-medium text-amber-900">Actual counted total</label>
        <input id="rb-actual" type="number" step="0.01" min="0" name="actual_total" required class="input" value="<?= e($b['expected_total']) ?>">
    </div>
    <div class="space-y-1.5 sm:col-span-2">
        <label for="rb-confirm-notes" class="block text-xs font-medium text-amber-900">Notes</label>
        <input id="rb-confirm-notes" type="text" name="notes" class="input" placeholder="Cash counted by two cashiers; drop safe sealed.">
    </div>
    <div class="flex items-end">
        <button type="submit" class="btn-primary">Confirm batch</button>
    </div>
</form>
<?php break; endif; endforeach; ?>
