<?php
/** @var array $pnl */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/profit')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Profit</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Profit &amp; Loss Statement</h1>
<form method="get" action="<?= e(url('/profit/pnl')) ?>" class="mt-4 flex flex-wrap gap-2">
    <input type="date" name="from" value="<?= e($pnl['from'] ?? gmdate('Y-m-01')) ?>" class="input">
    <input type="date" name="to" value="<?= e($pnl['to'] ?? gmdate('Y-m-d')) ?>" class="input">
    <button type="submit" class="btn-primary">Generate</button>
</form>

<div class="mt-6 max-w-3xl rounded-xl border border-slate-200 bg-white p-6 text-sm">
    <h2 class="text-lg font-bold text-slate-900">Profit &amp; Loss</h2>
    <p class="text-xs text-slate-500">Period: <?= e($pnl['from'] ?? '') ?> to <?= e($pnl['to'] ?? '') ?></p>
    <dl class="mt-6 space-y-3">
        <div class="flex justify-between border-b border-slate-100 pb-2">
            <dt class="text-slate-700">Gross revenue (subtotal of completed orders)</dt>
            <dd class="font-medium text-slate-900"><?= money((string) ($pnl['total_revenue'] ?? 0)) ?></dd>
        </div>
        <div class="flex justify-between border-b border-slate-100 pb-2">
            <dt class="text-slate-700">Less: Discounts given</dt>
            <dd class="font-medium text-red-700">-<?= money((string) ($pnl['total_discount'] ?? 0)) ?></dd>
        </div>
        <div class="flex justify-between border-b-2 border-slate-200 pb-2">
            <dt class="text-slate-900 font-semibold">Net revenue</dt>
            <dd class="font-bold text-slate-900"><?= money((string) ($pnl['net_revenue'] ?? 0)) ?></dd>
        </div>
        <div class="flex justify-between border-b border-slate-100 pb-2">
            <dt class="text-slate-700">Less: Cost of goods sold</dt>
            <dd class="font-medium text-red-700">-<?= money((string) ($pnl['total_cogs'] ?? 0)) ?></dd>
        </div>
        <div class="flex justify-between border-b-2 border-slate-200 pb-2">
            <dt class="text-slate-900 font-semibold">Gross profit</dt>
            <dd class="font-bold text-lg <?= ($pnl['gross_profit'] ?? 0) >= 0 ? 'text-green-700' : 'text-red-700' ?>"><?= money((string) ($pnl['gross_profit'] ?? 0)) ?></dd>
        </div>
        <div class="flex justify-between border-b border-slate-100 pb-2">
            <dt class="text-slate-700">Gross margin</dt>
            <dd class="font-medium text-slate-900"><?= number_format($pnl['gross_margin'] ?? 0, 2) ?>%</dd>
        </div>
        <div class="flex justify-between border-b border-slate-100 pb-2">
            <dt class="text-slate-700">Less: Operating expenses</dt>
            <dd class="font-medium text-red-700">-<?= money((string) ($pnl['operating_expenses'] ?? 0)) ?></dd>
        </div>
        <?php if (($pnl['expenses_by_category'] ?? []) !== []): ?>
        <div class="ml-6 space-y-1 pb-2">
            <?php foreach (($pnl['expenses_by_category'] ?? []) as $cat): ?>
            <div class="flex justify-between text-xs">
                <span class="text-slate-500"><?= e($cat['name']) ?> (<?= (int) $cat['count'] ?>)</span>
                <span class="text-slate-700"><?= money((string) $cat['total']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="flex justify-between pt-2">
            <dt class="text-slate-900 font-bold text-base">Net profit</dt>
            <dd class="font-bold text-xl <?= ($pnl['net_profit'] ?? 0) >= 0 ? 'text-green-700' : 'text-red-700' ?>"><?= money((string) ($pnl['net_profit'] ?? 0)) ?></dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-slate-500">Net margin</dt>
            <dd class="font-medium text-slate-900"><?= number_format($pnl['net_margin'] ?? 0, 2) ?>%</dd>
        </div>
    </dl>
</div>

<div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
    <p class="font-semibold">Methodology</p>
    <ul class="mt-1 list-disc list-inside space-y-0.5">
        <li>COGS per unit = purchase_cost + landed_cost (actual acquisition cost including allocated shipping/customs).</li>
        <li>Sales revenue = orders.subtotal - orders.discount (NOT payments.amount). Payment collections are cash inflows, not revenue.</li>
        <li>Only 'completed' orders are counted. Cancelled/refunded orders are excluded from both revenue and COGS.</li>
        <li>Operating expenses = approved + paid expense_entries in the date range. Pending/rejected expenses excluded.</li>
        <li>Net profit = Gross profit - Operating expenses.</li>
    </ul>
</div>
