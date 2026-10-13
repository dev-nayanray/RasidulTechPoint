<?php
/**
 * Full hero slider section, included into storefront/home.php.
 *
 * Architecture mirrors the reference (hero-slider.html): every slide gets a
 * generated theme class .hs-t{i} holding its full 15-token palette, the hero
 * ROOT carries the active slide's class so the chrome (indicators, controls,
 * backgrounds) re-tints on every change, and hero-slider.js swaps that class
 * in go(). Each .hs-bg layer and .hs-slide also keeps its own class so the
 * outgoing slide holds its palette through the 500ms fade.
 *
 * Behavior lives in public/assets/js/hero-slider.js. It must be an external
 * file: the site CSP is "script-src 'self'", which blocks inline scripts. The
 * settings it needs are passed as data-* attributes on #hero.
 *
 * The thin announcement bar from the reference is NOT rendered here: the
 * homepage's own site-wide bar sits directly above the header and is fed from
 * the same hero settings (see home.php), which keeps one bar, not two.
 *
 * @var array $heroSlides   Hydrated slide rows from HeroSlideService::published().
 * @var array $heroSettings Settings array from HeroSlideService::settings().
 */
use App\Services\HeroSlideService;
use App\Support\View;

if (empty($heroSlides)) {
    // No slides available: fallback handled by home.php (renders the static hero).
    return;
}
$svc = new HeroSlideService();
$total = count($heroSlides);
$showControls = $total > 1;
$dur = max(3000, min(20000, (int) ($heroSettings['autoplay_interval_ms'] ?? 7000)));
$autoplay = !empty($heroSettings['autoplay_enabled']) && $showControls;
$pauseHover = !array_key_exists('pause_on_hover', $heroSettings) || !empty($heroSettings['pause_on_hover']);
?>
<style id="hero-theme-tokens">
<?php foreach ($heroSlides as $i => $slide): ?>
.hs-t<?= (int) $i ?>{<?= $svc->cssVars($slide) ?>}
<?php endforeach; ?>
</style>
<section class="hs-hero hs-t0<?= $autoplay ? '' : ' is-paused' ?>" id="hero" role="region" aria-roledescription="carousel" aria-label="Featured laptops" style="--hero-dur:<?= (int) $dur ?>ms"
  data-hero data-autoplay="<?= $autoplay ? '1' : '0' ?>" data-interval="<?= (int) $dur ?>" data-pause-hover="<?= $pauseHover ? '1' : '0' ?>">
  <div class="hs-bgs" aria-hidden="true">
    <?php foreach ($heroSlides as $i => $slide): ?>
      <div class="hs-bg hs-t<?= (int) $i ?><?= $i === 0 ? ' is-active' : '' ?>"></div>
    <?php endforeach; ?>
  </div>

  <div class="hs-slides" aria-live="<?= $autoplay ? 'off' : 'polite' ?>" data-hero-slides>
    <?php foreach ($heroSlides as $i => $slide):
        echo View::partial('storefront/hero/_slide', [
            'slide' => $slide,
            'i' => $i,
            'isActive' => $i === 0,
            'total' => $total,
        ]);
    endforeach; ?>
  </div>

  <?php if ($showControls): ?>
  <div class="hs-bar">
    <div class="hs-wrap">
      <ol class="hs-inds" aria-label="Choose a slide">
        <?php foreach ($heroSlides as $i => $slide):
            $nav = $slide['nav_label'] ?? 'Slide';
            $num = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT); ?>
          <li class="hs-ind<?= $i === 0 ? ' is-active' : '' ?>">
            <button type="button" data-go="<?= (int) $i ?>" aria-label="Show <?= e($nav) ?>, slide <?= $i + 1 ?> of <?= $total ?>" <?= $i === 0 ? 'aria-current="true"' : '' ?>>
              <span class="hs-track"><span class="hs-fill"></span></span>
              <span class="hs-lab"><i><?= $num ?></i><span class="hs-nm"><?= e($nav) ?></span></span>
            </button>
          </li>
        <?php endforeach; ?>
      </ol>
      <div class="hs-ctrls">
        <button class="hs-round" type="button" data-prev aria-label="Previous slide">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>
        </button>
        <button class="hs-round" type="button" data-toggle aria-label="<?= $autoplay ? 'Pause slideshow' : 'Play slideshow' ?>">
          <svg class="i-pause" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"<?= $autoplay ? '' : ' hidden' ?>><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>
          <svg class="i-play" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"<?= $autoplay ? ' hidden' : '' ?>><path d="M7 4l13 8-13 8z"/></svg>
        </button>
        <button class="hs-round" type="button" data-next aria-label="Next slide">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
        </button>
      </div>
    </div>
  </div>
  <?php endif; ?>
</section>
