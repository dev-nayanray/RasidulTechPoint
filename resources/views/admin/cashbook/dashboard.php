<?php
/** @var list<array> $accounts
 *  @var float $totalBalance
 *  @var int $unverified $disputed
 *  @var list<array> $recent
 */
?>
<div class="grid gap-4 sm:grid-cols-4">
    <?= \App\Support\View::component('stat-card', ['label' => 'Total balance', 'value' => money((string) $totalBalance), 'icon' => 'wallet']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Active accounts', 'value' => count($accounts), 'icon' => 'credit-card']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Unverified', 'value' => $unverified, 'icon' => 'alert-circle']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Disputed', 'value' => $disputed, 'icon' => 'x-circle']) ?>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Accounts</h2>
            <div class="flex gap-2">
                <a href="<?= e(url('/admin/cashbook/accounts')) ?>" class="btn-secondary text-xs">All</a>
                <a href="<?= e(url('/admin/cashbook/transfer')) ?>" class="btn-primary text-xs">Transfer</a>
            </div>
        </header>
        <?php if ($accounts === []): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-400">No cash accounts yet.</p>
        <?php else: ?>
        <table class="min-w-full divide-y divide-slate-100 text-sm">
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($accounts as $a): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2.5"><a class="font-medium text-blue-700 hover:underline" href="<?= e(url('/admin/cashbook/accounts/' . $a['id'])) ?>"><?= e($a['name']) ?></a><span class="block text-xs text-slate-400 capitalize"><?= e($a['account_type']) ?><?php if ($a['bank_name']): ?> · <?= e($a['bank_name']) ?><?php endif; ?></span></td>
                    <td class="px-4 py-2.5 text-right font-bold text-slate-900"><?= money($a['current_balance']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Recent movements</h2>
            <a href="<?= e(url('/admin/cashbook/movements')) ?>" class="text-xs font-medium text-blue-700 hover:underline">View all →</a>
        </header>
        <?php if ($recent === []): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-400">No movements yet.</p>
        <?php else: ?>
        <table class="min-w-full divide-y divide-slate-100 text-sm">
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($recent as $m): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2"><a class="font-mono text-blue-700" href="<?= e(url('/admin/cashbook/movements/' . $m['id'])) ?>"><?= e($m['movement_number']) ?></a><span class="block text-xs text-slate-400 capitalize"><?= e(str_replace('_', ' ', $m['movement_type'])) ?> · <?= e($m['account_name']) ?></span></td>
                    <td class="px-4 py-2 text-right font-semibold <?= $m['direction'] === 'in' ? 'text-green-700' : 'text-red-700' ?>"><?= $m['direction'] === 'in' ? '+' : '-' ?><?= money($m['amount']) ?></td>
                    <td class="px-4 py-2 text-xs">
                        <?php if ($m['settlement_status'] === 'verified'): ?><span class="text-green-700">✓ verified</span>
                        <?php elseif ($m['settlement_status'] === 'disputed'): ?><span class="text-red-700">! disputed</span>
                        <?php else: ?><span class="text-amber-700">⏳ unverified</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</div>

<div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
    <p class="font-semibold">Settlement status</p>
    <p class="mt-1">Each movement starts as <strong>unverified</strong> (recorded but not externally confirmed). Verify a movement when the bank statement or gateway confirmation is received. This separates "we recorded a payment" from "the bank confirmed the money arrived".</p>
</div>
