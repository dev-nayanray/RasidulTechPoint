<?php /** @var array $template
 *  @var list<array> $deliveries
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/notifications/templates')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Templates</a></p>
<div class="mt-2 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900"><span class="font-mono"><?= e($template['event_key']) ?></span></h1>
        <p class="mt-1 text-sm text-slate-500"><?= (int) $template['is_enabled'] === 1 ? 'Active' : 'Disabled' ?> · <?= (int) $template['dedupe_window_seconds'] ?>s dedupe window</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a href="<?= e(url('/admin/notifications/templates/' . $template['id'] . '/edit')) ?>" class="btn-secondary text-xs">Edit</a>
        <form method="post" action="<?= e(url('/admin/notifications/templates/' . $template['id'] . '/toggle')) ?>" data-loading-form>
            <?= csrf_field() ?>
            <input type="hidden" name="is_enabled" value="<?= (int) $template['is_enabled'] === 1 ? '0' : '1' ?>">
            <button type="submit" class="btn-secondary text-xs"><?= (int) $template['is_enabled'] === 1 ? 'Disable' : 'Enable' ?></button>
        </form>
        <form method="post" action="<?= e(url('/admin/notifications/templates/' . $template['id'] . '/delete')) ?>" data-loading-form data-confirm="Delete this template? Delivery history is preserved.">
            <?= csrf_field() ?>
            <button type="submit" class="btn-danger text-xs">Delete</button>
        </form>
    </div>
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-2">
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-base font-semibold text-slate-900">Definition</h2>
        <dl class="mt-3 space-y-2 text-sm">
            <div><dt class="text-xs uppercase text-slate-400">Title pattern</dt><dd class="font-mono text-slate-900"><?= e($template['title']) ?></dd></div>
            <?php if ($template['body'] !== null): ?><div><dt class="text-xs uppercase text-slate-400">Body pattern</dt><dd class="font-mono text-slate-700 whitespace-pre-wrap"><?= e($template['body']) ?></dd></div><?php endif; ?>
            <?php if ($template['link_template'] !== null): ?><div><dt class="text-xs uppercase text-slate-400">Link pattern</dt><dd class="font-mono text-slate-700"><?= e($template['link_template']) ?></dd></div><?php endif; ?>
            <div><dt class="text-xs uppercase text-slate-400">Recipients</dt><dd class="text-slate-900"><?= e($template['recipient_mode']) ?>: <?= e($template['recipient_value'] ?: '—') ?></dd></div>
        </dl>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
            <h2 class="text-base font-semibold text-slate-900">Recent deliveries</h2>
            <a href="<?= e(url('/admin/notifications/deliveries?event_key=' . urlencode($template['event_key']))) ?>" class="text-xs font-medium text-blue-700 hover:text-blue-800">View all →</a>
        </div>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50"><tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Recipient</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">When</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Attempts</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($deliveries as $d): ?>
                    <tr>
                        <td class="px-4 py-3 text-slate-700 text-xs"><?= e($d['recipient_name'] ?? '—') ?></td>
                        <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $d['status']]) ?></td>
                        <td class="px-4 py-3 text-xs text-slate-500"><?= e(local_datetime($d['created_at'])) ?></td>
                        <td class="px-4 py-3 text-right text-xs text-slate-500"><?= (int) $d['attempts'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($deliveries === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No deliveries yet.</p><?php endif; ?>
    </div>
</div>
