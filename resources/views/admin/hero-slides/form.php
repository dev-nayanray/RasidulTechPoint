<?php
/** @var array|null $slide @var array $errors @var array $presets @var int $maxSlides */
use App\Services\HeroSlideService;
$svc = new HeroSlideService();
$isEdit = $slide !== null;
$s = $slide ?? [];
// Normalize JSON fields for the form
$s['specs'] = isset($s['specs']) ? (is_array($s['specs']) ? $s['specs'] : (json_decode((string)$s['specs'], true) ?: [])) : [];
$s['chips'] = isset($s['chips']) ? (is_array($s['chips']) ? $s['chips'] : (json_decode((string)$s['chips'], true) ?: [])) : [];
// Ensure 3 slots for specs and chips
while (count($s['specs']) < 3) $s['specs'][] = '';
while (count($s['chips']) < 3) $s['chips'][] = ['value' => '', 'label' => ''];
$s['specs'] = array_slice($s['specs'], 0, 3);
$s['chips'] = array_slice($s['chips'], 0, 3);
?>
<div class="flex items-start justify-between mb-4">
    <div>
        <p class="text-sm text-slate-500"><a href="<?= e(url('/admin/hero-slides')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Hero Slides</a></p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900"><?= $isEdit ? 'Edit slide' : 'New slide' ?></h1>
    </div>
    <div class="flex gap-2">
        <a href="<?= e(url('/admin/hero-slides/' . ($s['id'] ?? 0) . '/preview')) ?>" target="_blank" class="btn-secondary text-sm" <?= !$isEdit ? 'style="pointer-events:none;opacity:.4"' : '' ?>>Preview</a>
    </div>
</div>

<?php $flash = array_filter($_SESSION['_flash'] ?? [], fn($k) => $k === 'success' || $k === 'error', ARRAY_FILTER_USE_KEY); ?>
<?php if (!empty($_SESSION['_flash']['success'])): ?>
<div class="mb-3 rounded-lg border-l-4 border-green-500 bg-green-50 p-3 text-sm text-green-800"><?= e($_SESSION['_flash']['success']) ?></div>
<?php endif; ?>
<?php if (!empty($_SESSION['_flash']['error'])): ?>
<div class="mb-3 rounded-lg border-l-4 border-red-500 bg-red-50 p-3 text-sm text-red-800"><?= e($_SESSION['_flash']['error']) ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-[1fr_minmax(0,520px)] gap-6">
    <!-- Editor form -->
    <form method="post" action="<?= e($isEdit ? url('/admin/hero-slides/' . $s['id'] . '/update') : url('/admin/hero-slides')) ?>" enctype="multipart/form-data" id="hero-slide-form" class="space-y-5">
        <?= csrf_field() ?>

        <!-- CONTENT -->
        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Content</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Nav label *</label>
                    <input name="nav_label" value="<?= e($s['nav_label'] ?? '') ?>" class="input" maxlength="20" placeholder="Aero 14" required>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Badge text</label>
                    <input name="badge_text" value="<?= e($s['badge_text'] ?? '') ?>" class="input" maxlength="24" placeholder="New arrival">
                </div>
                <div class="space-y-1.5 sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Category label</label>
                    <input name="category_label" value="<?= e($s['category_label'] ?? '') ?>" class="input" maxlength="40" placeholder="Ultrabook / 14 inch">
                </div>
                <div class="space-y-1.5 sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Headline *</label>
                    <input name="headline" value="<?= e($s['headline'] ?? '') ?>" class="input" maxlength="80" placeholder="1.12 kg of all-day power." required>
                </div>
                <div class="space-y-1.5 sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Description</label>
                    <textarea name="description" rows="2" class="input" maxlength="220" placeholder="The Aero 14 pairs a 2.8K OLED panel with 18 hours of battery..."><?= e($s['description'] ?? '') ?></textarea>
                </div>
            </div>
        </section>

        <!-- SPECS & CHIPS -->
        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Specs &amp; chips</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <?php foreach ($s['specs'] as $i => $spec): ?>
                <div class="space-y-1.5">
                    <label class="block text-xs font-medium text-slate-500">Spec <?= $i + 1 ?></label>
                    <input name="specs[<?= $i ?>]" value="<?= e(is_array($spec) ? ($spec['text'] ?? '') : $spec) ?>" class="input" maxlength="40" placeholder="16 GB LPDDR5X">
                </div>
                <?php endforeach; ?>
            </div>
            <hr class="my-4 border-slate-100">
            <div class="space-y-3">
                <?php foreach ($s['chips'] as $i => $chip): ?>
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1.5">
                        <label class="block text-xs font-medium text-slate-500">Chip <?= $i + 1 ?> value</label>
                        <input name="chips[<?= $i ?>][value]" value="<?= e($chip['value'] ?? '') ?>" class="input" maxlength="20" placeholder="2.8K OLED">
                    </div>
                    <div class="space-y-1.5">
                        <label class="block text-xs font-medium text-slate-500">Chip <?= $i + 1 ?> label</label>
                        <input name="chips[<?= $i ?>][label]" value="<?= e($chip['label'] ?? '') ?>" class="input" maxlength="30" placeholder="120 Hz panel">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- PRICING -->
        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Pricing</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Currency symbol</label>
                    <input name="currency_symbol" value="<?= e($s['currency_symbol'] ?? '$') ?>" class="input" maxlength="8" placeholder="$">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Price</label>
                    <input name="price" type="number" step="0.01" min="0" value="<?= e($s['price'] ?? '') ?>" class="input" placeholder="1299">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Old price (optional)</label>
                    <input name="old_price" type="number" step="0.01" min="0" value="<?= e($s['old_price'] ?? '') ?>" class="input" placeholder="1499">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Installment months</label>
                    <input name="installment_months" type="number" min="0" value="<?= e($s['installment_months'] ?? '') ?>" class="input" placeholder="12">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Monthly price (auto-calc if blank)</label>
                    <input name="monthly_price" type="number" step="0.01" min="0" value="<?= e($s['monthly_price'] ?? '') ?>" class="input" placeholder="108">
                </div>
                <div class="space-y-1.5 sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Installment note</label>
                    <input name="installment_note" value="<?= e($s['installment_note'] ?? '') ?>" class="input" maxlength="120" placeholder="0% interest, free setup">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Ghost word</label>
                    <input name="ghost_word" value="<?= e($s['ghost_word'] ?? '') ?>" class="input" maxlength="40" placeholder="Aero (defaults to first word of nav_label)">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Link to product (optional)</label>
                    <input name="product_id" type="number" value="<?= e($s['product_id'] ?? '') ?>" class="input" placeholder="Auto-fill price/URL from this product">
                </div>
            </div>
        </section>

        <!-- BUTTONS -->
        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Buttons</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Primary CTA label *</label>
                    <input name="cta1_label" value="<?= e($s['cta1_label'] ?? 'Shop Now') ?>" class="input" maxlength="60" required>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Primary CTA URL *</label>
                    <input name="cta1_url" value="<?= e($s['cta1_url'] ?? '/shop') ?>" class="input" maxlength="255" required>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Secondary CTA label</label>
                    <input name="cta2_label" value="<?= e($s['cta2_label'] ?? '') ?>" class="input" maxlength="60" placeholder="Compare specs">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Secondary CTA URL</label>
                    <input name="cta2_url" value="<?= e($s['cta2_url'] ?? '') ?>" class="input" maxlength="255" placeholder="/shop?compare=1">
                </div>
            </div>
        </section>

        <!-- APPEARANCE -->
        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Appearance</h2>
            <div class="mt-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Theme preset</label>
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                        <?php foreach (HeroSlideService::presets() as $name => $t): ?>
                        <label class="cursor-pointer relative rounded-lg border p-2 text-xs text-center <?= (($s['theme_preset'] ?? 'mist_teal') === $name) ? 'border-blue-500 ring-2 ring-blue-200' : 'border-slate-200' ?>">
                            <input type="radio" name="theme_preset" value="<?= e($name) ?>" class="sr-only" data-theme-preset="<?= e($name) ?>" <?= (($s['theme_preset'] ?? 'mist_teal') === $name) ? 'checked' : '' ?>>
                            <div class="w-full h-6 rounded mb-1" style="background:<?= e($t['bg']) ?>"></div>
                            <span class="block" style="color:<?= e($t['ink']) ?>"><?= e(str_replace('_', ' ', $name)) ?></span>
                            <span class="block mt-1" style="color:<?= e($t['accent']) ?>">accent</span>
                        </label>
                        <?php endforeach; ?>
                        <label class="cursor-pointer relative rounded-lg border p-2 text-xs text-center <?= (($s['theme_preset'] ?? '') === 'custom') ? 'border-blue-500 ring-2 ring-blue-200' : 'border-slate-200' ?>">
                            <input type="radio" name="theme_preset" value="custom" class="sr-only" data-theme-preset="custom" <?= (($s['theme_preset'] ?? '') === 'custom') ? 'checked' : '' ?>>
                            <div class="w-full h-6 rounded mb-1 bg-gradient-to-br from-pink-200 via-yellow-200 to-blue-200"></div>
                            <span>custom</span>
                        </label>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3 <?= (($s['theme_preset'] ?? '') === 'custom') ? '' : 'hidden' ?>" id="custom-theme-block">
                    <?php
                    $colorFields = [
                        'custom_bg' => ['label' => 'Background', 'default' => '#dce6ec'],
                        'custom_bg2' => ['label' => 'Background 2 (gradient)', 'default' => '#f3f8fa'],
                        'custom_ink' => ['label' => 'Ink (text)', 'default' => '#0d1b26'],
                        'custom_muted' => ['label' => 'Muted text', 'default' => '#3b4d5b'],
                        'custom_accent' => ['label' => 'Accent', 'default' => '#086560'],
                        'custom_accent_ink' => ['label' => 'Accent text', 'default' => '#ffffff'],
                    ];
                    foreach ($colorFields as $field => $cfg): ?>
                    <div class="space-y-1.5">
                        <label class="block text-xs font-medium text-slate-500"><?= e($cfg['label']) ?></label>
                        <div class="flex gap-2 items-center">
                            <input type="color" data-color-picker="<?= e($field) ?>" value="<?= e($s[$field] ?? $cfg['default']) ?>" class="h-9 w-12 rounded border border-slate-200">
                            <input type="text" name="<?= e($field) ?>" value="<?= e($s[$field] ?? $cfg['default']) ?>" class="input flex-1" data-color-input="<?= e($field) ?>" maxlength="9" pattern="^#[0-9a-fA-F]{6}$">
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="sm:col-span-3 mt-2">
                        <div id="contrast-warnings" class="text-xs text-amber-700 space-y-1"></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- VISUAL -->
        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Visual</h2>
            <div class="mt-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Screen visual</label>
                    <select name="visual_type" class="input">
                        <?php foreach (['screen_dunes' => 'Soft teal dunes (lifestyle)', 'screen_gaming' => 'Gaming HUD + perspective grid', 'screen_creator' => 'Color grading + timeline', 'screen_business' => 'Dashboard + bar chart', 'image' => 'Custom image upload'] as $v => $lbl): ?>
                        <option value="<?= e($v) ?>" <?= (($s['visual_type'] ?? 'screen_dunes') === $v) ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="space-y-1.5 <?= (($s['visual_type'] ?? '') === 'image') ? '' : 'hidden' ?>" id="image-upload-block">
                    <label class="block text-sm font-medium text-slate-700">Laptop image (jpg/png/webp/avif, ≤ 2 MiB)</label>
                    <input type="file" name="laptop_image_url" accept="image/jpeg,image/png,image/webp,image/avif" class="input text-sm">
                    <?php if (!empty($s['laptop_image_url'])): ?>
                        <img src="<?= e(url($s['laptop_image_url'])) ?>" alt="" class="mt-1 h-16 w-24 object-contain rounded bg-slate-100">
                        <input type="hidden" name="laptop_image_url_existing" value="<?= e($s['laptop_image_url']) ?>">
                    <?php endif; ?>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Image alt text</label>
                    <input name="laptop_image_alt" value="<?= e($s['laptop_image_alt'] ?? '') ?>" class="input" maxlength="190" placeholder="Aero 14 magnesium ultrabook">
                </div>
            </div>
        </section>

        <!-- SCHEDULE & STATUS -->
        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Schedule &amp; status</h2>
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
                    <label class="block text-sm font-medium text-slate-700">Status</label>
                    <select name="status" class="input">
                        <option value="draft"     <?= (($s['status'] ?? 'draft') === 'draft')     ? 'selected' : '' ?>>Draft (hidden on site)</option>
                        <option value="published" <?= (($s['status'] ?? 'draft') === 'published') ? 'selected' : '' ?>>Published</option>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Active</label>
                    <label class="flex items-center gap-2 text-sm pt-6">
                        <input type="checkbox" name="is_active" value="1" <?= !isset($s['is_active']) || (int)$s['is_active'] === 1 ? 'checked' : '' ?> class="rounded">
                        Show this slide on the live site (must also be Published)
                    </label>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Sort order</label>
                    <input name="sort_order" type="number" min="0" value="<?= e($s['sort_order'] ?? 0) ?>" class="input">
                </div>
                <div class="space-y-1.5 sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Accessibility description</label>
                    <textarea name="accessibility_desc" rows="2" class="input" maxlength="500" placeholder="Describe the slide for screen readers..."><?= e($s['accessibility_desc'] ?? '') ?></textarea>
                </div>
            </div>
        </section>

        <!-- Save -->
        <div class="sticky bottom-0 bg-white border-t border-slate-200 -mx-5 px-5 py-3 flex gap-3 items-center z-10">
            <button type="submit" class="btn-primary" id="save-btn"><?= $isEdit ? 'Save changes' : 'Create slide' ?></button>
            <a href="<?= e(url('/admin/hero-slides')) ?>" class="btn-secondary">Cancel</a>
            <span class="text-xs text-slate-400 ml-auto" id="unsaved-warn" hidden>You have unsaved changes.</span>
        </div>
    </form>

    <!-- LIVE PREVIEW -->
    <aside class="lg:sticky lg:top-4 self-start">
        <div class="rounded-xl border border-slate-200 bg-slate-900 p-3">
            <div class="flex items-center justify-between mb-2">
                <p class="text-xs font-semibold text-slate-300">Live preview</p>
                <div class="flex gap-1">
                    <button type="button" class="px-2 py-1 text-xs rounded bg-slate-700 text-white" data-w="desktop">Desktop</button>
                    <button type="button" class="px-2 py-1 text-xs rounded bg-slate-800 text-slate-400" data-w="phone">Phone</button>
                </div>
            </div>
            <div class="rounded-lg overflow-hidden bg-slate-950">
                <iframe id="hero-preview-iframe" class="w-full" style="height:480px;border:0" src="<?= $isEdit ? e(url('/admin/hero-slides/' . $s['id'] . '/preview')) : 'about:blank' ?>"></iframe>
            </div>
            <p class="text-[10px] text-slate-500 mt-2">Preview uses the exact same component as the public homepage.</p>
        </div>
    </aside>
</div>

<script type="module" src="<?= e(url('assets/js/admin-hero-form.js')) ?>"></script>
