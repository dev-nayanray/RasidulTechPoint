/**
 * Modal dialogs driven by data-modal-open="id" / data-modal-close attributes.
 */
function open(id) {
    const modal = document.querySelector(`[data-modal="${id}"]`);
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.querySelector('input:not([type=hidden]), select, textarea, button')?.focus();
    document.body.style.overflow = 'hidden';
}

function close(modal) {
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
    document.body.style.overflow = '';
}

document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-modal-open]');
    if (opener) {
        open(opener.getAttribute('data-modal-open'));
        return;
    }

    const closer = event.target.closest('[data-modal-close]');
    if (closer) {
        close(closer.closest('[data-modal]'));
        return;
    }

    const backdrop = event.target.closest('[data-modal]');
    if (backdrop && event.target === backdrop) {
        close(backdrop);
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('[data-modal]:not(.hidden)').forEach(close);
    }
});
