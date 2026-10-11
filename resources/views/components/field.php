<?php
/**
 * Form field: label + control + validation error.
 * Expects: $name, $label, $type (text|email|password|number|...), $value,
 * $error (optional), $required, $help, $attrs (extra input attributes).
 */
$type = $type ?? 'text';
$value = old($name, $value ?? '');
$error = $errors[$name] ?? ($error ?? null);
$required = $required ?? false;
$help = $help ?? null;
$id = 'field-' . $name;
?>
<div class="space-y-1.5">
    <label for="<?= e($id) ?>" class="block text-sm font-medium text-slate-700">
        <?= e($label) ?><?php if ($required): ?> <span class="text-red-600" aria-hidden="true">*</span><?php endif; ?>
    </label>
    <input
        id="<?= e($id) ?>"
        name="<?= e($name) ?>"
        type="<?= e($type) ?>"
        value="<?= e($value) ?>"
        <?= $required ? 'required' : '' ?>
        <?= !empty($attrs) ? $attrs : '' ?>
        class="input <?= $error ? 'border-red-400 focus:border-red-500 focus:ring-red-100' : '' ?>"
        <?= $error ? 'aria-invalid="true" aria-describedby="' . e($id) . '-error"' : '' ?>
    >
    <?php if ($error): ?>
        <p id="<?= e($id) ?>-error" class="text-xs text-red-600"><?= e($error) ?></p>
    <?php elseif ($help): ?>
        <p class="text-xs text-slate-500"><?= e($help) ?></p>
    <?php endif; ?>
</div>
