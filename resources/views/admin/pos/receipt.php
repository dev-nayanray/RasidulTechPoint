<?php
/** @var array $order
 *  @var array{name:string,address:string,phone:string,email:string} $business
 */
$paidSum = 0.0;
foreach ($order['payments'] as $p) {
    if ($p['status'] === 'completed') {
        $paidSum += (float) $p['amount'];
    }
}
$outstanding = (float) $order['total'] - $paidSum;
?>
<main class="mx-auto max-w-md bg-white p-6 print:shadow-none print:border-none">
    <div class="text-center">
        <h1 class="text-xl font-bold tracking-tight text-slate-900"><?= e($business['name']) ?></h1>
        <?php if ($business['address'] !== ''): ?><p class="mt-1 text-xs text-slate-500"><?= e($business['address']) ?></p><?php endif; ?>
        <?php if ($business['phone'] !== ''): ?><p class="text-xs text-slate-500">Tel: <?= e($business['phone']) ?></p><?php endif; ?>
        <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Receipt</p>
    </div>

    <dl class="mt-4 space-y-0.5 text-xs text-slate-700">
        <div class="flex justify-between"><dt class="text-slate-500">Receipt #</dt><dd class="font-mono font-medium"><?= e($order['reference']) ?></dd></div>
        <div class="flex justify-between"><dt class="text-slate-500">Date</dt><dd class="font-medium"><?= e(local_datetime($order['created_at'])) ?></dd></div>
        <div class="flex justify-between"><dt class="text-slate-500">Cashier</dt><dd class="font-medium"><?= e($order['placed_by_name'] ?? 'POS') ?></dd></div>
        <div class="flex justify-between"><dt class="text-slate-500">Customer</dt><dd class="font-medium"><?= e($order['customer_name']) ?></dd></div>
        <?php if (!empty($order['customer_phone'])): ?><div class="flex justify-between"><dt class="text-slate-500">Phone</dt><dd class="font-medium"><?= e($order['customer_phone']) ?></dd></div><?php endif; ?>
    </dl>

    <table class="mt-4 w-full text-xs">
        <thead class="border-t border-b border-slate-300 text-slate-500">
            <tr>
                <th class="py-1 text-left font-medium">Item</th>
                <th class="py-1 text-left font-medium">SKU</th>
                <th class="py-1 text-right font-medium">Price</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
            <?php foreach ($order['items'] as $item): ?>
                <tr>
                    <td class="py-1 text-slate-900"><?= e($item['product_name']) ?></td>
                    <td class="py-1 text-slate-500 font-mono"><?= e($item['sku']) ?></td>
                    <td class="py-1 text-right text-slate-900"><?= e(money($item['unit_price'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <dl class="mt-3 space-y-0.5 border-t border-slate-300 pt-3 text-sm">
        <div class="flex justify-between text-slate-600"><dt>Subtotal</dt><dd><?= e(money($order['subtotal'])) ?></dd></div>
        <?php if ((float) $order['discount'] > 0): ?>
            <div class="flex justify-between text-slate-600">
                <dt>Discount<?php if (!empty($order['discount_authorizer_name'])): ?> <span class="text-xs text-slate-400">(auth: <?= e($order['discount_authorizer_name']) ?>)</span><?php endif; ?></dt>
                <dd>-<?= e(money($order['discount'])) ?></dd>
            </div>
        <?php endif; ?>
        <div class="flex justify-between text-base font-bold text-slate-900"><dt>Total</dt><dd><?= e(money($order['total'])) ?></dd></div>
        <div class="flex justify-between text-slate-600"><dt>Paid</dt><dd><?= e(money((string) $paidSum)) ?></dd></div>
        <?php if ($outstanding > 0.001): ?>
            <div class="flex justify-between text-amber-700"><dt>Outstanding</dt><dd><?= e(money((string) $outstanding)) ?></dd></div>
        <?php else: ?>
            <div class="flex justify-between text-green-700"><dt>Balance</dt><dd><?= e(money('0.00')) ?></dd></div>
        <?php endif; ?>
    </dl>

    <?php if (!empty($order['payments'])): ?>
    <p class="mt-3 text-xs text-slate-500">Payment method: <?= e(ucfirst(str_replace('_', ' ', $order['payments'][0]['method']))) ?></p>
    <?php endif; ?>

    <p class="mt-6 text-center text-xs text-slate-400">
        <?= e(\App\Services\SettingService::get('invoice_footer_text', 'Thank you for shopping at Rasidul Tech Point.')) ?>
    </p>
    <p class="mt-2 text-center text-xs text-slate-400">
        Keep this receipt for warranty claims. Refunds within 7 days only.
    </p>

    <p class="mt-4 text-center text-xs">
        <button type="button" onclick="window.print()" class="btn-secondary text-xs">Print</button>
        <a href="<?= e(url('/admin/orders/' . $order['id'])) ?>" class="ml-2 inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-medium text-blue-700 hover:bg-slate-100">View order</a>
    </p>
</main>
