<?php
/** @var list<array> $categories
 *  @var list<string> $paymentAccounts
 *  @var array<string,string> $errors
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/expenses/entries')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Expenses</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">New Expense</h1>
<form method="post" action="<?= e(url('/admin/expenses/entries')) ?>" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <div class="space-y-1.5">
        <label for="field-category_id" class="block text-sm font-medium text-slate-700">Category</label>
        <select id="field-category_id" name="category_id" required class="input">
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?= \App\Support\View::component('field', ['name' => 'description', 'label' => 'Description', 'required' => true, 'errors' => $errors ?? []]) ?>
    <div class="grid gap-4 sm:grid-cols-2">
        <?= \App\Support\View::component('field', ['name' => 'amount', 'label' => 'Amount (BDT)', 'type' => 'number', 'step' => '0.01', 'min' => '0.01', 'required' => true, 'errors' => $errors ?? []]) ?>
        <?= \App\Support\View::component('field', ['name' => 'expense_date', 'label' => 'Expense date', 'type' => 'date', 'required' => true, 'value' => gmdate('Y-m-d')]) ?>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1.5">
            <label for="field-payment_account" class="block text-sm font-medium text-slate-700">Payment account</label>
            <select id="field-payment_account" name="payment_account" class="input">
                <?php foreach ($paymentAccounts as $pa): ?>
                    <option value="<?= e($pa) ?>"><?= e(ucfirst($pa)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?= \App\Support\View::component('field', ['name' => 'payment_reference', 'label' => 'Payment reference (optional)']) ?>
    </div>
    <div class="space-y-1.5">
        <label for="field-receipt" class="block text-sm font-medium text-slate-700">Receipt attachment (optional)</label>
        <input id="field-receipt" name="receipt" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="input">
        <p class="text-xs text-slate-500">Max 4 MiB. JPEG, PNG, WebP, or PDF.</p>
    </div>
    <div class="space-y-1.5">
        <label for="field-notes" class="block text-sm font-medium text-slate-700">Notes (optional)</label>
        <textarea id="field-notes" name="notes" rows="2" class="input"></textarea>
    </div>
    <div class="flex gap-3">
        <button type="submit" class="btn-primary">Create expense</button>
        <a href="<?= e(url('/admin/expenses/entries')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
