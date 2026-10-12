<?php /** @var array $account @var list<array> $recent_movements */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/cashbook/accounts')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Accounts</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= e($account['name']) ?></h1>
<p class="mt-1 text-sm text-slate-500 capitalize"><?= e($account['account_type']) ?><?php if ($account['bank_name']): ?> · <?= e($account['bank_name']) ?><?php endif; ?></p>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white">
        <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-900">Recent movements (<?= count($account['recent_movements'] ?? []) ?>)</h2>
        <?php if (($account['recent_movements'] ?? []) === []): ?>
            <p class="px-5 py-8 text-center text-sm text-slate-400">No movements yet.</p>
        <?php else: ?>
        <table class="min-w-full divide-y divide-slate-100 text-sm">
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($account['recent_movements'] as $m): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2"><a class="font-mono text-blue-700" href="<?= e(url('/admin/cashbook/movements/' . $m['id'])) ?>"><?= e($m['movement_number']) ?></a><span class="block text-xs text-slate-400 capitalize"><?= e(str_replace('_', ' ', $m['movement_type'])) ?></span></td>
                    <td class="px-4 py-2 text-right font-semibold <?= $m['direction'] === 'in' ? 'text-green-700' : 'text-red-700' ?>"><?= $m['direction'] === 'in' ? '+' : '-' ?><?= money($m['amount']) ?></td>
                    <td class="px-4 py-2 text-right text-slate-500 text-xs"><?= money($m['balance_after']) ?></td>
                    <td class="px-4 py-2 text-xs">
                        <?php if ($m['settlement_status'] === 'verified'): ?><span class="text-green-700">✓</span>
                        <?php elseif ($m['settlement_status'] === 'disputed'): ?><span class="text-red-700">!</span>
                        <?php else: ?><span class="text-amber-700">⏳</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <aside class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Balance</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Opening balance</dt><dd class="font-medium text-slate-900"><?= money($account['opening_balance']) ?></dd></div>
                <div><dt class="text-slate-500">Current balance</dt><dd class="font-bold text-lg text-slate-900"><?= money($account['current_balance']) ?></dd></div>
            </dl>
            <a href="<?= e(url('/admin/cashbook/accounts/' . $account['id'] . '/reconcile')) ?>" class="btn-secondary w-full text-xs mt-3">Reconcile</a>
        </div>
    </aside>
</div>
