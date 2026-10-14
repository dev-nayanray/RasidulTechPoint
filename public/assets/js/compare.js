/**
 * Product compare (storefront listings).
 *
 * - Each product card carries [data-compare-toggle="slug"] plus a JSON spec
 *   payload in [data-compare-data="slug"].
 * - Up to MAX products can be selected; the selection persists in
 *   sessionStorage across listing pages.
 * - The floating compare bar ([data-cmp-bar]) and modal ([data-cmp-modal])
 *   live in layouts/public.php — never on the homepage.
 */

const MAX = 3;
const STORE_KEY = 'rtp_compare_v1';

function readStore() {
  try {
    return JSON.parse(sessionStorage.getItem(STORE_KEY) || '{}');
  } catch (e) {
    return {};
  }
}

function writeStore(data) {
  try {
    sessionStorage.setItem(STORE_KEY, JSON.stringify(data));
  } catch (e) {
    /* private mode etc. — compare still works within the page */
  }
}

function esc(s) {
  const d = document.createElement('div');
  d.textContent = s == null ? '' : String(s);
  return d.innerHTML;
}

function cssEscape(v) {
  if (window.CSS && typeof CSS.escape === 'function') {
    return CSS.escape(v);
  }
  return v.replace(/["\\]/g, '\\$&');
}

function toast(msg) {
  let t = document.querySelector('.cmp-toast');
  if (!t) {
    t = document.createElement('div');
    t.className = 'cmp-toast';
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.classList.add('open');
  window.setTimeout(() => t.classList.remove('open'), 2200);
}

export function initCompare() {
  const bar = document.querySelector('[data-cmp-bar]');
  const modal = document.querySelector('[data-cmp-modal]');
  // The compare bar + modal live in layouts/public.php (shop/product/etc.).
  // The homepage has product cards with compare toggles but NO bar/modal —
  // the user can select items on the homepage and see the compare bar when
  // they navigate to any /shop page. So we must NOT return early when
  // bar/modal are absent; we still need to wire up the toggle buttons and
  // persist the selection in sessionStorage.
  const hasBar = !!bar;
  const hasModal = !!modal;
  const itemsEl = hasBar ? bar.querySelector('[data-cmp-items]') : null;
  const actionEl = hasBar ? bar.querySelector('[data-cmp-action]') : null;
  const barClose = hasBar ? bar.querySelector('[data-cmp-close]') : null;
  const modalClose = hasModal ? modal.querySelector('[data-cmp-modal-close]') : null;
  const tableEl = hasModal ? modal.querySelector('[data-cmp-table]') : null;

  // slug -> full spec payload (session store keeps values alive across pages)
  const selection = {};
  const saved = readStore();
  Object.keys(saved).forEach((k) => { selection[k] = saved[k]; });

  const syncCardStates = () => {
    document.querySelectorAll('[data-compare-toggle]').forEach((btn) => {
      const slug = btn.getAttribute('data-compare-toggle') || '';
      const on = Object.prototype.hasOwnProperty.call(selection, slug);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      const label = btn.querySelector('[data-compare-label]');
      if (label) {
        label.textContent = on ? 'In comparison' : 'Compare';
      }
    });
  };

  const renderBar = () => {
    if (!hasBar) return; // no compare bar on this page (e.g. homepage)
    const slugs = Object.keys(selection);
    if (slugs.length === 0) {
      bar.classList.remove('open');
      itemsEl.innerHTML = '';
      actionEl.innerHTML = '';
      return;
    }
    itemsEl.innerHTML = slugs.map((slug) =>
      '<span class="cmp-chip">' + esc(selection[slug].name) +
      ' <button type="button" data-cmp-remove="' + esc(slug) + '" aria-label="Remove ' + esc(selection[slug].name) + ' from comparison">×</button></span>'
    ).join('');
    actionEl.innerHTML = '<button type="button" class="cmp-btn" data-cmp-open>Compare ' + slugs.length + '</button>';
    bar.classList.add('open');
  };

  const renderTable = () => {
    if (!hasModal) return;
    const slugs = Object.keys(selection);
    const rows = [
      ['Price', (d) => '<b class="cmp-price">' + esc(d.price) + '</b>'],
      ['Brand', (d) => esc(d.brand)],
      ['Processor', (d) => esc(d.processor)],
      ['Memory', (d) => esc(d.ram)],
      ['Storage', (d) => esc(d.storage)],
      ['Display', (d) => esc(d.display)],
      ['Graphics', (d) => esc(d.graphics)],
      ['Availability', (d) => d.units > 0 ? '<span class="cmp-ok">' + d.units + ' in stock</span>' : '<span class="cmp-no">Out of stock</span>'],
    ];
    let html = '<table class="cmp-inner"><thead><tr><th class="ck"></th>';
    html += slugs.map((slug) => {
      const d = selection[slug];
      const img = d.image
        ? '<img src="' + esc(d.image) + '" alt="" loading="lazy">'
        : '<span class="cmp-noimg">No image</span>';
      return '<th class="chead">' + img + '<b>' + esc(d.name) + '</b><a href="' + esc(d.url) + '">View details →</a></th>';
    }).join('');
    html += '</tr></thead><tbody>';
    rows.forEach((row) => {
      html += '<tr class="crow"><td class="ck">' + row[0] + '</td>' +
        slugs.map((slug) => '<td class="cv">' + row[1](selection[slug]) + '</td>').join('') + '</tr>';
    });
    html += '</tbody></table>';
    tableEl.innerHTML = html;
  };

  const toggle = (slug) => {
    if (Object.prototype.hasOwnProperty.call(selection, slug)) {
      delete selection[slug];
    } else {
      if (Object.keys(selection).length >= MAX) {
        toast('You can compare up to ' + MAX + ' laptops at once. Remove one first.');
        return;
      }
      const payloadEl = document.querySelector('script[data-compare-data="' + cssEscape(slug) + '"]');
      if (payloadEl) {
        try {
          selection[slug] = JSON.parse(payloadEl.textContent || '{}');
        } catch (e) {
          return;
        }
      } else if (readStore()[slug]) {
        selection[slug] = readStore()[slug];
      } else {
        return;
      }
    }
    writeStore(selection);
    syncCardStates();
    renderBar();
  };

  document.addEventListener('click', (e) => {
    const target = e.target;
    const toggleBtn = target.closest('[data-compare-toggle]');
    if (toggleBtn) {
      toggle(toggleBtn.getAttribute('data-compare-toggle') || '');
      return;
    }
    const removeBtn = target.closest('[data-cmp-remove]');
    if (removeBtn) {
      toggle(removeBtn.getAttribute('data-cmp-remove') || '');
      return;
    }
    if (target.closest('[data-cmp-open]')) {
      if (Object.keys(selection).length < 2) {
        toast('Select at least 2 laptops to compare.');
        return;
      }
      renderTable();
      if (hasModal) {
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
      }
      return;
    }
    if (target.closest('[data-cmp-close]')) {
      if (hasBar) bar.classList.remove('open');
      return;
    }
    if (hasModal && (target.closest('[data-cmp-modal-close]') || target === modal)) {
      modal.classList.remove('open');
      document.body.style.overflow = '';
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && hasModal && modal.classList.contains('open')) {
      modal.classList.remove('open');
      document.body.style.overflow = '';
    }
  });

  if (barClose) barClose.addEventListener('click', () => { if (hasBar) bar.classList.remove('open'); });
  if (modalClose) modalClose.addEventListener('click', () => {
    if (hasModal) modal.classList.remove('open');
    document.body.style.overflow = '';
  });

  syncCardStates();
  renderBar();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initCompare);
} else {
  initCompare();
}
