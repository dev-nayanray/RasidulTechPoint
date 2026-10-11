/**
 * Page navigation progress indicator.
 *
 * When the user clicks a same-origin link or submits a GET form, a thin
 * indeterminate progress bar slides along the very top of the page. The bar
 * stays at ~85% until `load`/`pageshow` fires for the new document — at which
 * point it finishes and fades out. This matches the persistent "loading"
 * state the AGENTS.md requires without faking any data.
 *
 * No fake "loading complete" timers, no fetch interception, no framework.
 */
const BAR_ID = 'rtp-page-progress';

function ensureBar() {
    let bar = document.getElementById(BAR_ID);
    if (bar) return bar;
    bar = document.createElement('div');
    bar.id = BAR_ID;
    bar.setAttribute('aria-hidden', 'true');
    bar.style.cssText = [
        'position:fixed',
        'top:0',
        'left:0',
        'height:2px',
        'width:0',
        'z-index:80',
        'background:#2563EB',
        'box-shadow:0 0 6px rgba(37,99,235,.5)',
        'transition:width 220ms ease-out, opacity 240ms ease-out',
        'opacity:0',
        'pointer-events:none',
    ].join(';');
    document.documentElement.appendChild(bar);
    return bar;
}

let growTimer = null;
function start() {
    const bar = ensureBar();
    if (growTimer) clearTimeout(growTimer);
    bar.style.opacity = '1';
    bar.style.width = '0%';
    // Grow to 40% quickly, then inch toward 85% — never reaches 100% until
    // the next document actually loads.
    requestAnimationFrame(() => { bar.style.width = '40%'; });
    growTimer = setTimeout(() => { bar.style.width = '70%'; }, 120);
    growTimer = setTimeout(() => { bar.style.width = '85%'; }, 600);
}

function finish() {
    const bar = ensureBar();
    if (growTimer) {
        clearTimeout(growTimer);
        growTimer = null;
    }
    bar.style.width = '100%';
    bar.style.opacity = '0';
    setTimeout(() => { bar.style.width = '0%'; }, 280);
}

function isSameOrigin(url) {
    try {
        const u = new URL(url, window.location.href);
        return u.origin === window.location.origin;
    } catch (e) {
        return false;
    }
}

function isInternalLink(event) {
    const a = event.target.closest('a[href]');
    if (!a) return false;
    const href = a.getAttribute('href') || '';
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return false;
    if (a.target === '_blank' || a.hasAttribute('download')) return false;
    // Modifier keys bypass in-browser navigation (new tab, etc.).
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;
    return isSameOrigin(href);
}

document.addEventListener('click', (event) => {
    if (event.defaultPrevented) return;
    if (isInternalLink(event)) {
        start();
    }
});

// GET forms (search, filters) also navigate — show the bar.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.method?.toLowerCase() === 'get') {
        start();
    } else {
        // POST forms with data-loading-form already show inline spinners;
        // we also surface a bar so the page-load itself is visible.
        start();
    }
});

// Page is being unloaded for a navigation → keep the bar at 85% until next load.
window.addEventListener('beforeunload', () => {
    // No-op: the bar is already growing. We leave it as-is so the next
    // document's load event can finish it.
}, { passive: true });

// New document has loaded — finish any pending progress.
window.addEventListener('pageshow', finish);
if (document.readyState === 'complete') {
    finish();
} else {
    window.addEventListener('load', finish, { once: true });
}
