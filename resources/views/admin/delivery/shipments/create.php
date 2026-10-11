<?php
/** @var array $order
 *  @var list<array> $couriers
 *  @var list<array> $zones
 *  @var array<string,string> $errors
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/orders/' . $order['id'])) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Order</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Create shipment for <?= e($order['reference']) ?></h1>

<form method="post" action="<?= e(url('/admin/delivery/shipments')) ?>" class="mt-6 max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
    <?= \App\Support\View::component('field', ['name' => 'recipient_name', 'label' => 'Recipient name', 'required' => true, 'value' => old('recipient_name', $order['customer_name'] ?? ''), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'recipient_phone', 'label' => 'Recipient phone', 'required' => true, 'value' => old('recipient_phone', $order['customer_phone'] ?? ''), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'delivery_address', 'label' => 'Delivery address', 'required' => true, 'value' => old('delivery_address', $order['customer_address'] ?? ''), 'errors' => $errors ?? []]) ?>
    <div class="space-y-1.5">
        <label for="field-courier_id" class="block text-sm font-medium text-slate-700">Courier (optional — assign at dispatch)</label>
        <select id="field-courier_id" name="courier_id" class="input">
            <option value="">— Select later —</option>
            <?php foreach ($couriers as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?> · base <?= money($c['base_fee']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="space-y-1.5">
        <label for="field-delivery_zone_id" class="block text-sm font-medium text-slate-700">Delivery zone (optional)</label>
        <select id="field-delivery_zone_id" name="delivery_zone_id" class="input">
            <option value="">— None —</option>
            <?php foreach ($zones as $z): ?>
                <option value="<?= (int) $z['id'] ?>"><?= e($z['name']) ?> · <?= money($z['base_fee']) ?> · ~<?= (int) ($z['estimated_delivery_hours'] ?? 0) ?>h</option>
            <?php endforeach; ?>
        </select>
    </div>
    <?= \App\Support\View::component('field', ['name' => 'fee', 'label' => 'Delivery fee (BDT)', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'value' => old('fee', '0.00')]) ?>
    <div class="space-y-1.5">
        <label for="field-notes" class="block text-sm font-medium text-slate-700">Notes</label>
        <textarea id="field-notes" name="notes" rows="2" class="input"><?= e(old('notes')) ?></textarea>
    </div>
    <div class="flex gap-3">
        <button type="submit" class="btn-primary">Create shipment</button>
        <a href="<?= e(url('/admin/orders/' . $order['id'])) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
