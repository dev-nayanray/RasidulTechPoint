<?php
/** @var list<array> $accounts
 *  @var array<string,string> $errors
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/cashbook')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Cashbook</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Transfer Between Accounts</h1>
<form method="post" action="<?= e(url('/admin/cashbook/transfer')) ?>" class="mt-6 max-w-lg space-y-4 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <div class="space-y-1.5">
        <label for="field-from_account_id" class="block text-sm font-medium text-slate-700">From account</label>
        <select id="field-from_account_id" name="from_account_id" required class="input">
            <option value="">Select source account…</option>
            <?php foreach ($accounts as $a): ?>
                <option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?> (<?= money($a['current_balance']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="space-y-1.5">
        <label for="field-to_account_id" class="block text-sm font-medium text-slate-700">To account</label>
        <select id="field-to_account_id" name="to_account_id" required class="input">
            <option value="">Select destination account…</option>
            <?php foreach ($accounts as $a): ?>
                <option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?> (<?= money($a['current_balance']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <?= \App\Support\View::component('field', ['name' => 'amount', 'label' => 'Amount (BDT)', 'type' => 'number', 'step' => '0.01', 'min' => '0.01', 'required' => true, 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'description', 'label' => 'Description (optional)']) ?>
    <?php if (!empty($errors)): ?>
    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
        <?php foreach ($errors as $msg): ?><p><?= e($msg) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="flex gap-3">
        <button type="submit" class="btn-primary">Transfer</button>
        <a href="<?= e(url('/admin/cashbook')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
