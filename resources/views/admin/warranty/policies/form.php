<?php
/** @var array|null $policy
 *  @var array<string,string> $errors
 *  @var list<string> $coverageTypes
 */
$isEdit = $policy !== null;
$action = $isEdit ? url('/admin/warranty/policies/' . $policy['id'] . '/update') : url('/admin/warranty/policies');
$title = $isEdit ? ('Edit: ' . $policy['name']) : 'Add warranty policy';
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/warranty/policies')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Policies</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= e($title) ?></h1>
<form method="post" action="<?= e($action) ?>" class="mt-6 max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Policy name', 'required' => true, 'value' => $isEdit ? ($policy['name'] ?? '') : old('name'), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'description', 'label' => 'Description', 'value' => $isEdit ? ($policy['description'] ?? '') : old('description')]) ?>
    <div class="grid gap-4 sm:grid-cols-2">
        <?= \App\Support\View::component('field', ['name' => 'duration_days', 'label' => 'Duration (days)', 'type' => 'number', 'min' => '1', 'required' => true, 'value' => $isEdit ? ($policy['duration_days'] ?? '30') : old('duration_days', '30'), 'errors' => $errors ?? []]) ?>
        <div class="space-y-1.5">
            <label for="field-coverage_type" class="block text-sm font-medium text-slate-700">Coverage type</label>
            <select id="field-coverage_type" name="coverage_type" class="input">
                <?php foreach ($coverageTypes as $ct): ?>
                    <option value="<?= e($ct) ?>" <?= ($isEdit ? $policy['coverage_type'] : old('coverage_type', 'repair')) === $ct ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $ct))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="space-y-1.5">
        <label for="field-terms" class="block text-sm font-medium text-slate-700">Terms &amp; conditions</label>
        <textarea id="field-terms" name="terms" rows="4" class="input"><?= e($isEdit ? ($policy['terms'] ?? '') : old('terms')) ?></textarea>
    </div>
    <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="checkbox" name="is_active" value="1" <?= (!$isEdit || (int) ($policy['is_active'] ?? 0) === 1) ? 'checked' : '' ?>> Active
    </label>
    <div class="flex gap-3">
        <button type="submit" class="btn-primary"><?= $isEdit ? 'Save changes' : 'Create policy' ?></button>
        <a href="<?= e(url('/admin/warranty/policies')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
