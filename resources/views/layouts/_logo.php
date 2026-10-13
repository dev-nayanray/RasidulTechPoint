<?php
/**
 * Brand lockup (icon + wordmark) shared by every storefront header and footer.
 *
 * The icon is a committed SVG (public/assets/img/logo-mark.svg). It used to be
 * an uploaded JPEG, but public/uploads is gitignored, so the file never
 * existed on a fresh deploy and the header showed a broken image.
 *
 * @var string $variant 'light' for a white header, 'dark' for the navy footer.
 */
$variant = ($variant ?? 'light') === 'dark' ? 'dark' : 'light';
?>
<a class="logo rtp-logo rtp-logo--<?= e($variant) ?>" href="<?= e(url('/')) ?>" aria-label="Rasidul Tech Point home">
  <img class="rtp-mark" src="<?= e(url('assets/img/logo-mark.svg')) ?>" width="42" height="42" alt="" decoding="async">
  <span><span class="wm-a">Rasidul</span><span class="wm-b">TECH POINT</span></span>
</a>
