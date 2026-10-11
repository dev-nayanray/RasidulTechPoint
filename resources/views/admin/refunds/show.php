<?php
/** @var array $refund
 *  @var bool $canProcess
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/refunds')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Refunds</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Refund <?= e($refund['refund_number']) ?></h1>
<p class="mt-1 text-sm text-slate-500">
    <?= \App\Support\View::component('badge', ['status' => $refund['status']]) ?>
    · <?= e(ucfirst(str_replace('_', ' ', $refund['inventory_action']))) ?>
    · created <?= e(local_datetime($refund['created_at'])) ?>
</p>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Refund details</h2>
        <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
            <div><dt class="text-slate-500">Amount</dt><dd class="font-bold text-slate-900"><?= money($refund['amount']) ?></dd></div>
            <div><dt class="text-slate-500">Status</dt><dd><?= \App\Support\View::component('badge', ['status' => $refund['status']]) ?></dd></div>
            <div><dt class="text-slate-500">Inventory action</dt><dd class="font-medium text-slate-900 capitalize"><?= e(str_replace('_', ' ', $refund['inventory_action'])) ?></dd></div>
            <div><dt class="text-slate-500">Reason</dt><dd class="font-medium text-slate-900"><?= e($refund['reason']) ?></dd></div>
            <?php if (!empty($refund['processed_at'])): ?>
                <div><dt class="text-slate-500">Processed by</dt><dd class="font-medium text-slate-900"><?= e($refund['processed_by_name'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Processed at</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($refund['processed_at'])) ?></dd></div>
            <?php endif; ?>
            <?php if (!empty($refund['cancelled_at'])): ?>
                <div><dt class="text-slate-500">Cancelled at</dt><dd class="font-medium text-red-700"><?= e(local_datetime($refund['cancelled_at'])) ?></dd></div>
            <?php endif; ?>
        </dl>

        <?php if ($canProcess && $refund['status'] === 'pending'): ?>
        <div class="mt-6 flex flex-wrap gap-2">
            <form method="post" action="<?= e(url('/admin/refunds/' . $refund['id'] . '/process')) ?>" data-loading-form data-confirm="Process this refund? This will: refund the linked payment, release inventory per the action, and mark the refund as completed.">
                <?= csrf_field() ?>
                <button type="submit" class="btn-primary">Process refund</button>
            </form>
            <form method="post" action="<?= e(url('/admin/refunds/' . $refund['id'] . '/cancel')) ?>" data-loading-form data-confirm="Cancel this refund? The pending refund will be marked cancelled; no payment or inventory changes are made.">
                <?= csrf_field() ?>
                <button type="submit" class="btn-secondary">Cancel refund</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <aside class="space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Order</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Reference</dt><dd class="font-medium text-slate-900"><a class="hover:text-blue-700" href="<?= e(url('/admin/orders/' . $refund['order_id'])) ?>"><?= e($refund['order_reference']) ?></a></dd></div>
                <div><dt class="text-slate-500">Order total</dt><dd class="font-medium text-slate-900"><?= money($refund['order_total']) ?></dd></div>
                <div><dt class="text-slate-500">Refund amount</dt><dd class="font-bold text-red-700"><?= money($refund['amount']) ?></dd></div>
            </dl>
        </div>

        <?php if (!empty($refund['payment_id'])): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Linked payment</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Payment #</dt><dd class="font-medium text-slate-900"><a class="hover:text-blue-700" href="<?= e(url('/admin/payments/' . $refund['payment_id'])) ?>">#<?= (int) $refund['payment_id'] ?></a></dd></div>
                <div><dt class="text-slate-500">Payment amount</dt><dd class="font-medium text-slate-900"><?= money($refund['payment_amount']) ?></dd></div>
                <div><dt class="text-slate-500">Method</dt><dd class="font-medium text-slate-900 capitalize"><?= e(str_replace('_', ' ', $refund['payment_method'])) ?></dd></div>
                <div><dt class="text-slate-500">Reference</dt><dd class="font-mono text-xs text-slate-900"><?= e($refund['payment_reference'] ?? '—') ?></dd></div>
            </dl>
        </div>
        <?php endif; ?>

        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Audit</h2>
            <dl class="mt-3 space-y-2 text-xs">
                <div><dt class="text-slate-500">Authorized by</dt><dd class="font-medium text-slate-900"><?= e($refund['authorized_by_name'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Authorized at</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($refund['created_at'])) ?></dd></div>
            </dl>
        </div>
    </aside>
</div>
