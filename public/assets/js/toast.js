/**
 * Toast region: auto-dismiss success/info toasts after a few seconds;
 * error toasts stay until dismissed.
 */
document.querySelectorAll('[data-toast]').forEach((toast) => {
    const isError = toast.textContent.includes('data-error') || toast.className.includes('red');
    const dismiss = () => toast.remove();
    toast.querySelector('[data-toast-dismiss]')?.addEventListener('click', dismiss);
    if (!isError) {
        setTimeout(dismiss, 6000);
    }
});
