<?php
/** @var array $order */
$statusColors = [
    'draft' => 'border-slate-200 bg-slate-50 text-slate-600',
    'submitted' => 'border-blue-200 bg-blue-50 text-blue-700',
    'approved' => 'border-green-200 bg-green-50 text-green-700',
    'received' => 'border-green-200 bg-green-100 text-green-800',
    'cancelled' => 'border-red-200 bg-red-50 text-red-700',
];
$statusActions = [
    'draft' => [['action' => 'submit', 'label' => 'Submit for approval', 'class' => 'btn-primary']],
    'submitted' => [['action' => 'approve', 'label' => 'Approve', 'class' => 'btn-primary'], ['action' => 'cancel', 'label' => 'Cancel', 'class' => 'btn-danger']],
    'approved' => [['action' => 'receive', 'label' => 'Mark received', 'class' => 'btn-primary'], ['action' => 'cancel', 'label' => 'Cancel', 'class' => 'btn-danger']],
];
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/purchase-orders')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Purchase orders</a></p>

<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900"><?= e($order['reference']) ?></h1>
        <p class="text-sm text-slate-500">
            <?= e($order['supplier_name']) ?> ·
            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize <?= $statusColors[$order['status']] ?? $statusColors['draft'] ?>"><?= e(str_replace('_', ' ', $order['status'])) ?></span>
            <?php if ($order['import_batch_ref']): ?> · Batch: <code class="text-xs"><?= e($order['import_batch_ref']) ?></code><?php endif; ?>
        </p>
    </div>
    <div class="flex gap-2">
        <?php if (\App\Services\AuthService::can('purchases.manage') && $order['status'] === 'draft'): ?>
            <a href="<?= e(url('/admin/purchase-orders/' . $order['id'] . '/edit')) ?>" class="btn-secondary">Edit</a>
        <?php endif; ?>
        <?php foreach ($statusActions[$order['status']] ?? [] as $sa): ?>
            <form method="post" action="<?= e(url('/admin/purchase-orders/' . $order['id'] . '/status')) ?>" data-loading-form data-confirm="<?= e($sa['label']) ?> this purchase order?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= e($sa['action']) ?>">
                <button type="submit" class="<?= e($sa['class']) ?>"><?= e($sa['label']) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Order summary</h2>
        <dl class="mt-3 space-y-2">
            <div><dt class="text-slate-500">Status</dt><dd><span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize <?= $statusColors[$order['status']] ?? $statusColors['draft'] ?>"><?= e(str_replace('_', ' ', $order['status'])) ?></span></dd></div>
            <div><dt class="text-slate-500">Supplier</dt><dd class="font-medium text-slate-900"><?= e($order['supplier_name']) ?></dd></div>
            <div><dt class="text-slate-500">Import batch</dt><dd class="font-medium text-slate-900"><code class="text-xs"><?= e($order['import_batch_ref'] ?? '—') ?></code></dd></div>
            <div><dt class="text-slate-500">Created</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($order['created_at'])) ?></dd></div>
            <?php if ($order['submitted_at']): ?><div><dt class="text-slate-500">Submitted</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($order['submitted_at'])) ?></dd></div><?php endif; ?>
            <?php if ($order['approved_at']): ?><div><dt class="text-slate-500">Approved</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($order['approved_at'])) ?></dd></div><?php endif; ?>
            <?php if ($order['received_at']): ?><div><dt class="text-slate-500">Received</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($order['received_at'])) ?></dd></div><?php endif; ?>
        </dl>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Totals</h2>
        <dl class="mt-3 space-y-2">
            <div class="flex justify-between"><dt class="text-slate-500">Subtotal</dt><dd class="font-medium"><?= e(money($order['subtotal'])) ?></dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Discount</dt><dd class="font-medium"><?= e(money($order['discount'])) ?></dd></div>
            <div class="flex justify-between border-t border-slate-100 pt-2"><dt class="text-slate-500">Total</dt><dd class="font-bold text-green-700"><?= e(money($order['total'])) ?></dd></div>
        </dl>
    </div>
    <?php if ($order['notes']): ?>
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Notes</h2>
        <p class="mt-2 text-slate-600"><?= e($order['notes']) ?></p>
    </div>
    <?php endif; ?>
</div>

<section class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <header class="border-b border-slate-200 px-4 py-3">
        <h2 class="text-sm font-semibold text-slate-900">Line items (<?= count($order['items']) ?>)</h2>
    </header>
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Product model</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Description</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Qty</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Unit cost</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Line total</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($order['items'] as $item): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium text-slate-900"><a href="<?= e(url('/admin/products/' . $item['product_model_id'])) ?>" class="text-blue-700 hover:text-blue-800"><?= e($item['product_name']) ?></a></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($item['description'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-right text-slate-700"><?= (int) $item['quantity'] ?></td>
                    <td class="px-4 py-3 text-right text-slate-500"><?= e(money($item['unit_cost'])) ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= e(money($item['line_total'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="border-t-2 border-slate-200 bg-slate-50">
                <td colspan="4" class="px-4 py-3 text-right font-semibold text-slate-500">Total</td>
                <td class="px-4 py-3 text-right font-bold text-green-700"><?= e(money($order['total'])) ?></td>
            </tr>
        </tfoot>
    </table>
</section>

<?php if ($order['status'] === 'approved'): ?>
<p class="mt-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-700">
    This PO is approved. Receive stock against it via
    <a href="<?= e(url('/admin/inventory/create')) ?>" class="font-medium text-blue-800 underline">Inventory → Receive stock</a>
    — use <code class="text-xs"><?= e($order['import_batch_ref'] ?? $order['reference']) ?></code> as the purchase ref and
    <code class="text-xs"><?= e($order['supplier_name']) ?></code> as the supplier ref.
</p>
<?php endif; ?>
