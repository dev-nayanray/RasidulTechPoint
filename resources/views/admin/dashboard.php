<?php /** @var array $metrics @var list<array> $recentOrders @var list<array> $recentMovements @var list<array> $recentAudit @var list<array> $lowStock */ ?>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?= \App\Support\View::component('stat-card', ['label' => "Today's completed sales", 'value' => money($metrics['today_sales']), 'icon' => 'shopping-cart', 'hint' => 'Orders completed in the last 24h (UTC)']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Month-to-date sales', 'value' => money($metrics['mtd_sales']), 'icon' => 'bar-chart', 'hint' => 'Completed since 1st of month']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Payments collected (MTD)', 'value' => money($metrics['payments_mtd']), 'icon' => 'wallet', 'hint' => 'Completed payments; today: ' . money($metrics['payments_today'])]) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Outstanding balances', 'value' => money($metrics['outstanding']), 'icon' => 'alert', 'hint' => 'Order totals not yet covered by payments']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Available inventory', 'value' => number_format($metrics['available_units']), 'icon' => 'layers', 'hint' => 'Units ready to sell']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Awaiting inspection', 'value' => number_format($metrics['awaiting_inspection']), 'icon' => 'check-circle', 'hint' => 'Units needing QA']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Pending orders', 'value' => number_format($metrics['pending_orders']), 'icon' => 'clipboard', 'hint' => 'Pending / confirmed / processing']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Low stock alerts', 'value' => count($lowStock), 'icon' => 'alert', 'hint' => 'Published models at/below threshold']) ?>
</div>

<?php if (!$metrics['has_sales_data'] && $metrics['available_units'] === 0 && $metrics['awaiting_inspection'] === 0): ?>
    <div class="mt-6"><?= \App\Support\View::component('empty-state', [
        'title' => 'No business data recorded yet',
        'description' => 'Receive inventory and record your first order — every metric on this page is computed live from the database.',
        'icon' => 'chart',
        'actionUrl' => url('/admin/inventory/create'),
        'actionLabel' => 'Receive stock',
    ]) ?></div>
<?php endif; ?>

<div class="mt-6 grid gap-6 xl:grid-cols-2">
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Recent orders</h2>
            <a href="<?= e(url('/admin/orders')) ?>" class="text-xs font-medium text-blue-700 hover:text-blue-800">View all →</a>
        </header>
        <?php if ($recentOrders === []): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-400">No orders yet.</p>
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
            <p class="px-4 py-8 text-center text-sm text-slate-400">No movements yet.</p>
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
            <p class="px-4 py-8 text-center text-sm text-slate-400">No audit events yet.</p>
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
            <p class="px-4 py-8 text-center text-sm text-slate-400">No published models below the alert threshold.</p>
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
