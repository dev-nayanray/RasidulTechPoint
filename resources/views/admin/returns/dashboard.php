<?php /** @var int $requested $inProgress $completed @var list<array> $recent */ ?>
<div class="grid gap-4 sm:grid-cols-3">
    <?= \App\Support\View::component('stat-card', ['label' => 'Requested', 'value' => $requested, 'icon' => 'inbox']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'In progress', 'value' => $inProgress, 'icon' => 'refresh']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Completed', 'value' => $completed, 'icon' => 'check-circle']) ?>
</div>
<section class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
        <h2 class="text-sm font-semibold text-slate-900">Recent returns</h2>
        <a href="<?= e(url('/admin/returns/list')) ?>" class="text-xs font-medium text-blue-700 hover:underline">View all →</a>
    </header>
    <?php if ($recent === []): ?>
        <p class="px-4 py-8 text-center text-sm text-slate-400">No return requests yet.</p>
    <?php else: ?>
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-2 text-left font-medium text-slate-500">Return #</th>
            <th class="px-4 py-2 text-left font-medium text-slate-500">Order</th>
            <th class="px-4 py-2 text-left font-medium text-slate-500">Customer</th>
            <th class="px-4 py-2 text-left font-medium text-slate-500">Type</th>
            <th class="px-4 py-2 text-left font-medium text-slate-500">Status</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($recent as $r): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-2 font-mono text-blue-700"><a href="<?= e(url('/admin/returns/' . $r['id'])) ?>"><?= e($r['return_number']) ?></a></td>
                <td class="px-4 py-2 text-slate-500"><a class="hover:underline" href="<?= e(url('/admin/orders/' . $r['order_id'] ?? '')) ?>"><?= e($r['order_reference']) ?></a></td>
                <td class="px-4 py-2 text-slate-700"><?= e($r['customer_name']) ?></td>
                <td class="px-4 py-2 capitalize"><?= e(str_replace('_', ' ', $r['request_type'])) ?></td>
                <td class="px-4 py-2"><?= \App\Support\View::component('badge', ['status' => $r['status']]) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
