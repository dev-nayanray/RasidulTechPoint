/**
 * Progressive form behaviour:
 * - loading state + disable on submit (data-loading-form)
 * - lightweight client-side validation for usability (server validates always)
 */
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-loading-form')) return;

    const buttons = form.querySelectorAll('button[type=submit]');
    buttons.forEach((button) => {
        button.disabled = true;
        if (!button.querySelector('[data-spinner]')) {
            button.dataset.originalText = button.textContent;
            button.insertAdjacentHTML(
                'afterbegin',
                '<span data-spinner class="inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white" aria-hidden="true"></span>'
            );
        }
    });
    form.setAttribute('aria-busy', 'true');
});

// Usability-only inline validation hints (authoritative checks stay server-side).
document.addEventListener('blur', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.willValidate || input.validity.valid) {
        input.classList.remove('border-red-400');
        return;
    }
    input.classList.add('border-red-400');
}, true);
