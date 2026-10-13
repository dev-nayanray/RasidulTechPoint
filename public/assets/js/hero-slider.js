/**
 * Homepage hero slider behavior.
 *
 * Why this is an external file: the site Content-Security-Policy is
 * "script-src 'self'", so an inline <script> in home.php is silently blocked.
 * That is what stopped autoplay and left only slide 1 reachable. Settings come
 * from data-* attributes on #hero (see resources/views/storefront/hero/_slider.php):
 *
 *   data-autoplay="1|0"      autoplay on at load (admin setting)
 *   data-interval="7000"     milliseconds per slide
 *   data-pause-hover="1|0"   pause while a mouse is over the hero
 *
 * Autoplay is driven by one requestAnimationFrame loop that fills the active
 * progress bar. Pausing (hover, keyboard focus, hidden tab, pause button)
 * freezes the bar and resuming continues from the same point.
 *
 * Reduced motion: CSS removes movement and fades. Autoplay still runs because
 * the pause button is always visible, so the slider never appears frozen.
 */

const THEME_RE = /^hs-t\d+$/;
const MAX_FRAME_MS = 250; // ignore huge gaps (throttled tabs) so the bar never jumps

export function initHeroSlider(hero) {
  const slides = Array.from(hero.querySelectorAll('.hs-slide'));
  if (slides.length === 0) return null;

  const bgs = Array.from(hero.querySelectorAll('.hs-bg'));
  const inds = Array.from(hero.querySelectorAll('.hs-ind'));
  const fills = inds.map((li) => li.querySelector('.hs-fill'));
  const viewport = hero.querySelector('[data-hero-slides]');
  const toggle = hero.querySelector('[data-toggle]');
  const count = slides.length;

  const duration = Math.max(3000, Math.min(20000, parseInt(hero.dataset.interval, 10) || 7000));
  const pauseOnHover = hero.dataset.pauseHover !== '0';
  const canAutoplay = hero.dataset.autoplay === '1' && count > 1;

  let cur = 0;
  let userPlaying = canAutoplay;
  let hover = false;
  let focusPause = false;
  let elapsed = 0;
  let lastTs = 0;
  let raf = 0;

  const themeOf = (el) => Array.from(el.classList).find((c) => THEME_RE.test(c)) || '';

  function setFill(index, ratio) {
    const f = fills[index];
    if (f) f.style.transform = 'scaleX(' + ratio + ')';
  }

  function isRunning() {
    return userPlaying && count > 1 && !(pauseOnHover && hover) && !focusPause && !document.hidden;
  }

  function sync() {
    const running = isRunning();
    hero.classList.toggle('is-paused', !running);
    if (viewport) viewport.setAttribute('aria-live', running ? 'off' : 'polite');
    if (running && !raf) {
      lastTs = performance.now();
      raf = requestAnimationFrame(tick);
    } else if (!running && raf) {
      cancelAnimationFrame(raf);
      raf = 0;
    }
  }

  function tick(ts) {
    raf = 0;
    if (!isRunning()) return;
    elapsed += Math.min(MAX_FRAME_MS, Math.max(0, ts - lastTs));
    lastTs = ts;
    if (elapsed >= duration) {
      go(cur + 1, 1);
      return;
    }
    setFill(cur, elapsed / duration);
    raf = requestAnimationFrame(tick);
  }

  function go(n, dir) {
    if (count <= 1) return;
    n = ((n % count) + count) % count;
    if (n === cur) return;

    hero.style.setProperty('--dir', String(dir || (n > cur ? 1 : -1)));

    // The root swaps theme class so the bar, buttons and backgrounds re-tint.
    // Each slide and background keeps its own class so the outgoing one holds
    // its palette during the fade.
    const from = themeOf(slides[cur]);
    const to = themeOf(slides[n]);
    if (from && to && from !== to) {
      hero.classList.remove(from);
      hero.classList.add(to);
    }

    const prev = cur;
    slides[prev].classList.remove('is-active');
    slides[prev].inert = true;
    slides[prev].setAttribute('aria-hidden', 'true');
    if (bgs[prev]) bgs[prev].classList.remove('is-active');
    if (inds[prev]) {
      inds[prev].classList.remove('is-active');
      inds[prev].querySelector('button')?.removeAttribute('aria-current');
    }
    setFill(prev, 0);

    cur = n;
    slides[cur].classList.add('is-active');
    slides[cur].inert = false;
    slides[cur].removeAttribute('aria-hidden');
    if (bgs[cur]) bgs[cur].classList.add('is-active');
    if (inds[cur]) {
      inds[cur].classList.add('is-active');
      inds[cur].querySelector('button')?.setAttribute('aria-current', 'true');
    }

    elapsed = 0;
    // While paused the active bar shows full so it still reads as "current".
    setFill(cur, userPlaying ? 0 : 1);
    sync();
    if (userPlaying && !raf && isRunning()) {
      lastTs = performance.now();
      raf = requestAnimationFrame(tick);
    }
  }

  const next = () => go(cur + 1, 1);
  const prev = () => go(cur - 1, -1);

  // Initial state: only slide 0 is reachable until it changes.
  slides.forEach((s, i) => {
    s.inert = i !== cur;
  });
  setFill(cur, userPlaying ? 0 : 1);

  hero.querySelector('[data-prev]')?.addEventListener('click', prev);
  hero.querySelector('[data-next]')?.addEventListener('click', next);
  inds.forEach((li) => {
    li.querySelector('button')?.addEventListener('click', (e) => {
      go(parseInt(e.currentTarget.getAttribute('data-go'), 10));
    });
  });

  if (toggle) {
    toggle.addEventListener('click', () => {
      userPlaying = !userPlaying;
      toggle.setAttribute('aria-label', userPlaying ? 'Pause slideshow' : 'Play slideshow');
      const pauseIcon = toggle.querySelector('.i-pause');
      const playIcon = toggle.querySelector('.i-play');
      if (pauseIcon) pauseIcon.hidden = !userPlaying;
      if (playIcon) playIcon.hidden = userPlaying;
      if (userPlaying && elapsed === 0) setFill(cur, 0);
      sync();
    });
  }

  // Pause rules.
  hero.addEventListener('pointerenter', (e) => {
    if (e.pointerType === 'mouse') {
      hover = true;
      sync();
    }
  });
  hero.addEventListener('pointerleave', (e) => {
    if (e.pointerType === 'mouse') {
      hover = false;
      sync();
    }
  });
  hero.addEventListener('focusin', (e) => {
    try {
      focusPause = e.target.matches(':focus-visible');
    } catch (err) {
      focusPause = true;
    }
    sync();
  });
  hero.addEventListener('focusout', (e) => {
    if (!hero.contains(e.relatedTarget)) {
      focusPause = false;
      sync();
    }
  });
  document.addEventListener('visibilitychange', sync);

  // Keyboard.
  hero.addEventListener('keydown', (e) => {
    if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
    if (e.key === 'ArrowLeft') { e.preventDefault(); prev(); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); next(); }
    else if (e.key === 'Home') { e.preventDefault(); go(0, -1); }
    else if (e.key === 'End') { e.preventDefault(); go(count - 1, 1); }
  });

  // Placeholder links must not jump the page.
  hero.addEventListener('click', (e) => {
    const a = e.target.closest && e.target.closest('a[href="#"]');
    if (a) e.preventDefault();
  });

  // Swipe on touch and pen. Vertical scrolling stays native.
  if (viewport) {
    viewport.style.touchAction = 'pan-y';
    let startX = null;
    let startY = 0;
    viewport.addEventListener('pointerdown', (e) => {
      if (e.pointerType === 'mouse') return;
      startX = e.clientX;
      startY = e.clientY;
    });
    viewport.addEventListener('pointerup', (e) => {
      if (startX === null) return;
      const dx = e.clientX - startX;
      const dy = e.clientY - startY;
      startX = null;
      if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
        if (dx < 0) next(); else prev();
      }
    });
    viewport.addEventListener('pointercancel', () => { startX = null; });
  }

  // Gentle laptop tilt toward the pointer (desktop with a mouse only).
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduce && window.matchMedia('(hover: hover)').matches) {
    let tiltRaf = 0;
    hero.addEventListener('pointermove', (e) => {
      if (e.pointerType !== 'mouse') return;
      const r = hero.getBoundingClientRect();
      const x = (e.clientX - r.left) / r.width - 0.5;
      const y = (e.clientY - r.top) / r.height - 0.5;
      cancelAnimationFrame(tiltRaf);
      tiltRaf = requestAnimationFrame(() => {
        hero.style.setProperty('--ry', (x * 8).toFixed(2) + 'deg');
        hero.style.setProperty('--rx', (-y * 5).toFixed(2) + 'deg');
      });
    });
    hero.addEventListener('pointerleave', () => {
      hero.style.setProperty('--ry', '0deg');
      hero.style.setProperty('--rx', '0deg');
    });
  }

  sync();
  hero.dataset.ready = '1';
  return { go, next, prev, get index() { return cur; }, get count() { return count; } };
}

const hero = document.getElementById('hero');
if (hero && hero.hasAttribute('data-hero')) {
  initHeroSlider(hero);
}
