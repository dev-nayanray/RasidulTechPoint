<?php /** @var list<array> $templates
 *  @var array $stats  // delivered/failed/suppressed/total
 */
?>
<div class="flex items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Notification Templates</h1>
        <p class="mt-1 text-sm text-slate-500">Event-based in-app notifications. Recipients are resolved server-side from the template definition — a caller cannot direct a notification at an arbitrary user.</p>
    </div>
    <a href="<?= e(url('/admin/notifications/templates/create')) ?>" class="btn-primary">+ New template</a>
</div>

<div class="mt-4 grid gap-3 sm:grid-cols-4">
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-xs uppercase text-slate-400">Delivered</p>
        <p class="mt-1 text-2xl font-bold text-green-700"><?= number_format((int) $stats['delivered']) ?></p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-xs uppercase text-slate-400">Failed</p>
        <p class="mt-1 text-2xl font-bold text-red-700"><?= number_format((int) $stats['failed']) ?></p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-xs uppercase text-slate-400">Suppressed (duplicates)</p>
        <p class="mt-1 text-2xl font-bold text-amber-700"><?= number_format((int) $stats['suppressed']) ?></p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-xs uppercase text-slate-400">Total deliveries</p>
        <p class="mt-1 text-2xl font-bold text-slate-900"><?= number_format((int) $stats['total']) ?></p>
    </div>
</div>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Event key</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Title pattern</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Recipients</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Dedupe</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Enabled</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($templates as $t): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3"><a class="font-mono font-semibold text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/notifications/templates/' . $t['id'])) ?>"><?= e($t['event_key']) ?></a></td>
                    <td class="px-4 py-3 text-slate-700"><?= e($t['title']) ?></td>
                    <td class="px-4 py-3">
                        <span class="text-xs text-slate-500"><?= e($t['recipient_mode']) ?>:</span>
                        <span class="ml-1 font-mono text-xs text-slate-700"><?= e($t['recipient_value'] ?: '—') ?></span>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-500"><?= (int) $t['dedupe_window_seconds'] > 0 ? (int) $t['dedupe_window_seconds'] . 's' : 'off' ?></td>
                    <td class="px-4 py-3"><?= (int) $t['is_enabled'] === 1 ? '<span class="text-green-700">✓</span>' : '<span class="text-slate-400">×</span>' ?></td>
                    <td class="px-4 py-3 text-right">
                        <a href="<?= e(url('/admin/notifications/templates/' . $t['id'] . '/edit')) ?>" class="btn-secondary text-xs">Edit</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($templates === []): ?>
        <p class="px-4 py-10 text-center text-sm text-slate-400">No templates yet. <a href="<?= e(url('/admin/notifications/templates/create')) ?>" class="font-medium text-blue-700 hover:text-blue-800">Create one →</a></p>
    <?php endif; ?>
</div>

<p class="mt-4 text-sm">
    <a href="<?= e(url('/admin/notifications/deliveries')) ?>" class="font-medium text-blue-700 hover:text-blue-800">View delivery log →</a>
</p>
