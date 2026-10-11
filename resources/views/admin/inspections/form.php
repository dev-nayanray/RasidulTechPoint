<?php
/** @var array|null $unit
 *  @var list<array> $checklist
 *  @var array $errors
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/inspections')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Inspections</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">New inspection</h1>

<?php if ($unit !== null): ?>
<div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-sm">
    <p><strong>Unit:</strong> <a href="<?= e(url('/admin/inventory/' . $unit['id'])) ?>" class="font-medium text-blue-700"><?= e($unit['sku']) ?></a>
    <?php if (!empty($unit['serial_number'])): ?> · Serial: <code class="text-xs"><?= e($unit['serial_number']) ?></code><?php endif; ?>
    · <?= e($unit['product_name']) ?>
    · Status: <span class="capitalize font-medium"><?= e($unit['status']) ?></span></p>
</div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/inspections')) ?>" enctype="multipart/form-data" class="mt-6 max-w-3xl space-y-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <input type="hidden" name="inventory_unit_id" value="<?= e((string) ($unit['id'] ?? '')) ?>">

    <?php if ($unit === null): ?>
    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Select unit</h2>
        <p class="text-sm text-slate-500">Enter the SKU of the unit to inspect. The unit must be in <em>awaiting inspection</em> or <em>returned</em> status.</p>
        <input name="inventory_unit_id" class="input" placeholder="SKU → unit ID" required>
    </section>
    <?php endif; ?>

    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Outcome &amp; grading</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Outcome</label>
                <div class="flex gap-2">
                    <label class="cursor-pointer rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm has-[:checked]:border-green-500 has-[:checked]:bg-green-100">
                        <input type="radio" name="outcome" value="pass" class="sr-only" checked> Pass
                    </label>
                    <label class="cursor-pointer rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm has-[:checked]:border-red-500 has-[:checked]:bg-red-100">
                        <input type="radio" name="outcome" value="fail" class="sr-only"> Fail
                    </label>
                    <label class="cursor-pointer rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm has-[:checked]:border-amber-500 has-[:checked]:bg-amber-100">
                        <input type="radio" name="outcome" value="hold" class="sr-only"> Hold
                    </label>
                </div>
            </div>
            <div class="space-y-1.5">
                <label for="field-grade" class="block text-sm font-medium text-slate-700">Condition grade</label>
                <select id="field-grade" name="condition_grade" class="input">
                    <option value="">— unchanged —</option>
                    <option value="A">A — Excellent</option>
                    <option value="B" selected>B — Good</option>
                    <option value="C">C — Fair</option>
                    <option value="D">D — Poor/For parts</option>
                </select>
            </div>
        </div>
    </section>

    <?php if ($checklist !== []): ?>
    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Hardware test checklist</h2>
        <div class="space-y-2">
            <?php foreach ($checklist as $item): ?>
            <div class="flex items-center gap-3 rounded-lg border border-slate-100 px-3 py-2">
                <input type="hidden" name="checklist_id[]" value="<?= (int) $item['id'] ?>">
                <span class="flex-1 text-sm text-slate-700">
                    <span class="font-medium"><?= e($item['name']) ?></span>
                    <span class="block text-xs text-slate-400"><?= e($item['description'] ?? '') ?></span>
                </span>
                <select name="checklist_status[]" class="input w-28 text-xs">
                    <option value="pass">Pass</option>
                    <option value="fail">Fail</option>
                    <option value="pending" selected>Pending</option>
                </select>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Defect notes &amp; photos</h2>
        <div>
            <label for="field-defects" class="block text-sm font-medium text-slate-700">Defect notes</label>
            <textarea id="field-defects" name="defect_notes" rows="3" class="input mt-1.5" placeholder="Describe any defects, issues, or observations. Required when outcome is 'fail'."></textarea>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700">Photo evidence (max 5)</label>
            <div class="mt-2 grid gap-2 sm:grid-cols-3">
                <?php for ($i = 0; $i < 3; $i++): ?>
                <input name="photo_<?= $i ?>" type="file" accept="image/jpeg,image/png,image/webp" class="text-xs text-slate-600 file:mr-2 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white">
                <?php endfor; ?>
            </div>
        </div>
    </section>

    <div class="flex gap-3">
        <button type="submit" class="btn-primary">Record inspection</button>
        <a href="<?= e(url('/admin/inspections')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
