<?php
/** @var array $pnl */
?>
<div class="grid gap-4 sm:grid-cols-4">
    <?= \App\Support\View::component('stat-card', ['label' => 'Net revenue', 'value' => money((string) ($pnl['net_revenue'] ?? 0)), 'icon' => 'trending-up']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'COGS', 'value' => money((string) ($pnl['total_cogs'] ?? 0)), 'icon' => 'package']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Gross profit', 'value' => money((string) ($pnl['gross_profit'] ?? 0)), 'icon' => 'dollar-sign']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Net profit', 'value' => money((string) ($pnl['net_profit'] ?? 0)), 'icon' => 'piggy-bank']) ?>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <section class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">This month at a glance</h2>
        <dl class="mt-4 space-y-3">
            <div class="flex justify-between border-b border-slate-100 pb-2">
                <dt class="text-slate-500">Gross revenue (subtotal)</dt>
                <dd class="font-medium text-slate-900"><?= money((string) ($pnl['total_revenue'] ?? 0)) ?></dd>
            </div>
            <div class="flex justify-between border-b border-slate-100 pb-2">
                <dt class="text-slate-500">Less: Discounts</dt>
                <dd class="font-medium text-red-700">-<?= money((string) ($pnl['total_discount'] ?? 0)) ?></dd>
            </div>
            <div class="flex justify-between border-b border-slate-100 pb-2">
                <dt class="text-slate-500 font-semibold">Net revenue</dt>
                <dd class="font-bold text-slate-900"><?= money((string) ($pnl['net_revenue'] ?? 0)) ?></dd>
            </div>
            <div class="flex justify-between border-b border-slate-100 pb-2">
                <dt class="text-slate-500">Less: COGS</dt>
                <dd class="font-medium text-red-700">-<?= money((string) ($pnl['total_cogs'] ?? 0)) ?></dd>
            </div>
            <div class="flex justify-between border-b border-slate-100 pb-2">
                <dt class="text-slate-500 font-semibold">Gross profit</dt>
                <dd class="font-bold <?= ($pnl['gross_profit'] ?? 0) >= 0 ? 'text-green-700' : 'text-red-700' ?>"><?= money((string) ($pnl['gross_profit'] ?? 0)) ?></dd>
            </div>
            <div class="flex justify-between border-b border-slate-100 pb-2">
                <dt class="text-slate-500">Gross margin</dt>
                <dd class="font-medium text-slate-900"><?= number_format($pnl['gross_margin'] ?? 0, 2) ?>%</dd>
            </div>
            <div class="flex justify-between border-b border-slate-100 pb-2">
                <dt class="text-slate-500">Less: Operating expenses</dt>
                <dd class="font-medium text-red-700">-<?= money((string) ($pnl['operating_expenses'] ?? 0)) ?></dd>
            </div>
            <div class="flex justify-between pt-1">
                <dt class="text-slate-700 font-bold">Net profit</dt>
                <dd class="font-bold text-lg <?= ($pnl['net_profit'] ?? 0) >= 0 ? 'text-green-700' : 'text-red-700' ?>"><?= money((string) ($pnl['net_profit'] ?? 0)) ?></dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">Net margin</dt>
                <dd class="font-medium text-slate-900"><?= number_format($pnl['net_margin'] ?? 0, 2) ?>%</dd>
            </div>
        </dl>
        <div class="mt-4 flex gap-2">
            <a href="<?= e(url('/profit/cogs')) ?>" class="btn-secondary text-xs">COGS report</a>
            <a href="<?= e(url('/profit/pnl')) ?>" class="btn-secondary text-xs">Full P&L</a>
        </div>
    </section>

    <aside class="space-y-4">
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-xs text-amber-900">
            <p class="font-semibold">How COGS is calculated</p>
            <p class="mt-1">COGS per unit = purchase_cost + landed_cost (the actual cost of acquiring the unit, including allocated shipping/customs/import expenses). Sales revenue = orders.subtotal - orders.discount (what the customer owes), NOT payments.amount (what they paid). Payment collections are cash inflows, not revenue. Cancelled/refunded orders are excluded from both revenue and COGS. Operating expenses (rent, salaries, etc.) are separate from COGS.</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Expenses by category</h2>
            <?php if (($pnl['expenses_by_category'] ?? []) === []): ?>
                <p class="mt-2 text-xs text-slate-400">No expenses this month.</p>
            <?php else: ?>
            <ul class="mt-3 space-y-1 text-xs">
                <?php foreach (($pnl['expenses_by_category'] ?? []) as $cat): ?>
                <li class="flex justify-between"><span class="text-slate-600"><?= e($cat['name']) ?></span><span class="font-medium text-slate-900"><?= money((string) $cat['total']) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </aside>
</div>
