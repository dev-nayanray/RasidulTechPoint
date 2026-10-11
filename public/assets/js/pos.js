// POS terminal client. All actions go through the server; prices
// are recomputed server-side at checkout. The cart state here is
// advisory only.

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
    || document.querySelector('input[name="_token"]')?.value || '';

const cart = []; // [{unit_id, sku, name, price}]
let customerId = null;
let customerLabel = 'Walk-in customer';

const els = {
    search: document.getElementById('pos-search'),
    searchBtn: document.getElementById('pos-search-btn'),
    results: document.getElementById('pos-search-results'),
    cartBody: document.getElementById('pos-cart-body'),
    cartEmpty: document.getElementById('pos-cart-empty'),
    cartCount: document.getElementById('pos-cart-count'),
    subtotal: document.getElementById('pos-subtotal'),
    total: document.getElementById('pos-total'),
    discount: document.getElementById('pos-discount'),
    customerSearch: document.getElementById('pos-customer-search'),
    customerSearchBtn: document.getElementById('pos-customer-search-btn'),
    customerResults: document.getElementById('pos-customer-results'),
    customerDisplay: document.getElementById('pos-customer-display'),
    customerId: document.getElementById('pos-customer-id'),
    quickBtn: document.getElementById('pos-quick-customer-btn'),
    qcDialog: document.getElementById('pos-quick-customer-dialog'),
    qcSave: document.getElementById('pos-qc-save'),
    qcCancel: document.getElementById('pos-qc-cancel'),
    method: document.getElementById('pos-method'),
    paid: document.getElementById('pos-paid'),
    paymentRef: document.getElementById('pos-payment-ref'),
    change: document.getElementById('pos-change'),
    hold: document.getElementById('pos-hold-btn'),
    clear: document.getElementById('pos-clear-btn'),
    checkout: document.getElementById('pos-checkout-btn'),
};

const money = (n) => 'BDT ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function recomputeTotals() {
    const subtotal = cart.reduce((s, i) => s + Number(i.price), 0);
    const discount = Math.max(0, Number(els.discount.value || 0));
    const total = Math.max(0, subtotal - discount);
    els.subtotal.textContent = money(subtotal);
    els.total.textContent = money(total);
    els.cartCount.textContent = '(' + cart.length + ')';
    els.cartEmpty.classList.toggle('hidden', cart.length > 0);
    const paid = Number(els.paid.value || 0);
    els.change.textContent = money(Math.max(0, paid - total));
}

function renderCart() {
    els.cartBody.innerHTML = '';
    cart.forEach((item, idx) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="px-3 py-2 font-medium text-slate-900">${escapeHtml(item.name)}</td>
            <td class="px-3 py-2 text-slate-500 font-mono text-xs">${escapeHtml(item.sku)}</td>
            <td class="px-3 py-2 text-right text-slate-900">${money(item.price)}</td>
            <td class="px-3 py-2 text-right"><button type="button" class="text-xs text-red-600" data-idx="${idx}">Remove</button></td>
        `;
        tr.querySelector('button').addEventListener('click', () => {
            cart.splice(idx, 1);
            renderCart();
            recomputeTotals();
        });
        els.cartBody.appendChild(tr);
    });
    recomputeTotals();
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

async function searchUnits(q) {
    if (!q) { els.results.innerHTML = ''; return; }
    const r = await fetch(url('/admin/pos/search?q=' + encodeURIComponent(q)), { headers: { Accept: 'application/json' } });
    const data = await r.json();
    els.results.innerHTML = '';
    (data.units || []).forEach((u) => {
        const li = document.createElement('li');
        li.className = 'flex items-center justify-between py-2 cursor-pointer hover:bg-slate-50';
        li.innerHTML = `
            <div>
                <p class="font-medium text-slate-900">${escapeHtml(u.product_name)} <span class="text-xs text-slate-400">· ${escapeHtml(u.brand_name)}</span></p>
                <p class="text-xs text-slate-500">SKU: ${escapeHtml(u.sku)} · Grade ${escapeHtml(u.condition_grade)}</p>
            </div>
            <div class="text-right">
                <p class="font-semibold text-slate-900">${money(u.selling_price)}</p>
                <p class="text-xs text-blue-700">Add to cart →</p>
            </div>
        `;
        li.addEventListener('click', () => {
            if (cart.some((i) => i.unit_id === u.id)) {
                alert('Already in cart: ' + u.sku);
                return;
            }
            cart.push({ unit_id: u.id, sku: u.sku, name: u.product_name, price: u.selling_price });
            renderCart();
            els.results.innerHTML = '';
            els.search.value = '';
            els.search.focus();
        });
        els.results.appendChild(li);
    });
}

async function searchCustomers(q) {
    if (!q) { els.customerResults.innerHTML = ''; return; }
    const r = await fetch(url('/admin/pos/customers?q=' + encodeURIComponent(q)), { headers: { Accept: 'application/json' } });
    const data = await r.json();
    els.customerResults.innerHTML = '';
    (data.customers || []).forEach((c) => {
        const li = document.createElement('li');
        li.className = 'flex items-center justify-between py-2 cursor-pointer hover:bg-slate-50';
        li.innerHTML = `
            <div>
                <p class="font-medium text-slate-900">${escapeHtml(c.name)}</p>
                <p class="text-xs text-slate-500">${escapeHtml(c.phone)} ${c.email ? '· ' + escapeHtml(c.email) : ''}</p>
            </div>
            <p class="text-xs text-blue-700">Select →</p>
        `;
        li.addEventListener('click', () => {
            customerId = c.id;
            customerLabel = c.name + ' (' + c.phone + ')';
            els.customerId.value = c.id;
            els.customerDisplay.textContent = customerLabel;
            els.customerResults.innerHTML = '';
            els.customerSearch.value = '';
        });
        els.customerResults.appendChild(li);
    });
}

async function quickCreateCustomer() {
    const name = document.querySelector('[name="qc_name"]').value.trim();
    const phone = document.querySelector('[name="qc_phone"]').value.trim();
    const email = document.querySelector('[name="qc_email"]').value.trim();
    if (!name || !phone) { alert('Name and phone are required.'); return; }
    const r = await fetch(url('/admin/pos/quick-customer'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
        body: JSON.stringify({ name, phone, email: email || null }),
    });
    const data = await r.json();
    if (!r.ok) {
        alert(Object.values(data.errors || {}).join('\n'));
        return;
    }
    customerId = data.id;
    customerLabel = data.name + ' (' + data.phone + ')';
    els.customerId.value = data.id;
    els.customerDisplay.textContent = customerLabel;
    els.qcDialog.close();
}

async function holdSale() {
    if (cart.length === 0) { alert('Cart is empty.'); return; }
    const subtotal = cart.reduce((s, i) => s + Number(i.price), 0);
    const discount = Math.max(0, Number(els.discount.value || 0));
    const total = Math.max(0, subtotal - discount);
    const r = await fetch(url('/admin/pos/hold'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
        body: JSON.stringify({
            items: cart.map((i) => ({ unit_id: i.unit_id, sku: i.sku, name: i.name, price: i.price })),
            subtotal, discount, total,
            customer_id: customerId,
            customer_name: customerLabel,
            notes: null,
        }),
    });
    const data = await r.json();
    if (!r.ok) { alert(Object.values(data.errors || {}).join('\n')); return; }
    alert('Held as ' + data.reference);
    cart.length = 0;
    customerId = null;
    customerLabel = 'Walk-in customer';
    els.customerId.value = '';
    els.customerDisplay.textContent = 'No customer selected — walk-in by default.';
    els.discount.value = '0';
    renderCart();
    location.reload();
}

async function resumeHeld(id) {
    const r = await fetch(url('/admin/pos/resume/' + id), {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
    });
    const data = await r.json();
    if (!r.ok) { alert(Object.values(data.errors || {}).join('\n')); return; }
    cart.length = 0;
    (data.cart.items || []).forEach((i) => cart.push({ unit_id: i.unit_id, sku: i.sku, name: i.name, price: Number(i.price) }));
    if (data.held_sale.customer_id) {
        customerId = data.held_sale.customer_id;
        customerLabel = data.held_sale.customer_name + ' (' + (data.held_sale.customer_phone || '') + ')';
        els.customerId.value = customerId;
        els.customerDisplay.textContent = customerLabel;
    }
    els.discount.value = data.cart.discount || 0;
    renderCart();
    if ((data.unavailable_unit_ids || []).length > 0) {
        alert('Some items are no longer available (unit ids: ' + data.unavailable_unit_ids.join(', ') + '). Remove them before checkout.');
    }
}

async function cancelHeld(id) {
    if (!confirm('Cancel this held sale? The cart will be discarded.')) return;
    const r = await fetch(url('/admin/pos/cancel-held/' + id), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
        body: JSON.stringify({ reason: 'Cancelled from terminal' }),
    });
    if (r.ok) { location.reload(); }
}

async function checkout() {
    if (cart.length === 0) { alert('Cart is empty.'); return; }
    const subtotal = cart.reduce((s, i) => s + Number(i.price), 0);
    const discount = Math.max(0, Number(els.discount.value || 0));
    const total = Math.max(0, subtotal - discount);
    const r = await fetch(url('/admin/pos/checkout'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
        body: JSON.stringify({
            items: cart.map((i) => ({ unit_id: i.unit_id })),
            subtotal, discount, total,
            customer_id: customerId,
            payment_method: els.method.value,
            paid_amount: Number(els.paid.value || 0),
            payment_reference: els.paymentRef.value || null,
        }),
    });
    const data = await r.json();
    if (!r.ok) { alert(Object.values(data.errors || {}).join('\n')); return; }
    alert('Sale complete: ' + data.reference + ' — change: ' + money(data.change));
    cart.length = 0;
    customerId = null;
    customerLabel = 'Walk-in customer';
    els.customerId.value = '';
    els.customerDisplay.textContent = 'No customer selected — walk-in by default.';
    els.discount.value = '0';
    els.paid.value = '0';
    els.paymentRef.value = '';
    renderCart();
    // Open the receipt in a new tab.
    window.open(url('/admin/pos/receipt/' + data.order_id), '_blank');
}

// Wire up events after the DOM is ready.
els.search.addEventListener('input', () => searchUnits(els.search.value));
els.searchBtn.addEventListener('click', () => searchUnits(els.search.value));
els.customerSearch.addEventListener('input', () => searchCustomers(els.customerSearch.value));
els.customerSearchBtn.addEventListener('click', () => searchCustomers(els.customerSearch.value));
els.quickBtn.addEventListener('click', () => els.qcDialog.showModal());
els.qcCancel.addEventListener('click', () => els.qcDialog.close());
els.qcSave.addEventListener('click', (e) => { e.preventDefault(); quickCreateCustomer(); });
els.discount.addEventListener('input', recomputeTotals);
els.paid.addEventListener('input', recomputeTotals);
els.hold.addEventListener('click', holdSale);
els.clear.addEventListener('click', () => { cart.length = 0; renderCart(); });
els.checkout.addEventListener('click', checkout);

document.querySelectorAll('.pos-resume-btn').forEach((b) => b.addEventListener('click', () => resumeHeld(b.dataset.id)));
document.querySelectorAll('.pos-cancel-held-btn').forEach((b) => b.addEventListener('click', () => cancelHeld(b.dataset.id)));

function url(path) {
    const base = document.querySelector('meta[name="base-url"]')?.content || '';
    return base + path;
}
