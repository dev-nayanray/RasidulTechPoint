<?php /** @var array|null $slide @var array $errors */
$isEdit = $slide !== null;
$s = $slide ?? [];
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/hero-slides')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Hero Slides</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= $isEdit ? 'Edit Slide' : 'New Hero Slide' ?></h1>

<form method="post" action="<?= e($isEdit ? url('/admin/hero-slides/' . $slide['id'] . '/update') : url('/admin/hero-slides')) ?>" enctype="multipart/form-data" class="mt-6 space-y-6" data-loading-form>
    <?= csrf_field() ?>

    <!-- Content -->
    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold uppercase text-slate-400">Content</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Internal name *</label>
                <input name="internal_name" value="<?= e($s['internal_name'] ?? 'Slide') ?>" class="input" required maxlength="120">
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Eyebrow (small label)</label>
                <input name="eyebrow" value="<?= e($s['eyebrow'] ?? '') ?>" class="input" maxlength="80" placeholder="e.g. Premium Discovery">
            </div>
            <div class="space-y-1.5 sm:col-span-2">
                <label class="block text-sm font-medium text-slate-700">Headline *</label>
                <input name="headline" value="<?= e($s['headline'] ?? '') ?>" class="input" required maxlength="255" placeholder="Find Your Next Laptop.">
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Highlight phrase (colored)</label>
                <input name="highlight_phrase" value="<?= e($s['highlight_phrase'] ?? '') ?>" class="input" maxlength="120" placeholder="Smarter Prices.">
            </div>
            <div class="space-y-1.5 sm:col-span-2">
                <label class="block text-sm font-medium text-slate-700">Description</label>
                <textarea name="description" rows="2" class="input" placeholder="Explore available laptops for work, study..."><?= e($s['description'] ?? '') ?></textarea>
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Primary CTA label *</label>
                <input name="cta1_label" value="<?= e($s['cta1_label'] ?? 'Shop Now') ?>" class="input" required maxlength="60">
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Primary CTA URL *</label>
                <input name="cta1_url" value="<?= e($s['cta1_url'] ?? '/shop') ?>" class="input" required maxlength="255">
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Secondary CTA label</label>
                <input name="cta2_label" value="<?= e($s['cta2_label'] ?? '') ?>" class="input" maxlength="60" placeholder="Explore Budget Deals">
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Secondary CTA URL</label>
                <input name="cta2_url" value="<?= e($s['cta2_url'] ?? '') ?>" class="input" maxlength="255" placeholder="/shop?budget=1">
            </div>
        </div>
    </section>

    <!-- Images -->
    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold uppercase text-slate-400">Images &amp; Media</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Desktop background image</label>
                <input type="file" name="desktop_image" accept="image/jpeg,image/png,image/webp" class="input text-sm">
                <?php if (!empty($s['desktop_image'])): ?><img src="<?= e(url($s['desktop_image'])) ?>" alt="" class="mt-1 h-16 w-24 object-cover rounded"><?php endif; ?>
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Mobile background image</label>
                <input type="file" name="mobile_image" accept="image/jpeg,image/png,image/webp" class="input text-sm">
                <?php if (!empty($s['mobile_image'])): ?><img src="<?= e(url($s['mobile_image'])) ?>" alt="" class="mt-1 h-16 w-24 object-cover rounded"><?php endif; ?>
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Product foreground image (transparent)</label>
                <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp" class="input text-sm">
                <?php if (!empty($s['product_image'])): ?><img src="<?= e(url($s['product_image'])) ?>" alt="" class="mt-1 h-16 w-24 object-contain rounded bg-slate-100"><?php endif; ?>
            </div>
            <div class="space-y-1.5 sm:col-span-3">
                <label class="block text-sm font-medium text-slate-700">Image alt text</label>
                <input name="image_alt" value="<?= e($s['image_alt'] ?? '') ?>" class="input" maxlength="190" placeholder="Premium Dubai-imported laptop">
            </div>
        </div>
    </section>

    <!-- Styling -->
    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold uppercase text-slate-400">Layout &amp; Styling</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Background color</label>
                <div class="flex gap-2 items-center">
                    <input type="color" name="bg_color" value="<?= e($s['bg_color'] ?? '#01011D') ?>" class="h-10 w-14 rounded border border-slate-200">
                    <input type="text" value="<?= e($s['bg_color'] ?? '#01011D') ?>" class="input flex-1" oninput="document.querySelector('input[name=bg_color]').value=this.value">
                </div>
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Text color</label>
                <div class="flex gap-2 items-center">
                    <input type="color" id="text_color_picker" value="<?= e($s['text_color'] ?? '#ffffff') ?>" class="h-10 w-14 rounded border border-slate-200">
                    <input type="text" name="text_color" value="<?= e($s['text_color'] ?? '#ffffff') ?>" class="input flex-1" oninput="document.getElementById('text_color_picker').value=this.value">
                </div>
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Content position</label>
                <select name="content_position" class="input">
                    <option value="left" <?= ($s['content_position'] ?? 'left') === 'left' ? 'selected' : '' ?>>Left</option>
                    <option value="right" <?= ($s['content_position'] ?? '') === 'right' ? 'selected' : '' ?>>Right</option>
                    <option value="center" <?= ($s['content_position'] ?? '') === 'center' ? 'selected' : '' ?>>Center</option>
                </select>
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Image position</label>
                <select name="image_position" class="input">
                    <option value="right" <?= ($s['image_position'] ?? 'right') === 'right' ? 'selected' : '' ?>>Right</option>
                    <option value="left" <?= ($s['image_position'] ?? '') === 'left' ? 'selected' : '' ?>>Left</option>
                    <option value="center" <?= ($s['image_position'] ?? '') === 'center' ? 'selected' : '' ?>>Center</option>
                    <option value="background" <?= ($s['image_position'] ?? '') === 'background' ? 'selected' : '' ?>>Background (full-width)</option>
                </select>
            </div>
        </div>
    </section>

    <!-- Animation -->
    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold uppercase text-slate-400">Animation &amp; Behavior</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Transition style</label>
                <select name="transition_style" class="input">
                    <option value="fade" <?= ($s['transition_style'] ?? 'fade') === 'fade' ? 'selected' : '' ?>>Fade</option>
                    <option value="slide" <?= ($s['transition_style'] ?? '') === 'slide' ? 'selected' : '' ?>>Slide</option>
                    <option value="zoom" <?= ($s['transition_style'] ?? '') === 'zoom' ? 'selected' : '' ?>>Zoom</option>
                </select>
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Animation intensity</label>
                <select name="animation_intensity" class="input">
                    <option value="subtle" <?= ($s['animation_intensity'] ?? '') === 'subtle' ? 'selected' : '' ?>>Subtle</option>
                    <option value="normal" <?= ($s['animation_intensity'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Normal</option>
                    <option value="bold" <?= ($s['animation_intensity'] ?? '') === 'bold' ? 'selected' : '' ?>>Bold</option>
                </select>
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Slide duration (ms)</label>
                <input name="slide_duration" type="number" value="<?= e($s['slide_duration'] ?? 5000) ?>" class="input" min="2000" max="15000" step="500">
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-4">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="auto_rotate" value="1" <?= !isset($s['auto_rotate']) || (int)$s['auto_rotate'] === 1 ? 'checked' : '' ?> class="rounded"> Auto-rotate</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="show_nav" value="1" <?= !isset($s['show_nav']) || (int)$s['show_nav'] === 1 ? 'checked' : '' ?> class="rounded"> Show nav arrows</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="show_pagination" value="1" <?= !isset($s['show_pagination']) || (int)$s['show_pagination'] === 1 ? 'checked' : '' ?> class="rounded"> Show pagination dots</label>
        </div>
    </section>

    <!-- Visibility -->
    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold uppercase text-slate-400">Visibility &amp; Scheduling</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Start date (optional)</label>
                <input name="start_at" type="datetime-local" value="<?= e(substr($s['start_at'] ?? '', 0, 16)) ?>" class="input">
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">End date (optional)</label>
                <input name="end_at" type="datetime-local" value="<?= e(substr($s['end_at'] ?? '', 0, 16)) ?>" class="input">
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Sort order</label>
                <input name="sort_order" type="number" value="<?= e($s['sort_order'] ?? 0) ?>" class="input" min="0">
            </div>
            <div class="space-y-1.5 flex items-end">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" <?= !isset($s['is_active']) || (int)$s['is_active'] === 1 ? 'checked' : '' ?> class="rounded"> Active (show on homepage)</label>
            </div>
        </div>
    </section>

    <!-- SEO -->
    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold uppercase text-slate-400">SEO &amp; Accessibility</h2>
        <div class="mt-4 space-y-1.5">
            <label class="block text-sm font-medium text-slate-700">Accessibility description</label>
            <textarea name="accessibility_desc" rows="2" class="input" placeholder="Describe the slide for screen readers..."><?= e($s['accessibility_desc'] ?? '') ?></textarea>
        </div>
    </section>

    <div class="flex gap-3">
        <button type="submit" class="btn-primary"><?= $isEdit ? 'Save changes' : 'Create slide' ?></button>
        <a href="<?= e(url('/admin/hero-slides')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
