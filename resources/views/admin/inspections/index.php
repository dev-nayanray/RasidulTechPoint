<?php
/** @var list<array> $records
 *  @var list<array> $checklist
 *  @var string $q
 */
$outcomeColors = [
    'pass' => 'border-green-200 bg-green-50 text-green-700',
    'fail' => 'border-red-200 bg-red-50 text-red-700',
    'hold' => 'border-amber-200 bg-amber-50 text-amber-700',
];
?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-slate-500"><?= count($records) ?> inspection record(s)</p>
    <?php if (\App\Services\AuthService::can('inspections.manage')): ?>
        <a href="<?= e(url('/admin/inspections/create')) ?>" class="btn-primary">New inspection</a>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(url('/admin/inspections')) ?>" class="mt-4 flex max-w-md gap-2" role="search">
    <input name="q" value="<?= e($q) ?>" class="input" placeholder="Search by SKU or serial…">
    <button type="submit" class="btn-secondary shrink-0">Search</button>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Unit</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Technician</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Outcome</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Grade</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Approved</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">When</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($records as $r): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3"><a class="font-medium text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/inspections/' . $r['id'])) ?>"><?= e($r['unit_sku']) ?></a></td>
                    <td class="px-4 py-3 text-slate-500"><?= e($r['technician_name'] ?? '—') ?></td>
                    <td class="px-4 py-3"><span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize <?= $outcomeColors[$r['outcome']] ?? $outcomeColors['hold'] ?>"><?= e($r['outcome']) ?></span></td>
                    <td class="px-4 py-3"><?= $r['condition_grade'] ? \App\Support\View::component('badge', ['status' => $r['condition_grade']]) : '—' ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= $r['approver_name'] ?? ($r['approved_by'] !== null ? 'Yes' : '—') ?></td>
                    <td class="px-4 py-3 text-slate-500 whitespace-nowrap"><?= e(local_datetime($r['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($records === []): ?>
        <p class="px-4 py-10 text-center text-sm text-slate-400">No inspection records yet.</p>
    <?php endif; ?>
</div>

<!-- Checklist management -->
<section class="mt-8 rounded-xl border border-slate-200 bg-white p-5">
    <h2 class="text-sm font-semibold text-slate-900">Inspection checklist (<?= count($checklist) ?> items)</h2>
    <p class="mt-1 text-xs text-slate-500">These items appear on every new inspection form. Toggle or delete as needed.</p>

    <?php if (\App\Services\AuthService::can('inspections.manage')): ?>
    <form method="post" action="<?= e(url('/admin/inspections/checklist/add')) ?>" class="mt-4 flex flex-wrap gap-2" data-loading-form>
        <?= csrf_field() ?>
        <input name="name" class="input flex-1 text-sm" placeholder="New checklist item (e.g. Thermal test)" required>
        <select name="category" class="input w-32 text-sm">
            <option value="hardware">Hardware</option>
            <option value="physical">Physical</option>
            <option value="software">Software</option>
            <option value="general">General</option>
        </select>
        <input name="description" class="input flex-1 text-sm" placeholder="Description (optional)">
        <button type="submit" class="btn-secondary text-xs">Add</button>
    </form>
    <?php endif; ?>

    <ul class="mt-4 divide-y divide-slate-100">
        <?php foreach ($checklist as $item): ?>
            <li class="flex items-center gap-3 py-2 text-sm">
                <span class="flex h-6 w-6 items-center justify-center rounded-full <?= (int) $item['is_active'] === 1 ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-400' ?> text-xs font-medium"><?= (int) $item['is_active'] === 1 ? '✓' : '—' ?></span>
                <span class="<?= (int) $item['is_active'] === 1 ? 'text-slate-900' : 'text-slate-400 line-through' ?>"><?= e($item['name']) ?></span>
                <span class="text-xs text-slate-400 capitalize"><?= e($item['category']) ?></span>
                <?php if (\App\Services\AuthService::can('inspections.manage')): ?>
                    <div class="ml-auto flex gap-2">
                        <form method="post" action="<?= e(url('/admin/inspections/checklist/' . $item['id'] . '/toggle')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="text-xs text-blue-700 hover:text-blue-800"><?= (int) $item['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <form method="post" action="<?= e(url('/admin/inspections/checklist/' . $item['id'] . '/delete')) ?>" data-confirm="Delete this checklist item?">
                            <?= csrf_field() ?>
                            <button type="submit" class="text-xs text-red-600 hover:text-red-800">Delete</button>
                        </form>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
