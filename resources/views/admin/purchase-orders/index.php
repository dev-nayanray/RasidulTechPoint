<?php
/** @var list<array> $orders
 *  @var int $total
 *  @var int $page
 *  @var int $pages
 *  @var string $q
 *  @var string $statusFilter
 */
$statuses = ['draft', 'submitted', 'approved', 'received', 'cancelled'];
$statusColors = [
    'draft' => 'border-slate-200 bg-slate-50 text-slate-600',
    'submitted' => 'border-blue-200 bg-blue-50 text-blue-700',
    'approved' => 'border-green-200 bg-green-50 text-green-700',
    'received' => 'border-green-200 bg-green-100 text-green-800',
    'cancelled' => 'border-red-200 bg-red-50 text-red-700',
];
?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-slate-500"><?= (int) $total ?> purchase order(s)</p>
    <?php if (\App\Services\AuthService::can('purchases.manage')): ?>
        <a href="<?= e(url('/admin/purchase-orders/create')) ?>" class="btn-primary">New purchase order</a>
    <?php endif; ?>
</div>

<div class="mt-4 flex flex-wrap gap-2">
    <a href="<?= e(url('/admin/purchase-orders')) ?>" class="rounded-full border px-3 py-1 text-xs font-medium <?= $statusFilter === '' ? 'border-navy-700 bg-navy-700 text-white' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50' ?>">All</a>
    <?php foreach ($statuses as $s): ?>
        <a href="<?= e(url('/admin/purchase-orders?status=' . $s)) ?>" class="rounded-full border px-3 py-1 text-xs font-medium capitalize <?= $statusFilter === $s ? 'border-navy-700 bg-navy-700 text-white' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50' ?>"><?= e(str_replace('_', ' ', $s)) ?></a>
    <?php endforeach; ?>
</div>

<form method="get" action="<?= e(url('/admin/purchase-orders')) ?>" class="mt-4 flex max-w-md gap-2" role="search">
    <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
    <input name="q" value="<?= e($q) ?>" class="input" placeholder="Reference, batch or supplier…">
    <button type="submit" class="btn-secondary shrink-0">Search</button>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Reference</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Supplier</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Batch</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Items</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Total</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($orders as $order): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3"><a class="font-medium text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/purchase-orders/' . $order['id'])) ?>"><?= e($order['reference']) ?></a></td>
                    <td class="px-4 py-3 text-slate-700"><?= e($order['supplier_name']) ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($order['import_batch_ref'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-right text-slate-500"><?= (int) $order['item_count'] ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= e(money($order['total'])) ?></td>
                    <td class="px-4 py-3"><span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize <?= $statusColors[$order['status']] ?? $statusColors['draft'] ?>"><?= e(str_replace('_', ' ', $order['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($orders === []): ?>
        <p class="px-4 py-10 text-center text-sm text-slate-400">No purchase orders match.</p>
    <?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/purchase-orders', 'queryParams' => array_filter(['q' => $q, 'status' => $statusFilter])]) ?>
</div>
