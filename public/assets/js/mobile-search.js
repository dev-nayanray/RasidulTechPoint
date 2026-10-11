/**
 * Mobile search affordance: the admin header has a search icon button on
 * small screens (below md). Tapping it reveals a sticky search panel right
 * under the header, with focus moved inside the input for keyboard users.
 *
 * Tapping again or pressing Escape hides the panel and returns focus to the
 * toggle button. The panel auto-hides on resize past md, matching the
 * breakpoint where the inline header search becomes visible.
 */
const PANEL_ATTR = 'data-mobile-search-panel';
const TOGGLE_ATTR = 'data-mobile-search-toggle';

function getPanel() {
    return document.querySelector(`[${PANEL_ATTR}]`);
}

function getToggle() {
    return document.querySelector(`[${TOGGLE_ATTR}]`);
}

function isOpen() {
    const panel = getPanel();
    return !!panel && !panel.classList.contains('hidden');
}

function openPanel() {
    const panel = getPanel();
    const toggle = getToggle();
    if (!panel) return;
    panel.classList.remove('hidden');
    toggle?.setAttribute('aria-expanded', 'true');
    // Move focus to the input once the panel is visible.
    requestAnimationFrame(() => {
        const input = panel.querySelector('input');
        input?.focus();
    });
}

function closePanel() {
    const panel = getPanel();
    const toggle = getToggle();
    if (!panel) return;
    panel.classList.add('hidden');
    toggle?.setAttribute('aria-expanded', 'false');
    toggle?.focus();
}

document.addEventListener('click', (event) => {
    const toggle = event.target.closest(`[${TOGGLE_ATTR}]`);
    if (toggle) {
        if (isOpen()) {
            closePanel();
        } else {
            openPanel();
        }
        return;
    }

    // Outside click closes the panel.
    if (isOpen() && !event.target.closest(`[${PANEL_ATTR}]`)) {
        // Don't close when the toggle itself was clicked (handled above).
        if (!event.target.closest(`[${TOGGLE_ATTR}]`)) {
            closePanel();
        }
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isOpen()) {
        closePanel();
    }
});

let resizeTimer = null;
window.addEventListener('resize', () => {
    if (resizeTimer) clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
        if (window.innerWidth >= 768 && isOpen()) {
            closePanel();
        }
    }, 120);
});
