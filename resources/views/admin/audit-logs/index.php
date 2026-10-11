<?php
/** @var list<array> $logs
 *  @var int $total
 *  @var int $page
 *  @var int $pages
 *  @var string $q
 *  @var string $category
 *  @var string $entityType
 *  @var int $userId
 *  @var string $from
 *  @var string $to
 *  @var list<string> $categories
 *  @var list<string> $entityTypes
 *  @var list<array> $users
 */
$categoryColors = [
    'auth' => 'border-blue-200 bg-blue-50 text-blue-700',
    'user' => 'border-purple-200 bg-purple-50 text-purple-700',
    'role' => 'border-indigo-200 bg-indigo-50 text-indigo-700',
    'settings' => 'border-amber-200 bg-amber-50 text-amber-700',
    'inventory' => 'border-green-200 bg-green-50 text-green-700',
    'catalog' => 'border-cyan-200 bg-cyan-50 text-cyan-700',
    'location' => 'border-teal-200 bg-teal-50 text-teal-700',
    'orders' => 'border-violet-200 bg-violet-50 text-violet-700',
    'permissions' => 'border-red-200 bg-red-50 text-red-700',
    'account' => 'border-slate-200 bg-slate-50 text-slate-700',
    'system' => 'border-slate-200 bg-slate-100 text-slate-600',
    'other' => 'border-slate-200 bg-slate-50 text-slate-600',
];
$queryParams = ['q' => $q, 'category' => $category, 'entity_type' => $entityType, 'user_id' => (string) $userId, 'from' => $from, 'to' => $to];
?>
<form method="get" action="<?= e(url('/admin/audit-logs')) ?>" class="space-y-3">
    <div class="flex flex-wrap gap-2">
        <input type="text" name="q" value="<?= e($q) ?>" class="input flex-1" placeholder="Search action, entity, user, IP…">
        <select name="category" class="input w-40" onchange="this.form.submit()">
            <option value="">All categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= e(ucfirst($cat)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="entity_type" class="input w-40" onchange="this.form.submit()">
            <option value="">All entity types</option>
            <?php foreach ($entityTypes as $type): ?>
                <option value="<?= e($type) ?>" <?= $entityType === $type ? 'selected' : '' ?>><?= e($type) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <select name="user_id" class="input w-56" onchange="this.form.submit()">
            <option value="0">All actors</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="text-xs text-slate-500" for="audit-from">From</label>
        <input id="audit-from" name="from" type="date" value="<?= e($from) ?>" class="input w-40" max="<?= e(gmdate('Y-m-d')) ?>">
        <label class="text-xs text-slate-500" for="audit-to">to</label>
        <input id="audit-to" name="to" type="date" value="<?= e($to) ?>" class="input w-40" max="<?= e(gmdate('Y-m-d')) ?>">
        <button type="submit" class="btn-secondary">Apply filters</button>
        <a href="<?= e(url('/admin/audit-logs')) ?>" class="text-xs text-slate-500 hover:text-slate-700">Reset</a>
        <a href="<?= e(url('/admin/audit-logs/export?' . http_build_query($queryParams))) ?>" class="ml-auto btn-secondary text-xs">Export JSON</a>
    </div>
</form>

<div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <div class="border-b border-slate-200 px-4 py-2 text-xs text-slate-400">
        <?= (int) $total ?> event(s) — append-only record of privileged and security-relevant actions.
        Audit logs can never be edited or deleted through the application.
    </div>
    <?php if ($logs === []): ?>
        <p class="px-4 py-10 text-center text-sm text-slate-400">No audit events match your filters.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">When</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Actor</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Action</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Entity</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Metadata</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($logs as $log): ?>
                    <?php $cat = \App\Services\AuditService::categoryForAction($log['action']); ?>
                    <?php $catClass = $categoryColors[$cat] ?? $categoryColors['other']; ?>
                    <tr class="hover:bg-slate-50 align-top">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">
                            <?= e(local_datetime($log['created_at'])) ?>
                        </td>
                        <td class="px-4 py-3 text-slate-700">
                            <?= e($log['user_name'] ?? 'system') ?>
                            <?php if ($log['user_id'] !== null): ?>
                                <span class="block text-[10px] text-slate-400">#<?= (int) $log['user_id'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-medium capitalize <?= $catClass ?>"><?= e($cat) ?></span>
                            <code class="mt-1 block text-xs font-medium text-slate-800"><?= e($log['action']) ?></code>
                        </td>
                        <td class="px-4 py-3 text-slate-500">
                            <?= e($log['entity_type'] ?? '—') ?>
                            <?php if ($log['entity_id'] !== null): ?>
                                <span class="block text-[10px] text-slate-400">#<?= (int) $log['entity_id'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="max-w-xs px-4 py-3">
                            <?php if ($log['metadata'] !== null && $log['metadata'] !== ''): ?>
                                <?php $meta = json_decode((string) $log['metadata'], true); ?>
                                <?php if (is_array($meta) && $meta !== []): ?>
                                    <dl class="space-y-0.5">
                                        <?php foreach ($meta as $mk => $mv): ?>
                                            <div class="flex gap-1 text-xs">
                                                <dt class="font-medium text-slate-600"><?= e((string) $mk) ?>:</dt>
                                                <dd class="text-slate-500 break-all">
                                                    <?php if (is_array($mv)): ?>
                                                        <code class="text-[10px]"><?= e(json_encode($mv, JSON_UNESCAPED_SLASHES)) ?></code>
                                                    <?php elseif (is_bool($mv)): ?>
                                                        <?= $mv ? 'true' : 'false' ?>
                                                    <?php else: ?>
                                                        <?= e((string) $mv) ?>
                                                    <?php endif; ?>
                                                </dd>
                                            </div>
                                        <?php endforeach; ?>
                                    </dl>
                                <?php else: ?>
                                    <code class="text-[10px] text-slate-400">—</code>
                                <?php endif; ?>
                            <?php else: ?>
                                <code class="text-[10px] text-slate-400">—</code>
                            <?php endif; ?>
                            <?php if ($log['correlation_id']): ?>
                                <span class="mt-1 block text-[10px] text-slate-400" title="Correlation ID">⟳ <?= e($log['correlation_id']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-500">
                            <?= e($log['ip'] ?? '—') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/audit-logs', 'queryParams' => $queryParams]) ?>
</div>
