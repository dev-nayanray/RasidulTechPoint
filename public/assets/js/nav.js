/**
 * Mobile navigation: sidebar drawer (admin) and collapsible header nav (storefront).
 * Uses event delegation so it works on any page without re-binding.
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

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-nav-toggle]');
    if (toggle) {
        const sidebar = document.querySelector('[data-sidebar]');
        if (sidebar && sidebar.classList.contains('-translate-x-full')) {
            setDrawer(true);
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
