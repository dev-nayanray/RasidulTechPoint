<?php /** @var array $slide */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/hero-slides')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Hero Slides</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= e($slide['internal_name'] ?? 'Slide') ?></h1>

<div class="mt-6 rounded-2xl overflow-hidden relative" style="background:<?= e($slide['bg_color']) ?>;color:<?= e($slide['text_color']) ?>;min-height:280px">
    <div class="p-8 sm:p-10" style="max-width:600px">
        <?php if (!empty($slide['eyebrow'])): ?><p style="font-family:'IBM Plex Mono',monospace;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;opacity:.7"><?= e($slide['eyebrow']) ?></p><?php endif; ?>
        <h2 style="font-size:clamp(28px,4vw,44px);font-weight:800;letter-spacing:-.02em;line-height:1.05;margin-top:8px"><?= e($slide['headline']) ?> <?php if (!empty($slide['highlight_phrase'])): ?><span style="color:#7AFB01"><?= e($slide['highlight_phrase']) ?></span><?php endif; ?></h2>
        <?php if (!empty($slide['description'])): ?><p style="margin-top:12px;opacity:.8;font-size:15px;line-height:1.5;max-width:420px"><?= e($slide['description']) ?></p><?php endif; ?>
        <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap">
            <a href="<?= e(url($slide['cta1_url'])) ?>" style="display:inline-flex;align-items:center;padding:10px 20px;border-radius:10px;background:#2563EB;color:#fff;font-weight:700;font-size:14px"><?= e($slide['cta1_label']) ?></a>
            <?php if (!empty($slide['cta2_label'])): ?><a href="<?= e(url($slide['cta2_url'] ?? '#')) ?>" style="display:inline-flex;align-items:center;padding:10px 20px;border-radius:10px;border:1px solid rgba(255,255,255,.4);color:#fff;font-weight:700;font-size:14px"><?= e($slide['cta2_label']) ?></a><?php endif; ?>
        </div>
    </div>
</div>

<dl class="mt-6 grid gap-3 sm:grid-cols-2 text-sm">
    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Headline</dt><dd class="font-medium text-slate-900"><?= e($slide['headline']) ?></dd></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Highlight</dt><dd class="font-medium text-slate-900"><?= e($slide['highlight_phrase'] ?? '—') ?></dd></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Primary CTA</dt><dd class="font-medium text-slate-900"><?= e($slide['cta1_label']) ?> → <?= e($slide['cta1_url']) ?></dd></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Secondary CTA</dt><dd class="font-medium text-slate-900"><?= e($slide['cta2_label'] ?? '—') ?> <?= !empty($slide['cta2_url']) ? '→ ' . e($slide['cta2_url']) : '' ?></dd></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Background</dt><dd class="font-medium" style="color:<?= e($slide['bg_color']) ?>"><?= e($slide['bg_color']) ?></dd></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Transition</dt><dd class="font-medium text-slate-900"><?= e(ucfirst($slide['transition_style'])) ?> · <?= e(ucfirst($slide['animation_intensity'])) ?></dd></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Duration</dt><dd class="font-medium text-slate-900"><?= (int)$slide['slide_duration'] ?>ms</dd></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Status</dt><dd class="font-medium"><?= (int)$slide['is_active'] === 1 ? '<span class="text-green-700">Active</span>' : '<span class="text-slate-400">Inactive</span>' ?></dd></div>
</dl>

<div class="mt-4 flex gap-2">
    <a href="<?= e(url('/admin/hero-slides/' . $slide['id'] . '/edit')) ?>" class="btn-primary text-sm">Edit</a>
    <form method="post" action="<?= e(url('/admin/hero-slides/' . $slide['id'] . '/toggle')) ?>" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="btn-secondary text-sm"><?= (int)$slide['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
    </form>
    <form method="post" action="<?= e(url('/admin/hero-slides/' . $slide['id'] . '/duplicate')) ?>" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="btn-secondary text-sm">Duplicate</button>
    </form>
    <form method="post" action="<?= e(url('/admin/hero-slides/' . $slide['id'] . '/delete')) ?>" class="inline" data-confirm="Delete this slide?">
        <?= csrf_field() ?>
        <button type="submit" class="btn-danger text-sm">Delete</button>
    </form>
</div>
