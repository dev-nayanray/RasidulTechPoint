/**
 * Admin mobile helpers (no-op outside the admin shell).
 *
 * 1. Any <table> that is not already inside a horizontal scroll container is
 *    wrapped in one, so a wide table scrolls inside its own box instead of
 *    panning the whole page sideways.
 * 2. Tables with four or more columns get `rtp-table-wide`, which gives them a
 *    sensible minimum width on phones (see _design_css.php) so columns stay
 *    readable instead of being crushed.
 * 3. Scroll containers get a `has-more` class while content continues to the
 *    right, which drives a subtle edge shadow hint.
 */
const main = document.getElementById('main-content');
const isAdmin = !!main && !!document.querySelector('[data-sidebar]');

function isScrollContainer(el) {
    if (!el || el === main) return false;
    if (el.classList.contains('admin-table-wrap') || el.classList.contains('rtp-table-scroll')) return true;
    const ox = window.getComputedStyle(el).overflowX;
    return ox === 'auto' || ox === 'scroll';
}

function wrapTable(table) {
    if (table.dataset.rtpWrapped === '1') return;
    table.dataset.rtpWrapped = '1';

    const firstRow = table.querySelector('tr');
    const cols = firstRow ? firstRow.children.length : 0;
    if (cols >= 4) table.classList.add('rtp-table-wide');

    // Tables nested in an existing horizontal scroller (or inside another table) need no wrapper.
    if (isScrollContainer(table.parentElement) || table.parentElement.closest('table')) {
        track(table.parentElement);
        return;
    }
    const wrap = document.createElement('div');
    wrap.className = 'rtp-table-scroll';
    table.parentNode.insertBefore(wrap, table);
    wrap.appendChild(table);
    track(wrap);
}

function updateMore(el) {
    const more = el.scrollWidth - el.clientWidth - el.scrollLeft > 4;
    el.classList.toggle('has-more', more);
}

function track(el) {
    if (!el || el.dataset.rtpTracked === '1') return;
    el.dataset.rtpTracked = '1';
    updateMore(el);
    el.addEventListener('scroll', () => updateMore(el), { passive: true });
    if ('ResizeObserver' in window) new ResizeObserver(() => updateMore(el)).observe(el);
}

if (isAdmin) {
    const run = () => main.querySelectorAll('table').forEach(wrapTable);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
    else run();
}

// Bring the active pill of a segmented range toggle (Today / 7 days / ...) into view.
if (isAdmin) {
    const centreActive = () => {
        document.querySelectorAll('.admin-range-toggle').forEach((box) => {
            const active = box.querySelector('button.active');
            if (!active || box.scrollWidth <= box.clientWidth) return;
            box.scrollLeft = Math.max(0, active.offsetLeft - (box.clientWidth - active.offsetWidth) / 2);
        });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', centreActive);
    else centreActive();
}
