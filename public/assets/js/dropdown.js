/**
 * Dropdown menus (admin profile menu, notifications, search affordance).
 * Click to toggle, Escape/outside to close, arrow-key navigation with focus
 * management. Each open menu keeps focus inside until closed.
 */
function closeAll(except = null) {
    document.querySelectorAll('[data-dropdown]').forEach((root) => {
        if (root === except) return;
        root.querySelector('[data-dropdown-menu]')?.classList.add('hidden');
        root.querySelector('[data-dropdown-toggle]')?.setAttribute('aria-expanded', 'false');
    });
}

function focusableMenuItems(root) {
    return Array.from(root.querySelectorAll('[data-dropdown-menu] a, [data-dropdown-menu] button, [data-dropdown-menu] input, [data-dropdown-menu] [tabindex]:not([tabindex="-1"])'))
        .filter((el) => el.offsetParent !== null);
}

function openDropdown(root, toggle) {
    const menu = root?.querySelector('[data-dropdown-menu]');
    if (!menu) return;
    closeAll(root);
    menu.classList.remove('hidden');
    toggle.setAttribute('aria-expanded', 'true');
    // Move focus into the menu so screen-reader users can hear the items.
    requestAnimationFrame(() => {
        const items = focusableMenuItems(root);
        if (items.length) items[0].focus();
    });
}

function closeDropdown(root) {
    const menu = root?.querySelector('[data-dropdown-menu]');
    const toggle = root?.querySelector('[data-dropdown-toggle]');
    menu?.classList.add('hidden');
    toggle?.setAttribute('aria-expanded', 'false');
    toggle?.focus();
}

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-dropdown-toggle]');
    if (toggle) {
        const root = toggle.closest('[data-dropdown]');
        const menu = root?.querySelector('[data-dropdown-menu]');
        if (menu) {
            const willOpen = menu.classList.contains('hidden');
            if (willOpen) {
                openDropdown(root, toggle);
            } else {
                closeDropdown(root);
            }
            event.stopPropagation();
            return;
        }
    }

    if (!event.target.closest('[data-dropdown-menu]')) {
        closeAll();
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        const openMenu = document.querySelector('[data-dropdown-menu]:not(.hidden)');
        const root = openMenu?.closest('[data-dropdown]');
        closeAll();
        if (root) {
            root.querySelector('[data-dropdown-toggle]')?.focus();
        }
        return;
    }

    // Arrow-key navigation inside an open dropdown.
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
    const menu = event.target.closest('[data-dropdown-menu]');
    if (!menu) return;
    const root = menu.closest('[data-dropdown]');
    if (!root) return;

    event.preventDefault();
    const items = focusableMenuItems(root);
    if (items.length === 0) return;
    const idx = items.indexOf(document.activeElement);
    let next = idx;
    if (event.key === 'ArrowDown') {
        next = idx < 0 ? 0 : (idx + 1) % items.length;
    } else {
        next = idx <= 0 ? items.length - 1 : idx - 1;
    }
    items[next].focus();
});
