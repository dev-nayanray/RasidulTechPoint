<?php /** @var array $entry */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/expenses/entries')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Expenses</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Expense <?= e($entry['expense_number']) ?></h1>
<p class="mt-1 text-sm text-slate-500">
    <?= \App\Support\View::component('badge', ['status' => $entry['status']]) ?>
    · <?= e($entry['category_name']) ?> · <?= e($entry['expense_date']) ?>
</p>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Details</h2>
        <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
            <div><dt class="text-slate-500">Description</dt><dd class="font-medium text-slate-900"><?= e($entry['description']) ?></dd></div>
            <div><dt class="text-slate-500">Amount</dt><dd class="font-bold text-slate-900"><?= money($entry['amount']) ?></dd></div>
            <div><dt class="text-slate-500">Date</dt><dd class="font-medium text-slate-900"><?= e($entry['expense_date']) ?></dd></div>
            <div><dt class="text-slate-500">Payment account</dt><dd class="font-medium text-slate-900 capitalize"><?= e($entry['payment_account']) ?></dd></div>
            <?php if (!empty($entry['payment_reference'])): ?><div><dt class="text-slate-500">Payment reference</dt><dd class="font-mono text-slate-900"><?= e($entry['payment_reference']) ?></dd></div><?php endif; ?>
            <?php if (!empty($entry['receipt_path'])): ?>
            <div class="sm:col-span-2"><dt class="text-slate-500">Receipt</dt><dd><a href="<?= e(url('/' . $entry['receipt_path'])) ?>" target="_blank" class="text-blue-700 hover:underline">View receipt ↗</a></dd></div>
            <?php endif; ?>
            <?php if (!empty($entry['notes'])): ?><div class="sm:col-span-2"><dt class="text-slate-500">Notes</dt><dd class="text-slate-700"><?= e($entry['notes']) ?></dd></div><?php endif; ?>
            <?php if (!empty($entry['rejection_reason'])): ?><div class="sm:col-span-2"><dt class="text-slate-500">Rejection reason</dt><dd class="text-red-700"><?= e($entry['rejection_reason']) ?></dd></div><?php endif; ?>
        </dl>
    </div>

    <aside class="space-y-4">
        <?php if ($entry['status'] === 'pending'): ?>
        <div class="rounded-xl border border-green-200 bg-green-50 p-5 text-sm">
            <h2 class="font-semibold text-green-900">Approve</h2>
            <p class="mt-1 text-xs text-green-800">Approval locks the entry — no further edits allowed.</p>
            <form method="post" action="<?= e(url('/admin/expenses/entries/' . $entry['id'] . '/approve')) ?>" class="mt-3" data-loading-form data-confirm="Approve this expense? It will be locked.">
                <?= csrf_field() ?><button type="submit" class="btn-primary w-full text-xs">Approve</button>
            </form>
        </div>
        <details class="rounded-xl border border-red-200 bg-red-50 p-5 text-sm">
            <summary class="cursor-pointer font-semibold text-red-900">Reject</summary>
            <form method="post" action="<?= e(url('/admin/expenses/entries/' . $entry['id'] . '/reject')) ?>" class="mt-3 space-y-2" data-loading-form>
                <?= csrf_field() ?>
                <input name="reason" required maxlength="255" class="input" placeholder="Rejection reason">
                <button type="submit" class="btn-danger w-full text-xs">Reject</button>
            </form>
        </details>
        <?php endif; ?>
        <?php if ($entry['status'] === 'approved'): ?>
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-5 text-sm">
            <h2 class="font-semibold text-blue-900">Mark as paid</h2>
            <p class="mt-1 text-xs text-blue-800">This entry is approved and locked. Mark it as paid once the payment is settled.</p>
            <form method="post" action="<?= e(url('/admin/expenses/entries/' . $entry['id'] . '/paid')) ?>" class="mt-3" data-loading-form>
                <?= csrf_field() ?><button type="submit" class="btn-primary w-full text-xs">Mark as paid</button>
            </form>
        </div>
        <?php endif; ?>
        <?php if (in_array($entry['status'], ['approved', 'paid'])): ?>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-800">
            <p class="font-semibold">Entry locked</p>
            <p class="mt-1">This expense is <?= e($entry['status']) ?> and cannot be edited. Approved/paid expenses are immutable to preserve the audit trail.</p>
        </div>
        <?php endif; ?>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Audit</h2>
            <dl class="mt-3 space-y-2 text-xs">
                <div><dt class="text-slate-500">Created by</dt><dd class="font-medium text-slate-900"><?= e($entry['created_by_name'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Created at</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($entry['created_at'])) ?></dd></div>
                <?php if (!empty($entry['approved_by_name'])): ?><div><dt class="text-slate-500">Approved by</dt><dd class="font-medium text-slate-900"><?= e($entry['approved_by_name']) ?></dd></div><?php endif; ?>
                <?php if (!empty($entry['approved_at'])): ?><div><dt class="text-slate-500">Approved at</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($entry['approved_at'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($entry['paid_at'])): ?><div><dt class="text-slate-500">Paid at</dt><dd class="font-medium text-green-700"><?= e(local_datetime($entry['paid_at'])) ?></dd></div><?php endif; ?>
            </dl>
        </div>
    </aside>
</div>
