<?php /** @var list<array> $customers @var list<array> $availableUnits */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/orders')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Orders</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">New order</h1>
<p class="mt-1 text-sm text-slate-500">Prices and totals are calculated by the server from the selected units — the browser never submits amounts.</p>

<form method="post" action="<?= e(url('/admin/orders')) ?>" class="mt-6 max-w-3xl space-y-6" novalidate data-loading-form data-order-form>
    <?= csrf_field() ?>

    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Basics</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <?= \App\Support\View::component('select', [
                'name' => 'customer_id', 'label' => 'Customer', 'required' => true, 'placeholder' => 'Choose customer…',
                'options' => array_column($customers, 'name', 'id'), 'value' => old('customer_id'), 'errors' => $errors ?? [],
            ]) ?>
            <?= \App\Support\View::component('select', [
                'name' => 'channel', 'label' => 'Channel', 'required' => true,
                'options' => ['pos' => 'POS (in shop)', 'online' => 'Online'],
                'value' => old('channel', 'pos'), 'errors' => $errors ?? [],
            ]) ?>
            <?= \App\Support\View::component('select', [
                'name' => 'status', 'label' => 'Initial status', 'required' => true,
                'options' => ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'processing' => 'Processing', 'completed' => 'Completed (POS sale)'],
                'value' => old('status', 'pending'), 'errors' => $errors ?? [],
            ]) ?>
            <?= \App\Support\View::component('field', ['name' => 'discount', 'label' => 'Discount (BDT)', 'value' => old('discount', '0.00'), 'errors' => $errors ?? []]) ?>
        </div>
    </section>

    <section class="space-y-3 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Units</h2>
        <p class="text-xs text-slate-500">Only currently available units are offered. Reserved/damaged/sold units are rejected server-side even if forged into the request.</p>
        <?php if ($availableUnits === []): ?>
            <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">No available units in inventory — receive stock first.</p>
        <?php else: ?>
            <div class="max-h-96 space-y-1.5 overflow-y-auto">
                <?php foreach ($availableUnits as $unit): ?>
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50 has-[:checked]:border-blue-400 has-[:checked]:bg-blue-50/60">
                        <input type="checkbox" name="units[]" value="<?= (int) $unit['id'] ?>" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-slate-900"><?= e($unit['product_name']) ?></span>
                            <span class="block text-xs text-slate-500">SKU <?= e($unit['sku']) ?> · Grade <?= e($unit['condition_grade']) ?></span>
                        </span>
                        <span class="font-semibold text-slate-900"><?= money($unit['selling_price']) ?></span>
                        <span class="hidden text-right text-xs text-slate-400 sm:block" data-order-unit-price="<?= (int) $unit['id'] ?>"><?= e($unit['selling_price']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="border-t border-slate-100 pt-3 text-sm font-semibold text-slate-900">
                Selected total (client hint — server recalculates): <span data-order-total>BDT 0.00</span>
            </p>
        <?php endif; ?>
        <?php if (!empty($errors['units'])): ?><p class="text-xs text-red-600"><?= e($errors['units']) ?></p><?php endif; ?>
    </section>

    <div class="space-y-1.5">
        <label for="field-notes" class="block text-sm font-medium text-slate-700">Notes</label>
        <textarea id="field-notes" name="notes" rows="2" class="input"><?= e(old('notes')) ?></textarea>
    </div>

    <div class="flex gap-3">
        <button type="submit" class="btn-primary" <?= $availableUnits === [] ? 'disabled' : '' ?>>Create order</button>
        <a href="<?= e(url('/admin/orders')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
