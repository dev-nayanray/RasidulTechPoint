<?php
/** Select field: label + select + error. $options as value => label. */
$value = old($name, $value ?? '');
$error = $errors[$name] ?? ($error ?? null);
$id = 'field-' . $name;
?>
<div class="space-y-1.5">
    <label for="<?= e($id) ?>" class="block text-sm font-medium text-slate-700">
        <?= e($label) ?><?php if (!empty($required)): ?> <span class="text-red-600" aria-hidden="true">*</span><?php endif; ?>
    </label>
    <select id="<?= e($id) ?>" name="<?= e($name) ?>" class="input <?= $error ? 'border-red-400' : '' ?>" <?= !empty($required) ? 'required' : '' ?>>
        <?php if (!empty($placeholder)): ?>
            <option value=""><?= e($placeholder) ?></option>
        <?php endif; ?>
        <?php foreach (($options ?? []) as $optionValue => $optionLabel): ?>
            <option value="<?= e($optionValue) ?>" <?= (string) $optionValue === (string) $value ? 'selected' : '' ?>><?= e($optionLabel) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($error): ?><p class="text-xs text-red-600"><?= e($error) ?></p><?php endif; ?>
</div>
