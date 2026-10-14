/**
 * Product detail gallery: swap the main image when a thumbnail is clicked.
 * Wired through .product-gallery-thumb[data-full-src] buttons.
 */
export function initProductGallery() {
  const main = document.getElementById('product-main-image');
  if (!main || !(main instanceof HTMLImageElement)) {
    return;
  }
  const thumbs = document.querySelectorAll('.product-gallery-thumb[data-full-src]');
  if (thumbs.length === 0) {
    return;
  }

  const select = function (btn) {
    main.src = btn.dataset.fullSrc || main.src;
    if (btn.dataset.fullAlt) {
      main.alt = btn.dataset.fullAlt;
    }
    thumbs.forEach(function (t) {
      t.classList.toggle('ring-brand-blue', t === btn);
      t.classList.toggle('ring-slate-200', t !== btn);
    });
  };

  thumbs.forEach(function (btn) {
    btn.addEventListener('click', function () { select(btn); });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initProductGallery);
} else {
  initProductGallery();
}
