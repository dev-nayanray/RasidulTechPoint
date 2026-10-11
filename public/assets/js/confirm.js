/**
 * Confirmation dialogs for destructive or significant actions:
 * <form data-confirm="Message?"> — native confirm(), deliberately simple
 * and dependency-free; the server re-validates everything anyway.
 */
document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (form && !form.dataset.confirmed) {
        const message = form.getAttribute('data-confirm') || 'Are you sure?';
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    }
});
