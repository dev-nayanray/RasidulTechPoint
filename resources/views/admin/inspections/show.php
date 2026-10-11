<?php
/** @var array $record */
$outcomeColors = [
    'pass' => 'border-green-200 bg-green-50 text-green-700',
    'fail' => 'border-red-200 bg-red-50 text-red-700',
    'hold' => 'border-amber-200 bg-amber-50 text-amber-700',
];
$checklistResults = $record['checklist_results'] ? json_decode((string) $record['checklist_results'], true) : [];
$photos = $record['photo_paths'] ? json_decode((string) $record['photo_paths'], true) : [];
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/inspections')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Inspections</a></p>

<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Inspection #<?= (int) $record['id'] ?></h1>
        <p class="text-sm text-slate-500">
            <a href="<?= e(url('/admin/inventory/' . $record['inventory_unit_id'])) ?>" class="font-medium text-blue-700 hover:text-blue-800"><?= e($record['unit_sku']) ?></a> ·
            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize <?= $outcomeColors[$record['outcome']] ?? $outcomeColors['hold'] ?>"><?= e($record['outcome']) ?></span>
            <?php if ($record['condition_grade']): ?> · Grade <?= \App\Support\View::component('badge', ['status' => $record['condition_grade']]) ?><?php endif; ?>
        </p>
    </div>
    <div class="flex gap-2">
        <?php if ($record['outcome'] === 'pass' && $record['approved_by'] === null && \App\Services\AuthService::can('inventory.approve')): ?>
            <form method="post" action="<?= e(url('/admin/inspections/' . $record['id'] . '/approve')) ?>" data-loading-form data-confirm="Approve this inspection and make the unit sellable?">
                <?= csrf_field() ?>
                <button type="submit" class="btn-primary">Approve &amp; make sellable</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($record['approved_by'] !== null): ?>
<p class="mt-3 rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-700">
    ✓ Approved by <?= e($record['approver_name'] ?? 'unknown') ?> on <?= e(local_datetime($record['approved_at'])) ?>
</p>
<?php endif; ?>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Inspection details</h2>
        <dl class="mt-3 space-y-2">
            <div><dt class="text-slate-500">Technician</dt><dd class="font-medium text-slate-900"><?= e($record['technician_name'] ?? '—') ?></dd></div>
            <div><dt class="text-slate-500">Outcome</dt><dd><span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize <?= $outcomeColors[$record['outcome']] ?? $outcomeColors['hold'] ?>"><?= e($record['outcome']) ?></span></dd></div>
            <div><dt class="text-slate-500">Condition grade</dt><dd><?= $record['condition_grade'] ? \App\Support\View::component('badge', ['status' => $record['condition_grade']]) : '—' ?></dd></div>
            <div><dt class="text-slate-500">Date</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($record['created_at'])) ?></dd></div>
        </dl>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Defect notes</h2>
        <p class="mt-2 text-slate-600"><?= e($record['defect_notes'] ?? '—') ?></p>
    </div>
</div>

<?php if (!empty($checklistResults)): ?>
<section class="mt-6 rounded-xl border border-slate-200 bg-white p-5">
    <h2 class="text-sm font-semibold text-slate-900">Checklist results (<?= count($checklistResults) ?> items)</h2>
    <ul class="mt-3 divide-y divide-slate-100">
        <?php foreach ($checklistResults as $r): ?>
            <li class="flex items-center justify-between py-2 text-sm">
                <span class="text-slate-700">Item #<?= (int) $r['id'] ?></span>
                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium capitalize
                    <?= $r['status'] === 'pass' ? 'border-green-200 bg-green-50 text-green-700' : ($r['status'] === 'fail' ? 'border-red-200 bg-red-50 text-red-700' : 'border-slate-200 bg-slate-50 text-slate-500') ?>">
                    <?= e($r['status']) ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if (!empty($photos)): ?>
<section class="mt-6 rounded-xl border border-slate-200 bg-white p-5">
    <h2 class="text-sm font-semibold text-slate-900">Photo evidence (<?= count($photos) ?>)</h2>
    <div class="mt-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
        <?php foreach ($photos as $path): ?>
            <?php if (file_exists(BASE_PATH . '/public/' . $path)): ?>
                <img src="<?= e(url($path)) ?>" alt="Evidence" class="h-28 w-full rounded-lg border border-slate-200 object-cover">
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
