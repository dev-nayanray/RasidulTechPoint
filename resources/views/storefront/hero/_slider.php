<?php
/**
 * Full hero slider section — included into storefront/home.php.
 *
 * @var array  $heroSlides   Hydrated slide rows from HeroSlideService::published().
 * @var array  $heroSettings Settings array from HeroSlideService::settings().
 * @var bool   $heroEnabled  True when the slider should render at all.
 */
use App\Services\HeroSlideService;

if (empty($heroSlides)) {
    // No slides available — fallback handled by home.php (renders the static hero).
    return;
}
$svc = new HeroSlideService();
$total = count($heroSlides);
$showControls = $total > 1;
$dur = (int) ($heroSettings['autoplay_interval_ms'] ?? 7000);
$autoplay = !empty($heroSettings['autoplay_enabled']) && $showControls;
$announcementItems = !empty($heroSettings['announcement_enabled']) ? ($heroSettings['announcement_items'] ?? []) : [];
?>
<section class="hero <?= $autoplay ? '' : 'is-paused' ?>" id="hero" role="region" aria-roledescription="carousel" aria-label="Featured laptops" style="--hero-dur:<?= e((string) $dur) ?>ms">
  <div class="bgs" aria-hidden="true">
    <?php foreach ($heroSlides as $i => $slide):
        $tokens = $svc->themeTokens($slide);
        $bgStyle = sprintf('--bg:%s;--bg2:%s;--dot:color-mix(in srgb,%s 16%%,transparent);', e($tokens['bg']), e($tokens['bg2']), e($tokens['ink'])); ?>
      <div class="bg hs-bg-<?= (int) $i ?><?= $i === 0 ? ' is-active' : '' ?>" style="<?= $bgStyle ?>"></div>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($announcementItems)): ?>
  <div class="announce">
    <?php foreach ($announcementItems as $item): ?><span><?= e($item) ?></span><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="slides" aria-live="<?= $autoplay ? 'off' : 'polite' ?>" data-hero-slides>
    <?php foreach ($heroSlides as $i => $slide):
        echo View::partial('storefront/hero/_slide', [
            'slide' => $slide,
            'i' => $i,
            'isActive' => $i === 0,
            'total' => $total,
        ]);
    endforeach; ?>
  </div>

  <div class="bar">
    <div class="wrap">
      <?php if ($showControls): ?>
      <ol class="inds" aria-label="Choose a slide">
        <?php foreach ($heroSlides as $i => $slide):
            $nav = $slide['nav_label'] ?? 'Slide';
            $num = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT); ?>
          <li class="ind<?= $i === 0 ? ' is-active' : '' ?>">
            <button type="button" data-go="<?= (int) $i ?>" aria-label="Show <?= e($nav) ?>, slide <?= $i + 1 ?> of <?= $total ?>" <?= $i === 0 ? 'aria-current="true"' : '' ?>>
              <span class="track"><span class="fill"></span></span>
              <span class="lab"><i><?= $num ?></i><span class="nm"><?= e($nav) ?></span></span>
            </button>
          </li>
        <?php endforeach; ?>
      </ol>
      <div class="ctrls">
        <button class="round" type="button" data-prev aria-label="Previous slide">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>
        </button>
        <button class="round" type="button" data-toggle aria-label="<?= $autoplay ? 'Pause slideshow' : 'Play slideshow' ?>">
          <?php if ($autoplay): ?>
            <svg class="i-pause" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>
            <svg class="i-play" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" hidden><path d="M7 4l13 8-13 8z"/></svg>
          <?php else: ?>
            <svg class="i-play" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>
            <svg class="i-pause" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" hidden><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>
          <?php endif; ?>
        </button>
        <button class="round" type="button" data-next aria-label="Next slide">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
        </button>
      </div>
      <?php else: ?>
      <div class="ctrls" aria-hidden="true">
        <button class="round" type="button" disabled aria-hidden="true" style="opacity:.4;cursor:default"></button>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
