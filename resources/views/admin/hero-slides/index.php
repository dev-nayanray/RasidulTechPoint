<?php /** @var list<array> $slides */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/dashboard')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Dashboard</a></p>
<div class="flex items-center justify-between mt-2">
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Homepage Hero Slider</h1>
    <a href="<?= e(url('/admin/hero-slides/create')) ?>" class="btn-primary text-sm">+ New Slide</a>
</div>
<p class="mt-1 text-sm text-slate-500">Manage the premium full-width animated hero slider on the homepage. Slides load dynamically — changes appear on the live site immediately.</p>

<div class="mt-6 space-y-3" data-slide-list>
    <?php if ($slides === []): ?>
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <p class="text-sm text-slate-500">No hero slides yet. <a href="<?= e(url('/admin/hero-slides/create')) ?>" class="font-medium text-blue-700">Create one →</a></p>
        </div>
    <?php else: ?>
        <?php foreach ($slides as $slide): ?>
            <div class="rounded-xl border border-slate-200 bg-white p-4 flex items-center gap-4" data-slide-id="<?= (int)$slide['id'] ?>">
                <div class="w-20 h-12 rounded-lg shrink-0 overflow-hidden flex items-center justify-center" style="background:<?= e($slide['bg_color']) ?>">
                    <?php if (!empty($slide['desktop_image'])): ?>
                        <img src="<?= e(url($slide['desktop_image'])) ?>" alt="" class="w-full h-full object-cover">
                    <?php else: ?>
                        <span style="color:<?= e($slide['text_color']) ?>" class="text-[10px] font-bold">PREVIEW</span>
                    <?php endif; ?>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <p class="font-semibold text-slate-900 truncate"><?= e($slide['internal_name'] ?? 'Slide') ?></p>
                        <?php if ((int)$slide['is_active'] === 1): ?>
                            <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-[10px] font-medium text-green-700 ring-1 ring-green-200">Active</span>
                        <?php else: ?>
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500">Inactive</span>
                        <?php endif; ?>
                        <span class="text-[10px] text-slate-400">Order: <?= (int)$slide['sort_order'] ?></span>
                    </div>
                    <p class="mt-0.5 text-sm text-slate-600 truncate"><?= e($slide['headline']) ?></p>
                    <p class="text-xs text-slate-400 mt-0.5"><?= e($slide['cta1_label'] ?? '') ?> → <?= e($slide['cta1_url'] ?? '') ?></p>
                </div>
                <div class="flex items-center gap-1.5 shrink-0">
                    <a href="<?= e(url('/admin/hero-slides/' . $slide['id'])) ?>" class="btn-secondary text-xs">View</a>
                    <a href="<?= e(url('/admin/hero-slides/' . $slide['id'] . '/edit')) ?>" class="btn-secondary text-xs">Edit</a>
                    <form method="post" action="<?= e(url('/admin/hero-slides/' . $slide['id'] . '/toggle')) ?>" class="inline">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-secondary text-xs"><?= (int)$slide['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                    <form method="post" action="<?= e(url('/admin/hero-slides/' . $slide['id'] . '/duplicate')) ?>" class="inline">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-secondary text-xs">Duplicate</button>
                    </form>
                    <form method="post" action="<?= e(url('/admin/hero-slides/' . $slide['id'] . '/delete')) ?>" class="inline" data-confirm="Delete this slide?">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-danger text-xs">Delete</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
