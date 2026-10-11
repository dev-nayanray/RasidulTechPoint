<?php /** @var int $pending $delivered $failed $returned
 *  @var list<array> $recent
 *  @var list<array> $couriers
 */
?>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <?= \App\Support\View::component('stat-card', ['label' => 'In transit', 'value' => $pending, 'icon' => 'truck']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Delivered', 'value' => $delivered, 'icon' => 'check-circle']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Failed', 'value' => $failed, 'icon' => 'x-circle']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Returned to origin', 'value' => $returned, 'icon' => 'rotate-ccw']) ?>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-[2fr_1fr]">
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Recent shipments</h2>
            <a href="<?= e(url('/admin/delivery/shipments')) ?>" class="text-xs font-medium text-blue-700 hover:underline">View all →</a>
        </header>
        <?php if ($recent === []): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-400">No shipments yet. Create one from an order's page.</p>
        <?php else: ?>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">Shipment #</th>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">Order</th>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">Courier</th>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">Status</th>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">Recipient</th>
                    <th class="px-4 py-2 text-right font-medium text-slate-500">Fee</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($recent as $s): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2 font-mono text-blue-700"><a href="<?= e(url('/admin/delivery/shipments/' . $s['id'])) ?>"><?= e($s['shipment_number']) ?></a></td>
                    <td class="px-4 py-2 text-slate-500"><a class="hover:underline" href="<?= e(url('/admin/orders/' . $s['order_id'])) ?>"><?= e($s['order_reference']) ?></a></td>
                    <td class="px-4 py-2 text-slate-700"><?= e($s['courier_name'] ?? '—') ?></td>
                    <td class="px-4 py-2"><?= \App\Support\View::component('badge', ['status' => $s['status']]) ?></td>
                    <td class="px-4 py-2 text-slate-700"><?= e($s['recipient_name']) ?></td>
                    <td class="px-4 py-2 text-right text-slate-700"><?= money($s['fee']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <aside class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <h2 class="text-sm font-semibold text-slate-900">Couriers</h2>
            <?php if ($couriers === []): ?>
                <p class="mt-2 text-xs text-slate-400">No couriers yet.</p>
            <?php else: ?>
            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                <?php foreach ($couriers as $c): ?>
                <li class="py-2 flex items-center justify-between">
                    <a class="font-medium text-blue-700 hover:underline" href="<?= e(url('/admin/delivery/couriers/' . $c['id'])) ?>"><?= e($c['name']) ?></a>
                    <span class="text-xs text-slate-500"><?= (int) ($c['shipment_count'] ?? 0) ?> shipments</span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <div class="mt-3 flex gap-2">
                <a href="<?= e(url('/admin/delivery/couriers')) ?>" class="btn-secondary text-xs">All couriers</a>
                <a href="<?= e(url('/admin/delivery/zones')) ?>" class="btn-secondary text-xs">Zones</a>
            </div>
        </div>
    </aside>
</div>
