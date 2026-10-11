<?php
/** @var list<array> $movements
 *  @var int $total
 *  @var int $page
 *  @var int $pages
 *  @var string $q
 *  @var string $typeFilter
 *  @var list<string> $movementTypes
 */
?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-slate-500"><?= (int) $total ?> movement(s) — append-only, never deleted or modified</p>
    <a href="<?= e(url('/admin/inventory')) ?>" class="btn-secondary text-xs">← Inventory</a>
</div>

<form method="get" action="<?= e(url('/admin/inventory/movements')) ?>" class="mt-4 flex flex-wrap gap-2" role="search">
    <input type="text" name="q" value="<?= e($q) ?>" class="input flex-1" placeholder="Search SKU, actor or reason…">
    <select name="type" class="input w-40" onchange="this.form.submit()">
        <option value="">All types</option>
        <?php foreach ($movementTypes as $type): ?>
            <option value="<?= e($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $type)) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-secondary">Search</button>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">When</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Unit</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Type</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">From → To</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Reason</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Actor</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($movements as $m): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 whitespace-nowrap text-slate-500"><?= e(local_datetime($m['created_at'])) ?></td>
                    <td class="px-4 py-3">
                        <?php if (!empty($m['unit_sku'])): ?>
                            <a href="<?= e(url('/admin/inventory/' . $m['inventory_unit_id'])) ?>" class="font-medium text-blue-700 hover:text-blue-800"><?= e($m['unit_sku']) ?></a>
                        <?php else: ?>
                            <span class="text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3"><span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs font-medium capitalize text-slate-700"><?= e(str_replace('_', ' ', $m['movement_type'])) ?></span></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($m['from_status'] ?? '∅') ?> → <?= e($m['to_status'] ?? '∅') ?></td>
                    <td class="px-4 py-3 text-slate-600 max-w-xs break-words"><?= e($m['reason'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($m['user_name'] ?? 'system') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($movements === []): ?>
        <p class="px-4 py-10 text-center text-sm text-slate-400">No movements recorded.</p>
    <?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/inventory/movements', 'queryParams' => array_filter(['q' => $q, 'type' => $typeFilter])]) ?>
</div>
