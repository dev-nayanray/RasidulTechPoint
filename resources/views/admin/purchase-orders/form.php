<?php
/** @var array|null $order
 *  @var array $suppliers
 *  @var list<array> $models
 *  @var array $errors
 */
$isEdit = !empty($order['id']);
$postPath = $isEdit ? '/admin/purchase-orders/' . $order['id'] . '/update' : '/admin/purchase-orders';
$supplierOptions = array_column($suppliers, 'name', 'id');
$modelOptions = array_column($models, 'name', 'id');
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/purchase-orders')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Purchase orders</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= $isEdit ? 'Edit: ' . e($order['reference']) : 'New purchase order' ?></h1>
<p class="mt-1 text-sm text-slate-500">Stock is not added when a PO is created. Stock arrives through the inventory receive flow.</p>

<form method="post" action="<?= e(url($postPath)) ?>" class="mt-6 max-w-3xl space-y-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Order details</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <?= \App\Support\View::component('select', ['name' => 'supplier_id', 'label' => 'Supplier', 'required' => true, 'options' => $supplierOptions, 'value' => (string) ($order['supplier_id'] ?? $order['supplier_id'] ?? ''), 'placeholder' => 'Choose supplier…', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'import_batch_ref', 'label' => 'Import batch ref (Dubai)', 'value' => $order['import_batch_ref'] ?? '', 'placeholder' => 'DXB-LOT-2026-10', 'errors' => $errors ?? []]) ?>
        </div>
        <div>
            <label for="field-notes" class="block text-sm font-medium text-slate-700">Notes</label>
            <textarea id="field-notes" name="notes" rows="2" class="input mt-1.5" placeholder="Internal notes…"><?= e($order['notes'] ?? '') ?></textarea>
        </div>
    </section>

    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-900">Line items</h2>
            <button type="button" class="btn-secondary text-xs" id="add-po-item">+ Add item</button>
        </div>
        <div id="po-items" class="space-y-3">
            <?php
            $existingItems = $order['items'] ?? [];
            if (empty($existingItems)) $existingItems = [['product_model_id' => '', 'quantity' => 1, 'unit_cost' => '', 'description' => '']];
            foreach ($existingItems as $i => $item):
            ?>
            <div class="po-item-row grid gap-3 sm:grid-cols-[2fr_1fr_1fr_2fr_auto]">
                <select name="item_model[]" class="input text-sm">
                    <option value="">— model —</option>
                    <?php foreach ($modelOptions as $id => $name): ?>
                        <option value="<?= (int) $id ?>" <?= (string) ($item['product_model_id'] ?? '') === (string) $id ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="item_qty[]" type="number" min="1" value="<?= e((string) ($item['quantity'] ?? 1)) ?>" class="input text-sm" placeholder="Qty">
                <input name="item_cost[]" type="number" step="0.01" min="0" value="<?= e((string) ($item['unit_cost'] ?? '')) ?>" class="input text-sm" placeholder="Unit cost">
                <input name="item_desc[]" type="text" value="<?= e($item['description'] ?? '') ?>" class="input text-sm" placeholder="Description (optional)">
                <button type="button" class="text-red-600 hover:text-red-800 text-sm pt-2" onclick="this.closest('.po-item-row').remove()">✕</button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($errors['items'])): ?><p class="text-xs text-red-600"><?= e($errors['items']) ?></p><?php endif; ?>
    </section>

    <div class="flex gap-3">
        <button type="submit" class="btn-primary"><?= $isEdit ? 'Save changes' : 'Create purchase order' ?></button>
        <a href="<?= e(url('/admin/purchase-orders')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>

<script>
document.getElementById('add-po-item')?.addEventListener('click', function() {
    var row = document.querySelector('.po-item-row');
    if (!row) return;
    var clone = row.cloneNode(true);
    clone.querySelectorAll('select').forEach(function(s) { s.selectedIndex = 0; });
    clone.querySelectorAll('input').forEach(function(i) {
        if (i.type === 'number') i.value = i.min === '1' ? '1' : '';
        else i.value = '';
    });
    document.getElementById('po-items').appendChild(clone);
});
</script>
