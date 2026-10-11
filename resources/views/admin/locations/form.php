<?php
/** @var array|null $location
 *  @var array $errors
 */
$isEdit = !empty($location['id']);
$postPath = $isEdit ? '/admin/locations/' . $location['id'] . '/update' : '/admin/locations';
$types = ['shop' => 'Shop', 'warehouse' => 'Warehouse', 'service_center' => 'Service Center'];
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/locations')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Locations</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= $isEdit ? 'Edit: ' . e($location['name']) : 'Create location' ?></h1>

<form method="post" action="<?= e(url($postPath)) ?>" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => old('name', $location['name'] ?? ''), 'help' => 'Unique name, e.g. "Main Shop" or "Dhaka Warehouse"', 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'code', 'label' => 'Code', 'value' => old('code', $location['code'] ?? ''), 'help' => 'Short code (max 10, uppercase letters/numbers/hyphens). Used in SKU prefixes and transfers.', 'errors' => $errors ?? []]) ?>

    <div class="space-y-1.5">
        <label for="field-type" class="block text-sm font-medium text-slate-700">Type</label>
        <select id="field-type" name="type" class="input">
            <?php foreach ($types as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= ($location['type'] ?? 'shop') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <?= \App\Support\View::component('field', ['name' => 'address', 'label' => 'Address', 'value' => old('address', $location['address'] ?? ''), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'phone', 'label' => 'Phone', 'value' => old('phone', $location['phone'] ?? ''), 'errors' => $errors ?? []]) ?>

    <fieldset class="space-y-2">
        <label class="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
            <input type="checkbox" name="is_primary" value="1"
                <?= !empty($location['is_primary']) ? 'checked' : '' ?>
                class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
            <div>
                <span class="font-medium text-slate-900">Primary shop</span>
                <span class="block text-xs text-slate-500">Designate this location as the main shop. Only one location can be primary at a time.</span>
            </div>
        </label>
        <?php if ($isEdit): ?>
        <label class="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
            <input type="checkbox" name="is_active" value="1"
                <?= (!isset($location['is_active']) || (int) $location['is_active'] === 1) ? 'checked' : '' ?>
                class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
            <div>
                <span class="font-medium text-slate-900">Active</span>
                <span class="block text-xs text-slate-500">Inactive locations are hidden from the inventory receive dropdown but their units are retained.</span>
            </div>
        </label>
        <?php endif; ?>
    </fieldset>

    <?php if (!empty($errors)): ?>
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
            <?php foreach ($errors as $field => $msg): ?>
                <p><?= e($msg) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="flex gap-3">
        <button type="submit" class="btn-primary"><?= $isEdit ? 'Save changes' : 'Create location' ?></button>
        <a href="<?= e(url('/admin/locations')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>

<?php if ($isEdit && !empty($location['stock'])): ?>
<section class="mt-8 max-w-2xl rounded-xl border border-slate-200 bg-white p-5">
    <h2 class="text-sm font-semibold text-slate-900">Stock at this location</h2>
    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
        <?php
        $statuses = ['available' => 'green', 'awaiting_inspection' => 'amber', 'reserved' => 'purple', 'in_transit' => 'blue', 'sold' => 'slate', 'damaged' => 'red', 'returned' => 'orange', 'written_off' => 'slate'];
        foreach ($statuses as $status => $color):
            $count = $location['stock'][$status] ?? 0;
        ?>
            <div class="rounded-lg border border-slate-200 px-3 py-2 text-center">
                <p class="text-2xl font-bold text-slate-900"><?= $count ?></p>
                <p class="text-xs capitalize text-slate-500"><?= e(str_replace('_', ' ', $status)) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
