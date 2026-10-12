<?php
/** @var array $dashboard */
$d = $dashboard;
?>
<h1 class="text-2xl font-bold tracking-tight text-slate-900">Financial Reports</h1>
<p class="mt-1 text-sm text-slate-500">Period: <?= e($d['month_start'] ?? '') ?> to <?= e($d['today'] ?? '') ?> (current month)</p>

<div class="mt-6 grid gap-4 sm:grid-cols-4">
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <p class="text-slate-500 text-xs">Net sales revenue</p>
        <p class="mt-1 text-xl font-bold text-slate-900"><?= money((string) ($d['sales']['total_net'] ?? 0)) ?></p>
        <p class="mt-1 text-xs text-slate-400"><?= (int) ($d['sales']['order_count'] ?? 0) ?> orders</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <p class="text-slate-500 text-xs">Payments collected</p>
        <p class="mt-1 text-xl font-bold text-green-700"><?= money((string) ($d['payments']['total_collected'] ?? 0)) ?></p>
        <p class="mt-1 text-xs text-slate-400"><?= (int) ($d['payments']['count'] ?? 0) ?> payments</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <p class="text-slate-500 text-xs">Refunds</p>
        <p class="mt-1 text-xl font-bold text-red-700"><?= money((string) ($d['refunds']['total_refunded'] ?? 0)) ?></p>
        <p class="mt-1 text-xs text-slate-400"><?= (int) ($d['refunds']['count'] ?? 0) ?> refunds</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <p class="text-slate-500 text-xs">Operating expenses</p>
        <p class="mt-1 text-xl font-bold text-amber-700"><?= money((string) ($d['expenses']['total'] ?? 0)) ?></p>
        <p class="mt-1 text-xs text-slate-400"><?= (int) ($d['expenses']['count'] ?? 0) ?> entries</p>
    </div>
</div>

<div class="mt-4 grid gap-4 sm:grid-cols-2">
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <p class="text-slate-500 text-xs">Receivables (outstanding)</p>
        <p class="mt-1 text-xl font-bold text-red-700"><?= money((string) ($d['receivables']['total_outstanding'] ?? 0)) ?></p>
        <p class="mt-1 text-xs text-slate-400"><?= (int) ($d['receivables']['count'] ?? 0) ?> orders unpaid/partial</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <p class="text-slate-500 text-xs">Payables (supplier outstanding)</p>
        <p class="mt-1 text-xl font-bold text-amber-700"><?= money((string) ($d['payables']['total_outstanding'] ?? 0)) ?></p>
        <p class="mt-1 text-xs text-slate-400"><?= (int) ($d['payables']['count'] ?? 0) ?> suppliers</p>
    </div>
</div>

<section class="mt-6 rounded-xl border border-slate-200 bg-white">
    <header class="border-b border-slate-200 px-4 py-3">
        <h2 class="text-sm font-semibold text-slate-900">Available reports</h2>
    </header>
    <div class="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <a href="<?= e(url('/admin/reports/sales')) ?>" class="rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
            <p class="font-medium text-slate-900 text-sm">Sales</p>
            <p class="text-xs text-slate-500">Revenue by day, discounts, net</p>
        </a>
        <a href="<?= e(url('/admin/reports/payments')) ?>" class="rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
            <p class="font-medium text-slate-900 text-sm">Payments</p>
            <p class="text-xs text-slate-500">Collected by method</p>
        </a>
        <a href="<?= e(url('/admin/reports/refunds')) ?>" class="rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
            <p class="font-medium text-slate-900 text-sm">Refunds</p>
            <p class="text-xs text-slate-500">Processed refunds</p>
        </a>
        <a href="<?= e(url('/admin/reports/expenses')) ?>" class="rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
            <p class="font-medium text-slate-900 text-sm">Expenses</p>
            <p class="text-xs text-slate-500">By category</p>
        </a>
        <a href="<?= e(url('/admin/reports/gross-profit')) ?>" class="rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
            <p class="font-medium text-slate-900 text-sm">Gross Profit</p>
            <p class="text-xs text-slate-500">Revenue - COGS</p>
        </a>
        <a href="<?= e(url('/admin/reports/receivables')) ?>" class="rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
            <p class="font-medium text-slate-900 text-sm">Receivables</p>
            <p class="text-xs text-slate-500">Unpaid orders</p>
        </a>
        <a href="<?= e(url('/admin/reports/payables')) ?>" class="rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
            <p class="font-medium text-slate-900 text-sm">Payables</p>
            <p class="text-xs text-slate-500">Supplier balances</p>
        </a>
        <a href="<?= e(url('/admin/reports/reconciliation')) ?>" class="rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
            <p class="font-medium text-slate-900 text-sm">Reconciliation</p>
            <p class="text-xs text-slate-500">Cash vs expected</p>
        </a>
    </div>
</section>

<div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
    <p class="font-semibold">Notes &amp; estimates</p>
    <ul class="mt-1 list-disc list-inside space-y-0.5">
        <li><?= e($d['notes']['sales_note'] ?? '') ?></li>
        <li><?= e($d['notes']['receivables_note'] ?? '') ?></li>
        <li><?= e($d['notes']['payables_note'] ?? '') ?></li>
        <li><?= e($d['notes']['reconciliation_note'] ?? '') ?></li>
    </ul>
</div>
