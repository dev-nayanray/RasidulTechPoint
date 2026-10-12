<?php
/** @var array $order
 *  @var list<string> $requestTypes
 *  @var array<string,string> $errors
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/orders/' . $order['id'])) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Order</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Create return for <?= e($order['reference']) ?></h1>
<form method="post" action="<?= e(url('/admin/returns')) ?>" class="mt-6 max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
    <input type="hidden" name="customer_id" value="<?= (int) $order['customer_id'] ?>">
    <div class="space-y-1.5">
        <label for="field-request_type" class="block text-sm font-medium text-slate-700">Request type</label>
        <select id="field-request_type" name="request_type" class="input">
            <?php foreach ($requestTypes as $t): ?>
                <option value="<?= e($t) ?>"><?= e(ucfirst(str_replace('_', ' ', $t))) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="space-y-1.5">
        <label class="block text-sm font-medium text-slate-700">Items to return</label>
        <div class="space-y-2 rounded-lg border border-slate-100 p-3">
            <?php foreach (($order['items'] ?? []) as $item): ?>
            <label class="flex items-center gap-3 text-sm">
                <input type="checkbox" name="items[]" value="<?= (int) $item['id'] ?>" <?= ($item['unit_status'] ?? '') !== 'sold' ? 'disabled' : '' ?>>
                <span class="flex-1">
                    <span class="font-medium text-slate-900"><?= e($item['product_name']) ?></span>
                    <span class="ml-2 font-mono text-xs text-slate-400"><?= e($item['sku']) ?></span>
                </span>
                <span class="text-right text-slate-700"><?= money($item['unit_price']) ?></span>
                <span class="text-xs"><?= ($item['unit_status'] ?? '—') !== 'sold' ? '(' . e($item['unit_status'] ?? '—') . ' — not returnable)' : '' ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="space-y-1.5">
        <label for="field-reason" class="block text-sm font-medium text-slate-700">Reason (required)</label>
        <textarea id="field-reason" name="reason" rows="2" required class="input" placeholder="Defective screen; customer changed mind; wrong model shipped…"></textarea>
    </div>
    <div class="space-y-1.5">
        <label for="field-customer_reported" class="block text-sm font-medium text-slate-700">Customer-reported issue (optional)</label>
        <textarea id="field-customer_reported" name="customer_reported" rows="2" class="input" placeholder="Customer's own words describing the problem…"></textarea>
    </div>
    <?php if (!empty($errors)): ?>
    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
        <?php foreach ($errors as $field => $msg): ?><p><?= e($msg) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="flex gap-3">
        <button type="submit" class="btn-primary">Create return request</button>
        <a href="<?= e(url('/admin/orders/' . $order['id'])) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
