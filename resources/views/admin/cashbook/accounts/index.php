<?php
/** @var list<array> $accounts
 *  @var list<string> $accountTypes
 */
?>
<div class="flex items-center justify-between">
    <p class="text-sm text-slate-500"><?= count($accounts) ?> account(s)</p>
</div>
<details class="mt-4 rounded-xl border border-slate-200 bg-white p-5 text-sm" open>
    <summary class="cursor-pointer font-semibold text-slate-900">Add account</summary>
    <form method="post" action="<?= e(url('/admin/cashbook/accounts')) ?>" class="mt-4 grid gap-3 sm:grid-cols-2" data-loading-form>
        <?= csrf_field() ?>
        <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Account name', 'required' => true]) ?>
        <div class="space-y-1.5">
            <label for="field-account_type" class="block text-sm font-medium text-slate-700">Account type</label>
            <select id="field-account_type" name="account_type" class="input">
                <?php foreach ($accountTypes as $t): ?>
                    <option value="<?= e($t) ?>"><?= e(ucfirst($t)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?= \App\Support\View::component('field', ['name' => 'opening_balance', 'label' => 'Opening balance (BDT)', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'value' => '0.00']) ?>
        <?= \App\Support\View::component('field', ['name' => 'account_number', 'label' => 'Account number (optional)']) ?>
        <?= \App\Support\View::component('field', ['name' => 'bank_name', 'label' => 'Bank name (optional)']) ?>
        <?= \App\Support\View::component('field', ['name' => 'branch', 'label' => 'Branch (optional)']) ?>
        <div class="sm:col-span-2"><button type="submit" class="btn-primary text-xs">Create account</button></div>
    </form>
</details>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Name</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Type</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Account #</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Bank</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Opening</th>
            <th class="px-4 py-3 text-right font-medium text-slate-500">Current</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($accounts as $a): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-medium text-blue-700"><a href="<?= e(url('/admin/cashbook/accounts/' . $a['id'])) ?>"><?= e($a['name']) ?></a></td>
                <td class="px-4 py-3 capitalize text-slate-500"><?= e($a['account_type']) ?></td>
                <td class="px-4 py-3 font-mono text-xs text-slate-500"><?= e($a['account_number'] ?? '—') ?></td>
                <td class="px-4 py-3 text-xs text-slate-500"><?= e($a['bank_name'] ?? '—') ?></td>
                <td class="px-4 py-3 text-right text-slate-500"><?= money($a['opening_balance']) ?></td>
                <td class="px-4 py-3 text-right font-bold text-slate-900"><?= money($a['current_balance']) ?></td>
                <td class="px-4 py-3"><?= (int) $a['is_active'] === 1 ? \App\Support\View::component('badge', ['status' => 'active']) : \App\Support\View::component('badge', ['status' => 'inactive']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($accounts === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No accounts yet.</p><?php endif; ?>
</div>
