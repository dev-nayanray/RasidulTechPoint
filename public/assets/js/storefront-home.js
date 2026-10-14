import { initCompare } from './compare.js';
/**
 * Homepage header behavior: mobile menu, header dropdown panels, live search.
 *
 * Moved out of an inline <script> in storefront/home.php. The site CSP is
 * "script-src 'self'", so inline scripts never run. Behavior is unchanged;
 * the search endpoint now comes from data-suggest-url on the search form.
 */

// Mobile nav drawer toggle.
document.addEventListener('click', (e) => {
  const t = e.target.closest('[data-nav-toggle]');
  if (!t) return;
  const n = document.querySelector('[data-nav-mobile]');
  if (n) {
    const open = n.classList.toggle('open');
    t.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
});

// Dropdown panels (account, wishlist, cart) — with viewport-overflow protection.
document.addEventListener('click', (e) => {
  const tg = e.target.closest('[data-dd-toggle]');
  document.querySelectorAll('.dd-panel.open').forEach((p) => {
    if (!tg || p.id !== tg.getAttribute('data-dd-toggle')) {
      p.classList.remove('open');
      p.style.right = '';
      p.style.left = '';
    }
  });
  if (tg) {
    e.preventDefault();
    const p = document.getElementById(tg.getAttribute('data-dd-toggle'));
    if (p) {
      p.classList.toggle('open');
      if (p.classList.contains('open')) {
        var rect = p.getBoundingClientRect();
        if (rect.right > window.innerWidth - 8) {
          p.style.right = 'auto';
          p.style.left = '0';
        } else if (rect.left < 8) {
          p.style.right = '0';
          p.style.left = 'auto';
        }
      } else {
        p.style.right = '';
        p.style.left = '';
      }
    }
  }
});

// Close dropdowns on Escape.
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.dd-panel.open').forEach((p) => {
      p.classList.remove('open');
      p.style.right = '';
      p.style.left = '';
    });
  }
});

// AJAX live search.
(() => {
  const form = document.querySelector('[data-search-form]');
  const input = document.querySelector('[data-search-input]');
  const dd = document.querySelector('[data-sugg-dropdown]');
  if (!form || !input || !dd) return;
  const endpoint = form.getAttribute('data-suggest-url');
  if (!endpoint) return;

  let timer = null;
  let lastQ = '';
  const esc = (s) => {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  };

  input.addEventListener('input', () => {
    const q = input.value.trim();
    if (q.length < 2) {
      dd.classList.remove('open');
      lastQ = '';
      return;
    }
    if (q === lastQ) return;
    lastQ = q;
    clearTimeout(timer);
    timer = setTimeout(() => {
      fetch(endpoint + '?q=' + encodeURIComponent(q))
        .then((r) => r.json())
        .then((data) => {
          if (!data.results || data.results.length === 0) {
            dd.innerHTML = '<div class="s-empty">No laptops found. Try Dell, HP, Lenovo.</div>';
          } else {
            const LAPTOP_ICON = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 9l10-5 10 5-10 5z"/><path d="M2 9v6l10 5 10-5V9"/></svg>';
            const thumb = (r) => r.image
              ? '<span class="s-thumb s-thumb-img"><img src="' + esc(r.image) + '" alt="" loading="lazy"></span>'
              : '<span class="s-thumb">' + LAPTOP_ICON + '</span>';
            dd.innerHTML = data.results.map((r) =>
              '<a class="s-item" href="' + r.url + '">' + thumb(r) + '<div class="s-info"><div class="s-name">'
                + esc(r.brand + ' ' + r.name) + '</div><div class="s-meta">' + esc(r.processor) + (r.ram ? ' · ' + esc(r.ram) : '') + (r.units > 0 ? ' · In stock' : '')
                + '</div></div><span class="s-price">' + esc(r.price) + '</span></a>'
            ).join('') + (data.more ? '<a class="s-more" href="' + data.search_url + '">See all results →</a>' : '');
          }
          dd.classList.add('open');
        })
        .catch(() => dd.classList.remove('open'));
    }, 250);
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.search')) dd.classList.remove('open');
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      dd.classList.remove('open');
      input.blur();
    }
  });
})();

// Initialize compare system on the homepage.
initCompare();

// Scroll-aware announcement bar: hide on scroll-down, show on scroll-up.
(() => {
  const announce = document.querySelector('.announce');
  if (!announce) return;
  let lastY = window.scrollY || 0;
  let ticking = false;
  function onScroll() {
    const y = window.scrollY || 0;
    if (y > lastY && y > 80) {
      announce.classList.add('hide');
    } else {
      
      announce.classList.remove('hide');
    }
    lastY = y;
    ticking = false;
  }
  window.addEventListener('scroll', () => {
    if (!ticking) {
      window.requestAnimationFrame(onScroll);
      ticking = true;
    }
  }, { passive: true });
})();
