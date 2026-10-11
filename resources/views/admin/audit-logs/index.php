<?php /** @var list<array> $logs */ ?>
<form method="get" action="<?= e(url('/admin/audit-logs')) ?>" class="flex max-w-md gap-2" role="search">
    <label class="sr-only" for="audit-q">Search audit log</label>
    <input id="audit-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Action, entity or user…">
    <button type="submit" class="btn-secondary shrink-0">Search</button>
</form>

<div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <div class="border-b border-slate-200 px-4 py-2 text-xs text-slate-400"><?= (int) $total ?> event(s) — append-only record of privileged and security-relevant actions.</div>
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">When</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Actor</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Action</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Entity</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Metadata</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($logs as $log): ?>
                <tr class="hover:bg-slate-50 align-top">
                    <td class="whitespace-nowrap px-4 py-3 text-slate-500"><?= e(local_datetime($log['created_at'])) ?></td>
                    <td class="px-4 py-3 text-slate-700"><?= e($log['user_name'] ?? 'system') ?></td>
                    <td class="px-4 py-3"><code class="text-xs font-medium text-slate-800"><?= e($log['action']) ?></code></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($log['entity_type'] ?? '—') ?><?= $log['entity_id'] !== null ? ' #' . e($log['entity_id']) : '' ?></td>
                    <td class="max-w-xs px-4 py-3"><code class="block break-all text-xs text-slate-500"><?= e($log['metadata'] ?? '') ?></code></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($logs === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No audit events match.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/audit-logs', 'queryParams' => ['q' => $q]]) ?>
</div>
