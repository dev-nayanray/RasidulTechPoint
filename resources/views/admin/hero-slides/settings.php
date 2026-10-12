<?php /** @var array $settings @var int $slideCount */
use App\Services\HeroSlideService;
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/hero-slides')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Hero Slides</a></p>
<h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">Hero slider settings</h1>
<p class="mt-1 text-sm text-slate-500">Global slider behavior. Affects every published slide on the homepage.</p>

<?php if (!empty($_SESSION['_flash']['success'])): ?>
<div class="mt-3 rounded-lg border-l-4 border-green-500 bg-green-50 p-3 text-sm text-green-800"><?= e($_SESSION['_flash']['success']) ?></div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/hero-slides/settings')) ?>" class="mt-6 space-y-5">
    <?= csrf_field() ?>

    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Autoplay</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="autoplay_enabled" value="1" <?= !empty($settings['autoplay_enabled']) ? 'checked' : '' ?> class="rounded">
                Enable autoplay
            </label>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Interval (ms)</label>
                <input name="autoplay_interval_ms" type="number" min="3000" max="20000" step="500" value="<?= (int) $settings['autoplay_interval_ms'] ?>" class="input">
                <p class="text-[10px] text-slate-400">Allowed 3000–20000ms. Default 7000.</p>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="pause_on_hover" value="1" <?= !empty($settings['pause_on_hover']) ? 'checked' : '' ?> class="rounded">
                Pause on hover
            </label>
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Announcement bar</h2>
        <div class="mt-4 space-y-3">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="announcement_enabled" value="1" <?= !empty($settings['announcement_enabled']) ? 'checked' : '' ?> class="rounded">
                Show announcement bar above the slider
            </label>
            <p class="text-[10px] text-slate-400">Up to 3 short strings. Empty fields are not rendered.</p>
            <?php for ($i = 0; $i < 3; $i++): $v = $settings['announcement_items'][$i] ?? '' ?>
            <input name="announcement_items[<?= $i ?>]" value="<?= e($v) ?>" class="input" maxlength="120" placeholder="Announcement <?= $i + 1 ?> (e.g. Free delivery over ৳500)">
            <?php endfor; ?>
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Limits</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Max slides</label>
                <input name="max_slides" type="number" min="1" max="20" value="<?= (int) $settings['max_slides'] ?>" class="input">
                <p class="text-[10px] text-slate-400">Currently <?= (int) $slideCount ?> slide(s) configured.</p>
            </div>
        </div>
    </section>

    <div class="flex gap-3">
        <button type="submit" class="btn-primary">Save settings</button>
        <a href="<?= e(url('/admin/hero-slides')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
