<?php /** @var array $account */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/cashbook/accounts/' . $account['id'])) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Account</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Reconcile <?= e($account['name']) ?></h1>
<div class="mt-6 max-w-lg rounded-xl border border-slate-200 bg-white p-6 text-sm">
    <dl class="space-y-2">
        <div class="flex justify-between"><dt class="text-slate-500">Recorded balance</dt><dd class="font-bold text-slate-900"><?= money($account['current_balance']) ?></dd></div>
    </dl>
    <form method="post" action="<?= e(url('/admin/cashbook/accounts/' . $account['id'] . '/reconcile')) ?>" class="mt-4 space-y-3" data-loading-form>
        <?= csrf_field() ?>
        <label class="block text-sm font-medium text-slate-700" for="verified_balance">Verified external balance (from bank statement)</label>
        <input id="verified_balance" name="verified_balance" type="number" step="0.01" min="0" value="<?= e($account['current_balance']) ?>" required class="input">
        <button type="submit" class="btn-primary">Reconcile</button>
    </form>
</div>
