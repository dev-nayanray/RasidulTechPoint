<?php
/** @var array|null $shift
 *  @var list<array> $heldSales
 *  @var bool $canAuthorizeDiscount
 *  @var float $discountThreshold
 *  @var array<string,string> $paymentMethods
 *  @var array{name:string,address:string,phone:string,email:string} $business
 */
?>
<div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
    <!-- Left: terminal -->
    <section class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <h2 class="text-sm font-semibold text-slate-900">Scan or search inventory</h2>
            <div class="mt-3 flex gap-2">
                <label class="sr-only" for="pos-search">Search SKU, serial or model</label>
                <input id="pos-search" type="search" class="input" placeholder="Scan barcode / type SKU or model name…" autocomplete="off">
                <button id="pos-search-btn" type="button" class="btn-primary">Search</button>
            </div>
            <ul id="pos-search-results" class="mt-2 divide-y divide-slate-100 text-sm"></ul>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white">
            <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-900">Cart <span id="pos-cart-count" class="text-slate-400">(0)</span></h2>
                <div class="flex gap-2">
                    <button type="button" id="pos-hold-btn" class="btn-secondary text-xs">Hold sale</button>
                    <button type="button" id="pos-clear-btn" class="btn-secondary text-xs">Clear</button>
                </div>
            </header>
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-slate-500">Item</th>
                        <th class="px-3 py-2 text-left font-medium text-slate-500">SKU</th>
                        <th class="px-3 py-2 text-right font-medium text-slate-500">Price</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody id="pos-cart-body" class="divide-y divide-slate-100"></tbody>
            </table>
            <p id="pos-cart-empty" class="px-4 py-6 text-center text-sm text-slate-400">Scan an item to add it to the cart.</p>
            <div class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm">
                <p class="flex justify-between text-slate-500"><span>Subtotal</span><span id="pos-subtotal">BDT 0.00</span></p>
                <div class="flex items-center justify-between gap-3">
                    <label for="pos-discount" class="text-slate-500">Discount</label>
                    <input id="pos-discount" type="number" step="0.01" min="0" value="0" class="input w-32 text-right">
                </div>
                <p class="flex justify-between text-base font-bold text-slate-900"><span>Total</span><span id="pos-total">BDT 0.00</span></p>
                <?php if (!$canAuthorizeDiscount): ?>
                    <p class="text-xs text-slate-400">Discounts above <?= round($discountThreshold * 100) ?>% require a manager. Ask one to log in at their terminal.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-3">
            <h2 class="text-sm font-semibold text-slate-900">Customer</h2>
            <div class="flex flex-wrap gap-2">
                <input id="pos-customer-id" type="hidden" value="">
                <input id="pos-customer-search" type="search" class="input flex-1" placeholder="Search by name or phone…">
                <button id="pos-customer-search-btn" type="button" class="btn-secondary">Search</button>
                <button id="pos-quick-customer-btn" type="button" class="btn-secondary">Walk-in…</button>
            </div>
            <p id="pos-customer-display" class="text-sm text-slate-500">No customer selected — walk-in by default.</p>
            <ul id="pos-customer-results" class="divide-y divide-slate-100 text-sm"></ul>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-3">
            <h2 class="text-sm font-semibold text-slate-900">Payment</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="space-y-1.5">
                    <label for="pos-method" class="block text-xs font-medium text-slate-500">Method</label>
                    <select id="pos-method" class="input">
                        <?php foreach ($paymentMethods as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <label for="pos-paid" class="block text-xs font-medium text-slate-500">Paid amount</label>
                    <input id="pos-paid" type="number" step="0.01" min="0" value="0" class="input text-right">
                </div>
                <div class="space-y-1.5">
                    <label for="pos-payment-ref" class="block text-xs font-medium text-slate-500">Reference (optional)</label>
                    <input id="pos-payment-ref" type="text" maxlength="120" class="input" placeholder="bKash txn / bank slip">
                </div>
            </div>
            <p class="text-xs text-slate-500">Change due: <span id="pos-change" class="font-semibold text-slate-900">BDT 0.00</span></p>
            <button id="pos-checkout-btn" type="button" class="btn-primary w-full text-base">Complete sale</button>
        </div>
    </section>

    <!-- Right: shift + held sales -->
    <aside class="space-y-4">
        <?= \App\Support\View::partial('admin/pos/_shift', ['shift' => $shift]) ?>

        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <h2 class="text-sm font-semibold text-slate-900">Held sales</h2>
            <?php if ($heldSales === []): ?>
                <p class="mt-2 text-xs text-slate-400">No held sales right now.</p>
            <?php else: ?>
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    <?php foreach ($heldSales as $held): ?>
                        <li class="py-2">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-medium text-slate-800"><?= e($held['reference']) ?></p>
                                    <p class="text-xs text-slate-500"><?= e($held['customer_name']) ?> · <?= money($held['total']) ?></p>
                                </div>
                                <div class="flex gap-1">
                                    <button type="button" class="btn-secondary text-xs pos-resume-btn" data-id="<?= (int) $held['id'] ?>">Resume</button>
                                    <button type="button" class="btn-secondary text-xs pos-cancel-held-btn" data-id="<?= (int) $held['id'] ?>">Cancel</button>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
            <p class="font-semibold">Server-side rules in force</p>
            <ul class="mt-1 list-disc list-inside space-y-0.5">
                <li>Each physical unit is locked at checkout — no double-selling.</li>
                <li>Discounts above <?= round($discountThreshold * 100) ?>% require manager approval.</li>
                <li>Non-cash payments without a reference stay pending evidence.</li>
            </ul>
        </div>
    </aside>
</div>

<!-- Walk-in customer modal -->
<dialog id="pos-quick-customer-dialog" class="rounded-xl border border-slate-200 p-6 max-w-md">
    <form method="dialog" class="space-y-3">
        <h3 class="text-base font-semibold text-slate-900">Quick walk-in customer</h3>
        <p class="text-xs text-slate-500">Use this for one-off POS sales. The customer will be saved to the CRM.</p>
        <?= \App\Support\View::component('field', ['name' => 'qc_name', 'label' => 'Name', 'required' => true]) ?>
        <?= \App\Support\View::component('field', ['name' => 'qc_phone', 'label' => 'Phone', 'required' => true]) ?>
        <?= \App\Support\View::component('field', ['name' => 'qc_email', 'label' => 'Email (optional)', 'type' => 'email']) ?>
        <div class="flex gap-2 pt-2">
            <button type="button" id="pos-qc-save" class="btn-primary">Create customer</button>
            <button type="button" id="pos-qc-cancel" class="btn-secondary">Cancel</button>
        </div>
    </form>
</dialog>

<script type="module" src="<?= e(url('/assets/js/pos.js')) ?>"></script>
