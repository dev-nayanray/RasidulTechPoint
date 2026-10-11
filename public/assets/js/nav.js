/**
 * Navigation behaviours:
 * 1. Mobile drawer (admin sidebar + storefront header nav).
 * 2. Desktop sidebar collapse to an icon rail, persisted in localStorage
 *    (class applied pre-paint by an inline script in the admin layout).
 */
const MOBILE_VISIBLE = ['translate-x-0'];

function setDrawer(open) {
    const sidebar = document.querySelector('[data-sidebar]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    if (!sidebar) return;

    if (open) {
        sidebar.classList.remove('-translate-x-full');
        MOBILE_VISIBLE.forEach((c) => sidebar.classList.add(c));
        backdrop?.classList.remove('hidden');
        sidebar.querySelector('[data-nav-toggle]')?.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    } else {
        sidebar.classList.add('-translate-x-full');
        MOBILE_VISIBLE.forEach((c) => sidebar.classList.remove(c));
        backdrop?.classList.add('hidden');
        sidebar.querySelector('[data-nav-toggle]')?.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }
}

function setCollapsed(collapsed) {
    const root = document.documentElement;
    root.classList.toggle('sidebar-collapsed', collapsed);
    try {
        localStorage.setItem('rtp-sidebar-collapsed', collapsed ? '1' : '0');
    } catch (e) { /* storage unavailable */ }

    document.querySelector('[data-sidebar-collapse]')?.setAttribute('aria-expanded', String(!collapsed));
    document.querySelector('[data-sidebar-collapse]')?.setAttribute(
        'aria-label',
        collapsed ? 'Expand sidebar' : 'Collapse sidebar'
    );
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

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        setDrawer(false);
    }
});
