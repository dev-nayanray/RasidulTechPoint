<?php /** @var array $slide */
use App\Services\HeroSlideService;
$svc = new HeroSlideService();
$tokens = $svc->themeTokens($slide);
$preview = $slide['desktop_image'] ?: ($slide['laptop_image_url'] ?? '');
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/hero-slides')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Hero Slides</a></p>
<h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900"><?= e($slide['nav_label'] ?? 'Slide') ?></h1>
<p class="mt-1 text-sm text-slate-500"><?= e($slide['headline']) ?></p>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <!-- Mini preview -->
    <div class="rounded-2xl overflow-hidden relative" style="background:<?= e($tokens['bg']) ?>;color:<?= e($tokens['ink']) ?>;min-height:320px">
        <div class="p-8 sm:p-10" style="max-width:600px">
            <?php if (!empty($slide['badge_text'])): ?>
            <span style="display:inline-block;padding:6px 10px;border-radius:4px;background:<?= e($tokens['accent']) ?>;color:<?= e($tokens['accent_ink']) ?>;font-family:'IBM Plex Mono',monospace;font-size:11px;letter-spacing:.1em"><?= e($slide['badge_text']) ?></span>
            <?php endif; ?>
            <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-size:clamp(28px,4vw,44px);font-weight:700;letter-spacing:-.02em;line-height:1.05;margin-top:8px"><?= e($slide['headline']) ?></h2>
            <?php if (!empty($slide['description'])): ?>
            <p style="margin-top:12px;opacity:.8;font-size:15px;line-height:1.5;max-width:420px"><?= e($slide['description']) ?></p>
            <?php endif; ?>
            <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap">
                <?php if (!empty($slide['cta1_label'])): ?>
                <a href="<?= e(url($slide['cta1_url'])) ?>" style="display:inline-flex;align-items:center;padding:10px 20px;border-radius:8px;background:<?= e($tokens['accent']) ?>;color:<?= e($tokens['accent_ink']) ?>;font-weight:600;font-size:14px"><?= e($slide['cta1_label']) ?></a>
                <?php endif; ?>
                <?php if (!empty($slide['cta2_label'])): ?>
                <a href="<?= e(url($slide['cta2_url'] ?? '#')) ?>" style="display:inline-flex;align-items:center;padding:10px 20px;border-radius:8px;border:1.5px solid <?= e($tokens['ink']) ?>;color:<?= e($tokens['ink']) ?>;font-weight:600;font-size:14px"><?= e($slide['cta2_label']) ?></a>
                <?php endif; ?>
            </div>
            <?php if (!empty($slide['price'])): ?>
            <p style="margin-top:20px;font-family:'Bricolage Grotesque',sans-serif;font-size:28px;font-weight:700;letter-spacing:-.02em">
                <?= e($slide['currency_symbol'] ?: '$') ?><?= e(number_format((float) $slide['price'], 0)) ?>
                <?php if (!empty($slide['old_price'])): ?>
                <span style="font-family:'IBM Plex Mono',monospace;font-size:14px;color:<?= e($tokens['muted']) ?>;text-decoration:line-through;margin-left:10px"><?= e($slide['currency_symbol'] ?: '$') ?><?= e(number_format((float) $slide['old_price'], 0)) ?></span>
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Details -->
    <dl class="grid gap-3 sm:grid-cols-2 text-sm">
        <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Nav label</dt><dd class="font-medium text-slate-900"><?= e($slide['nav_label'] ?? '—') ?></dd></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Badge</dt><dd class="font-medium text-slate-900"><?= e($slide['badge_text'] ?? '—') ?></dd></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Category</dt><dd class="font-medium text-slate-900"><?= e($slide['category_label'] ?? '—') ?></dd></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Ghost word</dt><dd class="font-medium text-slate-900"><?= e($slide['ghost_word'] ?? '—') ?></dd></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Specs</dt><dd class="font-medium text-slate-900"><?= e(implode(' · ', is_array($slide['specs']) ? $slide['specs'] : [])) ?></dd></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Chips</dt><dd class="font-medium text-slate-900 text-xs">
            <?php foreach ((is_array($slide['chips']) ? $slide['chips'] : []) as $c): ?>
                <?= e($c['value'] ?? '') ?> (<?= e($c['label'] ?? '') ?>) ·
            <?php endforeach; ?>
        </dd></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Price</dt><dd class="font-medium text-slate-900"><?= e($slide['currency_symbol'] ?: '$') ?><?= e($slide['price'] ?? '—') ?></dd></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Old price</dt><dd class="font-medium text-slate-900"><?= e($slide['old_price'] ?? '—') ?></dd></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Installment</dt><dd class="font-medium text-slate-900">
            <?php if (!empty($slide['installment_months'])): ?>
                <?= e($slide['monthly_price'] ?? '') ?>/mo × <?= (int) $slide['installment_months'] ?>
            <?php else: ?>—<?php endif; ?>
        </dd></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Theme</dt><dd class="font-medium" style="color:<?= e($tokens['accent']) ?>"><?= e(ucfirst(str_replace('_', ' ', $slide['theme_preset'] ?? 'mist_teal'))) ?></dd></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Visual</dt><dd class="font-medium text-slate-900"><?= e(ucfirst(str_replace('_', ' ', $slide['visual_type'] ?? 'screen_dunes'))) ?></dd></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Status</dt><dd class="font-medium">
            <?php if (($slide['status'] ?? '') === 'published'): ?>
                <span class="text-blue-700">Published</span>
            <?php else: ?>
                <span class="text-amber-700">Draft</span>
            <?php endif; ?>
            · <?= (int) $slide['is_active'] === 1 ? 'Active' : 'Inactive' ?>
        </dd></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Schedule</dt><dd class="font-medium text-slate-900 text-xs">
            <?= e($slide['start_at'] ?: '∞') ?> → <?= e($slide['end_at'] ?: '∞') ?>
        </dd></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-400">Sort order</dt><dd class="font-medium text-slate-900"><?= (int) $slide['sort_order'] ?></dd></div>
    </dl>
</div>

<div class="mt-6 flex flex-wrap gap-2">
    <a href="<?= e(url('/admin/hero-slides/' . $slide['id'] . '/edit')) ?>" class="btn-primary text-sm">Edit</a>
    <a href="<?= e(url('/admin/hero-slides/' . $slide['id'] . '/preview')) ?>" target="_blank" class="btn-secondary text-sm">Full preview</a>
    <form method="post" action="<?= e(url('/admin/hero-slides/' . $slide['id'] . '/toggle')) ?>" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="btn-secondary text-sm"><?= (int) $slide['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
    </form>
    <form method="post" action="<?= e(url('/admin/hero-slides/' . $slide['id'] . '/duplicate')) ?>" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="btn-secondary text-sm">Duplicate</button>
    </form>
    <form method="post" action="<?= e(url('/admin/hero-slides/' . $slide['id'] . '/delete')) ?>" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="btn-danger text-sm" data-confirm>Delete</button>
    </form>
</div>
