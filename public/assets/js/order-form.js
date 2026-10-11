/**
 * Order creation form: client-side running total over the selected units.
 * Display hint only — the server recalculates every price and total.
 */
const form = document.querySelector('[data-order-form]');
if (form) {
    const total = form.querySelector('[data-order-total]');
    const checkboxes = form.querySelectorAll('input[name="units[]"]');

    const priceOf = (checkbox) => {
        const row = checkbox.closest('label');
        const text = row?.querySelector('[data-order-unit-price]')?.textContent?.trim() ?? '0';
        return parseFloat(text.replace(/[^\d.]/g, '')) || 0;
    };

    const update = () => {
        let sum = 0;
        checkboxes.forEach((checkbox) => {
            if (checkbox.checked) sum += priceOf(checkbox);
        });
        if (total) {
            total.textContent = 'BDT ' + sum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    };

    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', update));
    update();
}
