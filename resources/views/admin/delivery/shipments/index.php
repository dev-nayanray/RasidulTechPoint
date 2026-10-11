<?php /** @var list<array> $shipments @var list<string> $statuses @var list<array> $couriers */ ?>
<form method="get" action="<?= e(url('/admin/delivery/shipments')) ?>" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4" role="search">
    <div class="sm:col-span-2 space-y-1.5">
        <label for="sh-q" class="block text-sm font-medium text-slate-700">Search</label>
        <input id="sh-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Shipment #, tracking ref, order, recipient…">
    </div>
    <?= \App\Support\View::component('select', [
        'name' => 'status', 'label' => 'Status', 'value' => $status,
        'options' => array_merge(['' => 'All statuses'], array_combine($statuses, array_map(fn ($s) => ucfirst(str_replace('_', ' ', $s)), $statuses))),
    ]) ?>
    <?= \App\Support\View::component('select', [
        'name' => 'courier', 'label' => 'Courier', 'value' => (string) $courierId,
        'options' => array_merge(['' => 'All couriers'], array_column($couriers, 'name', 'id')),
    ]) ?>
    <div class="flex items-end"><button type="submit" class="btn-primary">Apply</button></div>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Shipment #</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Order</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Courier</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Recipient</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Tracking</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Fee</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($shipments as $s): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-mono text-blue-700"><a href="<?= e(url('/admin/delivery/shipments/' . $s['id'])) ?>"><?= e($s['shipment_number']) ?></a></td>
                <td class="px-4 py-3 text-slate-500"><a class="hover:underline" href="<?= e(url('/admin/orders/' . $s['order_id'])) ?>"><?= e($s['order_reference']) ?></a></td>
                <td class="px-4 py-3 text-slate-700"><?= e($s['courier_name'] ?? '—') ?></td>
                <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $s['status']]) ?></td>
                <td class="px-4 py-3 text-slate-700"><?= e($s['recipient_name']) ?><span class="block text-xs text-slate-400"><?= e($s['recipient_phone']) ?></span></td>
                <td class="px-4 py-3 text-xs font-mono text-slate-500"><?= e($s['tracking_reference'] ?? '—') ?></td>
                <td class="px-4 py-3 text-right text-slate-700"><?= money($s['fee']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($shipments === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No shipments match your filters.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/delivery/shipments', 'queryParams' => array_filter(['q' => $q, 'status' => $status, 'courier' => $courierId ? (string) $courierId : ''])]) ?>
</div>
