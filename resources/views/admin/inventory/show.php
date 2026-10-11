<?php /** @var array $unit @var array $transitions */ ?>
<?php
$allowedTransitions = [];
foreach ($transitions as $movementType => $byFrom) {
    foreach (($byFrom[$unit['status']] ?? []) as $toStatus) {
        $allowedTransitions[] = [$movementType, $toStatus];
    }
}
$transitionLabels = [
    'receipt' => 'Receive', 'inspection' => 'Inspection', 'adjustment' => 'Adjust',
    'reservation' => 'Reserve', 'release' => 'Release', 'sale' => 'Mark sold',
    'return' => 'Customer return', 'write_off' => 'Write off',
];
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/inventory')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Inventory</a></p>

<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900"><?= e($unit['sku']) ?></h1>
        <p class="text-sm text-slate-500"><?= e($unit['brand_name']) ?> <?= e($unit['product_name']) ?> ·
            Grade <?= \App\Support\View::component('badge', ['status' => $unit['condition_grade']]) ?> ·
            <?= \App\Support\View::component('badge', ['status' => $unit['status']]) ?>
        </p>
    </div>
    <a href="<?= e(url('/admin/products/' . $unit['product_model_id'])) ?>" class="btn-secondary">Model record</a>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="text-sm font-semibold text-slate-900">Unit details</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Serial number</dt><dd class="font-mono text-xs text-slate-700"><?= e($unit['serial_number'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Location</dt><dd class="font-medium text-slate-900"><?= e($unit['location_name'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Supplier ref</dt><dd class="font-medium text-slate-900"><?= e($unit['supplier_ref'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Purchase ref</dt><dd class="font-medium text-slate-900"><?= e($unit['purchase_ref'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Received</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($unit['received_at'])) ?></dd></div>
                <div><dt class="text-slate-500">Inspected</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($unit['inspected_at'])) ?></dd></div>
                <div><dt class="text-slate-500">Sold</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($unit['sold_at'])) ?></dd></div>
                <?php if (!empty($unit['notes'])): ?>
                    <div><dt class="text-slate-500">Notes</dt><dd class="text-slate-700"><?= e($unit['notes']) ?></dd></div>
                <?php endif; ?>
            </dl>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="text-sm font-semibold text-slate-900">Money</h2>
            <dl class="mt-3 space-y-2">
                <div class="flex justify-between"><dt class="text-slate-500">Purchase cost</dt><dd class="font-medium"><?= money($unit['purchase_cost']) ?></dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Landed cost</dt><dd class="font-medium"><?= money($unit['landed_cost']) ?></dd></div>
                <div class="flex justify-between border-t border-slate-100 pt-2"><dt class="text-slate-500">Total cost</dt><dd class="font-semibold"><?= money((float) $unit['purchase_cost'] + (float) $unit['landed_cost']) ?></dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Selling price</dt><dd class="font-bold text-green-700"><?= money($unit['selling_price']) ?></dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Min price</dt><dd class="font-medium"><?= $unit['min_selling_price'] !== null ? money($unit['min_selling_price']) : '—' ?></dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Margin</dt><dd class="font-medium"><?= money((float) $unit['selling_price'] - (float) $unit['purchase_cost'] - (float) $unit['landed_cost']) ?></dd></div>
            </dl>

            <?php if (!in_array($unit['status'], ['sold', 'written_off'], true) && \App\Services\AuthService::can('inventory.adjust')): ?>
                <form method="post" action="<?= e(url('/admin/inventory/' . $unit['id'] . '/price')) ?>" class="mt-4 flex items-end gap-2 border-t border-slate-100 pt-4" data-loading-form>
                    <?= csrf_field() ?>
                    <div class="flex-1 space-y-1">
                        <label for="price-input" class="block text-xs font-medium text-slate-500">Change selling price</label>
                        <input id="price-input" name="selling_price" value="<?= e($unit['selling_price']) ?>" class="input" inputmode="decimal">
                    </div>
                    <button type="submit" class="btn-secondary">Save</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="lg:col-span-2 space-y-6">
        <!-- Unit photos -->
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-900">Unit photos</h2>
                <?php if (\App\Services\AuthService::can('inventory.adjust')): ?>
                    <form method="post" action="<?= e(url('/admin/inventory/' . $unit['id'] . '/photos')) ?>" enctype="multipart/form-data" class="flex items-center gap-2" data-loading-form>
                        <?= csrf_field() ?>
                        <input name="caption" type="text" placeholder="Caption (optional)" class="input w-32 text-xs">
                        <input name="photo" type="file" accept="image/jpeg,image/png,image/webp" class="text-xs text-slate-600 file:mr-2 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white">
                        <button type="submit" class="btn-primary text-xs">Upload</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <?php if (($unit['photos'] ?? []) === []): ?>
                    <p class="col-span-full py-4 text-center text-sm text-slate-400">No unit photos yet.</p>
                <?php else: ?>
                    <?php foreach ($unit['photos'] as $photo): ?>
                        <div class="relative overflow-hidden rounded-lg border border-slate-200 bg-white">
                            <?php if (file_exists(BASE_PATH . '/public/' . $photo['file_path'])): ?>
                                <img src="<?= e(url($photo['file_path'])) ?>" alt="<?= e($photo['caption'] ?? '') ?>" class="h-28 w-full object-cover">
                            <?php else: ?>
                                <div class="flex h-28 items-center justify-center text-xs text-slate-400">File missing</div>
                            <?php endif; ?>
                            <div class="flex items-center justify-between px-2 py-1 text-xs">
                                <?php if (!empty($photo['caption'])): ?>
                                    <span class="text-slate-600"><?= e($photo['caption']) ?></span>
                                <?php else: ?>
                                    <span class="text-slate-400">#<?= (int) $photo['sort_order'] ?></span>
                                <?php endif; ?>
                                <?php if (\App\Services\AuthService::can('inventory.adjust')): ?>
                                    <form method="post" action="<?= e(url('/admin/inventory/photos/' . $photo['id'] . '/delete')) ?>" data-confirm="Delete this photo?" data-loading-form>
                                        <?= csrf_field() ?>
                                        <button type="submit" class="text-red-600 hover:text-red-800" title="Delete">✕</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">Inventory operations</h2>
            <p class="mt-1 text-xs text-slate-500">Only state-machine-valid transitions are offered. Every operation requires a reason and is recorded permanently.</p>
            <?php if ($allowedTransitions === []): ?>
                <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-500">No transitions available from status "<?= e($unit['status']) ?>".</p>
            <?php else: ?>
                <form method="post" action="<?= e(url('/admin/inventory/' . $unit['id'] . '/transition')) ?>" class="mt-4 space-y-3" data-loading-form data-confirm="Apply this inventory operation?">
                    <?= csrf_field() ?>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($allowedTransitions as $i => [$movementType, $toStatus]): ?>
                            <label class="cursor-pointer rounded-lg border px-3 py-2 text-sm has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 <?= $i === 0 ? 'border-blue-300 bg-blue-50/50' : 'border-slate-200' ?>">
                                <input type="radio" name="transition" value="<?= e($movementType . ':' . $toStatus) ?>" class="sr-only" <?= $i === 0 ? 'checked' : '' ?>>
                                <span class="font-medium text-slate-800"><?= e($transitionLabels[$movementType] ?? $movementType) ?> → <span class="capitalize"><?= e(str_replace('_', ' ', $toStatus)) ?></span></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="space-y-1.5">
                        <label for="reason-input" class="block text-sm font-medium text-slate-700">Reason <span class="text-red-600">*</span></label>
                        <input id="reason-input" name="reason" class="input" placeholder="e.g. QA passed all checks / customer returned within 7 days…" required>
                    </div>
                    <button type="submit" class="btn-primary">Apply operation</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Transfer between locations -->
        <?php if ($unit['status'] === 'available' && \App\Services\AuthService::can('inventory.adjust')): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">Transfer to location</h2>
            <p class="mt-1 text-xs text-slate-500">Move this unit to a different branch. The status stays 'available' but the location changes. Requires a reason.</p>
            <form method="post" action="<?= e(url('/admin/inventory/' . $unit['id'] . '/transfer')) ?>" class="mt-4 flex flex-wrap items-end gap-3" data-loading-form data-confirm="Transfer this unit?">
                <?= csrf_field() ?>
                <div class="flex-1 space-y-1.5">
                    <label for="transfer-location" class="block text-xs font-medium text-slate-500">Target location</label>
                    <select id="transfer-location" name="to_location_id" class="input" required>
                        <option value="">— select —</option>
                        <?php foreach (\App\Support\Database\Connection::pdo()->query('SELECT id, name FROM locations WHERE is_active = 1 AND id != ' . (int) ($unit['location_id'] ?? 0) . ' ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC) as $loc): ?>
                            <option value="<?= (int) $loc['id'] ?>"><?= e($loc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex-1 space-y-1.5">
                    <label for="transfer-reason" class="block text-xs font-medium text-slate-500">Reason</label>
                    <input id="transfer-reason" name="reason" class="input" placeholder="e.g. Relocating stock to Chittagong branch" required>
                </div>
                <button type="submit" class="btn-secondary">Transfer</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h2 class="text-sm font-semibold text-slate-900">Movement history (append-only)</h2>
                <a href="<?= e(url('/admin/inventory/movements')) ?>" class="text-xs font-medium text-blue-700 hover:text-blue-800">View all movements →</a>
            </div>
            <?php if ($unit['movements'] === []): ?>
                <p class="px-5 py-8 text-center text-sm text-slate-400">No movements recorded.</p>
            <?php else: ?>
                <ol class="divide-y divide-slate-100">
                    <?php foreach ($unit['movements'] as $movement): ?>
                        <li class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm">
                            <span class="font-medium capitalize text-slate-800"><?= e(str_replace('_', ' ', $movement['movement_type'])) ?></span>
                            <span class="text-slate-500"><?= e($movement['from_status'] ?? '∅') ?> → <?= e($movement['to_status'] ?? '∅') ?></span>
                            <span class="flex-1 min-w-[12rem] text-slate-600"><?= e($movement['reason'] ?? '') ?></span>
                            <span class="text-xs text-slate-400"><?= e($movement['user_name'] ?? 'system') ?> · <?= e(local_datetime($movement['created_at'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </div>
</div>
