<?php /** @var array|null $template
 *  @var array $errors
 *  @var list<string> $modes
 */
$isEdit = $template !== null && !empty($template['id']);
$eventKey = $isEdit ? ($template['event_key'] ?? '') : ($template['event_key'] ?? '');
$title = $isEdit ? ($template['title'] ?? '') : ($template['title'] ?? '');
$body = $isEdit ? ($template['body'] ?? '') : ($template['body'] ?? '');
$linkTemplate = $isEdit ? ($template['link_template'] ?? '') : ($template['link_template'] ?? '');
$recipientMode = $isEdit ? ($template['recipient_mode'] ?? 'roles') : ($template['recipient_mode'] ?? 'roles');
$recipientValue = $isEdit ? ($template['recipient_value'] ?? '') : ($template['recipient_value'] ?? '');
$isEnabled = $isEdit ? ((int) ($template['is_enabled'] ?? 0) === 1) : true;
$dedupe = $isEdit ? ((int) ($template['dedupe_window_seconds'] ?? 0)) : 300;
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/notifications/templates')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Templates</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= $isEdit ? 'Edit template' : 'New notification template' ?></h1>

<form method="post" action="<?= e($isEdit ? url('/admin/notifications/templates/' . $template['id'] . '/update') : url('/admin/notifications/templates')) ?>" class="mt-4 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2" data-loading-form>
    <?= csrf_field() ?>

    <div class="space-y-1.5 sm:col-span-2">
        <label for="field-event_key" class="block text-sm font-medium text-slate-700">Event key <span class="text-red-600">*</span></label>
        <input id="field-event_key" name="event_key" value="<?= e($eventKey) ?>" class="input <?= !empty($errors['event_key']) ? 'border-red-400' : '' ?>" required <?= $isEdit ? 'readonly' : '' ?> pattern="[a-z][a-z0-9_.]*" placeholder="order.created">
        <p class="text-xs text-slate-500">Lowercase letters, digits, dot, or underscore. Once set, this cannot be changed.</p>
        <?php if (!empty($errors['event_key'])): ?><p class="text-xs text-red-600"><?= e($errors['event_key']) ?></p><?php endif; ?>
    </div>

    <div class="space-y-1.5 sm:col-span-2">
        <label for="field-title" class="block text-sm font-medium text-slate-700">Title pattern <span class="text-red-600">*</span></label>
        <input id="field-title" name="title" value="<?= e($title) ?>" class="input" required maxlength="190" placeholder="New order {reference}">
        <p class="text-xs text-slate-500">Use {placeholder} tokens that match keys in the dispatch context (e.g. {reference}, {amount}).</p>
        <?php if (!empty($errors['title'])): ?><p class="text-xs text-red-600"><?= e($errors['title']) ?></p><?php endif; ?>
    </div>

    <div class="space-y-1.5 sm:col-span-2">
        <label for="field-body" class="block text-sm font-medium text-slate-700">Body pattern</label>
        <textarea id="field-body" name="body" rows="3" class="input" placeholder="Order {reference} for {customer_name} totalling {total} BDT."><?= e($body) ?></textarea>
    </div>

    <div class="space-y-1.5 sm:col-span-2">
        <label for="field-link_template" class="block text-sm font-medium text-slate-700">Link pattern (optional)</label>
        <input id="field-link_template" name="link_template" value="<?= e($linkTemplate) ?>" class="input" placeholder="/admin/orders/{order_id}">
    </div>

    <div class="space-y-1.5">
        <label for="field-recipient_mode" class="block text-sm font-medium text-slate-700">Recipient resolver</label>
        <select id="field-recipient_mode" name="recipient_mode" class="input">
            <?php foreach ($modes as $m): ?>
                <option value="<?= e($m) ?>" <?= $m === $recipientMode ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $m)) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="text-xs text-slate-500">How recipients are resolved server-side at dispatch time.</p>
    </div>
    <div class="space-y-1.5">
        <label for="field-recipient_value" class="block text-sm font-medium text-slate-700">Recipient value</label>
        <input id="field-recipient_value" name="recipient_value" value="<?= e($recipientValue) ?>" class="input" placeholder="manager,accountant">
        <p class="text-xs text-slate-500">Comma-separated. Roles: <code>manager,accountant</code>. Permissions: <code>payments.manage</code>. Specific users: numeric IDs. All-staff: leave blank.</p>
        <?php if (!empty($errors['recipient_value'])): ?><p class="text-xs text-red-600"><?= e($errors['recipient_value']) ?></p><?php endif; ?>
    </div>

    <div class="space-y-1.5">
        <label for="field-dedupe_window_seconds" class="block text-sm font-medium text-slate-700">Dedupe window (seconds)</label>
        <input id="field-dedupe_window_seconds" name="dedupe_window_seconds" type="number" min="0" value="<?= (int) $dedupe ?>" class="input">
        <p class="text-xs text-slate-500">0 = no dedupe. Otherwise, suppress duplicate dispatches for the same event + recipient + dedupe key within this window.</p>
    </div>
    <div class="space-y-1.5">
        <label class="block text-sm font-medium text-slate-700">Enabled</label>
        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="is_enabled" value="1" <?= $isEnabled ? 'checked' : '' ?>> Active (dispatches fire when this event occurs)</label>
    </div>

    <div class="sm:col-span-2 flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
        <a href="<?= e(url('/admin/notifications/templates')) ?>" class="btn-secondary">Cancel</a>
        <button type="submit" class="btn-primary"><?= $isEdit ? 'Save changes' : 'Create template' ?></button>
    </div>
</form>
