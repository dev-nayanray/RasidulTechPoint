<?php
/**
 * @var array $metrics
 * @var list<array> $salesTrend
 * @var list<array> $recentOrders
 * @var list<array> $recentMovements
 * @var list<array> $recentAudit
 * @var list<array> $lowStock
 * @var array<string,string> $limitations
 * @var array<string,string> $metricDefinitions
 * @var string $range
 * @var string $rangeLabel
 * @var string $from
 * @var string $to
 * @var ?int $locationId
 * @var list<array{id:int,name:string}> $locations
 */
?>
<?php
$rangeOptions = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'mtd' => 'Month to date', 'custom' => 'Custom'];
?>
<form method="get" action="<?= e(url('/admin/dashboard')) ?>" class="flex flex-wrap items-end gap-3" data-loading-form>
    <div class="flex flex-wrap gap-1 rounded-lg border border-slate-300 bg-white p-1">
        <?php foreach ($rangeOptions as $value => $label): ?>
            <button type="submit" name="range" value="<?= e($value) ?>"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition <?= $range === $value ? 'bg-navy-700 text-white' : 'text-slate-600 hover:bg-slate-100' ?>">
                <?= e($label) ?>
            </button>
        <?php endforeach; ?>
    </div>

    <?php if ($range === 'custom'): ?>
        <div class="flex items-center gap-2">
            <label class="text-xs text-slate-500" for="from">From</label>
            <input id="from" name="from" type="date" value="<?= e($from) ?>" class="input" max="<?= e(gmdate('Y-m-d')) ?>">
            <label class="text-xs text-slate-500" for="to">to</label>
            <input id="to" name="to" type="date" value="<?= e($to) ?>" class="input" max="<?= e(gmdate('Y-m-d')) ?>">
            <input type="hidden" name="range" value="custom">
            <button type="submit" class="btn-secondary">Apply</button>
        </div>
    <?php endif; ?>

    <?php if (!empty($locations)): ?>
        <div class="ml-auto flex items-center gap-2">
            <label class="text-xs text-slate-500" for="location_id">Branch</label>
            <select id="location_id" name="location_id" class="input" onchange="this.form.submit()">
                <option value="0" <?= $locationId === null ? 'selected' : '' ?>>All branches</option>
                <?php foreach ($locations as $loc): ?>
                    <option value="<?= e((string) $loc['id']) ?>" <?= $locationId === (int) $loc['id'] ? 'selected' : '' ?>>
                        <?= e($loc['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="range" value="<?= e($range) ?>">
        </div>
    <?php endif; ?>
</form>

<p class="mt-2 text-xs text-slate-500">
    Showing data for: <span class="font-medium text-slate-700"><?= e($rangeLabel) ?></span><?php
    if ($locationId !== null): ?> · scoped to branch <span class="font-medium text-slate-700">
        <?php foreach ($locations as $loc): if ((int) $loc['id'] === $locationId): echo e($loc['name']); break; endif; endforeach; ?>
    </span><?php endif; ?>.
    All times stored in UTC; presentation converts through <?= e(\App\Support\Config::get('app.timezone', 'UTC')) ?>.
</p>

<?php
if (!$metrics['has_sales_data'] && !$metrics['has_inventory_data']): ?>
    <div class="mt-6"><?= \App\Support\View::component('empty-state', [
        'title' => 'No business data recorded yet',
        'description' => 'Receive inventory and record your first order — every metric on this page is computed live from the database. No numbers are shown until they are real.',
        'icon' => 'chart',
        'actionUrl' => url('/admin/inventory/create'),
        'actionLabel' => 'Receive stock',
    ]) ?></div>
<?php return; endif; ?>

<div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?= \App\Support\View::component('stat-card', ['label' => "Sales (range)", 'value' => money($metrics['sales_total']), 'icon' => 'bar-chart', 'hint' => $metrics['sales_count'] . ' completed order(s) in the selected range']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => "Today's sales", 'value' => money($metrics['sales_today']), 'icon' => 'shopping-cart', 'hint' => 'Completed since UTC midnight (calendar today)']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Month-to-date sales', 'value' => money($metrics['sales_mtd']), 'icon' => 'bar-chart', 'hint' => 'Completed since 1st of month']) ?>

    <?= \App\Support\View::component('stat-card', ['label' => 'Payments collected (range)', 'value' => money($metrics['payments_total']), 'icon' => 'wallet', 'hint' => 'Completed payments in the selected range; today: ' . money($metrics['payments_today']) . ', MTD: ' . money($metrics['payments_mtd'])]) ?>

    <?= \App\Support\View::component('stat-card', ['label' => 'Refunded orders (range)', 'value' => money($metrics['refunded_orders_total']), 'icon' => 'alert', 'hint' => 'Gross order value returned to customers (status=refunded)']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Refunded payments (range)', 'value' => money($metrics['refunded_payments_total']), 'icon' => 'alert', 'hint' => 'Cash actually reversed (gateway refunds); separate from order refunds']) ?>

    <?= \App\Support\View::component('stat-card', ['label' => 'Outstanding balances', 'value' => money($metrics['outstanding']), 'icon' => 'alert', 'hint' => 'Order totals not yet covered by completed payments (snapshot)']) ?>

    <?= \App\Support\View::component('stat-card', ['label' => 'Available inventory', 'value' => number_format($metrics['available_units']), 'icon' => 'layers', 'hint' => $locationId !== null ? 'Units ready to sell at this branch' : 'Units ready to sell (all branches)']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Awaiting inspection', 'value' => number_format($metrics['awaiting_inspection']), 'icon' => 'check-circle', 'hint' => 'Units needing QA before sale']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Reserved units', 'value' => number_format($metrics['reserved_units']), 'icon' => 'shield', 'hint' => 'Units held for a pending order']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'In transit', 'value' => number_format($metrics['in_transit_units']), 'icon' => 'truck', 'hint' => 'Units on the way to the warehouse']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Pending orders', 'value' => number_format($metrics['pending_orders']), 'icon' => 'clipboard', 'hint' => 'Pending / confirmed / processing']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Low stock alerts', 'value' => count($lowStock), 'icon' => 'alert', 'hint' => 'Published models at/below threshold']) ?>
</div>

<?php if ($salesTrend !== []): ?>
    <div class="mt-6">
        <?= \App\Support\View::component('sparkline-chart', [
            'data' => $salesTrend,
            'format' => 'money',
            'title' => 'Sales trend (last 7 UTC days)',
            'subtitle' => 'Completed order revenue per day. Bars are zero-filled so the chart never has gaps. Hover for the exact value.',
            'chartId' => 'dashboard-sales-trend',
            'height' => 180,
        ]) ?>
    </div>
<?php endif; ?>

<div class="mt-6 grid gap-6 xl:grid-cols-2">
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Recent orders</h2>
            <a href="<?= e(url('/admin/orders')) ?>" class="text-xs font-medium text-blue-700 hover:text-blue-800">View all →</a>
        </header>
        <?php if ($recentOrders === []): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-400">No orders yet. Orders will appear here as soon as the first one is recorded.</p>
        <?php else: ?>
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <tbody>
                    <?php foreach ($recentOrders as $order): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5"><a class="font-medium text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/orders/' . $order['id'])) ?>"><?= e($order['reference']) ?></a></td>
                            <td class="px-4 py-2.5 text-slate-500"><?= e($order['customer_name']) ?></td>
                            <td class="px-4 py-2.5"><?= \App\Support\View::component('badge', ['status' => $order['status']]) ?></td>
                            <td class="px-4 py-2.5 text-right font-semibold text-slate-900"><?= money($order['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Recent inventory movements</h2>
            <a href="<?= e(url('/admin/inventory')) ?>" class="text-xs font-medium text-blue-700 hover:text-blue-800">Inventory →</a>
        </header>
        <?php if ($recentMovements === []): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-400">No movements yet. Stock receipts and transitions will appear here.</p>
        <?php else: ?>
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <tbody>
                    <?php foreach ($recentMovements as $movement): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-medium text-slate-700"><?= e($movement['sku']) ?></td>
                            <td class="px-4 py-2.5 text-slate-500"><?= e(str_replace('_', ' ', $movement['movement_type'])) ?></td>
                            <td class="px-4 py-2.5 text-slate-500"><?= e($movement['from_status'] ?? '∅') ?> → <?= e($movement['to_status'] ?? '∅') ?></td>
                            <td class="px-4 py-2.5 text-right text-xs text-slate-400"><?= e(local_datetime($movement['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Recent audit events</h2>
            <?php if (\App\Services\AuthService::can('audit_logs.view')): ?>
                <a href="<?= e(url('/admin/audit-logs')) ?>" class="text-xs font-medium text-blue-700 hover:text-blue-800">Audit log →</a>
            <?php endif; ?>
        </header>
        <?php if ($recentAudit === []): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-400">No audit events yet. Privileged actions (login, role changes, inventory transitions) will be recorded here.</p>
        <?php else: ?>
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <tbody>
                    <?php foreach ($recentAudit as $event): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-medium text-slate-700"><?= e($event['action']) ?></td>
                            <td class="px-4 py-2.5 text-slate-500"><?= e($event['user_name'] ?? 'system') ?></td>
                            <td class="px-4 py-2.5 text-right text-xs text-slate-400"><?= e(local_datetime($event['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <header class="border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Low stock alerts</h2>
        </header>
        <?php if ($lowStock === []): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-400">
                <?php if ($metrics['has_inventory_data']): ?>
                    No published models below the alert threshold.
                <?php else: ?>
                    No inventory received yet. Low-stock alerts will appear once units are in stock.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <tbody>
                    <?php foreach ($lowStock as $row): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-medium text-slate-700"><?= e($row['name']) ?></td>
                            <td class="px-4 py-2.5 text-right">
                                <?= \App\Support\View::component('badge', ['status' => (int) $row['available_count'] === 0 ? 'damaged' : 'awaiting_inspection', 'label' => (string) $row['available_count'] . ' left']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

<details class="mt-6 rounded-xl border border-slate-200 bg-white">
    <summary class="cursor-pointer select-none px-4 py-3 text-sm font-semibold text-slate-900 hover:bg-slate-50">
        Metric definitions &amp; calculation notes
    </summary>
    <div class="border-t border-slate-200 px-4 py-3">
        <p class="mb-3 text-xs text-slate-500">Every number on this page is a real MySQL aggregate. Here is exactly what each one means (and what it deliberately does NOT include):</p>
        <dl class="space-y-2 text-sm">
            <?php foreach ($metricDefinitions as $key => $def): ?>
                <div class="grid gap-1 sm:grid-cols-[12rem_1fr]">
                    <dt><code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-700"><?= e($key) ?></code></dt>
                    <dd class="text-slate-600"><?= e($def) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>
</details>

<?php if (!empty($limitations)): ?>
<details class="mt-3 rounded-xl border border-amber-200 bg-amber-50/40">
    <summary class="cursor-pointer select-none px-4 py-3 text-sm font-semibold text-amber-800 hover:bg-amber-50">
        Metrics not yet available (<?= count($limitations) ?>)
    </summary>
    <div class="border-t border-amber-200 px-4 py-3">
        <p class="mb-3 text-xs text-amber-700">These dashboard tiles are intentionally hidden because their backing business module has not been built yet. No fake or placeholder numbers are ever shown.</p>
        <dl class="space-y-2 text-sm">
            <?php foreach ($limitations as $key => $reason): ?>
                <div class="grid gap-1 sm:grid-cols-[12rem_1fr]">
                    <dt><code class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800"><?= e($key) ?></code></dt>
                    <dd class="text-amber-700/80"><?= e($reason) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>
</details>
<?php endif; ?>
