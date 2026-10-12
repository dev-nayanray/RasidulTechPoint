<?php /** @var list<array> $jobs */
?>
<h1 class="text-xl font-bold tracking-tight text-navy-700">Repairs & Service</h1>
<p class="mt-1 text-sm text-slate-500">Track repair and service jobs for your laptops.</p>

<?php if ($jobs === []): ?>
    <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
        <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
            <?= \App\Support\View::component('icon', ['name' => 'wrench', 'class' => 'h-6 w-6']) ?>
        </span>
        <p class="mt-4 font-semibold text-navy-700">No service jobs yet</p>
        <p class="mt-1 text-sm text-slate-500">When you submit a laptop for repair or service, it will appear here.</p>
        <a href="<?= e(url('/account/support')) ?>" class="mt-4 inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-navy-700 hover:bg-slate-50 transition">Contact us about a repair</a>
    </div>
<?php else: ?>
    <div class="mt-4 space-y-3">
        <?php foreach ($jobs as $job): ?>
            <div class="rounded-xl bg-white ring-1 ring-slate-200 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold text-navy-700 text-sm"><?= e(($job['brand_name'] ?? '') . ' ' . ($job['product_name'] ?? 'Unknown device')) ?></p>
                        <dl class="mt-1.5 space-y-0.5 text-xs text-slate-600">
                            <?php if (!empty($job['job_number'])): ?><div><dt class="inline text-slate-400">Job #:</dt> <dd class="inline font-mono text-navy-700"><?= e($job['job_number']) ?></dd></div><?php endif; ?>
                            <?php if (!empty($job['sku'])): ?><div><dt class="inline text-slate-400">SKU:</dt> <dd class="inline font-mono text-navy-700"><?= e($job['sku']) ?></dd></div><?php endif; ?>
                            <div><dt class="inline text-slate-400">Received:</dt> <dd class="inline text-navy-700"><?= e(local_datetime($job['received_date'] ?? $job['created_at'])) ?></dd></div>
                            <?php if (!empty($job['expected_completion_date'])): ?><div><dt class="inline text-slate-400">Est. completion:</dt> <dd class="inline text-navy-700"><?= e($job['expected_completion_date']) ?></dd></div><?php endif; ?>
                        </dl>
                    </div>
                    <div class="text-right">
                        <?= \App\Support\View::component('badge', ['status' => $job['status'] ?? 'received']) ?>
                        <?php if (!empty($job['estimate_amount']) && (float) $job['estimate_amount'] > 0): ?>
                            <p class="mt-1 text-xs text-slate-500">Est: <?= money($job['estimate_amount']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
