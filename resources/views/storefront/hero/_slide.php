<?php
/**
 * Single hero slide markup (public).
 *
 * The slide's palette comes from its generated theme class .hs-t{i}
 * (defined by _slider.php from HeroSlideService::cssVars()). Keeping the
 * class ON the slide means the outgoing slide holds its own colors through
 * the 500ms fade even after the hero root has switched to the next theme.
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
$ghost = $slide['ghost_word'] ?: ($nav !== '' ? explode(' ', trim($nav))[0] : '');

// Keep hyphenated compounds ("all-day", "MIL-STD-810H") on one line, like
// the reference's <span style="white-space:nowrap">all-day</span>.
$headlineHtml = preg_replace(
    '/([\pL\pN][\pL\pN-]*-[\pL\pN-]*)/u',
    '<span style="white-space:nowrap">$1</span>',
    e($headline)
);

$ariaLabel = ($i + 1) . ' of ' . ($total ?? ($i + 1)) . ': ' . $nav;
$activeClass = $isActive ? ' is-active' : '';
$ariaHidden = $isActive ? '' : ' aria-hidden="true" inert';
?>
<article class="slide hs-t<?= (int) $i ?><?= $activeClass ?>" role="group" aria-roledescription="slide" aria-label="<?= e($ariaLabel) ?>"<?= $ariaHidden ?> data-theme="<?= e($slide['theme_preset'] ?? 'mist_teal') ?>">
  <div class="wrap grid">
    <div class="copy">
      <?php if ($badge || $category): ?>
      <p class="eyebrow rise" style="--d:0">
        <?php if ($badge): ?><span class="badge"><?= e($badge) ?></span><?php endif; ?>
        <?php if ($category): ?><span><?= e($category) ?></span><?php endif; ?>
      </p>
      <?php endif; ?>
      <h2 class="hs-head rise" style="--d:1"><?= $headlineHtml ?></h2>
      <?php if ($description): ?><p class="lede rise" style="--d:2"><?= e($description) ?></p><?php endif; ?>
      <?php if ($specs): ?>
      <ul class="specs rise" style="--d:3"><?php foreach ($specs as $sp): ?><li><?= e($sp) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <?php if (!empty($slide['price'])): ?>
      <div class="buy rise" style="--d:4">
        <div>
          <span class="price"><?= e($slide['currency_symbol'] ?: '$') ?><?= e(number_format((float) $slide['price'], $slide['currency_symbol'] === '$' ? 0 : 0)) ?></span>
          <?php if (!empty($slide['old_price'])): ?><span class="was"><?= e($slide['currency_symbol'] ?: '$') ?><?= e(number_format((float) $slide['old_price'], $slide['currency_symbol'] === '$' ? 0 : 0)) ?></span><?php endif; ?>
        </div>
        <?php if (!empty($slide['monthly_price']) && !empty($slide['installment_months'])): ?>
        <p class="emi">or <b><?= e($slide['currency_symbol'] ?: '$') ?><?= e(number_format((float) $slide['monthly_price'], 0)) ?>/mo</b> for <?= (int) $slide['installment_months'] ?> months<br><?= e($slide['installment_note'] ?? '') ?></p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="cta-row rise" style="--d:5">
        <?php if (!empty($slide['cta1_label']) && !empty($slide['cta1_url'])): ?>
        <a class="btn btn-primary" href="<?= e(url($slide['cta1_url'])) ?>"><?= e($slide['cta1_label']) ?> <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
        <?php endif; ?>
        <?php if (!empty($slide['cta2_label']) && !empty($slide['cta2_url'])): ?>
        <a class="btn btn-ghost" href="<?= e(url($slide['cta2_url'])) ?>"><?= e($slide['cta2_label']) ?></a>
        <?php endif; ?>
      </div>
    </div>
    <div class="visual">
      <?php if ($ghost): ?><span class="ghost" aria-hidden="true"><?= e($ghost) ?></span><?php endif; ?>
      <span class="glow" aria-hidden="true"></span>
      <div class="stage">
        <div class="laptop">
          <div class="lid"><div class="screen">
            <?php if (($slide['visual_type'] ?? 'screen_dunes') === 'image' && !empty($slide['laptop_image_url'])): ?>
              <img src="<?= e(url($slide['laptop_image_url'])) ?>" alt="<?= e($slide['laptop_image_alt'] ?? '') ?>" loading="lazy">
            <?php else: ?>
              <?= (new HeroSlideService())->screenSvg($slide) ?>
            <?php endif; ?>
          </div></div>
          <div class="base"></div>
          <span class="floor"></span>
        </div>
        <?php foreach ($chips as $ci => $chip): $class = 'c' . ($ci + 1); ?>
          <div class="chip <?= $class ?>" style="--d:<?= 5 + $ci ?>"><b><?= e($chip['value'] ?? '') ?></b><?= e($chip['label'] ?? '') ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</article>
