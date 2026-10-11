<?php
/** @var array|null $courier
 *  @var array<string,string> $errors
 */
$isEdit = $courier !== null;
$action = $isEdit ? url('/admin/delivery/couriers/' . $courier['id'] . '/update') : url('/admin/delivery/couriers');
$title = $isEdit ? ('Edit: ' . $courier['name']) : 'Add courier';
$apiProviders = ['manual', 'pathao', 'redx', 'steadfast', 'other'];
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/delivery/couriers')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Couriers</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= e($title) ?></h1>

<form method="post" action="<?= e($action) ?>" class="mt-6 max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Courier name', 'required' => true, 'value' => $isEdit ? ($courier['name'] ?? '') : old('name'), 'errors' => $errors ?? []]) ?>
    <div class="grid gap-4 sm:grid-cols-2">
        <?= \App\Support\View::component('field', ['name' => 'contact_name', 'label' => 'Contact person', 'value' => $isEdit ? ($courier['contact_name'] ?? '') : old('contact_name')]) ?>
        <?= \App\Support\View::component('field', ['name' => 'phone', 'label' => 'Phone', 'value' => $isEdit ? ($courier['phone'] ?? '') : old('phone')]) ?>
    </div>
    <?= \App\Support\View::component('field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $isEdit ? ($courier['email'] ?? '') : old('email'), 'errors' => $errors ?? []]) ?>
    <div class="space-y-1.5">
        <label for="field-api_provider" class="block text-sm font-medium text-slate-700">API provider</label>
        <select id="field-api_provider" name="api_provider" class="input">
            <?php foreach ($apiProviders as $p): ?>
                <option value="<?= e($p) ?>" <?= ($isEdit ? $courier['api_provider'] : old('api_provider', 'manual')) === $p ? 'selected' : '' ?>><?= e(ucfirst($p)) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="text-xs text-slate-500">External tracking is integrated only when real credentials and APIs are configured. Manual means the operator pastes in tracking references by hand.</p>
    </div>
    <?= \App\Support\View::component('field', ['name' => 'tracking_url_template', 'label' => 'Tracking URL template', 'help' => 'Use {ref} as placeholder, e.g. https://pathao.com/track/{ref}']) ?>
    <div class="grid gap-4 sm:grid-cols-2">
        <?= \App\Support\View::component('field', ['name' => 'base_fee', 'label' => 'Base fee (BDT)', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'value' => $isEdit ? ($courier['base_fee'] ?? '0.00') : old('base_fee', '0.00')]) ?>
        <?= \App\Support\View::component('field', ['name' => 'per_km_fee', 'label' => 'Per-km fee (BDT)', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'value' => $isEdit ? ($courier['per_km_fee'] ?? '0.00') : old('per_km_fee', '0.00')]) ?>
    </div>
    <div class="space-y-1.5">
        <label for="field-notes" class="block text-sm font-medium text-slate-700">Notes</label>
        <textarea id="field-notes" name="notes" rows="2" class="input"><?= e($isEdit ? ($courier['notes'] ?? '') : old('notes')) ?></textarea>
    </div>
    <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="checkbox" name="is_active" value="1" <?= (!$isEdit || (int) ($courier['is_active'] ?? 0) === 1) ? 'checked' : '' ?>>
        Active (available for new shipments)
    </label>
    <div class="flex gap-3">
        <button type="submit" class="btn-primary"><?= $isEdit ? 'Save changes' : 'Create courier' ?></button>
        <a href="<?= e(url('/admin/delivery/couriers')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
