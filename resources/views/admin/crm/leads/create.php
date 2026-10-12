<?php
/** @var list<string> $sources
 *  @var array<string,string> $errors
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/crm/leads')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Leads</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">New lead</h1>
<form method="post" action="<?= e(url('/admin/crm/leads')) ?>" class="mt-6 max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => old('name'), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'phone', 'label' => 'Phone (WhatsApp)', 'required' => true, 'value' => old('phone'), 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'email', 'label' => 'Email (optional)', 'type' => 'email', 'value' => old('email')]) ?>
    <div class="space-y-1.5">
        <label for="field-source" class="block text-sm font-medium text-slate-700">Source</label>
        <select id="field-source" name="source" class="input">
            <?php foreach ($sources as $s): ?>
                <option value="<?= e($s) ?>"><?= e(ucfirst(str_replace('_', ' ', $s))) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?= \App\Support\View::component('field', ['name' => 'budget', 'label' => 'Budget (optional)', 'type' => 'number', 'step' => '0.01', 'min' => '0']) ?>
    <?= \App\Support\View::component('field', ['name' => 'interest', 'label' => 'Interest (optional)']) ?>
    <?= \App\Support\View::component('field', ['name' => 'next_followup_at', 'label' => 'Next follow-up (optional)', 'type' => 'datetime-local']) ?>
    <div class="flex gap-3">
        <button type="submit" class="btn-primary">Create lead</button>
        <a href="<?= e(url('/admin/crm/leads')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
