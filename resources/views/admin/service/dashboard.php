<?php /** @var int $open $completed $cancelled @var list<array> $recent */ ?>
<div class="grid gap-4 sm:grid-cols-3">
    <?= \App\Support\View::component('stat-card', ['label' => 'Open jobs', 'value' => $open, 'icon' => 'wrench']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Completed', 'value' => $completed, 'icon' => 'check-circle']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Cancelled', 'value' => $cancelled, 'icon' => 'x-circle']) ?>
</div>

<section class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
        <h2 class="text-sm font-semibold text-slate-900">Recent jobs</h2>
        <div class="flex gap-2">
            <a href="<?= e(url('/admin/service/jobs/create')) ?>" class="btn-primary text-xs">New job</a>
            <a href="<?= e(url('/admin/service/jobs')) ?>" class="btn-secondary text-xs">View all</a>
        </div>
    </header>
    <?php if ($recent === []): ?>
        <p class="px-4 py-8 text-center text-sm text-slate-400">No service jobs yet.</p>
    <?php else: ?>
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-2 text-left font-medium text-slate-500">Job #</th>
            <th class="px-4 py-2 text-left font-medium text-slate-500">Customer</th>
            <th class="px-4 py-2 text-left font-medium text-slate-500">Type</th>
            <th class="px-4 py-2 text-left font-medium text-slate-500">Status</th>
            <th class="px-4 py-2 text-left font-medium text-slate-500">Priority</th>
            <th class="px-4 py-2 text-left font-medium text-slate-500">Technician</th>
            <th class="px-4 py-2 text-right font-medium text-slate-500">Charge</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($recent as $j): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-2 font-mono text-blue-700"><a href="<?= e(url('/admin/service/jobs/' . $j['id'])) ?>"><?= e($j['job_number']) ?></a></td>
                <td class="px-4 py-2 text-slate-700"><?= e($j['customer_name'] ?? 'Walk-in') ?></td>
                <td class="px-4 py-2 capitalize"><?= e($j['service_type']) ?></td>
                <td class="px-4 py-2"><?= \App\Support\View::component('badge', ['status' => $j['status']]) ?></td>
                <td class="px-4 py-2 capitalize"><?= e($j['priority']) ?></td>
                <td class="px-4 py-2 text-slate-500 text-xs"><?= e($j['assigned_to_name'] ?? '—') ?></td>
                <td class="px-4 py-2 text-right text-slate-700"><?= money($j['total_charge']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
