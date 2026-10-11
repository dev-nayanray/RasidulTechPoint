<?php
/** @var array $invoice
 *  @var array{name:string,address:string,phone:string,email:string} $business
 *  @var bool $canVoid
 */
$taxAmount = (float) $invoice['tax_amount'];
$taxable = (float) $invoice['subtotal'] - (float) $invoice['discount'];
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/invoices')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Invoices</a></p>

<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Invoice <?= e($invoice['invoice_number']) ?></h1>
        <p class="mt-1 text-sm text-slate-500">
            <?= \App\Support\View::component('badge', ['status' => $invoice['status']]) ?>
            · issued <?= e(local_datetime($invoice['issued_at'] ?? $invoice['created_at'], 'd M Y')) ?>
            <?php if (!empty($invoice['due_date'])): ?> · due <?= e(local_datetime($invoice['due_date'], 'd M Y')) ?><?php endif; ?>
        </p>
    </div>
    <div class="flex gap-2">
        <button type="button" onclick="window.print()" class="btn-secondary">Print</button>
        <a href="<?= e(url('/admin/orders/' . $invoice['order_id'])) ?>" class="btn-secondary">View order</a>
        <?php if ($canVoid && $invoice['status'] !== 'void'): ?>
            <a href="<?= e(url('/admin/invoices/' . $invoice['id'] . '#void')) ?>" class="btn-danger">Void…</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($invoice['status'] === 'void'): ?>
<p class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">This invoice was voided on <?= e(local_datetime($invoice['voided_at'])) ?> by <?= e($invoice['voided_by_name'] ?? 'unknown') ?>. Reason: <?= e($invoice['void_reason'] ?? '—') ?>. The invoice is preserved for audit; issue a new invoice with the next sequential number.</p>
<?php endif; ?>

<main class="mt-6 mx-auto max-w-3xl bg-white p-8 print:shadow-none print:border-none">
    <header class="flex flex-wrap items-start justify-between gap-6 border-b border-slate-200 pb-6">
        <div>
            <h2 class="text-xl font-bold text-slate-900"><?= e($business['name']) ?></h2>
            <?php if ($business['address'] !== ''): ?><p class="mt-1 text-xs text-slate-500"><?= e($business['address']) ?></p><?php endif; ?>
            <?php if ($business['phone'] !== ''): ?><p class="text-xs text-slate-500">Tel: <?= e($business['phone']) ?></p><?php endif; ?>
            <?php if ($business['email'] !== ''): ?><p class="text-xs text-slate-500"><?= e($business['email']) ?></p><?php endif; ?>
        </div>
        <div class="text-right">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Invoice</p>
            <p class="font-mono text-lg font-bold text-slate-900"><?= e($invoice['invoice_number']) ?></p>
            <p class="mt-1 text-xs text-slate-500">Issue date: <?= e($invoice['issue_date']) ?></p>
            <?php if (!empty($invoice['due_date'])): ?><p class="text-xs text-slate-500">Due: <?= e($invoice['due_date']) ?></p><?php endif; ?>
        </div>
    </header>

    <section class="mt-6 grid gap-6 sm:grid-cols-2">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Bill to</p>
            <p class="mt-1 font-medium text-slate-900"><?= e($invoice['billing_name']) ?></p>
            <?php if (!empty($invoice['billing_address'])): ?><p class="text-xs text-slate-500"><?= e($invoice['billing_address']) ?></p><?php endif; ?>
            <?php if (!empty($invoice['billing_phone'])): ?><p class="text-xs text-slate-500">Tel: <?= e($invoice['billing_phone']) ?></p><?php endif; ?>
            <?php if (!empty($invoice['billing_email'])): ?><p class="text-xs text-slate-500"><?= e($invoice['billing_email']) ?></p><?php endif; ?>
        </div>
        <div class="sm:text-right">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Order reference</p>
            <p class="mt-1 font-mono text-sm text-slate-900"><a class="hover:text-blue-700" href="<?= e(url('/admin/orders/' . $invoice['order_id'])) ?>"><?= e($invoice['order_reference']) ?></a></p>
        </div>
    </section>

    <table class="mt-6 w-full text-sm">
        <thead class="border-t border-b border-slate-300 text-slate-500">
            <tr>
                <th class="py-2 text-left font-medium">Item</th>
                <th class="py-2 text-left font-medium">SKU</th>
                <th class="py-2 text-right font-medium">Unit price</th>
                <th class="py-2 text-right font-medium">Line total</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
            <?php foreach ($invoice['items'] as $item): ?>
                <tr>
                    <td class="py-2 text-slate-900"><?= e($item['product_name']) ?></td>
                    <td class="py-2 font-mono text-xs text-slate-500"><?= e($item['sku']) ?></td>
                    <td class="py-2 text-right text-slate-700"><?= e(money($item['unit_price'])) ?></td>
                    <td class="py-2 text-right font-medium text-slate-900"><?= e(money($item['line_total'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <dl class="mt-4 ml-auto max-w-xs space-y-1 text-sm">
        <div class="flex justify-between text-slate-600"><dt>Subtotal</dt><dd><?= e(money($invoice['subtotal'])) ?></dd></div>
        <?php if ((float) $invoice['discount'] > 0): ?>
            <div class="flex justify-between text-slate-600"><dt>Discount</dt><dd>-<?= e(money($invoice['discount'])) ?></dd></div>
        <?php endif; ?>
        <?php if ($taxAmount > 0): ?>
            <div class="flex justify-between text-slate-600"><dt><?= e($invoice['tax_name']) ?> (<?= number_format((float) $invoice['tax_rate'] * 100, 2) ?>%)</dt><dd><?= e(money($invoice['tax_amount'])) ?></dd></div>
        <?php endif; ?>
        <div class="flex justify-between border-t border-slate-300 pt-2 text-base font-bold text-slate-900"><dt>Total</dt><dd><?= e(money($invoice['total'])) ?></dd></div>
    </dl>

    <?php if (!empty($invoice['notes'])): ?>
        <p class="mt-6 border-t border-slate-200 pt-4 text-xs text-slate-500"><?= e($invoice['notes']) ?></p>
    <?php endif; ?>

    <p class="mt-6 text-center text-xs text-slate-400">
        <?= e(\App\Services\SettingService::get('invoice_footer_text', 'Thank you for shopping at Rasidul Tech Point.')) ?>
    </p>
    <p class="mt-1 text-center text-xs text-slate-400">Invoice <?= e($invoice['invoice_number']) ?> · issued <?= e($invoice['issue_date']) ?> · <?= e($invoice['currency']) ?></p>
</main>

<?php if ($canVoid && $invoice['status'] !== 'void'): ?>
<section id="void" class="mt-6 mx-auto max-w-3xl rounded-xl border border-red-200 bg-red-50 p-5 text-sm">
    <h3 class="font-semibold text-red-900">Void this invoice</h3>
    <p class="mt-1 text-xs text-red-800">Voiding preserves the row for audit and unlinks the order. A new invoice can be issued with the next sequential number.</p>
    <form method="post" action="<?= e(url('/admin/invoices/' . $invoice['id'] . '/void')) ?>" class="mt-3 space-y-2" data-loading-form data-confirm="Void this invoice?">
        <?= csrf_field() ?>
        <label class="block text-xs font-medium text-red-900" for="void-reason">Void reason</label>
        <input id="void-reason" name="reason" required maxlength="255" class="input" placeholder="Incorrect tax rate; customer requested revised invoice…">
        <button type="submit" class="btn-danger">Void invoice</button>
    </form>
</section>
<?php endif; ?>
