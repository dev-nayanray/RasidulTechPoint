<?php /** @var list<array> $registrations */
?>
<h1 class="text-xl font-bold tracking-tight text-navy-700">My Warranty</h1>
<p class="mt-1 text-sm text-slate-500">Registered laptops and their warranty status.</p>

<?php if ($registrations === []): ?>
    <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
        <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
            <?= \App\Support\View::component('icon', ['name' => 'shield', 'class' => 'h-6 w-6']) ?>
        </span>
        <p class="mt-4 font-semibold text-navy-700">No warranty registrations yet</p>
        <p class="mt-1 text-sm text-slate-500">When you buy a laptop with warranty, it will appear here after registration is processed.</p>
        <a href="<?= e(url('/shop')) ?>" class="mt-4 inline-flex items-center gap-2 rounded-lg bg-brand-blue px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">Browse Laptops</a>
    </div>
<?php else: ?>
    <div class="mt-4 space-y-3">
        <?php foreach ($registrations as $reg): ?>
            <div class="rounded-xl bg-white ring-1 ring-slate-200 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold text-navy-700 text-sm"><?= e(($reg['brand_name'] ?? '') . ' ' . ($reg['product_name'] ?? 'Unknown model')) ?></p>
                        <dl class="mt-1.5 space-y-0.5 text-xs text-slate-600">
                            <?php if (!empty($reg['sku'])): ?><div><dt class="inline text-slate-400">SKU:</dt> <dd class="inline font-mono text-navy-700"><?= e($reg['sku']) ?></dd></div><?php endif; ?>
                            <?php if (!empty($reg['serial_number'])): ?><div><dt class="inline text-slate-400">Serial:</dt> <dd class="inline font-mono text-navy-700"><?= e(substr($reg['serial_number'], 0, 4)) ?>••••</dd></div><?php endif; ?>
                            <div><dt class="inline text-slate-400">Registered:</dt> <dd class="inline text-navy-700"><?= e(local_datetime($reg['registered_at'] ?? $reg['created_at'])) ?></dd></div>
                            <div><dt class="inline text-slate-400">Warranty ends:</dt> <dd class="inline text-navy-700"><?= !empty($reg['end_date']) ? e($reg['end_date']) : '—' ?></dd></div>
                        </dl>
                    </div>
                    <div class="text-right">
                        <?= \App\Support\View::component('badge', ['status' => $reg['status'] ?? 'active']) ?>
                        <p class="mt-1 text-[10px] text-slate-400">Grade <?= e($reg['condition_grade'] ?? '—') ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
