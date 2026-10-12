<?php /** @var list<array> $deliveries
 *  @var int $total
 *  @var int $page
 *  @var int $pages
 *  @var string $status
 *  @var string $eventKey
 *  @var list<string> $statuses
 *  @var array $stats
 */
?>
<div class="flex items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Delivery Log</h1>
        <p class="mt-1 text-sm text-slate-500">Append-only record of every dispatch attempt. <strong>Never exposes notification body content</strong> — only event_key, status, recipient name, and timestamps.</p>
    </div>
    <form method="post" action="<?= e(url('/admin/notifications/deliveries/retry')) ?>" data-loading-form data-confirm="Retry all failed deliveries?">
        <?= csrf_field() ?>
        <button type="submit" class="btn-primary">Retry failed</button>
    </form>
</div>

<div class="mt-4 grid gap-3 sm:grid-cols-4">
    <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-400">Delivered</p><p class="mt-1 text-2xl font-bold text-green-700"><?= number_format((int) $stats['delivered']) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-400">Failed</p><p class="mt-1 text-2xl font-bold text-red-700"><?= number_format((int) $stats['failed']) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-400">Suppressed (duplicates)</p><p class="mt-1 text-2xl font-bold text-amber-700"><?= number_format((int) $stats['suppressed']) ?></p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-400">Total</p><p class="mt-1 text-2xl font-bold text-slate-900"><?= number_format((int) $stats['total']) ?></p></div>
</div>

<form method="get" action="<?= e(url('/admin/notifications/deliveries')) ?>" class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4" role="search">
    <div class="space-y-1.5">
        <label for="dl-status" class="block text-sm font-medium text-slate-700">Status</label>
        <select id="dl-status" name="status" class="input">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= e($s) ?>" <?= $s === $status ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $s))) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="sm:col-span-2 space-y-1.5">
        <label for="dl-event" class="block text-sm font-medium text-slate-700">Event key</label>
        <input id="dl-event" name="event_key" value="<?= e($eventKey) ?>" class="input" placeholder="order.created, payment.confirmed…">
    </div>
    <div class="flex items-end"><button type="submit" class="btn-primary">Apply</button></div>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Event</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Recipient</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Attempts</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Error</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">When</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Delivered at</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($deliveries as $d): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-mono text-xs text-slate-700"><?= e($d['event_key']) ?></td>
                    <td class="px-4 py-3 text-xs text-slate-700"><?= e($d['recipient_name'] ?? '—') ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $d['status']]) ?></td>
                    <td class="px-4 py-3 text-xs text-slate-500"><?= (int) $d['attempts'] ?></td>
                    <td class="px-4 py-3 text-xs text-slate-500 truncate max-w-xs" title="<?= e($d['last_error'] ?? '') ?>"><?= e($d['last_error'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs text-slate-500"><?= e(local_datetime($d['created_at'])) ?></td>
                    <td class="px-4 py-3 text-xs text-slate-500"><?= e(local_datetime($d['delivered_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($deliveries === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No deliveries match your filters.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/notifications/deliveries', 'queryParams' => array_filter(['status' => $status, 'event_key' => $eventKey])]) ?>
</div>
