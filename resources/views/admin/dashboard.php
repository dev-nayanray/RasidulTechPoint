<?php
/**
 * @var array $metrics
 * @var list<array> $salesTrend
 * @var list<array> $recentOrders
 * @var list<array> $recentMovements
 * @var list<array> $recentAudit
 * @var list<array> $lowStock
 * @var ?array{total:int,new:int} $inquiryStats
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
<div class="admin-page-header">
    <div class="admin-page-header-title">
        <h2>Dashboard overview</h2>
        <p class="admin-page-header-sub">Every metric below is computed live from the database. No numbers are shown until they are real — no fake growth, no demo stats.</p>
    </div>
    <div class="admin-page-header-actions">
        <form method="get" action="<?= e(url('/admin/dashboard')) ?>" class="flex flex-wrap items-center gap-2" data-loading-form>
            <div class="admin-range-toggle">
                <?php foreach ($rangeOptions as $value => $label): ?>
                    <button type="submit" name="range" value="<?= e($value) ?>" class="<?= $range === $value ? 'active' : '' ?>">
                        <?= e($label) ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php if ($range === 'custom'): ?>
                <label class="text-xs text-slate-500" for="from">From</label>
                <input id="from" name="from" type="date" value="<?= e($from) ?>" class="input" style="min-height:38px" max="<?= e(gmdate('Y-m-d')) ?>">
                <label class="text-xs text-slate-500" for="to">to</label>
                <input id="to" name="to" type="date" value="<?= e($to) ?>" class="input" style="min-height:38px" max="<?= e(gmdate('Y-m-d')) ?>">
                <input type="hidden" name="range" value="custom">
                <button type="submit" class="btn-secondary">Apply</button>
            <?php endif; ?>
            <?php if (!empty($locations)): ?>
                <select id="location_id" name="location_id" class="input" style="min-height:38px" onchange="this.form.submit()">
                    <option value="0" <?= $locationId === null ? 'selected' : '' ?>>All branches</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?= e((string) $loc['id']) ?>" <?= $locationId === (int) $loc['id'] ? 'selected' : '' ?>>
                            <?= e($loc['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="range" value="<?= e($range) ?>">
            <?php endif; ?>
        </form>
    </div>
</div>

<p class="text-xs text-slate-500" style="margin:-8px 0 16px">
    Showing data for: <span class="font-medium text-slate-700"><?= e($rangeLabel) ?></span><?php
    if ($locationId !== null): ?> · scoped to branch <span class="font-medium text-slate-700">
        <?php foreach ($locations as $loc): if ((int) $loc['id'] === $locationId): echo e($loc['name']); break; endif; endforeach; ?>
    </span><?php endif; ?>.
    All times stored in UTC; presentation converts through <?= e(\App\Support\Config::get('app.timezone', 'UTC')) ?>.
</p>

<?php if ($inquiryStats !== null): ?>
    <a href="<?= e(url('/admin/contact-inquiries')) ?>" class="block no-underline admin-card" style="margin-bottom:16px;padding:18px 20px;border-color:rgba(37,99,235,.25);background:linear-gradient(135deg,#fff 0%,#F8FAFF 100%)">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="admin-stat-card-label">Contact inquiries</p>
                <p class="admin-stat-card-value" style="font-size:24px">
                    <?= number_format($inquiryStats['new']) ?><span style="font-size:14px;font-weight:600;color:var(--muted)"> new</span>
                    <span class="ml-2 text-sm font-medium text-slate-500">/ <?= number_format($inquiryStats['total']) ?> total</span>
                </p>
            </div>
            <span class="text-sm font-medium text-blue-700">Review submissions →</span>
        </div>
    </a>
<?php endif; ?>

<?php
if (!$metrics['has_sales_data'] && !$metrics['has_inventory_data']): ?>
    <div class="admin-card" style="margin-top:8px">
        <div class="admin-empty">
            <span class="admin-empty-ic"><?= \App\Support\View::component('icon', ['name' => 'chart', 'class' => 'h-7 w-7']) ?></span>
            <h3>No business data recorded yet</h3>
            <p>Receive inventory and record your first order — every metric on this page is computed live from the database. No numbers are shown until they are real.</p>
            <a class="btn btn-primary" href="<?= e(url('/admin/inventory/create')) ?>">Receive stock</a>
        </div>
    </div>
<?php return; endif; ?>

<?php
// Quick action shortcuts — permission-gated, no fake entries.
$quickActions = [];
if (\App\Services\AuthService::can('orders.manage')) {
    $quickActions[] = ['New order', 'orders/index.php → New order', 'shopping-cart', '/admin/orders/create'];
}
if (\App\Services\AuthService::can('inventory.receive')) {
    $quickActions[] = ['Receive stock', 'Add a unit to inventory', 'package-check', '/admin/inventory/create'];
}
if (\App\Services\AuthService::can('products.create')) {
    $quickActions[] = ['Add model', 'Create a product model', 'box', '/admin/products/create'];
}
if (\App\Services\AuthService::can('inspections.manage')) {
    $quickActions[] = ['QA inspection', 'Inspect a unit', 'check-circle', '/admin/inspections'];
}
if (\App\Services\AuthService::can('contact_inquiries.view') && !empty($inquiryStats['new'])) {
    $quickActions[] = ['Reply to inquiries', $inquiryStats['new'] . ' new awaiting reply', 'mail', '/admin/contact-inquiries?status=new'];
}
if (\App\Services\AuthService::can('marketing.manage')) {
    $quickActions[] = ['Edit content', 'Homepage CMS blocks', 'megaphone', '/admin/content'];
}
if (\App\Services\AuthService::can('reports.view')) {
    $quickActions[] = ['Reports', 'Sales / inventory / profit', 'bar-chart', '/admin/reports'];
}
if (\App\Services\AuthService::can('settings.manage')) {
    $quickActions[] = ['Settings', 'Business + system', 'settings', '/admin/settings'];
}
?>
<?php if ($quickActions !== []): ?>
<div class="admin-section" style="margin-top:0">
    <p class="admin-section-title">Quick actions</p>
    <div class="admin-quick-grid">
        <?php foreach ($quickActions as [$title, $sub, $icon, $href]): ?>
            <a href="<?= e(url($href)) ?>" class="admin-quick-card">
                <span class="admin-quick-card-ic"><?= \App\Support\View::component('icon', ['name' => $icon, 'class' => 'h-5 w-5']) ?></span>
                <p class="admin-quick-card-title"><?= e($title) ?></p>
                <p class="admin-quick-card-sub"><?= e($sub) ?></p>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="admin-section" style="margin-top:24px">
    <p class="admin-section-title">Snapshot</p>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?= \App\Support\View::component('stat-card', ['label' => 'Sales (range)', 'value' => money($metrics['sales_total']), 'icon' => 'bar-chart', 'tone' => 'blue', 'hint' => $metrics['sales_count'] . ' completed order(s) in the selected range']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => "Today's sales", 'value' => money($metrics['sales_today']), 'icon' => 'shopping-cart', 'tone' => 'green', 'hint' => 'Completed since UTC midnight (calendar today)']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Month-to-date sales', 'value' => money($metrics['sales_mtd']), 'icon' => 'trending-up', 'tone' => 'green', 'hint' => 'Completed since 1st of month']) ?>

    <?= \App\Support\View::component('stat-card', ['label' => 'Payments collected (range)', 'value' => money($metrics['payments_total']), 'icon' => 'wallet', 'tone' => 'blue', 'hint' => 'Completed payments in the selected range; today: ' . money($metrics['payments_today']) . ', MTD: ' . money($metrics['payments_mtd'])]) ?>

    <?= \App\Support\View::component('stat-card', ['label' => 'Refunded orders (range)', 'value' => money($metrics['refunded_orders_total']), 'icon' => 'trending-down', 'tone' => 'amber', 'hint' => 'Gross order value returned to customers (status=refunded)']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Refunded payments (range)', 'value' => money($metrics['refunded_payments_total']), 'icon' => 'trending-down', 'tone' => 'amber', 'hint' => 'Cash actually reversed (gateway refunds); separate from order refunds']) ?>

    <?= \App\Support\View::component('stat-card', ['label' => 'Outstanding balances', 'value' => money($metrics['outstanding']), 'icon' => 'alert', 'tone' => 'red', 'hint' => 'Order totals not yet covered by completed payments (snapshot)']) ?>

    <?= \App\Support\View::component('stat-card', ['label' => 'Available inventory', 'value' => number_format($metrics['available_units']), 'icon' => 'layers', 'tone' => 'green', 'hint' => $locationId !== null ? 'Units ready to sell at this branch' : 'Units ready to sell (all branches)']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Awaiting inspection', 'value' => number_format($metrics['awaiting_inspection']), 'icon' => 'check-circle', 'tone' => 'amber', 'hint' => 'Units needing QA before sale']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Reserved units', 'value' => number_format($metrics['reserved_units']), 'icon' => 'shield', 'tone' => 'navy', 'hint' => 'Units held for a pending order']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'In transit', 'value' => number_format($metrics['in_transit_units']), 'icon' => 'truck', 'tone' => 'slate', 'hint' => 'Units on the way to the warehouse']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Pending orders', 'value' => number_format($metrics['pending_orders']), 'icon' => 'clipboard', 'tone' => 'blue', 'hint' => 'Pending / confirmed / processing']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Low stock alerts', 'value' => count($lowStock), 'icon' => 'alert', 'tone' => 'red', 'hint' => 'Published models at/below threshold']) ?>
    </div>
</div>

<?php if ($salesTrend !== []): ?>
    <div class="admin-section">
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

<div class="admin-section grid gap-6 xl:grid-cols-2">
    <section class="admin-card">
        <header class="admin-card-header">
            <h3>Recent orders</h3>
            <a href="<?= e(url('/admin/orders')) ?>">View all →</a>
        </header>
        <?php if ($recentOrders === []): ?>
            <p class="admin-empty-text">No orders yet. Orders will appear here as soon as the first one is recorded.</p>
        <?php else: ?>
            <div class="admin-table-wrap">
            <table class="admin-table">
                <tbody>
                    <?php foreach ($recentOrders as $order): ?>
                        <tr>
                            <td><a href="<?= e(url('/admin/orders/' . $order['id'])) ?>"><?= e($order['reference']) ?></a></td>
                            <td class="text-slate-500"><?= e($order['customer_name']) ?></td>
                            <td><?= \App\Support\View::component('badge', ['status' => $order['status']]) ?></td>
                            <td class="num font-semibold"><?= money($order['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="admin-card">
        <header class="admin-card-header">
            <h3>Recent inventory movements</h3>
            <a href="<?= e(url('/admin/inventory')) ?>">Inventory →</a>
        </header>
        <?php if ($recentMovements === []): ?>
            <p class="admin-empty-text">No movements yet. Stock receipts and transitions will appear here.</p>
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

    <section class="admin-card">
        <header class="admin-card-header">
            <h3>Activity feed</h3>
            <?php if (\App\Services\AuthService::can('audit_logs.view')): ?>
                <a href="<?= e(url('/admin/audit-logs')) ?>">Audit log →</a>
            <?php endif; ?>
        </header>
        <?php if ($recentAudit === []): ?>
            <p class="admin-empty-text">No audit events yet. Privileged actions (login, role changes, inventory transitions) will be recorded here.</p>
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

    <section class="admin-card">
        <header class="admin-card-header">
            <h3>Low stock alerts</h3>
        </header>
        <?php if ($lowStock === []): ?>
            <p class="admin-empty-text">
                <?php if ($metrics['has_inventory_data']): ?>
                    No published models below the alert threshold.
                <?php else: ?>
                    No inventory received yet. Low-stock alerts will appear once units are in stock.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <div class="admin-card-body" style="padding:0">
            <?php foreach ($lowStock as $row):
                $count = (int) $row['available_count'];
                $max = 5; // visual max for the progress bar
                $pct = min(100, ($count / $max) * 100);
                $barClass = $count === 0 ? 'zero' : ($count <= 1 ? 'crit' : ($count <= 3 ? 'low' : 'ok'));
            ?>
                <div style="display:flex;align-items:center;gap:12px;padding:10px 18px;border-bottom:1px solid var(--border)">
                    <span style="font-size:14px;font-weight:600;color:var(--ink);min-width:0;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($row['name']) ?></span>
                    <div class="admin-stock-bar">
                        <div class="admin-stock-bar-fill <?= $barClass ?>" style="width:<?= $pct ?>%"></div>
                    </div>
                    <span style="font-size:13px;font-weight:700;color:var(--muted);min-width:40px;text-align:right"><?= $count ?> left</span>
                </div>
            <?php endforeach; ?>
            </div>
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
