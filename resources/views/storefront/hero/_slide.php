<?php
/**
 * Single hero slide markup (public + admin preview).
 *
 * The slide's palette comes from its generated theme class .hs-t{i}
 * (defined by _slider.php from HeroSlideService::cssVars()). Keeping the
 * class ON the slide means the outgoing slide holds its own colors through
 * the 500ms fade even after the hero root has switched to the next theme.
 *
 * All classes are hs- prefixed so the storefront stylesheet cannot leak in.
 *
 * @var array  $slide     The slide row, hydrated by HeroSlideService::find().
 * @var int    $i         0-indexed position in the slider.
 * @var bool   $isActive  True when this slide is the initial active slide.
 * @var ?int   $total     Total slide count for aria labels (null when unknown).
 */
use App\Services\HeroSlideService;

$nav = $slide['nav_label'] ?? ($slide['internal_name'] ?? 'Slide');
$headline = $slide['headline'] ?? '';
$description = $slide['description'] ?? '';
$badge = $slide['badge_text'] ?? '';
$category = $slide['category_label'] ?? '';
$specs = is_array($slide['specs'] ?? null) ? $slide['specs'] : [];
$chips = is_array($slide['chips'] ?? null) ? $slide['chips'] : [];
$ghost = ($slide['ghost_word'] ?? '') ?: ($nav !== '' ? explode(' ', trim($nav))[0] : '');
$symbol = ($slide['currency_symbol'] ?? '') ?: '$';

// Keep hyphenated compounds ("all-day", "MIL-STD-810H") on one line, like
// the reference's nowrap span.
$headlineHtml = preg_replace(
    '/([\pL\pN][\pL\pN-]*-[\pL\pN-]*)/u',
    '<span class="hs-nw">$1</span>',
    e($headline)
);

// CTA links: absolute http(s) URLs and in-page anchors are used as saved;
// relative paths go through url() so subdirectory installs keep working.
$href = static function (string $u): string {
    $u = trim($u);
    if ($u === '' || $u[0] === '#' || preg_match('#^(https?:)?//#i', $u) === 1) {
        return $u;
    }
    return url($u);
};
$money = static fn ($v): string => $symbol . number_format((float) $v, 0);

$ariaLabel = ($i + 1) . ' of ' . ($total ?? ($i + 1)) . ': ' . $nav;
$activeClass = $isActive ? ' is-active' : '';
// Do NOT set inert as a bare HTML attribute here. hero-slider.js sets it via
// el.inert at runtime (the spec-compliant way); until then inactive slides are
// already hidden with visibility:hidden.
$ariaHidden = $isActive ? '' : ' aria-hidden="true"';
?>
<article class="hs-slide hs-t<?= (int) $i ?><?= $activeClass ?>" role="group" aria-roledescription="slide" aria-label="<?= e($ariaLabel) ?>"<?= $ariaHidden ?> data-theme="<?= e($slide['theme_preset'] ?? 'mist_teal') ?>">
  <div class="hs-wrap hs-grid">
    <div class="hs-copy">
      <?php if ($badge || $category): ?>
      <p class="hs-eyebrow hs-rise" style="--d:0">
        <?php if ($badge): ?><span class="hs-badge"><?= e($badge) ?></span><?php endif; ?>
        <?php if ($category): ?><span><?= e($category) ?></span><?php endif; ?>
      </p>
      <?php endif; ?>
      <h2 class="hs-head hs-rise" style="--d:1"><?= $headlineHtml ?></h2>
      <?php if ($description): ?><p class="hs-lede hs-rise" style="--d:2"><?= e($description) ?></p><?php endif; ?>
      <?php if ($specs): ?>
      <ul class="hs-specs hs-rise" style="--d:3"><?php foreach ($specs as $sp): ?><li><?= e($sp) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <?php if (!empty($slide['price'])): ?>
      <div class="hs-buy hs-rise" style="--d:4">
        <div>
          <span class="hs-price"><?= e($money($slide['price'])) ?></span>
          <?php if (!empty($slide['old_price'])): ?><span class="hs-was"><?= e($money($slide['old_price'])) ?></span><?php endif; ?>
        </div>
        <?php if (!empty($slide['monthly_price']) && !empty($slide['installment_months'])): ?>
        <p class="hs-emi">or <b><?= e($money($slide['monthly_price'])) ?>/mo</b> for <?= (int) $slide['installment_months'] ?> months<?php if (!empty($slide['installment_note'])): ?><br><?= e($slide['installment_note']) ?><?php endif; ?></p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="hs-cta hs-rise" style="--d:5">
        <?php if (!empty($slide['cta1_label']) && !empty($slide['cta1_url'])): ?>
        <a class="hs-btn hs-btn-primary" href="<?= e($href((string) $slide['cta1_url'])) ?>"><?= e($slide['cta1_label']) ?> <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
        <?php endif; ?>
        <?php if (!empty($slide['cta2_label']) && !empty($slide['cta2_url'])): ?>
        <a class="hs-btn hs-btn-ghost" href="<?= e($href((string) $slide['cta2_url'])) ?>"><?= e($slide['cta2_label']) ?></a>
        <?php endif; ?>
      </div>
    </div>
    <div class="hs-visual">
      <?php if ($ghost): ?><span class="hs-ghost" aria-hidden="true"><?= e($ghost) ?></span><?php endif; ?>
      <span class="hs-glow" aria-hidden="true"></span>
      <div class="hs-stage">
        <div class="hs-laptop">
          <div class="hs-lid"><div class="hs-screen">
            <?php if (($slide['visual_type'] ?? 'screen_dunes') === 'image' && !empty($slide['laptop_image_url'])): ?>
              <img src="<?= e(url($slide['laptop_image_url'])) ?>" alt="<?= e($slide['laptop_image_alt'] ?? '') ?>" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>" decoding="async">
            <?php else: ?>
              <?= (new HeroSlideService())->screenSvg($slide) ?>
            <?php endif; ?>
          </div></div>
          <div class="hs-base"></div>
          <span class="hs-floor"></span>
        </div>
        <?php foreach ($chips as $ci => $chip): ?>
          <div class="hs-chip hs-c<?= (int) $ci + 1 ?>" style="--d:<?= 5 + (int) $ci ?>"><b><?= e($chip['value'] ?? '') ?></b><?= e($chip['label'] ?? '') ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</article>
