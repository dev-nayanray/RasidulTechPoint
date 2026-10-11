<?php /** @var list<array> $models @var list<array> $locations */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/inventory')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Inventory</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Receive stock</h1>
<p class="mt-1 text-sm text-slate-500">Register an individual physical unit. New units normally start as <em>awaiting inspection</em> and become sellable only after QA passes.</p>

<form method="post" action="<?= e(url('/admin/inventory')) ?>" class="mt-6 max-w-3xl space-y-6" novalidate data-loading-form>
    <?= csrf_field() ?>

    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Identification</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <?= \App\Support\View::component('field', ['name' => 'sku', 'label' => 'Internal SKU', 'required' => true, 'placeholder' => 'RTP-DEL-0001', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'serial_number', 'label' => 'Serial / service tag (optional)', 'value' => old('serial_number'), 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('select', [
                'name' => 'product_model_id', 'label' => 'Laptop model', 'required' => true, 'placeholder' => 'Choose model…',
                'options' => array_column($models, 'name', 'id'),
                'value' => old('product_model_id'), 'errors' => $errors ?? [],
            ]) ?>
            <?= \App\Support\View::component('select', [
                'name' => 'condition_grade', 'label' => 'Condition grade', 'required' => true,
                'options' => ['A' => 'A — Excellent', 'B' => 'B — Good', 'C' => 'C — Fair', 'D' => 'D — Poor/For parts'],
                'value' => old('condition_grade', 'B'), 'errors' => $errors ?? [],
            ]) ?>
        </div>
    </section>

    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Costs &amp; pricing (BDT)</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <?= \App\Support\View::component('field', ['name' => 'purchase_cost', 'label' => 'Purchase cost', 'required' => true, 'value' => old('purchase_cost'), 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'landed_cost', 'label' => 'Additional landed cost', 'value' => old('landed_cost', '0.00'), 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'selling_price', 'label' => 'Selling price', 'required' => true, 'value' => old('selling_price'), 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'min_selling_price', 'label' => 'Minimum permitted price (optional)', 'value' => old('min_selling_price'), 'help' => 'Price changes below this are rejected.', 'errors' => $errors ?? []]) ?>
        </div>
    </section>

    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Source &amp; placement</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <?= \App\Support\View::component('field', ['name' => 'supplier_ref', 'label' => 'Supplier reference', 'value' => old('supplier_ref'), 'placeholder' => 'Dubai Supplier LLC', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'purchase_ref', 'label' => 'Purchase / lot reference', 'value' => old('purchase_ref'), 'placeholder' => 'DXB-LOT-2026-10', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('select', [
                'name' => 'location_id', 'label' => 'Location', 'placeholder' => '— unassigned —',
                'options' => array_column($locations, 'name', 'id'), 'value' => old('location_id'), 'errors' => $errors ?? [],
            ]) ?>
            <?= \App\Support\View::component('select', [
                'name' => 'initial_status', 'label' => 'Initial status', 'required' => true,
                'options' => ['awaiting_inspection' => 'Awaiting inspection (normal)', 'in_transit' => 'In transit'],
                'value' => old('initial_status', 'awaiting_inspection'), 'errors' => $errors ?? [],
            ]) ?>
        </div>
        <div class="space-y-1.5">
            <label for="field-notes" class="block text-sm font-medium text-slate-700">Notes</label>
            <textarea id="field-notes" name="notes" rows="3" class="input"><?= e(old('notes')) ?></textarea>
        </div>
    </section>

    <div class="flex gap-3">
        <button type="submit" class="btn-primary">Receive unit</button>
        <a href="<?= e(url('/admin/inventory')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
