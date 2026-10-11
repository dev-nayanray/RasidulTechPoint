/**
 * Dropdown menus (admin profile menu). Click to toggle, Escape/outside to close.
 */
function closeAll(except = null) {
    document.querySelectorAll('[data-dropdown]').forEach((root) => {
        if (root === except) return;
        root.querySelector('[data-dropdown-menu]')?.classList.add('hidden');
        root.querySelector('[data-dropdown-toggle]')?.setAttribute('aria-expanded', 'false');
    });
}

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-dropdown-toggle]');
    if (toggle) {
        const root = toggle.closest('[data-dropdown]');
        const menu = root?.querySelector('[data-dropdown-menu]');
        if (menu) {
            const willOpen = menu.classList.contains('hidden');
            closeAll(root);
            menu.classList.toggle('hidden', !willOpen);
            toggle.setAttribute('aria-expanded', String(willOpen));
        }
        event.stopPropagation();
        return;
    }

    if (!event.target.closest('[data-dropdown-menu]')) {
        closeAll();
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeAll();
});
