<?php
/** @var array $movement */
$settlement = $movement['settlement_status'] ?? 'unverified';
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/cashbook/movements')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Movements</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Movement <?= e($movement['movement_number']) ?></h1>
<p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
    <span class="capitalize"><?= e(str_replace('_', ' ', $movement['movement_type'])) ?></span>
    · <span class="font-semibold <?= $movement['direction'] === 'in' ? 'text-green-700' : 'text-red-700' ?>"><?= $movement['direction'] === 'in' ? 'IN' : 'OUT' ?> <?= money($movement['amount']) ?></span>
    · <?php if ($settlement === 'verified'): ?><span class="text-green-700">✓ verified</span><?php elseif ($settlement === 'disputed'): ?><span class="text-red-700">! disputed</span><?php else: ?><span class="text-amber-700">⏳ unverified</span><?php endif; ?>
</p>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Details</h2>
        <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
            <div><dt class="text-slate-500">Account</dt><dd class="font-medium text-slate-900"><?= e($movement['account_name']) ?> (<?= e($movement['account_type']) ?>)</dd></div>
            <div><dt class="text-slate-500">Amount</dt><dd class="font-bold <?= $movement['direction'] === 'in' ? 'text-green-700' : 'text-red-700' ?>"><?= $movement['direction'] === 'in' ? '+' : '-' ?><?= money($movement['amount']) ?></dd></div>
            <div><dt class="text-slate-500">Balance after</dt><dd class="font-medium text-slate-900"><?= money($movement['balance_after']) ?></dd></div>
            <?php if (!empty($movement['external_reference'])): ?><div><dt class="text-slate-500">External reference</dt><dd class="font-mono text-slate-900"><?= e($movement['external_reference']) ?></dd></div><?php endif; ?>
            <?php if (!empty($movement['description'])): ?><div class="sm:col-span-2"><dt class="text-slate-500">Description</dt><dd class="text-slate-700"><?= e($movement['description']) ?></dd></div><?php endif; ?>
            <?php if (!empty($movement['reference_type'])): ?><div><dt class="text-slate-500">Reference</dt><dd class="font-mono text-slate-900"><?= e($movement['reference_type']) ?> #<?= e($movement['reference_id'] ?? '—') ?></dd></div><?php endif; ?>
        </dl>
    </div>
    <aside class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Settlement</h2>
            <p class="mt-2 text-xs text-slate-500">A movement starts as <strong>unverified</strong> (recorded but not externally confirmed). Verify when the bank statement or gateway confirmation is received.</p>
            <?php if ($settlement === 'unverified'): ?>
            <form method="post" action="<?= e(url('/admin/cashbook/movements/' . $movement['id'] . '/verify')) ?>" class="mt-3" data-loading-form data-confirm="Verify this movement?">
                <?= csrf_field() ?><button type="submit" class="btn-primary w-full text-xs">Verify settlement</button>
            </form>
            <form method="post" action="<?= e(url('/admin/cashbook/movements/' . $movement['id'] . '/dispute')) ?>" class="mt-2" data-loading-form>
                <?= csrf_field() ?><button type="submit" class="btn-danger w-full text-xs">Flag as disputed</button>
            </form>
            <?php elseif ($settlement === 'verified'): ?>
            <p class="mt-3 text-xs text-green-700">✓ Verified by <?= e($movement['verified_by_name'] ?? '—') ?> on <?= e(local_datetime($movement['verified_at'])) ?>.</p>
            <?php elseif ($settlement === 'disputed'): ?>
            <p class="mt-3 text-xs text-red-700">! Flagged as disputed. Investigation required.</p>
            <?php endif; ?>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Audit</h2>
            <dl class="mt-3 space-y-2 text-xs">
                <div><dt class="text-slate-500">Created by</dt><dd class="font-medium text-slate-900"><?= e($movement['created_by_name'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Created at</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($movement['created_at'])) ?></dd></div>
            </dl>
        </div>
    </aside>
</div>
