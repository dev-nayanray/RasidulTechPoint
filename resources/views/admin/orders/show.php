<?php
/** @var array $order
 *  @var list<string> $methods
 *  @var bool $canManage
 *  @var bool $canManagePayments
 */
$canSeeFinance = $canManagePayments || \App\Services\AuthService::can('finance.view') || \App\Services\AuthService::can('payments.view');
$hasPendingPayments = false;
foreach ($order['payments'] as $p) {
    if ($p['status'] === 'pending') {
        $hasPendingPayments = true;
        break;
    }
}
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/orders')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Orders</a></p>

<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Order <?= e($order['reference']) ?></h1>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <?= \App\Support\View::component('badge', ['status' => $order['status']]) ?>
            <?= \App\Support\View::component('badge', ['status' => $order['payment_status']]) ?>
            <?= \App\Support\View::component('badge', ['status' => $order['channel']]) ?>
        </p>
    </div>
    <?php if ($canManage && $order['allowed_transitions'] !== []): ?>
        <form method="post" action="<?= e(url('/admin/orders/' . $order['id'] . '/status')) ?>" class="flex flex-wrap items-end gap-2" data-loading-form data-confirm="Change order status?">
            <?= csrf_field() ?>
            <div class="space-y-1">
                <label for="order-status" class="block text-xs font-medium text-slate-500">Update status</label>
                <select id="order-status" name="status" class="input">
                    <?php foreach ($order['allowed_transitions'] as $t): ?>
                        <option value="<?= e($t['to']) ?>"><?= e($t['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="space-y-1">
                <label for="order-status-reason" class="block text-xs font-medium text-slate-500">Reason (optional)</label>
                <input id="order-status-reason" name="reason" class="input" placeholder="Customer cancelled / Shipped via Pathao…">
            </div>
            <button type="submit" class="btn-secondary">Apply</button>
        </form>
    <?php elseif ($canManage): ?>
        <p class="text-xs text-slate-400">No further transitions allowed from <?= e($order['status']) ?>.</p>
    <?php endif; ?>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-900">Items (sale-time snapshots)</h2>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-slate-500">Product</th>
                        <th class="px-4 py-3 text-left font-medium text-slate-500">SKU</th>
                        <th class="px-4 py-3 text-left font-medium text-slate-500">Serial</th>
                        <th class="px-4 py-3 text-left font-medium text-slate-500">Grade</th>
                        <th class="px-4 py-3 text-left font-medium text-slate-500">Unit status</th>
                        <th class="px-4 py-3 text-right font-medium text-slate-500">Unit price</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($order['items'] as $item): ?>
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-900"><?= e($item['product_name']) ?></td>
                            <td class="px-4 py-3 text-slate-500"><?= e($item['sku']) ?></td>
                            <td class="px-4 py-3 text-slate-500"><?= e($item['serial_number'] ?? '—') ?></td>
                            <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $item['condition_grade'] ?? '—']) ?></td>
                            <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $item['unit_status'] ?? '—']) ?></td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money($item['unit_price']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm">
                <p class="flex justify-between text-slate-500"><span>Subtotal</span><span><?= money($order['subtotal']) ?></span></p>
                <?php if ((float) $order['discount'] > 0): ?>
                    <p class="flex justify-between text-slate-500"><span>Discount</span><span class="text-green-700">-<?= money($order['discount']) ?></span></p>
                <?php endif; ?>
                <p class="flex justify-between text-base font-bold text-slate-900"><span>Total</span><span><?= money($order['total']) ?></span></p>
                <?php
                $paidSum = 0.0;
                foreach ($order['payments'] as $p) {
                    if ($p['status'] === 'completed') {
                        $paidSum += (float) $p['amount'];
                    }
                }
                $outstanding = (float) $order['total'] - $paidSum;
                ?>
                <?php if ($canSeeFinance): ?>
                    <p class="flex justify-between border-t border-slate-100 pt-2 text-slate-600"><span>Paid (completed)</span><span><?= money((string) $paidSum) ?></span></p>
                    <?php if ($outstanding > 0.001): ?>
                        <p class="flex justify-between text-amber-700"><span>Outstanding</span><span><?= money((string) $outstanding) ?></span></p>
                    <?php else: ?>
                        <p class="flex justify-between text-green-700"><span>Outstanding</span><span><?= money('0.00') ?></span></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-900">Payments</h2>
            <?php if ($order['payments'] === []): ?>
                <p class="px-5 py-8 text-center text-sm text-slate-400">No payments recorded yet — payment state is tracked separately from order state.</p>
            <?php else: ?>
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <tbody>
                        <?php foreach ($order['payments'] as $payment): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2.5 capitalize"><?= e(str_replace('_', ' ', $payment['method'])) ?></td>
                                <td class="px-4 py-2.5"><?= \App\Support\View::component('badge', ['status' => $payment['status']]) ?></td>
                                <td class="px-4 py-2.5 text-slate-500"><?= e($payment['reference'] ?? '') ?></td>
                                <td class="px-4 py-2.5 text-slate-500">
                                    <?php if (!empty($payment['evidence_ref'])): ?>
                                        <span class="text-xs">evidence: <?= e($payment['evidence_ref']) ?></span>
                                    <?php elseif ($payment['status'] === 'pending'): ?>
                                        <span class="text-xs text-amber-700">awaiting evidence</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-400"><?= e($payment['received_by_name'] ?? '') ?> · <?= e($payment['paid_at'] ? local_datetime($payment['paid_at']) : '—') ?></td>
                                <td class="px-4 py-2.5 text-right font-semibold text-slate-900"><?= money($payment['amount']) ?></td>
                                <td class="px-4 py-2.5 text-right">
                                    <?php if ($canManagePayments && $payment['status'] === 'pending'): ?>
                                        <form method="post" action="<?= e(url('/admin/orders/' . $order['id'] . '/payments/' . $payment['id'] . '/confirm')) ?>" data-loading-form data-confirm="Confirm this payment with verified evidence?">
                                            <?= csrf_field() ?>
                                            <input type="text" name="evidence_ref" required maxlength="120" placeholder="Gateway slip / Txn ID" class="input !py-1 !text-xs w-40">
                                            <button type="submit" class="btn-secondary !text-xs !py-1">Confirm</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ($canManagePayments && !in_array($order['status'], ['cancelled', 'refunded'], true)): ?>
                <form method="post" action="<?= e(url('/admin/orders/' . $order['id'] . '/payments')) ?>" class="grid gap-3 border-t border-slate-200 p-4 sm:grid-cols-[1fr_1fr_1fr_1fr_auto]" data-loading-form>
                    <?= csrf_field() ?>
                    <?= \App\Support\View::component('field', ['name' => 'amount', 'label' => 'Amount', 'required' => true, 'placeholder' => number_format((float) $order['total'], 2, '.', '')]) ?>
                    <?= \App\Support\View::component('select', ['name' => 'method', 'label' => 'Method', 'required' => true, 'options' => array_combine($methods, array_map(fn ($m) => ucfirst(str_replace('_', ' ', $m)), $methods)), 'value' => 'cash']) ?>
                    <?= \App\Support\View::component('field', ['name' => 'reference', 'label' => 'Reference (optional)', 'help' => 'Receipt / gateway txn id']) ?>
                    <?= \App\Support\View::component('field', ['name' => 'evidence_ref', 'label' => 'Evidence (non-cash)', 'help' => 'Bank slip — required to mark non-cash paid']) ?>
                    <div class="flex items-end"><button type="submit" class="btn-primary">Record payment</button></div>
                </form>
                <?php if ($hasPendingPayments): ?>
                    <p class="border-t border-amber-100 bg-amber-50 px-4 py-2 text-xs text-amber-800">One or more payments are pending evidence. Confirm them with a gateway slip to mark the order paid.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div id="fulfillment-notes" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-900">Fulfillment notes (<?= count($order['fulfillment_notes']) ?>)</h2>
            <p class="border-b border-slate-100 px-5 py-2 text-xs text-slate-500">Append-only log — operator-facing, never shown to the customer.</p>
            <?php if ($order['fulfillment_notes'] === []): ?>
                <p class="px-5 py-8 text-center text-sm text-slate-400">No fulfillment notes recorded yet.</p>
            <?php else: ?>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($order['fulfillment_notes'] as $note): ?>
                        <li class="px-5 py-3">
                            <div class="flex flex-wrap items-baseline justify-between gap-2 text-xs">
                                <span class="font-medium text-slate-700"><?= e($note['author_name'] ?? 'Staff') ?></span>
                                <time class="text-slate-400" datetime="<?= e($note['created_at']) ?>"><?= e(local_datetime($note['created_at'])) ?></time>
                            </div>
                            <p class="mt-1 whitespace-pre-wrap text-sm text-slate-700"><?= e($note['body']) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($canManage && !in_array($order['status'], ['cancelled', 'refunded'], true)): ?>
                <form method="post" action="<?= e(url('/admin/orders/' . $order['id'] . '/fulfillment-notes')) ?>" class="border-t border-slate-100 px-5 py-4 space-y-2" data-loading-form>
                    <?= csrf_field() ?>
                    <label for="fulfillment-note-body" class="block text-xs font-medium text-slate-500">Add a fulfillment note</label>
                    <textarea id="fulfillment-note-body" name="body" rows="2" class="input" required maxlength="2000" placeholder="Packed and handed to Pathao rider, slip #P-…"></textarea>
                    <button type="submit" class="btn-secondary">Add note</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Customer</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Name</dt><dd class="font-medium text-slate-900"><a class="hover:text-blue-700" href="<?= e(url('/admin/customers/' . $order['customer_id'])) ?>"><?= e($order['customer_name']) ?></a></dd></div>
                <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($order['customer_phone']) ?></dd></div>
                <div><dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-900"><?= e($order['customer_email'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Address</dt><dd class="font-medium text-slate-900"><?= e($order['customer_address'] ?? '—') ?><?= !empty($order['customer_city']) ? ', ' . e($order['customer_city']) : '' ?></dd></div>
            </dl>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Timeline</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Placed</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($order['created_at'])) ?></dd></div>
                <div><dt class="text-slate-500">Placed by</dt><dd class="font-medium text-slate-900"><?= e($order['placed_by_name'] ?? 'online') ?></dd></div>
                <?php if ($order['confirmed_at']): ?><div><dt class="text-slate-500">Confirmed</dt><dd class="font-medium text-blue-700"><?= e(local_datetime($order['confirmed_at'])) ?></dd></div><?php endif; ?>
                <?php if ($order['completed_at']): ?><div><dt class="text-slate-500">Completed</dt><dd class="font-medium text-green-700"><?= e(local_datetime($order['completed_at'])) ?></dd></div><?php endif; ?>
                <?php if ($order['cancelled_at']): ?><div><dt class="text-slate-500">Cancelled</dt><dd class="font-medium text-red-700"><?= e(local_datetime($order['cancelled_at'])) ?></dd></div><?php endif; ?>
                <?php if ($order['refunded_at']): ?><div><dt class="text-slate-500">Refunded</dt><dd class="font-medium text-orange-700"><?= e(local_datetime($order['refunded_at'])) ?></dd></div><?php endif; ?>
            </dl>
            <?php if (!empty($order['notes'])): ?>
                <p class="mt-3 border-t border-slate-100 pt-3 text-slate-600"><?= e($order['notes']) ?></p>
            <?php endif; ?>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-900">Status history</h2>
            <?php if ($order['status_history'] === []): ?>
                <p class="px-5 py-6 text-center text-xs text-slate-400">No status transitions recorded.</p>
            <?php else: ?>
                <ol class="divide-y divide-slate-100 text-sm">
                    <?php foreach ($order['status_history'] as $entry): ?>
                        <li class="px-5 py-2.5">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="font-medium text-slate-700">
                                    <?= e($entry['from_status'] === 'new' ? 'Created' : ucfirst($entry['from_status'])) ?>
                                    →
                                    <?= e(ucfirst($entry['to_status'])) ?>
                                </span>
                                <time class="text-xs text-slate-400" datetime="<?= e($entry['created_at']) ?>"><?= e(local_datetime($entry['created_at'])) ?></time>
                            </div>
                            <div class="text-xs text-slate-500">
                                by <?= e($entry['actor_name'] ?? 'system') ?>
                                <?php if (!empty($entry['reason'])): ?> · <?= e($entry['reason']) ?><?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Prompt 25: invoice + refund + corrections -->

<?php if (!empty($activeInvoice)): ?>
<div class="mt-6 rounded-xl border border-slate-200 bg-white p-5 text-sm">
    <h2 class="font-semibold text-slate-900">Active invoice</h2>
    <p class="mt-2 text-slate-700">
        <a class="font-mono font-medium text-blue-700 hover:underline" href="<?= e(url('/admin/invoices/' . $activeInvoice['id'])) ?>"><?= e($activeInvoice['invoice_number']) ?></a>
        — <?= \App\Support\View::component('badge', ['status' => $activeInvoice['status']]) ?>
        — <?= e(money($activeInvoice['total'])) ?> · issued <?= e(local_datetime($activeInvoice['issue_date'], 'd M Y')) ?>
    </p>
</div>
<?php elseif (!empty($canManage)): ?>
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-5 text-sm">
    <summary class="cursor-pointer font-semibold text-slate-900">Generate invoice</summary>
    <form method="post" action="<?= e(url('/admin/orders/' . $order['id'] . '/invoice')) ?>" class="mt-4 grid gap-3 sm:grid-cols-2" data-loading-form>
        <?= csrf_field() ?>
        <?= \App\Support\View::component('field', ['name' => 'billing_name', 'label' => 'Billing name', 'required' => true, 'value' => $order['customer_name'] ?? '']) ?>
        <?= \App\Support\View::component('field', ['name' => 'billing_address', 'label' => 'Billing address', 'value' => $order['customer_address'] ?? '']) ?>
        <?= \App\Support\View::component('field', ['name' => 'billing_phone', 'label' => 'Billing phone', 'value' => $order['customer_phone'] ?? '']) ?>
        <?= \App\Support\View::component('field', ['name' => 'billing_email', 'label' => 'Billing email', 'type' => 'email', 'value' => $order['customer_email'] ?? '']) ?>
        <?= \App\Support\View::component('field', ['name' => 'tax_name', 'label' => 'Tax name', 'value' => \App\Services\SettingService::get('invoice_tax_name', 'VAT')]) ?>
        <?= \App\Support\View::component('field', ['name' => 'tax_rate', 'label' => 'Tax rate (e.g. 0.15 for 15%)', 'type' => 'number', 'step' => '0.0001', 'min' => '0', 'value' => '0']) ?>
        <?= \App\Support\View::component('field', ['name' => 'due_date', 'label' => 'Due date (optional)', 'type' => 'date']) ?>
        <div class="sm:col-span-2">
            <label for="inv-notes" class="block text-xs font-medium text-slate-500">Notes (optional)</label>
            <textarea id="inv-notes" name="notes" rows="2" class="input" placeholder="Payment due within 7 days. Please quote invoice number on the bank transfer."></textarea>
        </div>
        <div class="sm:col-span-2">
            <button type="submit" class="btn-primary">Generate invoice</button>
        </div>
    </form>
</details>
<?php endif; ?>

<?php if (!empty($canRefund) && $order['status'] !== 'cancelled' && (float) ($refundableAmount ?? 0) > 0): ?>
<details class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm">
    <summary class="cursor-pointer font-semibold text-amber-900">Issue a refund</summary>
    <p class="mt-2 text-xs text-amber-800">Refundable amount: <strong class="font-mono"><?= money((string) $refundableAmount) ?></strong>. The refund amount cannot exceed this. After creation, the refund is 'pending' — process it to release the linked payment + inventory.</p>
    <form method="post" action="<?= e(url('/admin/orders/' . $order['id'] . '/refund')) ?>" class="mt-4 grid gap-3 sm:grid-cols-2" data-loading-form data-confirm="Create this refund? It will start as pending.">
        <?= csrf_field() ?>
        <?= \App\Support\View::component('field', ['name' => 'amount', 'label' => 'Refund amount', 'type' => 'number', 'step' => '0.01', 'min' => '0.01', 'required' => true, 'value' => number_format((float) $refundableAmount, 2, '.', '')]) ?>
        <?= \App\Support\View::component('select', [
            'name' => 'payment_id', 'label' => 'Linked payment (optional)',
            'options' => array_merge(['' => 'No linked payment (offline refund)'], array_map(fn ($p) => '#' . $p['id'] . ' ' . $p['method'] . ' ' . money($p['amount']), $order['payments'] ?? [])),
        ]) ?>
        <?= \App\Support\View::component('select', [
            'name' => 'inventory_action', 'label' => 'Inventory action',
            'options' => array_combine($inventoryActions, array_map(fn ($a) => ucfirst(str_replace('_', ' ', $a)), $inventoryActions)),
            'value' => 'no_action',
        ]) ?>
        <div class="sm:col-span-2">
            <label for="refund-reason" class="block text-xs font-medium text-amber-900">Refund reason (required)</label>
            <textarea id="refund-reason" name="reason" rows="2" required maxlength="255" class="input" placeholder="Customer returned the unit; warranty claim; overpayment reversal…"></textarea>
        </div>
        <div class="sm:col-span-2">
            <button type="submit" class="btn-danger">Create refund</button>
        </div>
    </form>
</details>
<?php endif; ?>

<?php if (!empty($refunds)): ?>
<section class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <header class="border-b border-slate-200 px-5 py-3">
        <h2 class="text-sm font-semibold text-slate-900">Refunds (<?= count($refunds) ?>)</h2>
    </header>
    <table class="min-w-full divide-y divide-slate-100 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-2 text-left font-medium text-slate-500">Refund #</th>
                <th class="px-4 py-2 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-2 text-left font-medium text-slate-500">Reason</th>
                <th class="px-4 py-2 text-left font-medium text-slate-500">Inventory</th>
                <th class="px-4 py-2 text-right font-medium text-slate-500">Amount</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($refunds as $r): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2 font-mono text-blue-700"><a class="hover:underline" href="<?= e(url('/admin/refunds/' . $r['id'])) ?>"><?= e($r['refund_number']) ?></a></td>
                    <td class="px-4 py-2"><?= \App\Support\View::component('badge', ['status' => $r['status']]) ?></td>
                    <td class="px-4 py-2 text-slate-700"><?= e($r['reason']) ?></td>
                    <td class="px-4 py-2 text-xs capitalize"><?= e(str_replace('_', ' ', $r['inventory_action'])) ?></td>
                    <td class="px-4 py-2 text-right font-semibold text-red-700"><?= money($r['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php if (!empty($canCorrect)): ?>
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-5 text-sm">
    <summary class="cursor-pointer font-semibold text-slate-900">Authorized corrections (<?= count($corrections ?? []) ?>)</summary>
    <p class="mt-2 text-xs text-slate-500">Append-only log of manager edits to this order's fields after the sale. The table is INSERT-only — corrections cannot be edited or deleted.</p>
    <?php if (!empty($corrections) && is_array($corrections)): ?>
    <ul class="mt-3 divide-y divide-slate-100">
        <?php foreach ($corrections as $c): ?>
            <li class="py-2 text-xs">
                <span class="font-mono text-slate-500"><?= e($c['field']) ?></span>:
                <span class="line-through text-slate-400"><?= e($c['old_value']) ?></span> →
                <span class="font-medium text-slate-800"><?= e($c['new_value']) ?></span>
                <span class="block text-slate-400">by <?= e($c['authorized_by_name'] ?? 'unknown') ?> · <?= e(local_datetime($c['applied_at'])) ?> · <?= e($c['reason']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/admin/orders/' . $order['id'] . '/corrections')) ?>" class="mt-4 grid gap-3 sm:grid-cols-2" data-loading-form>
        <?= csrf_field() ?>
        <?= \App\Support\View::component('select', [
            'name' => 'field', 'label' => 'Field', 'required' => true,
            'options' => array_combine($correctableFields, $correctableFields),
        ]) ?>
        <div></div>
        <?= \App\Support\View::component('field', ['name' => 'old_value', 'label' => 'Old value', 'required' => true]) ?>
        <?= \App\Support\View::component('field', ['name' => 'new_value', 'label' => 'New value', 'required' => true]) ?>
        <div class="sm:col-span-2">
            <label for="correction-reason" class="block text-xs font-medium text-slate-500">Reason (required)</label>
            <textarea id="correction-reason" name="reason" rows="2" required maxlength="255" class="input" placeholder="Customer asked to swap the unit; pricing error from yesterday's promo; …"></textarea>
        </div>
        <div class="sm:col-span-2">
            <button type="submit" class="btn-secondary">Record correction</button>
        </div>
    </form>
</details>
<?php endif; ?>
