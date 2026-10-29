/**
 * Navigation behaviours:
 * 1. Mobile drawer (admin sidebar + storefront header nav).
 * 2. Desktop sidebar collapse to an icon rail, persisted in localStorage
 *    (class applied pre-paint by an inline script in the admin layout).
 * 3. Accessibility: aria-expanded sync on every toggle button, focus moves
 *    into the drawer when it opens and returns to the opener when it closes,
 *    and Tab focus is trapped inside the drawer while it is visible.
 */
const MOBILE_VISIBLE = ['translate-x-0'];
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

let lastOpener = null;

function getDrawerToggles() {
    return document.querySelectorAll('[data-nav-toggle]');
}

function syncToggleAria(open) {
    // Every nav-toggle button reflects the drawer state — the hamburger in the
    // header opens; the close icon inside the sidebar header also closes.
    getDrawerToggles().forEach((btn) => {
        btn.setAttribute('aria-expanded', String(open));
    });
}

function focusableInside(root) {
    return Array.from(root.querySelectorAll(FOCUSABLE)).filter((el) => {
        const rect = el.getBoundingClientRect();
        return el.offsetParent !== null || rect.width > 0 || rect.height > 0;
    });
}

function setDrawer(open) {
    const sidebar = document.querySelector('[data-sidebar]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    if (!sidebar) return;

    if (open) {
        sidebar.classList.remove('-translate-x-full');
        MOBILE_VISIBLE.forEach((c) => sidebar.classList.add(c));
        backdrop?.classList.remove('hidden');
        syncToggleAria(true);
        document.body.style.overflow = 'hidden';
        // Move focus into the drawer once the transition has room to run.
        requestAnimationFrame(() => {
            const first = focusableInside(sidebar)[0];
            if (first) first.focus();
        });
    } else {
        sidebar.classList.add('-translate-x-full');
        MOBILE_VISIBLE.forEach((c) => sidebar.classList.remove(c));
        backdrop?.classList.add('hidden');
        syncToggleAria(false);
        document.body.style.overflow = '';
        // Return focus to the opener (usually the hamburger button in the header).
        if (lastOpener && typeof lastOpener.focus === 'function') {
            lastOpener.focus();
            lastOpener = null;
        }
    }
}

function setCollapsed(collapsed) {
    const root = document.documentElement;
    root.classList.toggle('sidebar-collapsed', collapsed);
    try {
        localStorage.setItem('rtp-sidebar-collapsed', collapsed ? '1' : '0');
    } catch (e) { /* storage unavailable */ }

    const collapseBtn = document.querySelector('[data-sidebar-collapse]');
    if (collapseBtn) {
        collapseBtn.setAttribute('aria-expanded', String(!collapsed));
        collapseBtn.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        collapseBtn.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }
}

document.addEventListener('click', (event) => {
    const collapseToggle = event.target.closest('[data-sidebar-collapse]');
    if (collapseToggle) {
        setCollapsed(!document.documentElement.classList.contains('sidebar-collapsed'));
        return;
    }

    if (event.target.closest('[data-sidebar-expand]')) {
        setCollapsed(false);
        return;
    }

    const toggle = event.target.closest('[data-nav-toggle]');
    if (toggle) {
        const sidebar = document.querySelector('[data-sidebar]');
        if (sidebar && sidebar.classList.contains('-translate-x-full')) {
            lastOpener = toggle;
            setDrawer(true);
        } else if (sidebar && window.getComputedStyle(sidebar).position === 'fixed') {
            setDrawer(false);
        } else if (sidebar) {
            setDrawer(false);
        } else {
            // Storefront header nav fallback
            const mobileNav = document.querySelector('[data-nav-mobile]');
            if (mobileNav) {
                const hidden = mobileNav.classList.toggle('hidden');
                mobileNav.classList.toggle('flex', !hidden);
                toggle.setAttribute('aria-expanded', String(!hidden));
            }
        }
        return;
    }

    if (event.target.closest('[data-sidebar-backdrop]')) {
        setDrawer(false);
    }
});

// Focus trap: keep Tab inside the mobile drawer while it is open.
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        setDrawer(false);
        return;
    }

    if (event.key !== 'Tab') return;
    const sidebar = document.querySelector('[data-sidebar]');
    if (!sidebar) return;
    // Only trap when the drawer is the mobile-mode visible overlay.
    if (sidebar.classList.contains('-translate-x-full')) return;
    if (window.getComputedStyle(sidebar).position !== 'fixed') return;

    const items = focusableInside(sidebar);
    if (items.length === 0) return;
    const first = items[0];
    const last = items[items.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
});

// Swipe the open drawer to the left to dismiss it (touch devices).
let touchStartX = null;
let touchStartY = null;
document.addEventListener('touchstart', (event) => {
    const sidebar = document.querySelector('[data-sidebar]');
    if (!sidebar || sidebar.classList.contains('-translate-x-full') || !sidebar.contains(event.target)) {
        touchStartX = null;
        return;
    }
    touchStartX = event.touches[0].clientX;
    touchStartY = event.touches[0].clientY;
}, { passive: true });
document.addEventListener('touchend', (event) => {
    if (touchStartX === null) return;
    const dx = event.changedTouches[0].clientX - touchStartX;
    const dy = Math.abs(event.changedTouches[0].clientY - touchStartY);
    touchStartX = null;
    if (dx < -60 && dy < 40) setDrawer(false);
}, { passive: true });

// Close the drawer when the viewport grows past the mobile breakpoint
// (otherwise a previously-toggled `-translate-x-full` removal can leave the
// sidebar in a weird hybrid state on resize). Passive, runs once per resize.
let resizeTimer = null;
window.addEventListener('resize', () => {
    if (resizeTimer) clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
        if (window.innerWidth >= 1024) {
            const sidebar = document.querySelector('[data-sidebar]');
            if (sidebar && !sidebar.classList.contains('-translate-x-full')) {
                setDrawer(false);
            }
        }
    }, 120);
});
