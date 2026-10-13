<?php /** @var list<array> $slides @var array $settings */
$hasLive = false;
foreach ($slides as $s) {
    if ((int) ($s['is_active'] ?? 0) === 1 && ($s['status'] ?? '') === 'published') {
        $hasLive = true;
        break;
    }
}
$svc = new \App\Services\HeroSlideService();
?>
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Homepage Hero Slider</h1>
        <p class="text-sm text-slate-500 mt-1">Premium full-width slider — content lives in the database, edits appear on the live site immediately.</p>
    </div>
    <div class="flex gap-2">
        <a href="<?= e(url('/admin/hero-slides/settings')) ?>" class="btn-secondary text-sm">Settings</a>
        <?php if (count($slides) < (int) $settings['max_slides']): ?>
        <a href="<?= e(url('/admin/hero-slides/create')) ?>" class="btn-primary text-sm">+ New slide</a>
        <?php else: ?>
        <span class="btn-primary text-sm opacity-50 cursor-not-allowed" title="Max slides reached (raise in Settings)">Max slides reached</span>
        <?php endif; ?>
    </div>
</div>

<?php if (!$hasLive && count($slides) > 0): ?>
<div class="mb-4 rounded-lg border-l-4 border-amber-400 bg-amber-50 p-3 text-sm text-amber-800">
    <strong>No live slide.</strong> No slide is currently published &amp; active — the homepage will fall back to the static hero.
    Activate + publish at least one slide to show the slider.
</div>
<?php endif; ?>

<?php if ($slides === []): ?>
<div class="rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center">
    <svg class="mx-auto h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M3 20h18"/></svg>
    <h3 class="mt-2 text-sm font-semibold text-slate-900">No hero slides yet</h3>
    <p class="mt-1 text-sm text-slate-500">Add your first slide to bring the homepage slider to life.</p>
    <a href="<?= e(url('/admin/hero-slides/create')) ?>" class="btn-primary text-sm mt-4 inline-block">Add first slide</a>
</div>
<?php else: ?>

<!-- Hidden reorder form (CSRF + POST to /admin/hero-slides/reorder) -->
<form id="hero-reorder-form" method="post" action="<?= e(url('/admin/hero-slides/reorder')) ?>" class="hidden">
    <?= csrf_field() ?>
    <input type="hidden" name="ids" id="hero-reorder-ids">
</form>

<div class="space-y-3" data-slide-list data-reorder-url="<?= e(url('/admin/hero-slides/reorder')) ?>">
    <?php foreach ($slides as $s):
        $tokens = $svc->themeTokens($s);
        $isPublished = ($s['status'] ?? 'draft') === 'published';
        $isActive = (int) ($s['is_active'] ?? 0) === 1;
        $live = $isPublished && $isActive;
        $preview = $s['desktop_image'] ?: ($s['laptop_image_url'] ?? '');
    ?>
    <div class="rounded-xl border border-slate-200 bg-white p-4 flex items-center gap-4" data-slide-id="<?= (int) $s['id'] ?>" draggable="true">
        <!-- Drag handle -->
        <button type="button" class="grip text-slate-400 hover:text-slate-700 cursor-grab" aria-label="Drag to reorder" data-grip>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>
        </button>

        <!-- Preview swatch -->
        <div class="w-20 h-12 rounded-lg shrink-0 overflow-hidden flex items-center justify-center" style="background:<?= e($tokens['bg']) ?>;color:<?= e($tokens['ink']) ?>">
            <?php if ($preview): ?>
                <img src="<?= e(url($preview)) ?>" alt="" class="w-full h-full object-cover">
            <?php else: ?>
                <span style="color:<?= e($tokens['accent']) ?>" class="text-[10px] font-bold uppercase tracking-wider"><?= e($s['nav_label'] ?? 'Slide') ?></span>
            <?php endif; ?>
        </div>

        <!-- Title + meta -->
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                <p class="font-semibold text-slate-900 truncate"><?= e($s['nav_label'] ?? $s['internal_name'] ?? 'Slide') ?></p>
                <?php if ($live): ?>
                    <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-[10px] font-medium text-green-700 ring-1 ring-green-200">Live</span>
                <?php elseif ($isPublished): ?>
                    <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-medium text-blue-700 ring-1 ring-blue-200">Published</span>
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500">Inactive</span>
                <?php else: ?>
                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700 ring-1 ring-amber-200">Draft</span>
                <?php endif; ?>
                <span class="text-[10px] text-slate-400">Order: <?= (int) $s['sort_order'] ?></span>
                <span class="text-[10px] text-slate-400">Theme: <?= e(str_replace('_', ' ', $s['theme_preset'] ?? 'mist_teal')) ?></span>
            </div>
            <p class="mt-0.5 text-sm text-slate-600 truncate"><?= e($s['headline'] ?? '') ?></p>
            <p class="text-xs text-slate-400 mt-0.5">
                <?= e($s['cta1_label'] ?? '') ?> → <?= e($s['cta1_url'] ?? '') ?>
                <?php if (!empty($s['start_at']) || !empty($s['end_at'])): ?>
                    · <?= e($s['start_at'] ?: '∞') ?> → <?= e($s['end_at'] ?: '∞') ?>
                <?php endif; ?>
            </p>
        </div>

        <!-- Move up/down (keyboard-accessible reorder) -->
        <div class="flex flex-col gap-1">
            <button type="button" class="p-1 text-slate-400 hover:text-slate-700" data-move-up aria-label="Move slide up" title="Move up">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 15l7-7 7 7"/></svg>
            </button>
            <button type="button" class="p-1 text-slate-400 hover:text-slate-700" data-move-down aria-label="Move slide down" title="Move down">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 9l7 7 7-7"/></svg>
            </button>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-1.5 shrink-0">
            <a href="<?= e(url('/admin/hero-slides/' . $s['id'] . '/preview')) ?>" target="_blank" class="btn-secondary text-xs" title="Preview in new tab">Preview</a>
            <a href="<?= e(url('/admin/hero-slides/' . $s['id'] . '/edit')) ?>" class="btn-secondary text-xs">Edit</a>
            <form method="post" action="<?= e(url('/admin/hero-slides/' . $s['id'] . '/toggle')) ?>" class="inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn-secondary text-xs" title="<?= $isActive ? 'Deactivate' : 'Activate' ?>"><?= $isActive ? 'Deactivate' : 'Activate' ?></button>
            </form>
            <form method="post" action="<?= e(url('/admin/hero-slides/' . $s['id'] . '/duplicate')) ?>" class="inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn-secondary text-xs">Duplicate</button>
            </form>
            <form method="post" action="<?= e(url('/admin/hero-slides/' . $s['id'] . '/delete')) ?>" class="inline" data-confirm-inline>
                <?= csrf_field() ?>
                <button type="button" class="btn-danger text-xs" data-confirm-btn data-confirm-text="Delete this slide?">Delete</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<p class="text-xs text-slate-400 mt-3">Drag a slide to reorder, or use the ↑/↓ buttons. Order is saved automatically.</p>

<script type="module" src="<?= e(url('assets/js/admin-hero-index.js')) ?>"></script>
<?php endif; ?>
