<?php
/** @var array $payment
 *  @var bool $canManage
 *  @var bool $canRefund
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/payments')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Payments</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Payment #<?= (int) $payment['id'] ?></h1>
<p class="mt-1 text-sm text-slate-500">
    <?= \App\Support\View::component('badge', ['status' => $payment['status']]) ?>
    <?= \App\Support\View::component('badge', ['status' => $payment['method']]) ?>
    <?php if (!empty($payment['gateway_name'])): ?>· gateway: <?= e($payment['gateway_name']) ?><?php endif; ?>
</p>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Summary</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                <div><dt class="text-slate-500">Amount</dt><dd class="font-bold text-slate-900"><?= money($payment['amount']) ?></dd></div>
                <div><dt class="text-slate-500">Method</dt><dd class="font-medium text-slate-900 capitalize"><?= e(str_replace('_', ' ', $payment['method'])) ?></dd></div>
                <div><dt class="text-slate-500">Reference</dt><dd class="font-mono text-slate-900"><?= e($payment['reference'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Evidence</dt><dd class="font-mono text-slate-900"><?= e($payment['evidence_ref'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Gateway txn id</dt><dd class="font-mono text-slate-900"><?= e($payment['gateway_txn_id'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Status</dt><dd><?= \App\Support\View::component('badge', ['status' => $payment['status']]) ?></dd></div>
            </dl>
        </div>

        <?php if (!empty($payment['refunds'])): ?>
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-900">Refund trail</h2>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($payment['refunds'] as $refund): ?>
                    <li class="px-5 py-3 text-sm">
                        <p class="font-semibold text-red-700">-<?= money($refund['amount']) ?></p>
                        <p class="mt-1 text-xs text-slate-500"><?= e($refund['refund_reason']) ?></p>
                        <p class="mt-0.5 text-xs text-slate-400">refunded at <?= e(local_datetime($refund['refunded_at'])) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($canManage && $payment['status'] === 'pending' && $payment['method'] !== 'cash'): ?>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm">
            <h2 class="font-semibold text-amber-900">Verify this payment</h2>
            <p class="mt-1 text-xs text-amber-800">Open the gateway / bank portal and confirm the transaction id below matches a real credit. Paste the verified reference to mark the payment completed.</p>
            <form method="post" action="<?= e(url('/admin/payments/' . $payment['id'] . '/verify')) ?>" class="mt-3 space-y-2" data-loading-form data-confirm="Mark this payment as completed?">
                <?= csrf_field() ?>
                <label class="block text-xs font-medium text-amber-900" for="verify-evidence">Verified evidence reference</label>
                <input id="verify-evidence" name="evidence_ref" type="text" required maxlength="120" class="input" placeholder="Bank slip / gateway txn id">
                <button type="submit" class="btn-primary">Verify payment</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($canRefund && $payment['status'] === 'completed'): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 p-5 text-sm">
            <h2 class="font-semibold text-red-900">Issue a refund</h2>
            <p class="mt-1 text-xs text-red-800">Refunding marks this payment as 'refunded' and recomputes the order's payment_status. The unit can then be released via the order's status transition to 'refunded'.</p>
            <form method="post" action="<?= e(url('/admin/payments/' . $payment['id'] . '/refund')) ?>" class="mt-3 space-y-2" data-loading-form data-confirm="Issue a refund? This cannot be undone.">
                <?= csrf_field() ?>
                <label class="block text-xs font-medium text-red-900" for="refund-reason">Refund reason (required)</label>
                <textarea id="refund-reason" name="reason" rows="2" required maxlength="255" class="input" placeholder="Customer returned the unit; gateway refund processed as txn ID …"></textarea>
                <button type="submit" class="btn-danger">Refund payment</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <aside class="space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Order</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Reference</dt><dd class="font-medium text-slate-900"><a class="hover:text-blue-700" href="<?= e(url('/admin/orders/' . $payment['order_id'])) ?>"><?= e($payment['order_reference']) ?></a></dd></div>
                <div><dt class="text-slate-500">Order total</dt><dd class="font-medium text-slate-900"><?= money($payment['order_total']) ?></dd></div>
                <div><dt class="text-slate-500">Channel</dt><dd><?= \App\Support\View::component('badge', ['status' => $payment['order_channel']]) ?></dd></div>
                <div><dt class="text-slate-500">Order status</dt><dd><?= \App\Support\View::component('badge', ['status' => $payment['order_status']]) ?></dd></div>
                <div><dt class="text-slate-500">Order payment</dt><dd><?= \App\Support\View::component('badge', ['status' => $payment['order_payment_status']]) ?></dd></div>
            </dl>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Customer</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Name</dt><dd class="font-medium text-slate-900"><a class="hover:text-blue-700" href="<?= e(url('/admin/customers/' . ($payment['customer_id'] ?? '#'))) ?>"><?= e($payment['customer_name']) ?></a></dd></div>
                <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($payment['customer_phone']) ?></dd></div>
                <?php if (!empty($payment['customer_email'])): ?><div><dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-900"><?= e($payment['customer_email']) ?></dd></div><?php endif; ?>
            </dl>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Audit</h2>
            <dl class="mt-3 space-y-2 text-xs">
                <div><dt class="text-slate-500">Recorded by</dt><dd class="font-medium text-slate-900"><?= e($payment['received_by_name'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Recorded at</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($payment['created_at'])) ?></dd></div>
                <?php if (!empty($payment['paid_at'])): ?><div><dt class="text-slate-500">Paid at</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($payment['paid_at'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($payment['verified_by_name'])): ?><div><dt class="text-slate-500">Verified by</dt><dd class="font-medium text-slate-900"><?= e($payment['verified_by_name']) ?></dd></div><?php endif; ?>
                <?php if (!empty($payment['verified_at'])): ?><div><dt class="text-slate-500">Verified at</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($payment['verified_at'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($payment['refunded_by_name'])): ?><div><dt class="text-slate-500">Refunded by</dt><dd class="font-medium text-slate-900"><?= e($payment['refunded_by_name']) ?></dd></div><?php endif; ?>
                <?php if (!empty($payment['refunded_at'])): ?><div><dt class="text-slate-500">Refunded at</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($payment['refunded_at'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($payment['shift_id'])): ?><div><dt class="text-slate-500">Cashier shift</dt><dd class="font-medium text-slate-900">#<?= (int) $payment['shift_id'] ?></dd></div><?php endif; ?>
                <?php if (!empty($payment['ip_address'])): ?><div><dt class="text-slate-500">Source IP</dt><dd class="font-mono text-slate-900"><?= e($payment['ip_address']) ?></dd></div><?php endif; ?>
            </dl>
        </div>
    </aside>
</div>
